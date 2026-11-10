"""Platform-wide usage reporting, strictly limited to active superusers."""

import csv
import functools
from collections import defaultdict
from datetime import datetime, timezone as dt_timezone
from django.conf import settings
from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied, ValidationError
from django.core.paginator import Paginator
from django.db.models import Q
from django.http import HttpResponse
from django.shortcuts import render
from django.utils import timezone
from django.views.decorators.http import require_GET
from .models import User, Workspace, Project, Blog, AiRun, AiSettings, AiUsage

TOKEN_FIELDS = (
    "input_tokens",
    "output_tokens",
    "cache_creation_input_tokens",
    "cache_read_input_tokens",
)


def superuser_required(view):
    @login_required
    @functools.wraps(view)
    def wrapped(request, *args, **kwargs):
        if not request.user.is_active or not request.user.is_superuser:
            raise PermissionDenied("Platform superadmin access is required.")
        response = view(request, *args, **kwargs)
        response["Cache-Control"] = "private, no-store"
        return response

    return wrapped


def number(value):
    try:
        return max(0, int(value))
    except (TypeError, ValueError):
        return 0


def blank():
    return dict(
        requests=0,
        complete=0,
        failed=0,
        pending=0,
        missing_usage=0,
        **{key: 0 for key in TOKEN_FIELDS},
    )


def add(summary, row):
    summary["requests"] += 1
    state = row["state"]
    if state == "complete":
        summary["complete"] += 1
    if state == "failed":
        summary["failed"] += 1
    if state in ("pending", "running"):
        summary["pending"] += 1
    usage = row["usage"] or {}
    if (
        state in ("complete", "failed")
        and "input_tokens" not in usage
        and "output_tokens" not in usage
    ):
        summary["missing_usage"] += 1
    for key in TOKEN_FIELDS:
        summary[key] += number(usage.get(key))


def filtered(request):
    month = request.GET.get("month") or timezone.now().strftime("%Y-%m")
    runs = AiRun.objects.all()
    if month != "all":
        try:
            start = datetime.strptime(month, "%Y-%m").replace(tzinfo=dt_timezone.utc)
            if start.strftime("%Y-%m") != month:
                raise ValueError
            end = (
                start.replace(year=start.year + 1, month=1)
                if start.month == 12
                else start.replace(month=start.month + 1)
            )
            runs = runs.filter(created_at__gte=start, created_at__lt=end)
        except ValueError:
            raise ValidationError("Choose a valid month or all.")
    search = request.GET.get("q", "").strip()[:200]
    if search:
        runs = runs.filter(
            Q(user__email__icontains=search)
            | Q(project__workspace__name__icontains=search)
            | Q(project__name__icontains=search)
        )
    return month, search, runs


@superuser_required
@require_GET
def dashboard(request, section="overview"):
    try:
        month, search, runs = filtered(request)
    except ValidationError as error:
        return HttpResponse(error.messages[0], status=400)
    clients = User.objects.filter(is_superuser=False).order_by("-date_joined")
    workspaces = Workspace.objects.select_related("owner").order_by("name")
    if search:
        clients = clients.filter(
            Q(email__icontains=search) | Q(first_name__icontains=search)
        )
        workspaces = workspaces.filter(
            Q(name__icontains=search) | Q(owner__email__icontains=search)
        )
    client_page = Paginator(clients, 20).get_page(request.GET.get("clients_page"))
    workspace_page = Paginator(workspaces, 20).get_page(
        request.GET.get("workspaces_page")
    )
    by_client, by_workspace = defaultdict(blank), defaultdict(blank)
    total = blank()
    for row in runs.values(
        "user_id", "project__workspace_id", "state", "usage"
    ).iterator(chunk_size=500):
        add(total, row)
        add(by_client[row["user_id"]], row)
        add(by_workspace[row["project__workspace_id"]], row)
    client_rows = [
        {"client": client, "usage": by_client[client.pk]} for client in client_page
    ]
    scopes = [f"workspace-{workspace.pk}" for workspace in workspace_page]
    allowances = {
        config.scope: config for config in AiSettings.objects.filter(scope__in=scopes)
    }
    reservations = (
        {
            entry.scope: entry.requests
            for entry in AiUsage.objects.filter(scope__in=scopes, month=month)
        }
        if month != "all"
        else {}
    )
    workspace_rows = [
        {
            "workspace": workspace,
            "usage": by_workspace[workspace.pk],
            "settings": allowances.get(f"workspace-{workspace.pk}"),
            "effective_limit": min(
                allowances[f"workspace-{workspace.pk}"].monthly_limit,
                settings.AI_MONTHLY_REQUEST_LIMIT,
            )
            if f"workspace-{workspace.pk}" in allowances
            else 0,
            "reserved": reservations.get(f"workspace-{workspace.pk}", 0),
        }
        for workspace in workspace_page
    ]
    details = runs.order_by("-created_at", "-id").values(
        "id",
        "created_at",
        "user__email",
        "project__name",
        "project__workspace__name",
        "blog_id",
        "output_blog_id",
        "action",
        "platform",
        "model",
        "state",
        "usage",
    )
    page = Paginator(details, 30).get_page(request.GET.get("page"))
    rows = [
        {
            **row,
            **{key: number((row["usage"] or {}).get(key)) for key in TOKEN_FIELDS},
            "usage_known": any(
                k in (row["usage"] or {}) for k in ("input_tokens", "output_tokens")
            ),
        }
        for row in page
    ]
    params = request.GET.copy()
    for key in ("page", "clients_page", "workspaces_page"):
        params.pop(key, None)
    return render(
        request,
        "portal/superadmin.html",
        {
            "admin_section": section,
            "page_title": {
                "overview": "Platform overview",
                "clients": "Clients",
                "workspaces": "Workspaces & allowances",
                "usage": "AI usage & requests",
            }[section],
            "ai_platform_enabled": settings.AI_ENABLED,
            "month": month,
            "search": search,
            "query": params.urlencode(),
            "total": total,
            "clients": client_rows,
            "client_page": client_page,
            "workspaces_usage": workspace_rows,
            "workspace_page": workspace_page,
            "rows": rows,
            "page": page,
            "counts": {
                "Clients": User.objects.filter(is_superuser=False).count(),
                "Workspaces": Workspace.objects.count(),
                "Projects": Project.objects.count(),
                "Blogs": Blog.objects.count(),
            },
        },
    )


def csv_cell(value):
    value = str(value or "")
    return (
        "'" + value
        if value.startswith(("=", "+", "-", "@", "\t", "\r", "\n"))
        else value
    )


@superuser_required
@require_GET
def usage_export(request):
    try:
        month, search, runs = filtered(request)
    except ValidationError as error:
        return HttpResponse(error.messages[0], status=400)
    response = HttpResponse(content_type="text/csv")
    response["Content-Disposition"] = 'attachment; filename="ai-usage.csv"'
    writer = csv.writer(response)
    writer.writerow(
        [
            "Request ID",
            "Created UTC",
            "Client",
            "Workspace",
            "Project",
            "Blog ID",
            "Action",
            "Platform",
            "Model",
            "State",
            *TOKEN_FIELDS,
            "Token usage recorded",
        ]
    )
    for row in (
        runs.order_by("-created_at")
        .values(
            "id",
            "created_at",
            "user__email",
            "project__workspace__name",
            "project__name",
            "blog_id",
            "output_blog_id",
            "action",
            "platform",
            "model",
            "state",
            "usage",
        )
        .iterator(chunk_size=500)
    ):
        usage = row["usage"] or {}
        writer.writerow(
            [
                row["id"],
                row["created_at"].isoformat(),
                *[
                    csv_cell(row[key])
                    for key in (
                        "user__email",
                        "project__workspace__name",
                        "project__name",
                    )
                ],
                row["blog_id"] or row["output_blog_id"] or "",
                row["action"],
                row["platform"],
                csv_cell(row["model"]),
                row["state"],
                *[number(usage.get(key)) for key in TOKEN_FIELDS],
                any(k in usage for k in ("input_tokens", "output_tokens")),
            ]
        )
    return response
