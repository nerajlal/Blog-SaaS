import json
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET
import dns.resolver
from django.conf import settings
from django.contrib import messages
from django.core.exceptions import ValidationError
from django.core.paginator import Paginator
from django.db import IntegrityError, transaction
from django.http import Http404, HttpResponse
from django.shortcuts import get_object_or_404, render, redirect
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_GET, require_http_methods
from .models import HostedSite
from .services import clean_html, image_url, public_url, seo_payload
from .views import page, project_for


def site_origin(site):
    if site.domain and site.verified_at and site.activated_at:
        return "https://" + site.domain
    return settings.PUBLIC_URL.rstrip("/") + reverse(
        "hosted_index", args=[site.project.subdomain]
    )


def resolve_site(request, publication=None):
    site = getattr(request, "hosted_site", None)
    if site is None:
        site = get_object_or_404(
            HostedSite.objects.select_related("project"),
            project__subdomain=publication,
            enabled=True,
        )
    if not site.enabled:
        raise Http404
    return site


@page
@require_http_methods(["GET", "POST"])
def setup(request, pk):
    project = project_for(request, pk, "manage")
    site, _ = HostedSite.objects.get_or_create(project=project)
    error = ""
    if request.method == "POST":
        operation = request.POST.get("operation", "save")
        try:
            if operation == "verify":
                if not site.domain:
                    raise ValidationError("Save a subdomain first.")
                try:
                    records = [
                        "".join(part.decode() for part in rr.strings)
                        for rr in dns.resolver.resolve(
                            "_trywryter-verification." + site.domain, "TXT", lifetime=5
                        )
                    ]
                except dns.exception.DNSException:
                    records = []
                if "trywryter=" + site.verification_token not in records:
                    raise ValidationError(
                        "TXT record not found. Check DNS and try again after propagation."
                    )
                if not HostedSite.objects.filter(
                    pk=site.pk,
                    domain=site.domain,
                    verification_token=site.verification_token,
                ).update(verified_at=timezone.now(), updated_at=timezone.now()):
                    raise ValidationError(
                        "Domain settings changed during verification; try again."
                    )
                messages.success(
                    request,
                    "Domain ownership verified. Support can now configure HTTPS and activate it.",
                )
            elif operation == "save":
                domain = request.POST.get("domain", "").strip().lower().rstrip(".")
                if domain:
                    parsed = urlsplit(public_url("https://" + domain))
                    if (
                        parsed.hostname != domain
                        or parsed.path not in ["", "/"]
                        or parsed.port
                        or len(domain.split(".")) < 3
                    ):
                        raise ValidationError(
                            "Enter a subdomain only, such as blog.example.com, without https or a path."
                        )
                    if domain == urlsplit(settings.PUBLIC_URL).hostname:
                        raise ValidationError("Use your own website subdomain.")
                with transaction.atomic():
                    site = HostedSite.objects.select_for_update().get(pk=site.pk)
                    if site.domain != (domain or None):
                        from .models import token

                        site.verification_token = token()
                        site.verified_at = None
                        site.activated_at = None
                    site.domain = domain or None
                    site.enabled = request.POST.get("enabled") == "1"
                    site.save()
                messages.success(request, "Hosted blog settings saved.")
            else:
                raise ValidationError("Unknown action.")
            return redirect("hosted_setup", pk=pk)
        except (ValidationError, ValueError, IntegrityError) as exc:
            error = (
                "This subdomain is already assigned to another project."
                if isinstance(exc, IntegrityError)
                else " ".join(exc.messages)
                if isinstance(exc, ValidationError)
                else "Enter a valid subdomain."
            )
            site.refresh_from_db()
    return render(
        request,
        "portal/hosted_setup.html",
        {
            "project": project,
            "site": site,
            "error": error,
            "hosted_url": site_origin(site),
        },
        status=400 if error else 200,
    )


@require_GET
def index(request, publication=None):
    site = resolve_site(request, publication)
    posts = Paginator(
        site.project.blogs.live()
        .select_related("category")
        .order_by("-published_at", "-id"),
        12,
    ).get_page(request.GET.get("page"))
    return render(
        request,
        "portal/hosted_index.html",
        {
            "site": site,
            "project": site.project,
            "posts": posts,
            "base_url": site_origin(site),
        },
    )


@require_GET
def post(request, slug, publication=None):
    site = resolve_site(request, publication)
    blog = get_object_or_404(
        site.project.blogs.live().select_related("user", "category"), slug=slug
    )
    sections = [
        {
            "html": clean_html(s.text_content),
            "image": image_url(s.image_path, site.project.seo_settings),
            "alt": s.image_alt,
            "caption": s.image_caption,
        }
        for s in blog.sections.all()
    ]
    seo = blog.seo_settings
    metadata = seo_payload(blog)
    return render(
        request,
        "portal/hosted_post.html",
        {
            "site": site,
            "project": site.project,
            "blog": blog,
            "content": clean_html(blog.content),
            "sections": sections,
            "cover": image_url(blog.cover_image, site.project.seo_settings),
            "base_url": site_origin(site),
            "canonical": seo.get("canonical_url")
            or site_origin(site) + "/posts/" + blog.slug,
            "seo_title": metadata["title"],
            "description": metadata["description"],
            "schema_json": json.dumps(metadata["schema"])
            .replace("<", "\\u003C")
            .replace(">", "\\u003E")
            .replace("&", "\\u0026"),
            "noindex": not metadata["robots"]["index"],
        },
    )


@require_GET
def sitemap(request, publication=None):
    site = resolve_site(request, publication)
    root = ET.Element("urlset", xmlns="http://www.sitemaps.org/schemas/sitemap/0.9")
    blogs = site.project.blogs.live().order_by("id")
    page = Paginator(blogs, 1000).get_page(request.GET.get("page", 1))
    if "page" not in request.GET and page.paginator.num_pages > 1:
        root = ET.Element(
            "sitemapindex", xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        )
        for number in page.paginator.page_range:
            entry = ET.SubElement(root, "sitemap")
            ET.SubElement(entry, "loc").text = (
                site_origin(site) + "/sitemap.xml?page=" + str(number)
            )
    else:
        for blog in page:
            url = site_origin(site) + "/posts/" + blog.slug
            if not seo_payload(blog)["robots"]["index"] or blog.seo_settings.get(
                "canonical_url"
            ) not in [
                None,
                "",
                url,
            ]:
                continue
            entry = ET.SubElement(root, "url")
            ET.SubElement(entry, "loc").text = url
            ET.SubElement(entry, "lastmod").text = blog.updated_at.isoformat()
    return HttpResponse(
        ET.tostring(root, encoding="utf-8", xml_declaration=True),
        content_type="application/xml",
    )


@require_GET
def robots(request):
    site = resolve_site(request)
    return HttpResponse(
        "User-agent: *\nAllow: /\nSitemap: " + site_origin(site) + "/sitemap.xml\n",
        content_type="text/plain",
    )
