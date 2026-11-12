import base64
import csv
import hashlib
import html
import io
import ipaddress
import json
import re
import secrets
import uuid
from datetime import timedelta
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit, urlunsplit
import bleach
import dns.resolver
import urllib3
from django.conf import settings
from django.core.exceptions import PermissionDenied, ValidationError
from django.core.files.base import ContentFile
from django.core.files.storage import default_storage
from django.db import transaction
from django.db.models import F, Q
from django.http import Http404
from django.utils import timezone
from django.utils.html import strip_tags
from PIL import Image, ImageOps
from .models import *

TAGS = [
    "p",
    "br",
    "strong",
    "b",
    "em",
    "i",
    "u",
    "s",
    "h1",
    "h2",
    "h3",
    "h4",
    "blockquote",
    "pre",
    "code",
    "ol",
    "ul",
    "li",
    "a",
    "span",
    "sub",
    "sup",
]


def clean_html(value):
    value = re.sub(
        r"<(script|style|iframe|object|svg|template)\b[^>]*>.*?</\1\s*>",
        "",
        value or "",
        flags=re.I | re.S,
    )
    return bleach.clean(
        value,
        tags=TAGS,
        attributes={
            "a": ["href", "title"],
            "li": ["data-list"],
            "span": lambda tag, name, value: (
                name == "class"
                and bool(value.split())
                and all(
                    part
                    in {
                        "ql-size-small",
                        "ql-size-large",
                        "ql-size-huge",
                        *{
                            f"ql-size-{size}px"
                            for size in (12, 14, 16, 18, 20, 24, 28, 32, 36, 48)
                        },
                    }
                    for part in value.split()
                )
            ),
        },
        protocols=["https", "http", "mailto"],
        strip=True,
    )


def digest(value):
    return hashlib.sha256(
        (
            value
            if isinstance(value, str)
            else json.dumps(value, sort_keys=True, default=str)
        ).encode()
    ).hexdigest()


def can_access(user, project, action="view"):
    if not user.is_authenticated:
        return False
    if not project.workspace_id:
        return project.user_id == user.pk
    roles = (
        ["owner", "admin"]
        if action == "manage"
        else ["owner", "admin", "editor"]
        if action == "update"
        else ["owner", "admin", "editor", "viewer"]
    )
    return Membership.objects.filter(
        is_frozen=False, workspace_id=project.workspace_id, user=user, role__in=roles
    ).exists()


def authorize(user, project, action="view"):
    if not can_access(user, project, action):
        raise PermissionDenied
    return project


def project_for(request, pk, action="view"):
    try:
        project = Project.objects.get(pk=pk)
    except Project.DoesNotExist:
        raise Http404
    authorize(request.user, project, action)
    request.project_context = project
    return project


def current_workspace(user):
    ws = Workspace.objects.filter(
        memberships__is_frozen=False,
        memberships__user=user,
        pk=user.current_workspace_id,
    ).first()
    if not ws:
        ws = Workspace.objects.filter(
            memberships__is_frozen=False, memberships__user=user
        ).first()
        if not ws:
            with transaction.atomic():
                ws = Workspace.objects.create(
                    owner=user,
                    name=f"{user.first_name or user.username}'s Workspace",
                    slug=uuid.uuid4().hex,
                )
                Membership.objects.create(user=user, workspace=ws, role="owner")
        user.current_workspace = ws
        user.save(update_fields=["current_workspace"])
    return ws


def revision(blog):
    data = {
        f.name: getattr(blog, f.attname)
        for f in Blog._meta.fields
        if f.name not in ["revision"]
    }
    data["sections"] = list(blog.sections.values())
    return digest(data)


def save_image(upload, folder="banners"):
    if upload.size > 2 * 1024 * 1024:
        raise ValidationError("Images must be at most 2 MB.")
    try:
        img = Image.open(upload)
        if (
            img.format not in ["JPEG", "PNG", "WEBP", "GIF"]
            or img.width * img.height > 20_000_000
        ):
            raise ValueError()
        img.verify()
        upload.seek(0)
        img = Image.open(upload)
        transparent = "A" in img.getbands() or "transparency" in img.info
        img = ImageOps.exif_transpose(img)
        img = img.convert("RGBA" if transparent else "RGB")
        limit = 512 if folder == "avatars" else 2560
        img.thumbnail((limit, limit), Image.Resampling.LANCZOS)
        out = io.BytesIO()
        img.save(
            out, format="WEBP", quality=82, method=4, exif=b"", icc_profile=b"", xmp=b""
        )
    except Exception as exc:
        raise ValidationError("Use a valid JPEG, PNG, WebP, or GIF image.") from exc
    name = default_storage.save(
        f"blogs/{folder}/{uuid.uuid4().hex}.webp", ContentFile(out.getvalue())
    )
    return settings.MEDIA_URL + name


# Explicit public destinations, DNS pinning, redirect validation and bounded reads.
def public_url(value):
    parts = urlsplit(value.strip())
    if (
        parts.scheme not in ["https", "http"]
        or not parts.hostname
        or parts.username
        or parts.password
        or parts.port not in [None, 80, 443]
    ):
        raise ValidationError("Use a public HTTP(S) URL.")
    if not re.fullmatch(
        r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}", parts.hostname, re.I
    ) or re.search(r"\.(local|localhost|internal|test|invalid)$", parts.hostname, re.I):
        raise ValidationError("Use a public hostname.")
    if any(ord(c) < 33 for c in value) or "\\" in value:
        raise ValidationError("Unsafe URL.")
    if parts.port and parts.port != (443 if parts.scheme == "https" else 80):
        raise ValidationError("Use standard ports.")
    return urlunsplit(
        (parts.scheme, parts.netloc.lower(), parts.path or "/", parts.query, "")
    )


def origin(url):
    p = urlsplit(public_url(url))
    return f"{p.scheme}://{p.netloc}"


def fetch_public(url, method="GET", same_origin=None, robots=None):
    for _ in range(4):
        url = public_url(url)
        if same_origin and origin(url) != same_origin:
            raise ValidationError(
                "Redirect left the selected website; enter the final public URL."
            )
        if robots and not robots.can_fetch("TryWryter-AI", url):
            raise ValidationError("Page is disallowed by robots.txt.")
        p = urlsplit(url)
        ips = []
        for kind in ["A", "AAAA"]:
            try:
                ips += [
                    str(r) for r in dns.resolver.resolve(p.hostname, kind, lifetime=3)
                ]
            except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN):
                pass
        if not ips or any(
            not ipaddress.ip_address(ip).is_global
            or ipaddress.ip_address(ip).is_multicast
            or ipaddress.ip_address(ip).is_reserved
            or any(
                ipaddress.ip_address(ip) in ipaddress.ip_network(net)
                for net in (
                    ["2001::/23", "2002::/16", "3fff::/20"]
                    if ":" in ip
                    else ["192.0.0.0/24", "198.18.0.0/15"]
                )
            )
            for ip in ips
        ):
            raise ValidationError(
                "Website DNS is missing or includes a non-public address."
            )
        pool = (
            urllib3.HTTPSConnectionPool(
                ips[0], 443, server_hostname=p.hostname, assert_hostname=p.hostname
            )
            if p.scheme == "https"
            else urllib3.HTTPConnectionPool(ips[0], 80)
        )
        try:
            response = pool.request(
                method,
                p.path + ("?" + p.query if p.query else ""),
                headers={"Host": p.netloc, "User-Agent": "TryWryter-AI/1.0"},
                redirect=False,
                retries=False,
                preload_content=False,
                timeout=urllib3.Timeout(connect=3, read=5),
            )
            if sum(len(k) + len(v) for k, v in response.headers.items()) > 32768:
                raise ValidationError("Website headers are too large.")
            if response.status in [301, 302, 303, 307, 308]:
                url = urljoin(url, response.headers.get("Location", ""))
                continue
            body = response.read(524289) if method != "HEAD" else b""
            if len(body) > 524288:
                raise ValidationError("Website page exceeds 512 KB.")
            return {
                "url": url,
                "status": response.status,
                "headers": {
                    key.lower(): value for key, value in response.headers.items()
                },
                "body": body.decode("utf-8", "replace"),
            }
        finally:
            pool.close()
    raise ValidationError("Too many redirects.")


class RobotsPolicy:
    """Conservative allow/disallow matching, including common wildcard/end rules."""

    def __init__(self):
        self.groups = []

    def parse(self, lines):
        agents = []
        rules = []
        for line in lines:
            line = line.split("#", 1)[0].strip()
            if ":" not in line:
                continue
            key, value = (part.strip() for part in line.split(":", 1))
            key = key.lower()
            if key == "user-agent":
                if rules:
                    self.groups.append((agents, rules))
                    agents = []
                    rules = []
                agents.append(value.lower())
            elif agents and key in ["allow", "disallow"] and value:
                rules.append((key, value))
        self.groups.append((agents, rules))

    def can_fetch(self, agent, url):
        parts = urlsplit(url)
        path = (parts.path or "/") + ("?" + parts.query if parts.query else "")
        best = -1
        allowed = True
        for agents, rules in self.groups:
            if not any(item == "*" or item in agent.lower() for item in agents):
                continue
            for kind, pattern in rules:
                end = pattern.endswith("$")
                pattern = pattern[:-1] if end else pattern
                expression = (
                    "^" + re.escape(pattern).replace(r"\*", ".*") + ("$" if end else "")
                )
                size = len(pattern.replace("*", ""))
                if re.search(expression, path) and (
                    size > best or (size == best and kind == "allow")
                ):
                    best = size
                    allowed = kind == "allow"
        return allowed


def website_sources(urls):
    if not 1 <= len(urls) <= 3:
        raise ValidationError("Choose one to three pages.")
    host = origin(urls[0])
    policy = fetch_public(host + "/robots.txt", same_origin=host)
    if policy["status"] not in [200, 404, 410] or "<html" in policy["body"].lower():
        raise ValidationError(
            "Cannot confirm crawl permissions; paste source text instead."
        )
    robots = RobotsPolicy()
    robots.parse(policy["body"].splitlines() if policy["status"] == 200 else [])
    sources = []
    for i, url in enumerate(urls):
        response = fetch_public(url, same_origin=host, robots=robots)
        if (
            response["status"] != 200
            or "text/html" not in response["headers"].get("content-type", "").lower()
        ):
            raise ValidationError("Selected page is not readable HTML.")
        text = strip_tags(
            re.sub(
                r"<(script|style|nav|footer|header)\b[^>]*>.*?</\1>",
                "",
                response["body"],
                flags=re.I | re.S,
            )
        )
        text = re.sub(r"\s+", " ", html.unescape(text)).strip()
        if len(text) < 60:
            raise ValidationError("Too little page text; paste source content instead.")
        sources.append(
            {
                "id": f"web-{i + 1}",
                "url": response["url"],
                "text": text[:8000],
                "retrieved_at": timezone.now().isoformat(),
            }
        )
    return sources


def rate_limit(request, name, count=60, seconds=60):
    from django.utils.crypto import salted_hmac

    window = int(timezone.now().timestamp()) // seconds
    remote = request.META.get("REMOTE_ADDR", "")
    if settings.TRUST_PROXY:
        forwarded = request.META.get("HTTP_X_FORWARDED_FOR", "").split(",")[-1].strip()
        try:
            remote = str(ipaddress.ip_address(forwarded))
        except ValueError:
            pass
    key = salted_hmac("rate", f"{name}:{remote}:{window}").hexdigest()
    with transaction.atomic():
        row, _ = RateBucket.objects.get_or_create(key=key)
        RateBucket.objects.filter(pk=row.pk).update(count=F("count") + 1)
        row.refresh_from_db()
        if row.count > count:
            raise ValidationError("Too many requests. Try again later.")


def article_url(blog):
    p = blog.project
    from .models import HostedSite

    site = HostedSite.objects.filter(project=p, enabled=True).first()
    if site:
        from .hosted import site_origin

        return site_origin(site) + "/posts/" + blog.slug
    if not p.seo_verified_at or not p.seo_settings.get("public_url"):
        return ""
    pattern = p.seo_settings.get(
        "article_path", p.seo_settings.get("blog_path", "/blog/{slug}")
    )
    return origin(p.seo_settings["public_url"]) + pattern.replace("{slug}", blog.slug)


def image_url(path, defaults):
    if not path:
        return None
    if path.startswith(("https://", "http://")):
        return path
    if not path.startswith("/storage/"):
        return None
    return (defaults.get("media_url") or settings.PUBLIC_URL).rstrip("/") + path


def seo_payload(blog):
    p = blog.project
    defaults = p.seo_settings
    seo = blog.seo_settings
    own = article_url(blog) or None
    text = lambda value: re.sub(
        r"\s+", " ", html.unescape(strip_tags(value or ""))
    ).strip()
    site = text(defaults.get("site_name") or p.name)
    title = text(seo.get("title")) or (
        defaults.get("title_template") or "{title} | {site_name}"
    ).replace("{title}", text(blog.title)).replace("{site_name}", site)
    body = " ".join(blog.sections.values_list("text_content", flat=True))
    description = (
        text(seo.get("description"))
        or text(defaults.get("description"))
        or text(blog.excerpt or body or blog.content or p.description)[:160]
    )
    canonical = (seo.get("canonical_url") or own) if own else None
    index = seo.get("robots_index") or "inherit"
    follow = seo.get("robots_follow") or "inherit"
    index = defaults.get("index", True) if index == "inherit" else index == "index"
    follow = defaults.get("follow", True) if follow == "inherit" else follow == "follow"
    cover = image_url(blog.cover_image, defaults)
    image = (
        seo.get("og_image_url")
        or cover
        or defaults.get("social_image_url")
        or defaults.get("social_image")
    )
    og_title = text(seo.get("og_title")) or title
    og_description = text(seo.get("og_description")) or description
    resolved = {
        "version": 1,
        "title": title,
        "description": description,
        "canonical_url": canonical,
        "public_url": own,
        "locale": (defaults.get("locale") or "en"),
        "robots": {"index": index, "follow": follow},
        "open_graph": {
            "type": "article",
            "site_name": site,
            "title": og_title,
            "description": og_description,
            "url": canonical,
            "image_url": image,
            "image_alt": text(blog.cover_image_alt)
            if image == cover and not blog.cover_image_decorative
            else "",
        },
        "twitter": {
            "card": "summary_large_image"
            if seo.get("x_image_url") or image
            else "summary",
            "title": text(seo.get("x_title")) or og_title,
            "description": text(seo.get("x_description")) or og_description,
            "image_url": seo.get("x_image_url") or image,
        },
        "schema": {},
        "warnings": []
        if own
        else [
            "Verify the project public domain before canonical URLs, structured data, and sitemap URLs can be generated."
        ],
    }
    if canonical and seo.get("schema_type", "BlogPosting") != "none":
        base = (defaults.get("public_url") or own.rsplit("/posts/", 1)[0]).rstrip("/")
        publisher = {
            "@type": (defaults.get("publisher_type") or "Organization"),
            "@id": base + "/#publisher",
            "name": text(defaults.get("publisher_name")) or site,
        }
        if defaults.get("publisher_logo_url"):
            publisher["logo" if publisher["@type"] == "Organization" else "image"] = {
                "@type": "ImageObject",
                "url": defaults["publisher_logo_url"],
            }
        article = {
            "@type": seo.get("schema_type", "BlogPosting"),
            "@id": canonical + "#article",
            "headline": text(blog.title),
            "description": description,
            "url": canonical,
            "mainEntityOfPage": {"@id": canonical + "#webpage"},
            "publisher": {"@id": base + "/#publisher"},
            "inLanguage": resolved["locale"],
            "author": {
                "@type": "Person",
                "name": blog.author_name or blog.user.first_name or "Editorial team",
            },
            "dateModified": blog.updated_at.isoformat(),
        }
        if blog.author_bio:
            article["author"]["description"] = text(blog.author_bio)
        if blog.author_title:
            article["author"]["jobTitle"] = text(blog.author_title)
        if blog.author_social_links:
            article["author"]["sameAs"] = list(blog.author_social_links.values())
        if blog.author_avatar:
            article["author"]["image"] = image_url(blog.author_avatar, defaults)
        if blog.published_at:
            article["datePublished"] = blog.published_at.isoformat()
        if image:
            article["image"] = [image]
        resolved["schema"] = {
            "@context": "https://schema.org",
            "@graph": [
                publisher,
                {
                    "@type": "WebSite",
                    "@id": base + "/#website",
                    "url": base + "/",
                    "name": site,
                    "publisher": {"@id": base + "/#publisher"},
                },
                {
                    "@type": "WebPage",
                    "@id": canonical + "#webpage",
                    "url": canonical,
                    "name": title,
                    "isPartOf": {"@id": base + "/#website"},
                    "breadcrumb": {"@id": canonical + "#breadcrumb"},
                },
                {
                    "@type": "BreadcrumbList",
                    "@id": canonical + "#breadcrumb",
                    "itemListElement": [
                        {
                            "@type": "ListItem",
                            "position": 1,
                            "name": site,
                            "item": base + "/",
                        },
                        {
                            "@type": "ListItem",
                            "position": 2,
                            "name": text(blog.title),
                            "item": canonical,
                        },
                    ],
                },
                article,
            ],
        }
    return resolved


def blog_payload(blog):
    keys = [
        "id",
        "project_id",
        "category_id",
        "title",
        "slug",
        "excerpt",
        "content",
        "cover_image",
        "author_name",
        "author_title",
        "author_avatar",
        "author_bio",
        "author_social_links",
        "image_credits",
        "status",
        "published_at",
        "created_at",
        "updated_at",
        "cover_image_alt",
        "cover_image_caption",
        "cover_image_decorative",
    ]
    data = {key: getattr(blog, key) for key in keys}
    data["sections"] = list(blog.sections.values())
    data["category"] = (
        {"id": blog.category_id, "name": blog.category.name, "slug": blog.category.slug}
        if blog.category_id
        else None
    )
    data["seo"] = seo_payload(blog)
    data["cover_image_url"] = image_url(blog.cover_image, blog.project.seo_settings)
    for section in data["sections"]:
        section["image_url"] = image_url(
            section["image_path"], blog.project.seo_settings
        )
    return data


SEO_RULES_VERSION = "django-2"


def seo_input_hash(blog):
    # Publication/review timestamps do not change these on-page content checks.
    return digest(
        {
            "title": blog.title,
            "excerpt": blog.excerpt,
            "content": blog.content,
            "seo": blog.seo_settings,
            "cover": blog.cover_image,
            "cover_alt": blog.cover_image_alt,
            "cover_decorative": blog.cover_image_decorative,
            "cornerstone": blog.is_cornerstone,
            "sections": list(
                blog.sections.values(
                    "text_content", "image_path", "image_alt", "image_decorative"
                )
            ),
        }
    )


def seo_context_hash(project):
    return digest(project.seo_settings)


def analysis_is_stale(analysis, blog):
    return (
        not analysis
        or analysis.report.get("rules_version") != SEO_RULES_VERSION
        or analysis.input_hash != seo_input_hash(blog)
        or analysis.report.get("context_hash") != seo_context_hash(blog.project)
    )


def analyze(blog):
    latest = blog.analyses.order_by("-id").first()
    if not analysis_is_stale(latest, blog):
        return latest
    sections = list(blog.sections.all())
    markup = blog.content + " " + " ".join(s.text_content for s in sections)
    text = re.sub(
        r"\s+",
        " ",
        html.unescape(
            strip_tags(
                re.sub(r"</?(?:p|li|h[1-6]|br)\b[^>]*>", " ", markup, flags=re.I)
            )
        ),
    ).strip()
    words = re.findall(r"\b\w+\b", text)
    resolved = seo_payload(blog)
    title = resolved["title"]
    description = resolved["description"]
    focus = (blog.seo_settings.get("focus_keyphrase") or "").strip().casefold()
    checks = []

    def check(key, label, passed, detail):
        checks.append(
            {
                "key": key,
                "label": label,
                "passed": passed,
                "status": "not_applicable"
                if passed is None
                else "pass"
                if passed
                else "needs_attention",
                "detail": detail,
            }
        )

    check(
        "title",
        "SEO title length",
        20 <= len(title) <= 70,
        f"{len(title)} characters. App guidance: 20–70 for a concise search title; this is not a Google limit. Set a separate SEO title without changing the article heading.",
    )
    check(
        "description",
        "Description length",
        70 <= len(description) <= 160,
        f"{len(description)} characters. App guidance: 70–160; write a useful summary, not a ranking promise.",
    )
    minimum = 600 if blog.is_cornerstone else 300
    check(
        "length",
        "Content coverage",
        len(words) >= minimum,
        f"{len(words)} words. This app uses {minimum} as a long-form review prompt; word count alone does not prove quality.",
    )
    check(
        "focus_title",
        "Focus phrase in SEO title",
        bool(focus and focus in title.casefold()),
        "Choose a relevant focus phrase and use it naturally in the SEO title."
        if not focus or focus not in title.casefold()
        else "The selected focus phrase appears in the SEO title.",
    )
    check(
        "focus_body",
        "Focus phrase in article",
        focus in text.casefold() if focus else None,
        "Select a focus phrase first."
        if not focus
        else "Check that the selected phrase accurately describes the article and appears naturally in its text.",
    )
    if blog.cover_image:
        check(
            "cover_alt",
            "Cover image alternative",
            bool(blog.cover_image_alt.strip() or blog.cover_image_decorative),
            "Describe the actual image, or explicitly mark a purely decorative image. Do not invent alt text from a topic alone.",
        )
    else:
        check(
            "cover_alt",
            "Cover image alternative",
            None,
            "No cover image attached. This check applies only when a cover image is present.",
        )
    images = [section for section in sections if section.image_path]
    check(
        "section_alt",
        "Section image alternatives",
        all(section.image_alt.strip() or section.image_decorative for section in images)
        if images
        else None,
        "Describe each actual section image or mark it decorative."
        if images
        else "No section images attached.",
    )
    check(
        "headings",
        "Paragraphs and headings",
        bool(re.search(r"<h[2-4](?:\s|>)", markup, re.I)),
        "Use descriptive subheadings to organize a longer article for readers.",
    )
    sentences = max(1, len(re.findall(r"[.!?]+(?:\s|$)", text)))
    average = len(words) / sentences
    report = {
        "seo_score": round(
            sum(c["passed"] is True for c in checks)
            / max(1, sum(c["passed"] is not None for c in checks))
            * 100
        ),
        "readability_score": max(0, min(100, round(120 - average * 3))),
        "word_count": len(words),
        "checks": checks,
        "rules_version": SEO_RULES_VERSION,
        "context_hash": seo_context_hash(blog.project),
        "score_note": "Local editorial checks only; not a Google ranking score or factual-quality guarantee.",
    }
    return SeoAnalysis.objects.create(
        blog=blog, input_hash=seo_input_hash(blog), report=report
    )


class LinksParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.url = None
        self.anchor = ""

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self.url = dict(attrs).get("href")
            self.anchor = ""

    def handle_data(self, data):
        if self.url:
            self.anchor += data

    def handle_endtag(self, tag):
        if tag == "a" and self.url:
            self.links.append((self.url, self.anchor))
            self.url = None


def rebuild_links(project):
    rows = []
    targets = {article_url(b): b.pk for b in project.blogs.live() if article_url(b)}
    for blog in project.blogs.prefetch_related("sections").all()[:1000]:
        parser = LinksParser()
        parser.feed(
            blog.content + " ".join(s.text_content for s in blog.sections.all())
        )
        for url, anchor in parser.links[:100]:
            url = urljoin(article_url(blog), url)
            try:
                url = public_url(url)
            except (ValidationError, ValueError):
                continue
            rows.append(
                Link(
                    project=project,
                    blog=blog,
                    url=url,
                    anchor=anchor[:500],
                    target_blog_id=targets.get(url),
                )
            )
    with transaction.atomic():
        Link.objects.filter(project=project).delete()
        Link.objects.bulk_create(rows)


def rooted_path(value):
    from urllib.parse import unquote

    if (
        not value.startswith("/")
        or value.startswith("//")
        or len(value) > 1000
        or re.search(r"%(?:2f|5c)", value, re.I)
        or any(c in unquote(value) for c in ["\\", "?", "#", "%"])
        or re.search(r"[\x00-\x20]", unquote(value))
        or any(s in [".", ".."] for s in unquote(value).split("/"))
    ):
        raise ValidationError(
            "Use a rooted path without query strings or dot segments."
        )
    return value


def validate_redirects(rows):
    graph = {r["source_path"]: r["target_path"] for r in rows}
    for source in graph:
        seen = set()
        current = source
        for _ in range(11):
            if current in seen:
                raise ValidationError("Redirect cycle detected.")
            seen.add(current)
            if current not in graph:
                break
            current = graph[current]
        else:
            raise ValidationError("Redirect chain is too long.")


def project_context_hash(project):
    project.refresh_from_db()
    return digest(
        [
            project.seo_settings,
            str(project.seo_verified_at),
            list(project.blogs.order_by("id").values("id", "revision", "updated_at")),
        ]
    )


META_FIELDS = [
    "title",
    "description",
    "focus_keyphrase",
    "robots_index",
    "robots_follow",
]


def preview_csv(project, user, kind, upload, reason):
    if upload.size > 131072:
        raise ValidationError("CSV must be under 128 KB.")
    reader = csv.DictReader(io.StringIO(upload.read().decode("utf-8-sig")))
    expected = (
        ["blog_id"] + META_FIELDS
        if kind == "metadata"
        else ["source_path", "target_path", "status_code"]
    )
    if reader.fieldnames != expected:
        raise ValidationError("Use the exact CSV export header.")
    rows = list(reader)
    if not 1 <= len(rows) <= 100:
        raise ValidationError("Use 1–100 rows.")
    changes = []
    if kind == "metadata":
        if len({row["blog_id"] for row in rows}) != len(rows):
            raise ValidationError("Each post ID must be distinct.")
        for row in rows:
            blog = project.blogs.get(pk=int(row["blog_id"]))
            after = {}
            for key in META_FIELDS:
                value = row[key]
                value = (
                    value[1:]
                    if value.startswith("'")
                    and len(value) > 1
                    and value[1] in "=+-@\t\r"
                    else value
                )
                if len(value) > 500:
                    raise ValidationError(
                        "Metadata fields must be under 500 characters."
                    )
                if key == "canonical_url" and value:
                    public_url(value)
                if key == "robots_index" and value not in [
                    "",
                    "inherit",
                    "index",
                    "noindex",
                ]:
                    raise ValidationError("Invalid indexing setting.")
                if key == "robots_follow" and value not in [
                    "",
                    "inherit",
                    "follow",
                    "nofollow",
                ]:
                    raise ValidationError("Invalid follow setting.")
                after[key] = value
            changes.append(
                {
                    "id": blog.pk,
                    "before": blog.seo_settings,
                    "after": {**blog.seo_settings, **after},
                }
            )
    else:
        combined = {
            r.source_path: {"source_path": r.source_path, "target_path": r.target_path}
            for r in RedirectRule.objects.filter(project=project)
        }
        for row in rows:
            src = rooted_path(row["source_path"])
            target = rooted_path(row["target_path"])
            code = int(row["status_code"])
            if code not in [301, 302, 307, 308]:
                raise ValidationError("Invalid redirect status.")
            old = RedirectRule.objects.filter(project=project, source_path=src).first()
            changes.append(
                {
                    "source": src,
                    "before": {
                        "target_path": old.target_path,
                        "status_code": old.status_code,
                    }
                    if old
                    else None,
                    "after": {"target_path": target, "status_code": code},
                }
            )
            combined[src] = {"source_path": src, "target_path": target}
        validate_redirects(list(combined.values()))
    return ChangeBatch.objects.create(
        project=project,
        user=user,
        kind=kind,
        reason=reason,
        changes=changes,
        context_hash=project_context_hash(project),
    )


def commit_batch(batch, undo=False):
    with transaction.atomic():
        batch = ChangeBatch.objects.select_for_update().get(pk=batch.pk)
        if not undo and (
            batch.expires_at < timezone.now()
            or (
                batch.context_hash
                and batch.context_hash != project_context_hash(batch.project)
            )
        ):
            raise ValidationError(
                "Preview expired or publication changed. Create a fresh preview."
            )
        if batch.state != ("applied" if undo else "preview"):
            raise ValidationError("Batch has already changed.")
        for change in batch.changes:
            before, after = (
                (change["after"], change["before"])
                if undo
                else (change["before"], change["after"])
            )
            if batch.kind == "metadata":
                blog = Blog.objects.select_for_update().get(
                    pk=change["id"], project=batch.project
                )
                if blog.seo_settings != before:
                    raise ValidationError(
                        "Metadata changed; guarded apply/undo stopped."
                    )
                blog.seo_settings = after
                blog.revision += 1
                blog.save()
            else:
                old = RedirectRule.objects.filter(
                    project=batch.project, source_path=change["source"]
                ).first()
                current = (
                    {"target_path": old.target_path, "status_code": old.status_code}
                    if old
                    else None
                )
                if current != before:
                    raise ValidationError(
                        "Redirect changed; guarded apply/undo stopped."
                    )
                if after is None:
                    if old:
                        old.delete()
                else:
                    RedirectRule.objects.update_or_create(
                        project=batch.project,
                        source_path=change["source"],
                        defaults=after,
                    )
        if batch.kind == "redirects":
            validate_redirects(
                list(
                    RedirectRule.objects.filter(project=batch.project).values(
                        "source_path", "target_path"
                    )
                )
            )
        if batch.kind == "metadata":
            for change in batch.changes:
                analyze(Blog.objects.get(pk=change["id"], project=batch.project))
        batch.state = "reverted" if undo else "applied"
        batch.save()
