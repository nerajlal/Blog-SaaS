from .models import Workspace
from .services import can_access


def navigation(request):
    if not request.user.is_authenticated:
        return {}
    workspaces = Workspace.objects.filter(memberships__user=request.user)
    project = getattr(request, "project_context", None)
    return {
        "workspaces": workspaces,
        "active_workspace": workspaces.filter(
            pk=request.user.current_workspace_id
        ).first(),
        "nav_project": project,
        "can_edit": bool(project and can_access(request.user, project, "update")),
        "can_manage": bool(project and can_access(request.user, project, "manage")),
    }
