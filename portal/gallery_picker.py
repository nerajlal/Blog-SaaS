"""Reusable image selection restricted to the post's workspace."""

from pathlib import PurePosixPath
from urllib.parse import urlsplit, unquote
from django.core.paginator import Paginator
from django.db.models import F, Q
from django.http import JsonResponse
from django.views.decorators.http import require_GET
from .models import Blog, BlogSection, GalleryImage
from .services import project_for, image_url
from .views import page


def scope_posts(project):
    return (
        Blog.objects.filter(project__workspace_id=project.workspace_id)
        if project.workspace_id
        else Blog.objects.filter(project=project)
    )


def image_available(project, path):
    if not project or not path:
        return False
    posts = scope_posts(project)
    return bool(
        (
            project.workspace_id
            and GalleryImage.objects.filter(
                workspace_id=project.workspace_id, path=path
            ).exists()
        )
        or posts.filter(cover_image=path).exists()
        or BlogSection.objects.filter(blog__in=posts, image_path=path).exists()
    )


@page
@require_GET
def picker(request, pk):
    project = project_for(request, pk, "update")
    query = request.GET.get("q", "").strip()[:150]
    posts = scope_posts(project)
    covers = posts.exclude(cover_image="")
    sections = BlogSection.objects.filter(blog__in=posts).exclude(image_path="")
    uploads = (
        GalleryImage.objects.filter(workspace_id=project.workspace_id)
        if project.workspace_id
        else GalleryImage.objects.none()
    )
    if query:
        covers = covers.filter(
            Q(cover_image__icontains=query)
            | Q(title__icontains=query)
            | Q(project__name__icontains=query)
        )
        sections = sections.filter(
            Q(image_path__icontains=query)
            | Q(blog__title__icontains=query)
            | Q(blog__project__name__icontains=query)
        )
        uploads = uploads.filter(
            Q(name__icontains=query)
            | Q(path__icontains=query)
            | Q(project__name__icontains=query)
        )
    paths = (
        covers.order_by()
        .values(path=F("cover_image"))
        .union(
            sections.order_by().values(path=F("image_path")),
            uploads.order_by().values("path"),
        )
        .order_by("path")
    )
    page = Paginator(paths, 24).get_page(request.GET.get("page"))
    page_paths = [row["path"] for row in page]
    names = (
        dict(
            GalleryImage.objects.filter(
                workspace_id=project.workspace_id, path__in=page_paths
            ).values_list("path", "name")
        )
        if project.workspace_id
        else {}
    )
    response = JsonResponse(
        {
            "images": [
                {
                    "path": path,
                    "url": image_url(path, {}),
                    "name": names.get(path)
                    or unquote(PurePosixPath(urlsplit(path).path).name),
                }
                for path in page_paths
                if image_url(path, {})
            ],
            "page": page.number,
            "pages": page.paginator.num_pages,
            "total": page.paginator.count,
        }
    )
    response["Cache-Control"] = "private, no-store"
    return response
