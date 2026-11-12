from .models import Workspace, Membership
from .services import can_access


def navigation(request):
    if not request.user.is_authenticated:
        return {}
    workspaces = Workspace.objects.filter(
        memberships__is_frozen=False, memberships__user=request.user
    )
    project = getattr(request, "project_context", None)
    match = request.resolver_match
    route = match.url_name if match else ""
    groups = {
        "projects": "projects",
        "gallery": "gallery",
        "superadmin": "superadmin",
        "superadmin_usage_export": "superadmin",
        "project": "blogs",
        "integration": "blogs",
        "create_blog": "blogs",
        "post_import": "blogs",
        "post_import_preview": "blogs",
        "edit_blog": "blogs",
        "studio": "blogs",
        "ai_run": "blogs",
        "ai_run_action": "blogs",
        "social_version": "blogs",
        "profile": "profile",
        "profile_action": "profile",
        "health": "health",
        "health_status": "health",
        "report": "health",
        "links": "links",
        "blog_links": "links",
        "seo_settings": "seo",
        "verify_domain": "seo",
        "search": "search",
        "analytics_hub": "search",
        "sitemap_download": "search",
        "search_action": "search",
        "search_export": "search",
        "google_callback": "search",
        "metadata": "metadata",
        "redirects": "redirects",
        "telemetry": "telemetry",
        "docs": "docs",
        "admin_docs": "docs",
    }
    active = groups.get(route, "projects")
    if route in ["batches", "batch_operation", "batch_action"]:
        active = match.kwargs.get("kind", "metadata")
    managed = workspaces.filter(
        memberships__is_frozen=False,
        memberships__user=request.user,
        memberships__role__in=["owner", "admin"],
    ).distinct()
    staff_memberships = (
        Membership.objects.filter(workspace__in=managed)
        .exclude(role="owner")
        .select_related("user", "workspace")
        .order_by("user__email", "workspace__name")
    )
    return {
        "managed_workspaces": managed,
        "staff_memberships": staff_memberships,
        "sidebar_active": active,
        "workspaces": workspaces,
        "editable_workspace_ids": set(
            Membership.objects.filter(
                is_frozen=False, user=request.user, role__in=["owner", "admin"]
            ).values_list("workspace_id", flat=True)
        ),
        "active_workspace": workspaces.filter(
            pk=request.user.current_workspace_id
        ).first(),
        "nav_project": project,
        "can_edit": bool(project and can_access(request.user, project, "update")),
        "can_manage": bool(project and can_access(request.user, project, "manage")),
    }
