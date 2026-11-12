"""Workspace-scoped post images and optimized direct uploads."""

from pathlib import PurePosixPath
from urllib.parse import urlsplit, unquote
from django import forms
from django.contrib import messages
from django.core.exceptions import PermissionDenied, ValidationError
from django.core.files.storage import default_storage
from django.db import transaction
from django.db.models import F, Q, Count, Window
from django.db.models.functions import RowNumber
from django.core.paginator import Paginator
from django.http import Http404
from django.urls import reverse
from django.shortcuts import render, redirect
from django.conf import settings
from django.views.decorators.http import require_http_methods
from .models import Blog, BlogSection, Project, Membership, GalleryImage
from .services import current_workspace, image_url, save_image, rate_limit
from .views import page


class MultipleImageInput(forms.ClearableFileInput):
    allow_multiple_selected = True


class MultipleImagesField(forms.ImageField):
    widget = MultipleImageInput(
        attrs={"accept": "image/jpeg,image/png,image/webp,image/gif"}
    )

    def clean(self, value, initial=None):
        files = value if isinstance(value, (list, tuple)) else [value] if value else []
        if not 1 <= len(files) <= 5:
            raise ValidationError("Choose between 1 and 5 images.")
        for file in files:
            if file.size > 2 * 1024 * 1024:
                raise ValidationError("Each image must be at most 2 MB.")
        return [super(MultipleImagesField, self).clean(file, initial) for file in files]


class GalleryUploadForm(forms.Form):
    images = MultipleImagesField(
        help_text="Up to 5 images, 2 MB each. JPEG, PNG, WebP or GIF. Saved as optimized WebP, up to 2560px."
    )
    project = forms.ModelChoiceField(
        queryset=Project.objects.none(),
        required=False,
        label="Project (optional)",
        empty_label="Workspace library",
    )

    def __init__(self, *args, workspace, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["project"].queryset = Project.objects.filter(
            workspace=workspace
        ).order_by("name")


@page
@require_http_methods(["GET", "POST"])
def gallery(request):
    if request.user.is_superuser:
        return redirect("superadmin")
    workspace = current_workspace(request.user)
    can_upload = Membership.objects.filter(
        workspace=workspace,
        user=request.user,
        is_frozen=False,
        role__in=["owner", "admin", "editor"],
    ).exists()
    upload_form = GalleryUploadForm(
        request.POST or None, request.FILES or None, workspace=workspace
    )
    response_status = 200
    if request.method == "POST":
        if not can_upload:
            raise PermissionDenied
        rate_limit(request, "gallery-upload", 10, 60)
        if upload_form.is_valid():
            paths = []
            try:
                with transaction.atomic():
                    for upload in upload_form.cleaned_data["images"]:
                        path = save_image(upload, "gallery")
                        paths.append(path)
                        GalleryImage.objects.create(
                            workspace=workspace,
                            project=upload_form.cleaned_data["project"],
                            uploaded_by=request.user,
                            path=path,
                            name=(PurePosixPath(upload.name).stem[:240] or "image")
                            + ".webp",
                        )
                messages.success(
                    request, f"{len(paths)} image(s) uploaded and optimized to WebP."
                )
                return redirect(reverse("gallery") + "?type=uploaded")
            except Exception as error:
                for path in paths:
                    try:
                        default_storage.delete(path.removeprefix(settings.MEDIA_URL))
                    except OSError:
                        pass
                if not isinstance(error, (ValidationError, OSError)):
                    raise
                upload_form.add_error(
                    None,
                    error
                    if isinstance(error, ValidationError)
                    else "Images could not be saved. Please try again.",
                )
        response_status = 400
    projects = Project.objects.filter(workspace=workspace).order_by("name")
    selected = request.GET.get("project", "")
    if selected and (
        not selected.isdigit() or not projects.filter(pk=selected).exists()
    ):
        raise Http404
    kind = request.GET.get("type", "all")
    if kind not in ["all", "featured", "section", "uploaded"]:
        kind = "all"
    query = request.GET.get("q", "").strip()[:150]
    posts = Blog.objects.filter(project__workspace=workspace)
    if selected:
        posts = posts.filter(project_id=selected)
    filtered_posts = (
        posts.filter(Q(title__icontains=query) | Q(project__name__icontains=query))
        if query
        else posts
    )
    sections = BlogSection.objects.filter(blog__in=posts)
    filtered_sections = BlogSection.objects.filter(blog__in=filtered_posts)
    uploads = GalleryImage.objects.filter(workspace=workspace)
    if selected:
        uploads = uploads.filter(
            Q(project_id=selected)
            | Q(path__in=posts.values("cover_image"))
            | Q(path__in=sections.values("image_path"))
        )
    if query:
        uploads = uploads.filter(
            Q(name__icontains=query)
            | Q(project__name__icontains=query)
            | Q(path__in=filtered_posts.values("cover_image"))
            | Q(path__in=filtered_sections.values("image_path"))
        )
    sources = [
        (
            "Featured image",
            posts.exclude(cover_image="").order_by(),
            "cover_image",
            "id",
            "title",
            "project__name",
        ),
        (
            "Section image",
            sections.exclude(image_path="").order_by(),
            "image_path",
            "blog_id",
            "blog__title",
            "blog__project__name",
        ),
    ]
    choices = []
    if kind in ["all", "featured"]:
        choices.append(
            filtered_posts.exclude(cover_image="")
            .order_by()
            .values(path=F("cover_image"))
        )
    if kind in ["all", "section"]:
        choices.append(
            filtered_sections.exclude(image_path="")
            .order_by()
            .values(path=F("image_path"))
        )
    if kind in ["all", "uploaded"]:
        # Use a different alias because path is already a model field.
        choices.append(uploads.order_by().values("path"))
    paths = (
        uploads.order_by("-created_at", "-id").values("path")
        if kind == "uploaded"
        else choices[0].union(*choices[1:]).order_by("path")
    )
    page_of_paths = Paginator(paths, 24).get_page(request.GET.get("page"))
    paths_on_page = [row["path"] for row in page_of_paths]
    assets = {
        path: {
            "path": path,
            "url": image_url(path, {}),
            "name": unquote(PurePosixPath(urlsplit(path).path).name),
            "types": [],
            "uses": [],
            "count": 0,
        }
        for path in paths_on_page
    }
    for label, source, field, id_field, title_field, project_field in sources:
        matching = source.filter(**{field + "__in": paths_on_page})
        for row in matching.values(field).annotate(total=Count("pk")):
            asset = assets[row[field]]
            asset["types"].append(label)
            asset["count"] += row["total"]
        examples = (
            matching.annotate(
                gallery_rank=Window(
                    expression=RowNumber(),
                    partition_by=[F(field)],
                    order_by=F("pk").desc(),
                )
            )
            .filter(gallery_rank__lte=3)
            .values(field, id_field, title_field, project_field)
        )
        for row in examples:
            asset = assets[row[field]]
            if len(asset["uses"]) < 3 and not any(
                use["id"] == row[id_field] for use in asset["uses"]
            ):
                asset["uses"].append(
                    {
                        "id": row[id_field],
                        "title": row[title_field],
                        "project": row[project_field],
                    }
                )
    for upload in GalleryImage.objects.filter(
        workspace=workspace, path__in=paths_on_page
    ).select_related("project"):
        asset = assets[upload.path]
        asset["name"] = upload.name
        asset["types"].append("Uploaded")
        asset["uploaded_project"] = (
            upload.project.name if upload.project else "Workspace library"
        )
    return render(
        request,
        "portal/gallery.html",
        {
            "workspace": workspace,
            "projects": projects,
            "selected_project": selected,
            "selected_type": kind,
            "query": query,
            "assets": list(assets.values()),
            "page": page_of_paths,
            "can_upload": can_upload,
            "upload_form": upload_form,
        },
        status=response_status,
    )
