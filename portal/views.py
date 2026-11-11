import csv
import functools
import html
import io
import json
import secrets
import uuid
from datetime import timedelta
from urllib.parse import urlencode, urlsplit
import requests
from django.conf import settings
from django.contrib import messages
from django.contrib.auth import login as auth_login, logout as auth_logout
from django.contrib.auth.decorators import login_required
from django.core.exceptions import ValidationError, PermissionDenied
from django.core.paginator import Paginator
from django.db import transaction
from django.db.models import Count, F, Q
from django.http import HttpResponse, JsonResponse, Http404
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST, require_http_methods
from . import ai
from .forms import *
from .models import *
from .services import *
from .tasks import google_request


def page(view):
    @login_required
    @functools.wraps(view)
    def wrapped(request, *args, **kwargs):
        try:
            return view(request, *args, **kwargs)
        except requests.RequestException:
            return render(
                request,
                "portal/error.html",
                {
                    "error": "The external service is unavailable. Your saved content is unchanged; try again later."
                },
                status=503,
            )
        except (ValidationError, ValueError, KeyError, json.JSONDecodeError) as exc:
            text = (
                " ".join(exc.messages)
                if isinstance(exc, ValidationError)
                else "Invalid input. Check the fields and try again."
            )
            return render(request, "portal/error.html", {"error": text}, status=400)

    return wrapped


@page
def admin_docs(request):
    return render(request, "portal/admin_docs.html")


def home(request):
    if request.user.is_authenticated and request.path == "/":
        return redirect("superadmin" if request.user.is_superuser else "projects")
    return render(request, "portal/landing.html")


def sign_in(request):
    if request.user.is_authenticated:
        return redirect("superadmin" if request.user.is_superuser else "projects")
    if request.method == "POST":
        try:
            rate_limit(request, "login", 5, 60)
        except ValidationError:
            return HttpResponse("Too many attempts. Try again later.", status=429)
    form = LoginForm(request, data=request.POST or None)
    if request.method == "POST" and form.is_valid():
        auth_login(request, form.get_user())
        request.session.set_expiry(1209600 if request.POST.get("remember") else 0)
        return redirect("superadmin" if request.user.is_superuser else "projects")
    google_configured = bool(settings.GOOGLE_CLIENT_ID and settings.GOOGLE_CLIENT_SECRET)
    return render(
        request,
        "portal/auth.html",
        {
            "form": form,
            "title": "Welcome back.",
            "mode": "login",
            "google_configured": google_configured,
        },
    )


def google_auth_start(request):
    if request.user.is_authenticated:
        return redirect("projects")
    if not (settings.GOOGLE_CLIENT_ID and settings.GOOGLE_CLIENT_SECRET):
        raise ValidationError("Google Login is not configured yet.")
    rate_limit(request, "google-login", 10, 60)
    redirect_uri = getattr(settings, "GOOGLE_AUTH_REDIRECT_URI", "") or request.build_absolute_uri(reverse("google_auth_callback"))
    state = secrets.token_urlsafe(24)
    verifier = secrets.token_urlsafe(32)
    challenge = (
        base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest())
        .decode()
        .rstrip("=")
    )
    request.session["google_login_oauth"] = {
        "state": state,
        "verifier": verifier,
        "time": timezone.now().timestamp(),
        "redirect_uri": redirect_uri,
    }
    params = {
        "client_id": settings.GOOGLE_CLIENT_ID,
        "redirect_uri": redirect_uri,
        "response_type": "code",
        "scope": "openid email profile",
        "state": state,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
        "access_type": "online",
        "prompt": "select_account",
    }
    return redirect("https://accounts.google.com/o/oauth2/v2/auth?" + urlencode(params))


def google_auth_callback(request):
    flow = request.session.pop("google_login_oauth", None)
    state = request.GET.get("state", "")
    code = request.GET.get("code", "")
    if (
        not flow
        or not secrets.compare_digest(str(flow["state"]), state)
        or timezone.now().timestamp() - flow.get("time", 0) > 600
        or not code
    ):
        raise ValidationError("Google login session expired or state did not match. Please try again.")

    token_resp = requests.post(
        "https://oauth2.googleapis.com/token",
        data={
            "client_id": settings.GOOGLE_CLIENT_ID,
            "client_secret": settings.GOOGLE_CLIENT_SECRET,
            "redirect_uri": flow["redirect_uri"],
            "grant_type": "authorization_code",
            "code": code,
            "code_verifier": flow["verifier"],
        },
        timeout=15,
    )
    if not token_resp.ok:
        raise ValidationError("Could not exchange authentication token with Google.")
    token_data = token_resp.json()
    access_token = token_data.get("access_token")
    if not access_token:
        raise ValidationError("Google authentication failed to return an access token.")

    userinfo_resp = requests.get(
        "https://www.googleapis.com/oauth2/v3/userinfo",
        headers={"Authorization": f"Bearer {access_token}"},
        timeout=15,
    )
    if not userinfo_resp.ok:
        raise ValidationError("Failed to fetch user information from Google.")
    profile = userinfo_resp.json()

    email = (profile.get("email") or "").strip().lower()
    email_verified = profile.get("email_verified", False)
    if not email or not email_verified:
        raise ValidationError("Your Google account must have a verified email address to log in.")

    user = User.objects.filter(email__iexact=email).first()
    if not user:
        given_name = (profile.get("given_name") or "").strip()
        family_name = (profile.get("family_name") or "").strip()
        full_name = (profile.get("name") or "").strip()
        base_username = email.split("@")[0][:30] or "user"
        username = base_username
        suffix = 1
        while User.objects.filter(username__iexact=username).exists():
            username = f"{base_username}_{suffix}"
            suffix += 1

        with transaction.atomic():
            user = User.objects.create(
                username=username,
                email=email,
                first_name=given_name or full_name,
                last_name=family_name,
            )
            user.set_unusable_password()
            user.save()
            ws_name = f"{given_name or user.username}'s Workspace"
            ws = Workspace.objects.create(
                owner=user,
                name=ws_name,
                slug=uuid.uuid4().hex,
            )
            Membership.objects.create(workspace=ws, user=user, role="owner")
            user.current_workspace = ws
            user.save()
    elif not user.is_active:
        raise ValidationError("This account is inactive.")

    auth_login(request, user)
    return redirect("superadmin" if user.is_superuser else "projects")


def register(request):
    if request.user.is_authenticated:
        return redirect("projects")
    if request.method == "POST":
        try:
            rate_limit(request, "register", 3, 60)
        except ValidationError:
            return HttpResponse("Too many attempts.", status=429)
    form = RegisterForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            user = form.save()
            ws = Workspace.objects.create(
                owner=user,
                name=form.cleaned_data["company_name"]
                or f"{user.first_name}'s Workspace",
                slug=uuid.uuid4().hex,
            )
            Membership.objects.create(workspace=ws, user=user, role="owner")
            user.current_workspace = ws
            user.save()
        auth_login(request, user)
        return redirect("projects")
    return render(
        request,
        "portal/auth.html",
        {"form": form, "title": "Your next chapter starts here.", "mode": "register"},
    )


@require_POST
@login_required
def sign_out(request):
    auth_logout(request)
    return redirect("login")


@page
@require_POST
def workspace_action(request, pk=None):
    if request.user.is_superuser:
        return redirect("superadmin")
    if pk:
        ws = get_object_or_404(Workspace, pk=pk, memberships__user=request.user)
    else:
        name = request.POST.get("name", "").strip()
        if not name or len(name) > 255:
            raise ValidationError("Enter a workspace name under 255 characters.")
        with transaction.atomic():
            ws = Workspace.objects.create(
                owner=request.user, name=name, slug=uuid.uuid4().hex
            )
            Membership.objects.create(workspace=ws, user=request.user, role="owner")
    request.user.current_workspace = ws
    request.user.save(update_fields=["current_workspace"])
    return redirect("projects")


@page
@require_http_methods(["GET", "POST"])
def projects(request):
    if request.user.is_superuser:
        return redirect("superadmin")
    ws = current_workspace(request.user)
    can_create = Membership.objects.filter(
        workspace=ws, user=request.user, role__in=["owner", "admin", "editor"]
    ).exists()
    form = ProjectForm(request.POST or None)
    if request.method == "POST":
        if not can_create:
            raise PermissionDenied
        if form.is_valid():
            with transaction.atomic():
                p = form.save(commit=False)
                p.user = request.user
                p.workspace = ws
                p.save()
                for name in ["General", "News", "Tutorials"]:
                    Category.objects.create(project=p, name=name, slug=name.lower())
            messages.success(request, "Project created.")
            return redirect("projects")
    query = (
        Project.objects.filter(
            Q(workspace=ws) | Q(workspace__isnull=True, user=request.user)
        )
        .annotate(blogs_count=Count("blogs"))
        .order_by("-id")
    )
    blogs = Blog.objects.filter(project__in=query)
    listing = Paginator(query, 12).get_page(request.GET.get("page"))
    for p in listing:
        p.manage = can_access(request.user, p, "manage")
        p.has_profile = AiProfile.objects.filter(project=p).exists()
        version = ai.approved(p)
        profile = AiProfile.objects.filter(project=p).first()
        p.profile_state = (
            "Not configured"
            if not profile
            else "Ready for AI"
            if version and version.revision == profile.revision
            else "Needs review"
        )
    return render(
        request,
        "portal/projects.html",
        {
            "projects": listing,
            "form": form,
            "workspace": ws,
            "can_create": can_create,
            "stats": {
                "Projects": query.count(),
                "Total stories": blogs.count(),
                "Published": blogs.live().count(),
                "Drafts": blogs.filter(status="draft").count(),
            },
        },
    )


@page
@require_POST
def project_delete(request, pk):
    project = project_for(request, pk, "manage")
    if request.POST.get("project_name") != project.name:
        raise ValidationError("Type the exact project name to confirm deletion.")
    project.delete()
    messages.success(request, "Project and its stories deleted.")
    return redirect("projects")


@page
def project_detail(request, pk, integration=False):
    project = project_for(request, pk)
    return render(
        request,
        "portal/project.html",
        {
            "project": project,
            "integration": integration,
            "has_ai_details": project.ai_versions.exists(),
            "movable_projects": Project.objects.filter(
                Q(workspace__memberships__user=request.user)
                | Q(workspace__isnull=True, user=request.user)
            )
            .exclude(pk=pk)
            .distinct()
            .prefetch_related("categories"),
            "blogs": Paginator(
                project.blogs.select_related("category", "user").order_by("-id"), 25
            ).get_page(request.GET.get("page")),
        },
    )


@page
@require_POST
def category_action(request, pk, category_id=None):
    project = project_for(request, pk, "update")
    if category_id:
        get_object_or_404(Category, pk=category_id, project=project).delete()
    else:
        name = request.POST.get("name", "").strip()
        if not name or len(name) > 255:
            raise ValidationError("Enter a category name.")
        Category.objects.get_or_create(
            project=project,
            slug=slugify(name) or uuid.uuid4().hex,
            defaults={"name": name},
        )
    return redirect("project", pk=pk)


@page
@require_http_methods(["GET", "POST"])
def edit_blog(request, pk=None, blog_id=None):
    blog = get_object_or_404(Blog, pk=blog_id) if blog_id else None
    project = project_for(request, blog.project_id if blog else pk, "update")
    blog = blog or Blog(project=project, user=request.user)
    form = BlogForm(
        request.POST or None, request.FILES or None, instance=blog, project=project
    )
    sections = SectionFormSet(
        request.POST or None, request.FILES or None, instance=blog, prefix="sections"
    )
    status = 200
    if request.method == "POST" and form.is_valid() and sections.is_valid():
        uploaded = []
        try:
            with transaction.atomic():
                if blog.pk:
                    current = Blog.objects.select_for_update().get(pk=blog.pk)
                    if form.cleaned_data["editor_revision"] != revision(current):
                        form.add_error(
                            None,
                            "This story changed in another tab. Your text is preserved below; review before saving.",
                        )
                        status = 409
                        raise ValidationError("conflict")
                saved = form.save(commit=False)
                saved.project = project
                saved.user_id = blog.user_id or request.user.pk
                if saved.status == "scheduled":
                    saved.status = "published"
                elif saved.status == "published" and not saved.published_at:
                    saved.published_at = timezone.now()
                saved.seo_settings = {
                    **saved.seo_settings,
                    "title": form.cleaned_data["seo_title"],
                    "description": form.cleaned_data["seo_description"],
                    "focus_keyphrase": form.cleaned_data["focus_keyphrase"],
                    "canonical_url": form.cleaned_data["canonical_url"],
                    "robots_index": form.cleaned_data["robots_index"] or "inherit",
                }
                for key in [
                    "robots_follow",
                    "schema_type",
                    "og_title",
                    "og_description",
                    "og_image_url",
                    "x_title",
                    "x_description",
                    "x_image_url",
                ]:
                    saved.seo_settings[key] = form.cleaned_data.get(key) or (
                        "inherit"
                        if key == "robots_follow"
                        else "BlogPosting"
                        if key == "schema_type"
                        else ""
                    )
                for field, source, folder in [
                    ("cover_image", "banner_image", "banners"),
                    ("author_avatar", "avatar_image", "avatars"),
                ]:
                    if form.cleaned_data.get(source):
                        value = save_image(form.cleaned_data[source], folder)
                        uploaded.append(value)
                        setattr(saved, field, value)
                saved.revision += 1
                saved.save()
                sections.instance = saved
                for section in sections.save(commit=False):
                    section_form = next(
                        (f for f in sections.forms if f.instance is section), None
                    )
                    if section_form and section_form.cleaned_data.get("image"):
                        section.image_path = save_image(
                            section_form.cleaned_data["image"], "sections"
                        )
                        uploaded.append(section.image_path)
                    section.blog = saved
                    section.save()
                for deleted in sections.deleted_objects:
                    deleted.delete()
                if request.POST.get("autosave_version"):
                    BlogAutosave.objects.filter(
                        blog=saved,
                        user=request.user,
                        version=request.POST["autosave_version"],
                    ).delete()
                analyze(saved)
            messages.success(request, "Story saved.")
            return redirect("project", pk=project.pk)
        except Exception as exc:
            for value in uploaded:
                default_storage.delete(value.removeprefix(settings.MEDIA_URL))
            if status != 409:
                raise
    if request.method == "GET" and blog.pk:
        form.initial["editor_revision"] = revision(blog)
    return render(
        request,
        "portal/editor.html",
        {"project": project, "blog": blog, "form": form, "sections": sections},
        status=status,
    )


@page
@require_POST
def blog_action(request, blog_id, action):
    blog = get_object_or_404(Blog, pk=blog_id)
    project = project_for(request, blog.project_id, "update")
    with transaction.atomic():
        blog = Blog.objects.select_for_update().get(pk=blog.pk)
        if action == "delete":
            blog.delete()
        elif action in ("toggle-status", "status"):
            new_status = request.POST.get("status")
            if new_status in ("draft", "published", "scheduled"):
                blog.status = new_status
            else:
                blog.status = "draft" if blog.status == "published" else "published"

            if blog.status == "published":
                blog.published_at = timezone.now()
            elif blog.status == "draft":
                blog.published_at = None
            elif blog.status == "scheduled":
                publish_time_str = request.POST.get("published_at", "").strip()
                if publish_time_str:
                    from django.utils import dateparse
                    parsed = dateparse.parse_datetime(publish_time_str)
                    if parsed:
                        if timezone.is_naive(parsed):
                            parsed = timezone.make_aware(parsed)
                        blog.published_at = parsed
                if not blog.published_at or blog.published_at <= timezone.now():
                    blog.published_at = timezone.now() + timedelta(days=1)

            blog.revision += 1
            blog.save()
        elif action == "move":
            target = project_for(
                request, int(request.POST["target_project_id"]), "update"
            )
            category = (
                get_object_or_404(
                    Category, project=target, pk=request.POST["category_id"]
                )
                if request.POST.get("category_id")
                else None
            )
            blog.project = target
            blog.category = category
            blog.revision += 1
            blog.save()
            BlogAutosave.objects.filter(blog=blog).delete()
        else:
            raise Http404
        if action != "delete":
            analyze(blog)
    return redirect("project", pk=project.pk)


@page
@require_http_methods(["GET", "POST", "DELETE"])
def autosave(request, blog_id):
    blog = get_object_or_404(Blog, pk=blog_id)
    project_for(request, blog.project_id, "update")
    if request.method == "GET":
        saved = BlogAutosave.objects.filter(blog=blog, user=request.user).first()
        return JsonResponse(
            {
                "snapshot": saved.snapshot if saved else {},
                "version": saved.version if saved else 0,
                "base_hash": saved.base_hash if saved else revision(blog),
            }
        )
    data = json.loads(request.body or b"{}")
    with transaction.atomic():
        current = Blog.objects.select_for_update().get(pk=blog.pk)
        saved, _ = BlogAutosave.objects.get_or_create(
            blog=blog, user=request.user, defaults={"base_hash": revision(current)}
        )
        if int(data.get("version", -1)) != saved.version or data.get(
            "base_hash"
        ) != revision(current):
            return JsonResponse(
                {
                    "error": "Source or autosave changed. Restore or copy your work before reloading."
                },
                status=409,
            )
        snapshot = (
            {}
            if request.method == "DELETE" or data.get("discard")
            else data.get("snapshot", {})
        )
        if not isinstance(snapshot, dict) or len(json.dumps(snapshot)) > 300000:
            raise ValidationError("Autosave payload too large.")
        allowed = [
            "title",
            "slug",
            "excerpt",
            "status",
            "published_at",
            "category",
            "seo_title",
            "seo_description",
            "focus_keyphrase",
            "canonical_url",
            "robots_index",
            "author_name",
            "author_title",
            "image_credits",
            "cover_image_alt",
            "cover_image_caption",
            "sections",
        ]
        snapshot = {k: v for k, v in snapshot.items() if k in allowed}
        if "sections" in snapshot:
            if (
                not isinstance(snapshot["sections"], list)
                or len(snapshot["sections"]) > 100
            ):
                raise ValidationError("Invalid draft sections.")
            snapshot["sections"] = [
                {
                    "text_content": clean_html(str(row.get("text_content", ""))),
                    "image_alt": str(row.get("image_alt", ""))[:500],
                    "image_caption": str(row.get("image_caption", ""))[:2000],
                }
                for row in snapshot["sections"]
                if isinstance(row, dict)
            ]
        saved.snapshot = snapshot
        saved.base_hash = revision(current)
        saved.version += 1
        saved.save()
    return JsonResponse({"version": saved.version, "base_hash": saved.base_hash})


@page
@require_http_methods(["GET", "POST"])
def profile(request, pk):
    project = project_for(request, pk)
    obj = AiProfile.objects.filter(project=project).first()
    form = ProfileForm(
        request.POST or None,
        initial={
            **(obj.data if obj else {"name": project.name}),
            "revision": obj.revision if obj else 0,
        },
    )
    if request.method == "POST":
        authorize(request.user, project, "manage")
        with transaction.atomic():
            Project.objects.select_for_update().get(pk=pk)
            current = AiProfile.objects.filter(project=project).first()
            if form.is_valid():
                if form.cleaned_data["revision"] != (
                    current.revision if current else 0
                ):
                    return HttpResponse(
                        "Profile changed in another tab. Reload before saving.",
                        status=409,
                    )
                values = {
                    k: v for k, v in form.cleaned_data.items() if k in ai.PROFILE_FIELDS
                }
                new_revision = (current.revision if current else 0) + 1
                ai_profile, _ = AiProfile.objects.update_or_create(
                    project=project,
                    defaults={
                        "data": values,
                        "revision": new_revision,
                    },
                )
                if all(values.get(k) for k in ["name", "summary", "audience"]):
                    AiProfileVersion.objects.create(
                        project=project,
                        revision=new_revision,
                        data=values,
                        approved_by=request.user,
                    )
                    messages.success(
                        request,
                        "Project details saved and activated for AI writing.",
                    )
                else:
                    messages.success(
                        request,
                        "Project details saved.",
                    )
                return redirect("profile", pk=pk)
    config, _ = AiSettings.objects.get_or_create(scope=ai.scope(project))
    used = (
        AiUsage.objects.filter(
            scope=ai.scope(project), month=timezone.now().strftime("%Y-%m")
        )
        .values_list("requests", flat=True)
        .first()
        or 0
    )
    limit = config.monthly_limit or 20
    usage_percent = min(100, int((used / limit) * 100)) if limit > 0 else 0

    return render(
        request,
        "portal/profile.html",
        {
            "project": project,
            "form": form,
            "profile": obj,
            "approved": ai.approved(project),
            "ai_config": config,
            "ready": ai.ready(),
            "requests_used": used,
            "usage_percent": usage_percent,
        },
    )


@page
@require_POST
def profile_action(request, pk, action):
    project = project_for(request, pk, "manage")
    if action == "approve":
        with transaction.atomic():
            obj = get_object_or_404(
                AiProfile.objects.select_for_update(), project=project
            )
            if int(request.POST["revision"]) != obj.revision:
                return HttpResponse("Profile changed. Reload and review.", status=409)
            if not request.POST.get("confirm") or not all(
                obj.data.get(k) for k in ["name", "summary", "audience"]
            ):
                raise ValidationError(
                    "Review complete business details and confirm approval."
                )
            AiProfileVersion.objects.get_or_create(
                project=project,
                revision=obj.revision,
                defaults={"data": obj.data, "approved_by": request.user},
            )
    elif action == "settings":
        defaults = {"enabled": request.POST.get("enabled") == "1"}
        if "monthly_limit" in request.POST and request.user.is_superuser:
            limit = int(request.POST.get("monthly_limit", 20))
            if not 1 <= limit <= settings.AI_MONTHLY_REQUEST_LIMIT:
                raise ValidationError("Invalid allowance.")
            defaults["monthly_limit"] = limit
        AiSettings.objects.update_or_create(
            scope=ai.scope(project),
            defaults=defaults,
        )
    else:
        raise Http404
    return redirect("profile", pk=pk)


@page
@require_http_methods(["GET", "POST"])
def studio(request, pk):
    project = project_for(request, pk)
    blog = (
        get_object_or_404(
            Blog,
            project=project,
            pk=request.GET.get("blog") or request.POST.get("blog_id"),
        )
        if request.GET.get("blog") or request.POST.get("blog_id")
        else None
    )
    platforms = [
        ("linkedin", "LinkedIn"),
        ("reddit", "Reddit"),
        ("facebook", "Facebook"),
        ("instagram", "Instagram"),
    ]
    selected_platform = (
        (
            request.POST.get("platform")
            if request.method == "POST"
            else request.GET.get("platform", "")
        )
        if blog
        else ""
    )
    if selected_platform not in dict(platforms):
        selected_platform = ""
    form = AiRequestForm(
        request.POST or None,
        initial={
            "request_key": uuid.uuid4(),
            "action": request.GET.get("action")
            if blog and request.GET.get("action") in ["metadata", "titles", "rewrite"]
            else "social"
            if blog
            else "outline",
            "platform": selected_platform,
            "brief": f"Create a {dict(platforms)[selected_platform]} version of this article, using its key points and the approved brand facts."
            if selected_platform
            else "",
            "public_url": article_url(blog)
            if blog and Blog.objects.live().filter(pk=blog.pk).exists()
            else "",
        },
    )
    if request.method == "POST":
        authorize(request.user, project, "update")
        rate_limit(request, "ai", 10)
        if form.is_valid():
            data = form.cleaned_data.copy()
            action = data.pop("action")
            key = data.pop("request_key")
            data.pop("consent")
            platform = data.pop("platform")
            if action == "analyze":
                authorize(request.user, project, "manage")
                urls = [
                    public_url(u.strip())
                    for u in data.pop("urls").splitlines()
                    if u.strip()
                ]
                if len(urls) > 3 or len({origin(u) for u in urls}) > 1:
                    raise ValidationError("Select up to three pages from one website.")
                text = data.pop("source_text")
                data["sources"] = [{"id": "manual-1", "text": text}] if text else []
                data["urls"] = urls
                obj = AiProfile.objects.filter(project=project).first()
                data["profile_revision"] = obj.revision if obj else 0
                if not urls and not text:
                    raise ValidationError("Provide pages or pasted source text.")
            elif action == "outline" and not data["brief"]:
                raise ValidationError("Enter a topic or brief.")
            elif action in ["social", "metadata", "titles"] and not blog:
                raise ValidationError("Select a saved story first.")
            elif action == "rewrite" and not data["selected_text"]:
                raise ValidationError("Enter the text to rewrite.")
            if action == "social":
                url = data.get("public_url")
                data["public_url"] = "[BLOG_URL]"
                if url:
                    if not Blog.objects.live().filter(pk=blog.pk).exists():
                        raise ValidationError("Use a link placeholder until published.")
                    url = public_url(url)
                    if urlsplit(url).query or re.search(
                        r"/(admin|preview)(/|$)", urlsplit(url).path
                    ):
                        raise ValidationError(
                            "Use a clean public article URL, not a private link."
                        )
                    data["public_url"] = url
            run = ai.enqueue(project, request.user, action, data, key, blog, platform)
            return redirect("ai_run", pk=pk, run_id=run.pk)
    runs = AiRun.objects.filter(project=project).order_by("-id")
    if blog:
        runs = runs.filter(blog=blog)
    if selected_platform:
        runs = runs.filter(action="social", platform=selected_platform)
    return render(
        request,
        "portal/studio.html",
        {
            "project": project,
            "blog": blog,
            "form": form,
            "runs": Paginator(runs, 12).get_page(request.GET.get("page")),
            "social_platforms": platforms,
            "selected_platform": selected_platform,
            "selected_platform_label": dict(platforms).get(selected_platform, ""),
            "approved": ai.approved(project),
            "ready": ai.ready(),
        },
    )


@page
@require_http_methods(["GET", "POST"])
def social_version(request, pk, blog_id, platform):
    project = project_for(request, pk)
    blog = get_object_or_404(Blog, project=project, pk=blog_id)
    labels = {
        "linkedin": "LinkedIn",
        "reddit": "Reddit",
        "facebook": "Facebook",
        "instagram": "Instagram",
    }
    if platform not in labels:
        raise Http404
    with transaction.atomic():
        # Serialize first-time clicks so two tabs do not purchase the same adaptation twice.
        Project.objects.select_for_update().get(pk=project.pk)
        versions = AiRun.objects.filter(
            project=project, blog=blog, action="social", platform=platform
        ).order_by("-id")
        saved = versions.filter(state="complete").first() or versions.first()
        replace = request.method == "POST" and bool(request.POST.get("replace_run_id"))
        if saved and not replace:
            return redirect("ai_run", pk=pk, run_id=saved.pk)
        if request.method == "POST":
            authorize(request.user, project, "update")
            if replace and (
                not saved
                or str(saved.pk) != request.POST["replace_run_id"]
                or request.POST.get("confirm") != "1"
            ):
                raise ValidationError(
                    "Review the latest saved version before requesting a replacement."
                )
            rate_limit(request, "ai", 10)
            source_url = (
                article_url(blog)
                if Blog.objects.live().filter(pk=blog.pk).exists()
                else ""
            )
            data = {
                "brief": f"Adapt the saved article below into a {labels[platform]} post. Preserve its message and supported facts. This is a social adaptation of the existing blog, not a new blog or outline.",
                "preferences": "Use the approved project voice and audience. Avoid inventing facts or repeating a full-length blog article.",
                "community_rules": "",
                "public_url": source_url or "[BLOG_URL]",
            }
            run = ai.enqueue(
                project,
                request.user,
                "social",
                data,
                request.POST.get("request_key", ""),
                blog,
                platform,
            )
            return redirect("ai_run", pk=pk, run_id=run.pk)
    return render(
        request,
        "portal/social_version.html",
        {
            "project": project,
            "blog": blog,
            "platform": platform,
            "platform_label": labels[platform],
            "request_key": uuid.uuid4(),
        },
    )


@page
@require_http_methods(["GET", "POST"])
def ai_run(request, pk, run_id, action=None):
    project = project_for(request, pk)
    run = get_object_or_404(AiRun, pk=run_id, project=project)
    if action == "status":
        return JsonResponse({"state": run.state})
    if action == "export":
        if run.state != "complete":
            raise ValidationError("No completed result.")
        text = (
            f"{run.action} {run.platform}\n"
            + ("STALE: source changed.\n" if ai.stale(run) else "")
            + "\n".join(
                f"{k}\n{strip_tags(v)}\n" for k, v in (run.edited or run.result).items()
            )
        )
        response = HttpResponse(text, content_type="text/plain")
        response["Content-Disposition"] = (
            f'attachment; filename="ai-content-{run.pk}.txt"'
        )
        return response
    if request.method == "POST":
        authorize(
            request.user, project, "manage" if run.action == "analyze" else "update"
        )
        with transaction.atomic():
            run = AiRun.objects.select_for_update().get(pk=run.pk)
            if action == "cancel":
                if run.state in ["pending", "running"]:
                    run.state = "cancelled"
                    run.detail = (
                        "Cancelled locally; provider billing may already have occurred."
                    )
                    run.save()
            elif action == "draft":
                if run.output_blog_id:
                    return redirect("edit_blog", blog_id=run.output_blog_id)
                if run.action != "draft" or not run.reviewed_at or ai.stale(run):
                    raise ValidationError("Review a current draft first.")
                category = get_object_or_404(
                    Category, project=project, pk=request.POST["category_id"]
                )
                result = run.edited or run.result
                blog = Blog.objects.create(
                    project=project,
                    user=request.user,
                    category=category,
                    title=result["title"],
                    excerpt=result["excerpt"],
                    seo_settings={
                        "description": result["seo_description"],
                        "title": result.get("seo_title", ""),
                        "focus_keyphrase": result.get("focus_keyphrase", ""),
                    },
                    status="draft",
                )
                BlogSection.objects.create(blog=blog, text_content=result["body_html"])
                analyze(blog)
                run.output_blog = blog
                run.save()
                return redirect("edit_blog", blog_id=blog.pk)
            elif action == "apply-seo":
                if (
                    run.action != "metadata"
                    or not run.blog_id
                    or run.state != "complete"
                    or not run.reviewed_at
                ):
                    raise ValidationError("Save and review the SEO suggestions first.")
                blog = Blog.objects.select_for_update().get(
                    pk=run.blog_id, project=project
                )
                if ai.stale(run):
                    raise ValidationError(
                        "The story or profile changed. Generate current SEO suggestions first."
                    )
                result = run.edited or run.result
                if not result.get("seo_title") or not result.get("focus_keyphrase"):
                    raise ValidationError(
                        "Supply an SEO title and focus phrase before applying."
                    )
                blog.seo_settings = {
                    **blog.seo_settings,
                    "title": result["seo_title"],
                    "description": result["seo_description"],
                    "focus_keyphrase": result["focus_keyphrase"],
                }
                blog.revision += 1
                blog.save()
                analyze(blog)
                messages.success(
                    request,
                    "Reviewed SEO metadata applied. Article text and publication status were preserved.",
                )
                return redirect("report", blog_id=blog.pk)
            elif action == "generate-draft":
                if (
                    run.action != "outline"
                    or not run.reviewed_at
                    or ai.stale(run)
                    or not request.POST.get("consent")
                ):
                    raise ValidationError("Review a current outline and consent first.")
                generated = ai.enqueue(
                    project,
                    request.user,
                    "draft",
                    {"approved_outline": run.edited or run.result},
                    request.POST["request_key"],
                    run.blog,
                )
                return redirect("ai_run", pk=pk, run_id=generated.pk)
            elif action == "adopt":
                obj = AiProfile.objects.filter(project=project).first()
                if (
                    run.action != "analyze"
                    or not run.reviewed_at
                    or (obj.revision if obj else 0)
                    != run.input.get("profile_revision", 0)
                ):
                    raise ValidationError(
                        "Review analysis first; project details must be unchanged since analysis."
                    )
                data = obj.data if obj else {"name": project.name}
                result = run.edited or run.result
                fields = request.POST.getlist("fields")
                if not fields:
                    raise ValidationError("Select fields to adopt.")
                for field in fields:
                    if (
                        field
                        not in ["summary", "products", "audience", "tone", "facts"]
                        or len(result[field]) > 4000
                    ):
                        raise ValidationError("Invalid or oversized selected field.")
                    data[field] = result[field]
                AiProfile.objects.update_or_create(
                    project=project,
                    defaults={
                        "data": data,
                        "revision": (obj.revision if obj else 0) + 1,
                    },
                )
                return redirect("profile", pk=pk)
            elif action is None:
                if (
                    run.state != "complete"
                    or int(request.POST["revision"]) != run.revision
                ):
                    return HttpResponse(
                        "Result changed. Reload before saving.", status=409
                    )
                values = ai.validate_result(
                    run.action,
                    {k: request.POST.get(k, "") for k in ai.RESULT_FIELDS[run.action]},
                )
                if "body_html" in values:
                    values["body_html"] = clean_html(values["body_html"])
                if request.POST.get("reviewed") and ai.stale(run):
                    return HttpResponse(
                        "Source changed. Generate a current result.", status=409
                    )
                run.edited = values
                run.reviewed_at = (
                    timezone.now() if request.POST.get("reviewed") else None
                )
                run.revision += 1
                run.save()
            else:
                raise Http404
        return redirect("ai_run", pk=pk, run_id=run.pk)
    social_values = run.edited or run.result
    social_body = social_values.get("body", "").strip()
    social_tags = social_values.get("hashtags", "").strip()
    linkedin_text = social_body + (
        "\n\n" + social_tags if social_tags and social_tags not in social_body else ""
    )
    preview_url = ""
    if run.blog_id and project.blogs.live().filter(pk=run.blog_id).exists():
        preview_url = article_url(run.blog)
    fields = [
        {
            "name": key,
            "label": key.replace("_", " ").title(),
            "value": (run.edited or run.result).get(key, ""),
        }
        for key in ai.RESULT_FIELDS[run.action]
    ]
    return render(
        request,
        "portal/ai_run.html",
        {
            "project": project,
            "run": run,
            "fields": fields,
            "stale": ai.stale(run),
            "request_key": uuid.uuid4(),
            "source_context": json.dumps(run.input, indent=2, ensure_ascii=False),
            "linkedin_text": linkedin_text,
            "linkedin_preview_url": preview_url,
            "linkedin_preview_image": image_url(
                run.blog.cover_image, project.seo_settings
            )
            if run.blog_id and run.blog.cover_image
            else "",
        },
    )


@page
@require_http_methods(["GET", "POST"])
def seo_settings(request, pk):
    project_for(request, pk, "manage")
    return redirect("integration", pk=pk)


@page
@require_POST
def verify_domain(request, pk):
    project = project_for(request, pk, "manage")
    url = project.seo_settings.get("public_url")
    if not url:
        raise ValidationError("Save a public website URL first.")
    hostname = urlsplit(public_url(url)).hostname
    expected = "blogsaas=" + project.seo_domain_token
    try:
        records = [
            "".join(part.decode() for part in rr.strings)
            for rr in dns.resolver.resolve(
                "_blogsaas-verification." + hostname, "TXT", lifetime=5
            )
        ]
    except Exception:
        records = []
    if expected not in records:
        raise ValidationError(
            "DNS record not found. Wait for propagation and try again."
        )
    project.seo_verified_at = timezone.now()
    project.save()
    return redirect("analytics_hub", pk=pk, tab="sitemap")


@page
@require_http_methods(["GET", "POST"])
def health(request, pk, blog_id=None):
    project = project_for(request, pk, "update" if request.method == "POST" else "view")
    query = project.blogs.order_by("-id")
    if blog_id:
        query = query.filter(pk=blog_id)
    if request.method == "POST":
        if blog_id:
            blog = get_object_or_404(query)
            if request.POST.get("operation") == "review":
                SeoReview.objects.create(
                    blog=blog,
                    user=request.user,
                    input_hash=seo_input_hash(blog),
                    note=request.POST.get("note", "")[:4000],
                )
                blog.content_reviewed_at = timezone.now()
                blog.save()
            analyze(blog)
            return redirect("report", blog_id=blog_id)
        with transaction.atomic():
            Project.objects.select_for_update().get(pk=pk)
            active = WorkItem.objects.filter(
                project=project, kind="seo_analyze", state__in=["pending", "running"]
            ).first()
            if not active and query.exists():
                WorkItem.objects.create(
                    project=project,
                    user=request.user,
                    kind="seo_analyze",
                    payload={
                        "cursor": 0,
                        "max_id": query.first().pk,
                        "processed": 0,
                        "total": query.count(),
                    },
                )
        messages.success(
            request,
            "Whole-project analysis queued. This uses local checks, not a paid AI request.",
        )
        return redirect("health", pk=pk)
    listing = Paginator(query, 25).get_page(request.GET.get("page"))
    rows = []
    for blog in listing:
        analysis = blog.analyses.order_by("-id").first()
        rows.append(
            {
                "blog": blog,
                "analysis": analysis,
                "stale": analysis_is_stale(analysis, blog) if analysis else False,
            }
        )
    job = (
        WorkItem.objects.filter(project=project, kind="seo_analyze")
        .order_by("-id")
        .first()
    )
    return render(
        request,
        "portal/health.html",
        {
            "project": project,
            "rows": rows,
            "single": bool(blog_id),
            "page": listing,
            "total_stories": query.count(),
            "analysis_job": job,
        },
    )


@page
def health_status(request, pk, job_id):
    project = project_for(request, pk)
    job = get_object_or_404(WorkItem, pk=job_id, project=project, kind="seo_analyze")
    return JsonResponse(
        {
            "state": job.state,
            "processed": job.payload.get("processed", 0),
            "total": job.payload.get("total", 0),
        }
    )


@page
def report(request, blog_id):
    blog = get_object_or_404(Blog, pk=blog_id)
    return health.__wrapped__(request, blog.project_id, blog_id)


@page
@require_http_methods(["GET", "POST"])
def links(request, pk, blog_id=None):
    project = project_for(request, pk, "update" if request.method == "POST" else "view")
    blog = get_object_or_404(Blog, pk=blog_id, project=project) if blog_id else None
    if request.method == "POST":
        operation = request.POST.get("operation")
        if operation in ["rebuild", "check"]:
            WorkItem.objects.create(
                user=request.user,
                project=project,
                kind="links" if operation == "rebuild" else "check_links",
            )
            messages.success(request, "Link task queued.")
        elif operation == "insert" and blog:
            target = get_object_or_404(
                Blog.objects.live(), project=project, pk=request.POST["target_blog_id"]
            )
            url = article_url(target)
            if not url:
                raise ValidationError(
                    "Verify the project public domain before inserting public links."
                )
            with transaction.atomic():
                locked = Blog.objects.select_for_update().get(pk=blog.pk)
                section = locked.sections.first()
                if not section:
                    section = BlogSection.objects.create(blog=locked)
                before = section.text_content
                section.text_content = clean_html(
                    before
                    + f'<p>Related reading: <a href="{html.escape(url, quote=True)}">{html.escape(target.title)}</a></p>'
                )
                section.save()
                LinkEdit.objects.create(
                    project=project,
                    blog=locked,
                    section=section,
                    before=before,
                    after_hash=digest(section.text_content),
                )
                locked.revision += 1
                locked.save()
        elif operation == "undo":
            with transaction.atomic():
                edit = get_object_or_404(
                    LinkEdit.objects.select_for_update(),
                    pk=request.POST["edit_id"],
                    project=project,
                    reverted_at__isnull=True,
                )
                section = BlogSection.objects.select_for_update().get(
                    pk=edit.section_id
                )
                if digest(section.text_content) != edit.after_hash:
                    raise ValidationError("Story changed. Guarded undo stopped.")
                section.text_content = edit.before
                section.save()
                edit.reverted_at = timezone.now()
                edit.save()
                Blog.objects.filter(pk=edit.blog_id).update(
                    revision=F("revision") + 1, updated_at=timezone.now()
                )
        else:
            raise ValidationError("Choose a supported link action.")
        return (
            redirect("blog_links", blog_id=blog.pk)
            if blog
            else redirect("links", pk=pk)
        )
    rows = Link.objects.filter(project=project).select_related("blog", "target_blog")[
        :200
    ]
    linked = Link.objects.filter(
        project=project, target_blog__isnull=False
    ).values_list("target_blog_id", flat=True)
    return render(
        request,
        "portal/links.html",
        {
            "project": project,
            "blog": blog,
            "rows": rows,
            "suggestions": project.blogs.live().exclude(pk=blog.pk if blog else None)[
                :20
            ],
            "orphans": project.blogs.live().exclude(pk__in=linked)[:50],
            "edits": LinkEdit.objects.filter(project=project).order_by("-id")[:20],
            "jobs": WorkItem.objects.filter(
                project=project, kind__in=["links", "check_links"]
            ).order_by("-id")[:10],
        },
    )


@page
def blog_links(request, blog_id):
    blog = get_object_or_404(Blog, pk=blog_id)
    return links.__wrapped__(request, blog.project_id, blog_id)


@page
@require_http_methods(["GET", "POST"])
def batches(request, pk, kind, batch_id=None, operation=None):
    project = project_for(request, pk, "manage")
    if kind not in ["metadata", "redirects"]:
        raise Http404
    if operation == "export":
        response = HttpResponse(content_type="text/csv")
        response["Content-Disposition"] = f'attachment; filename="{kind}.csv"'
        writer = csv.writer(response)
        if kind == "metadata":
            writer.writerow(["blog_id"] + META_FIELDS)
            for blog in project.blogs.order_by("id")[:1000]:
                writer.writerow(
                    [blog.pk]
                    + [
                        ("'" + str(blog.seo_settings.get(k, "")))
                        if str(blog.seo_settings.get(k, "")).startswith(
                            ("=", "+", "-", "@", "\t", "\r")
                        )
                        else str(blog.seo_settings.get(k, ""))
                        for k in META_FIELDS
                    ]
                )
        else:
            writer.writerow(["source_path", "target_path", "status_code"])
            for row in RedirectRule.objects.filter(project=project):
                writer.writerow([row.source_path, row.target_path, row.status_code])
        return response
    if request.method == "POST":
        if batch_id:
            batch = get_object_or_404(
                ChangeBatch, pk=batch_id, project=project, kind=kind
            )
            commit_batch(batch, operation == "undo")
        elif operation == "remove" and kind == "redirects":
            row = get_object_or_404(
                RedirectRule, pk=request.POST["rule_id"], project=project
            )
            ChangeBatch.objects.create(
                project=project,
                user=request.user,
                kind=kind,
                reason=request.POST.get("reason", "Remove reviewed redirect")[:500],
                changes=[
                    {
                        "source": row.source_path,
                        "before": {
                            "target_path": row.target_path,
                            "status_code": row.status_code,
                        },
                        "after": None,
                    }
                ],
            )
        else:
            reason = request.POST.get("reason", "").strip()
            if not 5 <= len(reason) <= 500 or "csv" not in request.FILES:
                raise ValidationError(
                    "Upload an exported CSV and provide a reason (5–500 characters)."
                )
            preview_csv(project, request.user, kind, request.FILES["csv"], reason)
        return redirect("batches", pk=pk, kind=kind)
    return render(
        request,
        "portal/batches.html",
        {
            "project": project,
            "kind": kind,
            "batches": ChangeBatch.objects.filter(project=project, kind=kind).order_by(
                "-id"
            )[:20],
            "rules": RedirectRule.objects.filter(project=project)[:100],
        },
    )


@page
@require_http_methods(["GET", "POST"])
def telemetry(request, pk):
    project = project_for(request, pk, "manage")
    key = None
    if request.method == "POST":
        key = token()
        project.telemetry_key_hash = digest(key)
        project.save()
    return render(
        request,
        "portal/telemetry.html",
        {
            "project": project,
            "telemetry_key": key,
            "events": NotFoundEvent.objects.filter(project=project).order_by(
                "-last_seen"
            )[:100],
        },
    )


def matching_property(site, public):
    host = urlsplit(public).hostname
    if site.startswith("sc-domain:"):
        domain = site[len("sc-domain:") :].lower()
        return host == domain or host.endswith("." + domain)
    try:
        return origin(site) == origin(public) and urlsplit(site).path in ["", "/"]
    except Exception:
        return False


@page
@require_http_methods(["GET", "POST"])
def search_console(request, pk, operation=None):
    operation = "select" if operation == "property" else operation
    project = project_for(request, pk, "manage")
    connection = SearchConnection.objects.filter(
        project=project, state="connected"
    ).first()
    properties = []
    if request.method == "POST":
        if operation == "connect":
            if (
                not settings.GOOGLE_CLIENT_ID
                or not settings.GOOGLE_CLIENT_SECRET
                or not settings.GOOGLE_REDIRECT_URI
                or not project.seo_verified_at
            ):
                raise ValidationError(
                    "Configure Google OAuth and verify the public domain first."
                )
            state = secrets.token_urlsafe(32)
            verifier = secrets.token_urlsafe(48)
            request.session["google_oauth"] = {
                "state": state,
                "verifier": verifier,
                "project": project.pk,
                "time": timezone.now().timestamp(),
            }
            challenge = (
                base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest())
                .decode()
                .rstrip("=")
            )
            return redirect(
                "https://accounts.google.com/o/oauth2/v2/auth?"
                + urlencode(
                    {
                        "client_id": settings.GOOGLE_CLIENT_ID,
                        "redirect_uri": settings.GOOGLE_REDIRECT_URI,
                        "response_type": "code",
                        "scope": "https://www.googleapis.com/auth/webmasters.readonly",
                        "state": state,
                        "code_challenge": challenge,
                        "code_challenge_method": "S256",
                        "access_type": "offline",
                        "prompt": "consent",
                    }
                )
            )
        if not connection:
            raise ValidationError("Connect Google Search Console first.")
        if operation == "disconnect":
            connection.delete()
            return redirect("search", pk=pk)
        sites = (
            google_request(
                connection, "GET", "https://www.googleapis.com/webmasters/v3/sites"
            ).get("siteEntry", [])
            if operation in ["properties", "select"]
            else []
        )
        properties = [
            s["siteUrl"]
            for s in sites
            if s.get("permissionLevel")
            in ["siteOwner", "siteFullUser", "siteRestrictedUser"]
            and matching_property(s.get("siteUrl", ""), connection.origin)
        ]
        if operation == "select":
            if request.POST.get("property") not in properties:
                raise ValidationError(
                    "Select a verified property matching this public domain."
                )
            connection.property = request.POST["property"]
            connection.save()
        elif operation in ["fetch", "inspect"]:
            if not connection.property or not matching_property(
                connection.property, project.seo_settings.get("public_url", "")
            ):
                raise ValidationError("Select a matching Search Console property.")
            if operation == "fetch":
                from datetime import date

                start = date.fromisoformat(request.POST["start_date"])
                end = date.fromisoformat(request.POST["end_date"])
                if (
                    start > end
                    or (end - start).days > 90
                    or end > timezone.localdate() - timedelta(days=3)
                ):
                    raise ValidationError(
                        "Use a range of at most 90 days ending at least three days ago."
                    )
                obj = SearchReport.objects.create(
                    project=project,
                    connection=connection,
                    start_date=start,
                    end_date=end,
                )
                kind = "search_report"
            else:
                blog = get_object_or_404(
                    project.blogs.live(), pk=request.POST["blog_id"]
                )
                if not article_url(blog):
                    raise ValidationError("Article needs a verified public URL.")
                obj = SearchInspection.objects.create(
                    project=project, connection=connection, blog=blog
                )
                kind = "search_inspect"
            WorkItem.objects.create(
                project=project,
                user=request.user,
                kind=kind,
                payload={
                    "id": obj.pk,
                    "property": connection.property,
                    "origin": connection.origin,
                },
            )
    return render(
        request,
        "portal/search.html",
        {
            "project": project,
            "connection": connection,
            "google_oauth_configured": bool(
                settings.GOOGLE_CLIENT_ID
                and settings.GOOGLE_CLIENT_SECRET
                and settings.GOOGLE_REDIRECT_URI
            ),
            "google_connect_ready": bool(
                settings.GOOGLE_CLIENT_ID
                and settings.GOOGLE_CLIENT_SECRET
                and settings.GOOGLE_REDIRECT_URI
                and project.seo_verified_at
                and project.seo_settings.get("public_url")
            ),
            "properties": properties,
            "reports": SearchReport.objects.filter(project=project).order_by("-id")[
                :20
            ],
            "inspections": SearchInspection.objects.filter(project=project).order_by(
                "-id"
            )[:20],
        },
    )


@page
def google_callback(request):
    flow = request.session.pop("google_oauth", None)
    if (
        not flow
        or not secrets.compare_digest(str(flow["state"]), request.GET.get("state", ""))
        or timezone.now().timestamp() - flow["time"] > 600
    ):
        raise ValidationError("OAuth request expired or state did not match.")
    project = project_for(request, flow["project"], "manage")
    if flow.get("service") != "analytics" and not project.seo_verified_at:
        raise ValidationError("Domain verification changed.")
    response = requests.post(
        "https://oauth2.googleapis.com/token",
        data={
            "client_id": settings.GOOGLE_CLIENT_ID,
            "client_secret": settings.GOOGLE_CLIENT_SECRET,
            "redirect_uri": settings.GOOGLE_REDIRECT_URI,
            "grant_type": "authorization_code",
            "code": request.GET.get("code", ""),
            "code_verifier": flow["verifier"],
        },
        timeout=15,
    )
    response.raise_for_status()
    tokens = response.json()
    if flow.get("service") == "analytics":
        from .google_tools import ANALYTICS_SCOPE

        if ANALYTICS_SCOPE not in tokens.get("scope", "").split():
            raise ValidationError("Required read-only Analytics scope was not granted.")
        tokens["expires_at"] = timezone.now().timestamp() + tokens.get(
            "expires_in", 3600
        )
        AnalyticsConnection.objects.update_or_create(
            project=project, defaults={"tokens": tokens, "property": "", "report": {}}
        )
        return redirect("analytics_hub", pk=project.pk, tab="analytics")
    if (
        "https://www.googleapis.com/auth/webmasters.readonly"
        not in tokens.get("scope", "").split()
    ):
        raise ValidationError(
            "Required read-only Search Console scope was not granted."
        )
    tokens["expires_at"] = timezone.now().timestamp() + tokens.get("expires_in", 3600)
    SearchConnection.objects.update_or_create(
        project=project,
        defaults={
            "tokens": tokens,
            "origin": project.seo_settings["public_url"],
            "property": "",
            "state": "connected",
        },
    )
    return redirect("search", pk=project.pk)


@page
def search_export(request, pk, report_id):
    project = project_for(request, pk, "manage")
    report = get_object_or_404(
        SearchReport, pk=report_id, project=project, state="complete"
    )
    response = HttpResponse(content_type="text/csv")
    response["Content-Disposition"] = 'attachment; filename="search-performance.csv"'
    writer = csv.writer(response)
    writer.writerow(["date", "clicks", "impressions", "ctr", "position"])
    for row in report.rows:
        writer.writerow(
            [(row.get("keys") or [""])[0]]
            + [
                max(0, float(row.get(k, 0)))
                for k in ["clicks", "impressions", "ctr", "position"]
            ]
        )
    return response


def api_project(request):
    key = request.headers.get("X-API-KEY") or request.GET.get("api_key")
    return Project.objects.filter(api_key=key).first() if key else None


def api_view(view):
    @functools.wraps(view)
    def wrapped(request, *args, **kwargs):
        project = api_project(request)
        if not project:
            return JsonResponse({"error": "Valid API key required."}, status=401)
        request.api_project = project
        try:
            rate_limit(request, "api-" + str(project.pk), 120)
            return view(request, project, *args, **kwargs)
        except (ValidationError, ValueError, KeyError):
            return JsonResponse(
                {"error": "Invalid or rate-limited request."}, status=400
            )

    return wrapped


@require_http_methods(["GET"])
@api_view
def api_blogs(request, project, slug=None):
    blogs = (
        project.blogs.live()
        .select_related("project", "category", "user")
        .prefetch_related("sections")
        .order_by("-created_at")
    )
    if slug:
        return JsonResponse(
            {
                "project": project.name,
                "blog": blog_payload(get_object_or_404(blogs, slug=slug)),
            }
        )
    per = int(request.GET.get("per_page", 20))
    page_number = int(request.GET.get("page", 1))
    if not 1 <= per <= 100 or page_number < 1:
        raise ValidationError("Invalid pagination.")
    paginator = Paginator(blogs, per)
    listing = paginator.get_page(page_number)
    return JsonResponse(
        {
            "project": project.name,
            "blogs": [blog_payload(b) for b in listing],
            "meta": {
                "current_page": listing.number,
                "last_page": paginator.num_pages,
                "per_page": per,
                "total": paginator.count,
            },
        }
    )


@api_view
def api_sitemap(request, project):
    import xml.etree.ElementTree as ET

    if not project.seo_verified_at:
        return JsonResponse({"error": "Verify the public domain first."}, status=409)
    page_number = int(request.GET.get("page", 1))
    if page_number < 1:
        raise ValidationError("Invalid page.")
    paginator = Paginator(project.blogs.live().order_by("id"), 1000)
    listing = paginator.get_page(page_number)
    root = ET.Element("urlset", xmlns="http://www.sitemaps.org/schemas/sitemap/0.9")
    for blog in listing:
        seo = seo_payload(blog)
        if (
            not seo["robots"]["index"]
            or not seo["public_url"]
            or seo["canonical_url"] != seo["public_url"]
        ):
            continue
        entry = ET.SubElement(root, "url")
        ET.SubElement(entry, "loc").text = seo["public_url"]
        ET.SubElement(entry, "lastmod").text = blog.updated_at.isoformat()
    response = HttpResponse(
        ET.tostring(root, encoding="utf-8", xml_declaration=True),
        content_type="application/xml",
    )
    response["X-Sitemap-Pages"] = str(paginator.num_pages)
    return response


@api_view
def api_redirect(request, project):
    path = rooted_path(request.GET.get("path", "").split("?")[0].split("#")[0])
    if not project.seo_verified_at or any(
        article_url(b) == origin(project.seo_settings["public_url"]) + path
        for b in project.blogs.live()
    ):
        return JsonResponse({"redirect": None})
    rule = RedirectRule.objects.filter(
        project=project, source_path=path, enabled=True
    ).first()
    return JsonResponse(
        {
            "redirect": {"status": rule.status_code, "location": rule.target_path}
            if rule
            else None
        }
    )


@csrf_exempt
@require_POST
def api_not_found(request):
    supplied = request.headers.get("X-Telemetry-Key", "")
    project = (
        Project.objects.filter(telemetry_key_hash=digest(supplied)).first()
        if 32 <= len(supplied) <= 128
        else None
    )
    if not project:
        return JsonResponse({"error": "Telemetry key required."}, status=401)
    if not project.seo_verified_at or not project.seo_settings.get("public_url"):
        return JsonResponse({"error": "Verified origin required."}, status=409)
    try:
        rate_limit(request, "telemetry-" + str(project.pk), 60)
        data = json.loads(request.body)
        path = rooted_path(data["path"].split("?")[0].split("#")[0])
        event_id = str(uuid.UUID(data["event_id"]))
        if data.get("origin") != origin(project.seo_settings["public_url"]):
            raise ValidationError("Origin mismatch.")
        with transaction.atomic():
            locked = Project.objects.select_for_update().get(pk=project.pk)
            if (
                locked.telemetry_key_hash != project.telemetry_key_hash
                or locked.seo_settings.get("public_url") != data["origin"]
            ):
                raise ValidationError("Origin or secret changed.")
            if TelemetryReceipt.objects.filter(
                project=project, event_id=event_id
            ).exists():
                return HttpResponse(status=204)
            if (
                TelemetryReceipt.objects.filter(
                    project=project, created_at__date=timezone.localdate()
                ).count()
                >= 10000
            ):
                return JsonResponse({"error": "Daily event limit reached."}, status=429)
            if (
                not NotFoundEvent.objects.filter(
                    project=project, day=timezone.localdate(), path=path
                ).exists()
                and NotFoundEvent.objects.filter(
                    project=project, day=timezone.localdate()
                ).count()
                >= 1000
            ):
                return JsonResponse({"error": "Daily path limit reached."}, status=429)
            TelemetryReceipt.objects.create(project=project, event_id=event_id)
            event, new = NotFoundEvent.objects.get_or_create(
                project=project, path=path, day=timezone.localdate()
            )
            if not new:
                NotFoundEvent.objects.filter(pk=event.pk).update(
                    hits=F("hits") + 1, last_seen=timezone.now()
                )
    except (ValidationError, ValueError, KeyError):
        return JsonResponse({"error": "Invalid telemetry payload."}, status=422)
    return HttpResponse(status=204)
