import json
from pathlib import Path
from datetime import datetime
from django.core.management.base import BaseCommand, CommandError
from django.core.management.color import no_style
from django.db import transaction, connection
from django.utils import timezone
from django.utils.dateparse import parse_datetime
from portal import models as m


class Command(BaseCommand):
    help = "Validate a private Laravel JSON export. Use --apply only against a fresh Django database; original tables are never modified."

    def add_arguments(self, parser):
        parser.add_argument("file")
        parser.add_argument("--apply", action="store_true")

    def handle(self, *args, **options):
        payload = json.loads(Path(options["file"]).read_text())
        if payload.get("format") != "blogsaas-laravel-export-v1":
            raise CommandError("Unknown export format.")
        tables = payload["tables"]
        if not options["apply"]:
            for table, rows in tables.items():
                self.stdout.write(f"{table}: {len(rows)} rows")
            self.stdout.write(
                "Validation only. Use --apply on an empty Django database after backing up both applications."
            )
            return
        if m.User.objects.exists() or m.Project.objects.exists():
            raise CommandError("Refusing to import into a populated Django database.")
        mapping = [
            ("users", m.User),
            ("workspaces", m.Workspace),
            ("workspace_user", m.Membership),
            ("projects", m.Project),
            ("categories", m.Category),
            ("blogs", m.Blog),
            ("blog_sections", m.BlogSection),
            ("blog_autosaves", m.BlogAutosave),
            ("seo_analyses", m.SeoAnalysis),
            ("seo_reviews", m.SeoReview),
            ("seo_links", m.Link),
            ("seo_link_edits", m.LinkEdit),
            ("seo_redirects", m.RedirectRule),
            ("seo_change_batches", m.ChangeBatch),
            ("seo_404_events", m.NotFoundEvent),
            ("search_connections", m.SearchConnection),
            ("search_reports", m.SearchReport),
            ("search_inspections", m.SearchInspection),
            ("ai_profiles", m.AiProfile),
            ("ai_profile_versions", m.AiProfileVersion),
            ("ai_settings", m.AiSettings),
            ("ai_usage_months", m.AiUsage),
            ("ai_runs", m.AiRun),
        ]
        imported = []
        currents = {}
        with transaction.atomic():
            for table, model in mapping:
                for source in tables.get(table, []):
                    row = source.copy()
                    if model is m.User:
                        currents[row["id"]] = row.pop("current_workspace_id", None)
                        row["username"] = row["email"].lower()
                        row["first_name"] = row.pop("name", "")[:150]
                        if row.get("password", "").startswith(("$2y$", "$2b$", "$2a$")):
                            row["password"] = "bcrypt$" + row["password"]
                        elif not row.get("password", "").startswith(
                            ("pbkdf2_", "bcrypt$")
                        ):
                            row["password"] = "!migration-reset-required"
                        row["is_active"] = True
                    if model is m.Project:
                        row["subdomain"] = (
                            row.get("subdomain") or f"project-{row['id']}"
                        )
                    if model is m.Membership and not row.get("id"):
                        row.pop("id", None)
                    if model is m.SearchConnection:
                        expiry = parse_datetime(str(row.get("expires_at", "")))
                        row["tokens"] = {
                            "access_token": row.get("access_token", ""),
                            "refresh_token": row.get("refresh_token", ""),
                            "expires_at": expiry.timestamp() if expiry else 0,
                        }
                    if model is m.AiRun:
                        row["profile_version_id"] = row.get("profile_version_id")
                        row.setdefault("result", {})
                        row["result"] = row.get("result") or {}
                        row["input"] = row.get("input") or {}
                        row["platform"] = row.get("platform") or ""
                        row["detail"] = row.get("detail") or ""
                        row["model"] = row.get("model") or ""
                        row["request_id"] = row.get("request_id") or ""
                        row["usage"] = row.get("usage") or {}
                        if row.get("state") in ["pending", "running"]:
                            row["state"] = "failed"
                            row["detail"] = "Imported task: not retried automatically."
                    if model is m.SeoAnalysis:
                        row["report"] = row.get("report") or {
                            k: row[k]
                            for k in ["seo_score", "readability_score", "checks"]
                            if k in row
                        }
                    if model is m.SeoReview:
                        row["note"] = row.get("note") or row.get("feedback") or ""
                        row["state"] = row.get("state") or "reviewed"
                    if model is m.ChangeBatch:
                        row["kind"] = row.get("type", row.get("kind"))
                        row["changes"] = row.get("changes") or []
                        row["reason"] = row.get("reason") or "Imported change history"
                    values = {}
                    for field in model._meta.fields:
                        key = field.attname
                        if key not in row:
                            continue
                        value = row[key]
                        if field.get_internal_type() in [
                            "JSONField",
                            "EncryptedJSONField",
                        ] and isinstance(value, str):
                            try:
                                value = json.loads(value)
                            except ValueError:
                                raise CommandError(
                                    f"Invalid JSON in {table}.{key}; import rolled back."
                                )
                        if value is None and not field.null:
                            if field.get_internal_type() in [
                                "CharField",
                                "TextField",
                                "SlugField",
                                "EmailField",
                                "URLField",
                            ]:
                                value = ""
                            elif field.has_default():
                                continue
                        if field.get_internal_type() == "DateTimeField" and isinstance(
                            value, str
                        ):
                            value = parse_datetime(value)
                            if value and timezone.is_naive(value):
                                value = timezone.make_aware(value)
                        values[key] = value
                    try:
                        model.objects.create(**values)
                    except Exception as exc:
                        raise CommandError(
                            f"Import failed at {table} row {source.get('id', 'unknown')}; all changes rolled back. Check schema compatibility."
                        ) from exc
                imported.append(model)
                self.stdout.write(f"Imported {table}: {len(tables.get(table, []))}")
            for user_id, workspace_id in currents.items():
                if (
                    workspace_id
                    and m.Membership.objects.filter(
                        user_id=user_id, workspace_id=workspace_id
                    ).exists()
                ):
                    m.User.objects.filter(pk=user_id).update(
                        current_workspace_id=workspace_id
                    )
            for statement in connection.ops.sequence_reset_sql(no_style(), imported):
                with connection.cursor() as cursor:
                    cursor.execute(statement)
        self.stdout.write(
            "Import complete. Copy approved media separately, recompute SEO analysis, and review imported AI/source freshness before enabling live integrations."
        )
