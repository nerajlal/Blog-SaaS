from .models import Workspace
from .services import can_access


def navigation(request):
    if not request.user.is_authenticated:
        return {}
    workspaces = Workspace.objects.filter(memberships__user=request.user)
    project = getattr(request, "project_context", None)
    match = request.resolver_match
    route = match.url_name if match else ""
    groups = {
        "projects": "projects",
        "project": "blogs",
        "integration": "blogs",
        "create_blog": "blogs",
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
    return {
        "sidebar_active": active,
        "workspaces": workspaces,
        "active_workspace": workspaces.filter(
            pk=request.user.current_workspace_id
        ).first(),
        "nav_project": project,
        "can_edit": bool(project and can_access(request.user, project, "update")),
        "can_manage": bool(project and can_access(request.user, project, "manage")),
    }
