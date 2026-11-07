import time
from datetime import timedelta
from django.core.management.base import BaseCommand
from django.db import transaction, connections
from django.utils import timezone
from portal.models import WorkItem, AiRun, SearchReport, SearchInspection
from portal.tasks import execute


class Command(BaseCommand):
    help = "Run one database worker. Jobs are never automatically retried after ambiguous provider failures."

    def add_arguments(self, parser):
        parser.add_argument("--once", action="store_true")
        parser.add_argument("--sleep", type=float, default=2)

    def handle(self, *args, **options):
        while True:
            pending_cutoff = timezone.now() - timedelta(minutes=30)
            AiRun.objects.filter(state="pending", created_at__lt=pending_cutoff).update(
                state="failed",
                detail="Pending task expired. Start a new request after checking the worker.",
            )
            WorkItem.objects.filter(
                state="pending", created_at__lt=pending_cutoff
            ).update(state="failed", detail="Pending task expired without execution.")
            cutoff = timezone.now() - timedelta(minutes=10)
            WorkItem.objects.filter(state="running", updated_at__lt=cutoff).update(
                state="failed",
                detail="Worker expired; check provider usage before retrying.",
            )
            AiRun.objects.filter(state="running", updated_at__lt=cutoff).update(
                state="failed",
                detail="Worker expired. Content retained; check provider usage before retrying.",
            )
            with transaction.atomic():
                item = WorkItem.objects.filter(state="pending").order_by("id").first()
                claimed = item and WorkItem.objects.filter(
                    pk=item.pk, state="pending"
                ).update(state="running", updated_at=timezone.now())
            if claimed:
                try:
                    execute(item)
                    state = "complete"
                    detail = ""
                except Exception:
                    state = "failed"
                    detail = "Task failed. Verify configuration, permissions, upstream access, and input. No automatic retry."
                    if item.kind == "ai":
                        AiRun.objects.filter(
                            pk=item.payload.get("run_id"),
                            state__in=["pending", "running"],
                        ).update(state="failed", detail=detail)
                    if item.kind == "search_report":
                        SearchReport.objects.filter(pk=item.payload.get("id")).update(
                            state="failed", detail=detail
                        )
                    if item.kind == "search_inspect":
                        SearchInspection.objects.filter(
                            pk=item.payload.get("id")
                        ).update(state="failed")
                WorkItem.objects.filter(pk=item.pk).update(
                    state=state, detail=detail, updated_at=timezone.now()
                )
            if options["once"]:
                return
            connections.close_all()
            if not claimed:
                time.sleep(max(0.2, options["sleep"]))
