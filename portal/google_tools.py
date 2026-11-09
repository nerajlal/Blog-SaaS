"""Authenticated Google Analytics reporting and sitemap setup."""

import base64
import hashlib
import re
import secrets
from datetime import date, timedelta
from urllib.parse import urlencode
from django.conf import settings
from django.core.exceptions import ValidationError
from django.shortcuts import redirect, render
from django.utils import timezone
from django.views.decorators.http import require_http_methods
from .models import AnalyticsConnection, SearchConnection
from .forms import SeoForm
from .services import project_for, origin, token, rate_limit
from .tasks import google_request
from .views import page

ANALYTICS_SCOPE = "https://www.googleapis.com/auth/analytics.readonly"


def oauth_ready():
    return bool(
        settings.GOOGLE_CLIENT_ID
        and settings.GOOGLE_CLIENT_SECRET
        and settings.GOOGLE_REDIRECT_URI
    )


def analytics_properties(connection):
    properties = []
    next_page = ""
    for _ in range(20):
        url = (
            "https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200"
        )
        if next_page:
            url += "&" + urlencode({"pageToken": next_page})
        result = google_request(connection, "GET", url)
        properties.extend(
            p
            for a in result.get("accountSummaries", [])
            for p in a.get("propertySummaries", [])
            if re.fullmatch(r"properties/\d+", p.get("property", ""))
        )
        next_page = result.get("nextPageToken")
        if not next_page:
            break
    return properties


@page
@require_http_methods(["GET", "POST"])
def hub(request, pk, tab):
    project = project_for(request, pk, "manage")
    if tab not in ["analytics", "sitemap"]:
        raise ValidationError("Unknown tab.")
    connection = AnalyticsConnection.objects.filter(project=project).first()
    properties = []
    form = SeoForm(
        request.POST if request.method == "POST" and tab == "sitemap" else None,
        initial={
            **project.seo_settings,
            "blog_path": project.seo_settings.get(
                "article_path", project.seo_settings.get("blog_path", "/blog/{slug}")
            ),
        },
    )
    if request.method == "POST":
        rate_limit(request, "google-tools-" + str(project.pk), 20)
        operation = request.POST.get("operation", "")
        if tab == "sitemap":
            if form.is_valid():
                data = form.cleaned_data
                data["public_url"] = (
                    origin(data["public_url"]) if data["public_url"] else ""
                )
                if data["public_url"] != project.seo_settings.get("public_url", ""):
                    project.seo_verified_at = None
                    project.seo_domain_token = token()
                    SearchConnection.objects.filter(project=project).delete()
                project.seo_settings = {
                    **project.seo_settings,
                    **data,
                    "article_path": data["blog_path"],
                }
                project.save()
                return redirect("analytics_hub", pk=pk, tab=tab)
        elif operation == "connect":
            if not oauth_ready():
                raise ValidationError(
                    "The platform administrator must configure Google OAuth first."
                )
            state, verifier = secrets.token_urlsafe(32), secrets.token_urlsafe(48)
            request.session["google_oauth"] = {
                "state": state,
                "verifier": verifier,
                "project": project.pk,
                "time": timezone.now().timestamp(),
                "service": "analytics",
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
                        "scope": ANALYTICS_SCOPE,
                        "state": state,
                        "code_challenge": challenge,
                        "code_challenge_method": "S256",
                        "access_type": "offline",
                        "prompt": "consent",
                    }
                )
            )
        elif operation == "disconnect":
            if connection:
                connection.delete()
            return redirect("analytics_hub", pk=pk, tab=tab)
        elif operation in ["properties", "select", "report"]:
            if not connection:
                raise ValidationError("Connect Google Analytics first.")
            if operation in ["properties", "select"]:
                properties = analytics_properties(connection)
            if operation == "select":
                selected = request.POST.get("property", "")
                if selected not in [p["property"] for p in properties]:
                    raise ValidationError(
                        "Choose a property available to your Google account."
                    )
                connection.property, connection.report = selected, {}
                connection.save()
            if operation == "report":
                if not re.fullmatch(r"properties/\d+", connection.property):
                    raise ValidationError("Select a GA4 property first.")
                start, end = (
                    date.fromisoformat(request.POST.get("start_date", "")),
                    date.fromisoformat(request.POST.get("end_date", "")),
                )
                if (
                    start > end
                    or (end - start).days > 90
                    or end >= timezone.localdate()
                ):
                    raise ValidationError(
                        "Use at most 90 days, ending yesterday or earlier."
                    )
                payload = {
                    "dateRanges": [{"startDate": str(start), "endDate": str(end)}],
                    "metrics": [
                        {"name": n}
                        for n in [
                            "activeUsers",
                            "sessions",
                            "screenPageViews",
                            "engagementRate",
                        ]
                    ],
                    "dimensions": [{"name": "pagePath"}],
                    "limit": 25,
                    "orderBys": [
                        {"metric": {"metricName": "screenPageViews"}, "desc": True}
                    ],
                }
                result = google_request(
                    connection,
                    "POST",
                    "https://analyticsdata.googleapis.com/v1beta/"
                    + connection.property
                    + ":runReport",
                    payload,
                )
                rows = [
                    {
                        "path": (r.get("dimensionValues") or [{}])[0].get("value", ""),
                        "metrics": [
                            v.get("value", "") for v in r.get("metricValues", [])
                        ],
                    }
                    for r in result.get("rows", [])
                ]
                source_result = google_request(
                    connection,
                    "POST",
                    "https://analyticsdata.googleapis.com/v1beta/"
                    + connection.property
                    + ":runReport",
                    {
                        "dateRanges": payload["dateRanges"],
                        "dimensions": [{"name": "sessionSourceMedium"}],
                        "metrics": [{"name": "sessions"}],
                        "limit": 25,
                        "orderBys": [
                            {"metric": {"metricName": "sessions"}, "desc": True}
                        ],
                    },
                )
                sources = [
                    {
                        "source": (r.get("dimensionValues") or [{}])[0].get(
                            "value", ""
                        ),
                        "sessions": (r.get("metricValues") or [{}])[0].get("value", ""),
                    }
                    for r in source_result.get("rows", [])
                ]
                connection.report = {
                    "start": str(start),
                    "end": str(end),
                    "rows": rows,
                    "sources": sources,
                    "fetched_at": timezone.now().isoformat(),
                }
                connection.save()
        else:
            raise ValidationError("Unknown action.")
    return render(
        request,
        "portal/analytics_hub.html",
        {
            "project": project,
            "tab": tab,
            "connection": connection,
            "properties": properties,
            "form": form,
            "google_oauth_configured": oauth_ready(),
            "default_start": (timezone.localdate() - timedelta(days=28)).isoformat(),
            "default_end": (timezone.localdate() - timedelta(days=1)).isoformat(),
        },
    )


@page
def sitemap_download(request, pk):
    project = project_for(request, pk, "manage")
    from .views import api_sitemap

    response = api_sitemap.__wrapped__(request, project)
    if response.status_code == 200:
        response["Content-Disposition"] = 'attachment; filename="sitemap.xml"'
    return response
