import json
import time
from datetime import timedelta
from urllib.parse import quote
import requests
from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import transaction
from django.utils import timezone
from .models import *
from .services import can_access, fetch_public, article_url, rebuild_links
from . import ai


def google_request(connection, method, url, payload=None):
    tokens = connection.tokens.copy()
    if tokens.get("expires_at", 0) < time.time() + 60:
        response = requests.post(
            "https://oauth2.googleapis.com/token",
            data={
                "client_id": settings.GOOGLE_CLIENT_ID,
                "client_secret": settings.GOOGLE_CLIENT_SECRET,
                "refresh_token": tokens.get("refresh_token"),
                "grant_type": "refresh_token",
            },
            timeout=15,
        )
        response.raise_for_status()
        fresh = response.json()
        tokens.update(fresh)
        tokens["expires_at"] = time.time() + fresh.get("expires_in", 3600)
        connection.tokens = tokens
        connection.save(update_fields=["tokens", "updated_at"])
    response = requests.request(
        method,
        url,
        headers={"Authorization": "Bearer " + tokens["access_token"]},
        json=payload,
        timeout=25,
        allow_redirects=False,
    )
    response.raise_for_status()
    return response.json()


def execute(item):
    if not can_access(
        item.user,
        item.project,
        "manage" if item.kind.startswith("search") else "update",
    ):
        raise ValidationError("Access changed.")
    if item.kind == "ai":
        run = AiRun.objects.filter(pk=item.payload["run_id"], state="pending").first()
        if not run:
            return
        ability = "manage" if run.action == "analyze" else "update"
        config = AiSettings.objects.filter(
            scope=ai.scope(run.project), enabled=True
        ).first()
        if (
            not config
            or ai.stale(run)
            or not can_access(item.user, item.project, ability)
            or item.payload["scope"] != ai.scope(run.project)
        ):
            run.state = "cancelled"
            run.save()
            return
        run.state = "running"
        run.save()
        try:
            result, usage, request_id = ai.generate(run)
            with transaction.atomic():
                current = AiRun.objects.select_for_update().filter(pk=run.pk).first()
                if not current or current.state != "running":
                    return
                valid = (
                    can_access(item.user, item.project, ability)
                    and not ai.stale(current)
                    and AiSettings.objects.filter(
                        scope=ai.scope(item.project), enabled=True
                    ).exists()
                )
                current.result = result
                current.usage = usage
                current.request_id = request_id
                current.state = "complete" if valid else "cancelled"
                current.save()
        except Exception as error:
            AiRun.objects.filter(pk=run.pk, state="running").update(
                state="failed",
                detail=ai.failure_detail(error),
            )
            raise
    elif item.kind == "links":
        rebuild_links(item.project)
    elif item.kind == "check_links":
        deadline = time.monotonic() + 35
        for link in Link.objects.filter(project=item.project).order_by("id")[:50]:
            if time.monotonic() > deadline:
                break
            try:
                result = fetch_public(link.url, method="HEAD")
                link.status = (
                    "broken"
                    if result["status"] in [404, 410]
                    else "healthy"
                    if 200 <= result["status"] < 300
                    else "restricted"
                )
                link.detail = str(result["status"])
            except Exception:
                link.status = "unconfirmed"
                link.detail = "Network destination blocked, failed, or timed out."
            link.checked_at = timezone.now()
            link.save()
    elif item.kind.startswith("search"):
        connection = SearchConnection.objects.get(
            project=item.project, state="connected"
        )
        if (
            not item.project.seo_verified_at
            or connection.origin != item.project.seo_settings.get("public_url")
            or connection.property != item.payload.get("property")
            or connection.origin != item.payload.get("origin")
        ):
            raise ValidationError("Domain verification or origin changed.")
        if item.kind == "search_report":
            report = SearchReport.objects.get(
                pk=item.payload["id"], project=item.project, connection=connection
            )
            result = google_request(
                connection,
                "POST",
                "https://www.googleapis.com/webmasters/v3/sites/"
                + quote(connection.property, safe="")
                + "/searchAnalytics/query",
                {
                    "startDate": str(report.start_date),
                    "endDate": str(report.end_date),
                    "dimensions": ["date"],
                    "type": "web",
                    "dataState": "final",
                    "rowLimit": 100,
                    "dimensionFilterGroups": [
                        {
                            "filters": [
                                {
                                    "dimension": "page",
                                    "operator": "includingRegex",
                                    "expression": "^"
                                    + __import__("re").escape(
                                        connection.origin.rstrip("/") + "/"
                                    ),
                                }
                            ]
                        }
                    ],
                },
            )
            connection.refresh_from_db()
            item.project.refresh_from_db()
            if (
                connection.state != "connected"
                or connection.property != item.payload["property"]
                or connection.origin != item.payload["origin"]
                or not item.project.seo_verified_at
                or item.project.seo_settings.get("public_url") != connection.origin
                or not can_access(item.user, item.project, "manage")
            ):
                raise ValidationError("Connection or access changed.")
            SearchReport.objects.filter(pk=report.pk, connection=connection).update(
                rows=result.get("rows", []), state="complete"
            )
        else:
            inspection = SearchInspection.objects.get(
                pk=item.payload["id"], project=item.project, connection=connection
            )
            result = google_request(
                connection,
                "POST",
                "https://searchconsole.googleapis.com/v1/urlInspection/index:inspect",
                {
                    "inspectionUrl": article_url(inspection.blog),
                    "siteUrl": connection.property,
                },
            )
            connection.refresh_from_db()
            item.project.refresh_from_db()
            if (
                connection.state != "connected"
                or connection.property != item.payload["property"]
                or connection.origin != item.payload["origin"]
                or not item.project.seo_verified_at
                or item.project.seo_settings.get("public_url") != connection.origin
                or not can_access(item.user, item.project, "manage")
            ):
                raise ValidationError("Connection or access changed.")
            SearchInspection.objects.filter(
                pk=inspection.pk, connection=connection
            ).update(result=result, state="complete")
