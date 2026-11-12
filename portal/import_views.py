from datetime import timedelta
from urllib.parse import urlsplit
from django.core.exceptions import ValidationError
from django.db import transaction
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone
from django.views.decorators.http import require_http_methods
from .views import page
from .services import project_for, rate_limit
from .models import PostImport, WorkItem
from .excel_import import UploadForm, preview


@page
@require_http_methods(["GET", "POST"])
def upload(request, pk):
    project = project_for(request, pk, "update")
    PostImport.objects.filter(
        project=project,
        user=request.user,
        confirmed_at__isnull=True,
        created_at__lt=timezone.now() - timedelta(hours=24),
    ).delete()
    form = UploadForm(request.POST or None, request.FILES or None)
    if request.method == "POST":
        rate_limit(request, "excel-upload", 5, 60)
        if form.is_valid():
            try:
                file = form.cleaned_data["workbook"]
                rows = preview(
                    file,
                    project,
                    form.cleaned_data["date_timezone"],
                    form.cleaned_data["recover_html"],
                )
                batch = PostImport.objects.create(
                    project=project,
                    user=request.user,
                    filename=file.name[:200],
                    payload={
                        "rows": rows,
                        "total": len(rows),
                        "authors": form.cleaned_data["authors"],
                        "timezone": form.cleaned_data["date_timezone"],
                    },
                )
                return redirect("post_import_preview", pk=pk, batch_id=batch.pk)
            except ValidationError as exc:
                form.add_error("workbook", exc)
    return render(
        request, "portal/import_upload.html", {"project": project, "form": form}
    )


@page
@require_http_methods(["GET", "POST"])
def review(request, pk, batch_id):
    project = project_for(request, pk, "update")
    batch = get_object_or_404(
        PostImport, pk=batch_id, project=project, user=request.user
    )
    if not batch.confirmed_at and batch.created_at < timezone.now() - timedelta(
        hours=24
    ):
        raise ValidationError("This preview expired. Upload the workbook again.")
    if request.method == "POST":
        if request.POST.get("confirm") != "1":
            raise ValidationError("Confirm the preview before importing.")
        with transaction.atomic():
            batch = PostImport.objects.select_for_update().get(pk=batch.pk)
            if not batch.confirmed_at:
                if batch.created_at < timezone.now() - timedelta(hours=24):
                    raise ValidationError("This preview expired. Upload again.")
                ready = [
                    (index, row)
                    for index, row in enumerate(batch.payload["rows"])
                    if not row["errors"] and not row.get("skip")
                ]
                if not ready:
                    raise ValidationError("No new valid posts to import.")
                batch.results = {
                    str(i): {
                        "title": row["title"],
                        "state": "skipped",
                        "detail": "; ".join(row["errors"] or row["warnings"]),
                    }
                    for i, row in enumerate(batch.payload["rows"])
                    if row["errors"] or row.get("skip")
                }
                batch.confirmed_at = timezone.now()
                batch.save(update_fields=["confirmed_at", "results", "updated_at"])
                for index, row in ready:
                    WorkItem.objects.create(
                        kind="excel_import",
                        project=project,
                        user=request.user,
                        import_batch=batch,
                        payload={"import_id": batch.pk, "index": index},
                    )
        return redirect("post_import_preview", pk=pk, batch_id=batch.pk)
    rows = batch.payload["rows"]
    ready = sum(not row["errors"] and not row.get("skip") for row in rows)
    jobs = (
        WorkItem.objects.filter(
            kind="excel_import", project=project, user=request.user, import_batch=batch
        )
        if batch.confirmed_at
        else []
    )
    jobs = list(jobs)
    if any(
        job.state not in ["pending", "running"]
        and str(job.payload.get("index")) not in batch.results
        for job in jobs
    ):
        batch.refresh_from_db(fields=["results", "payload"])
        rows = batch.payload["rows"]
    results = dict(batch.results)
    for job in jobs:
        key = str(job.payload.get("index"))
        if key not in results:
            results[key] = {
                "title": rows[int(key)]["title"] if rows else "Post",
                "state": job.state if job.state in ["pending", "running"] else "failed",
                "detail": "Your import is queued."
                if job.state == "pending"
                else "Processing post and images…"
                if job.state == "running"
                else "Import interrupted. Upload the workbook again; posts already imported will be skipped.",
            }
    counts = {
        state: sum(r["state"] == state for r in results.values())
        for state in ["created", "skipped", "failed", "pending", "running"]
    }
    pending = counts["pending"] + counts["running"]
    image_hosts = sorted(
        {
            urlsplit(url).hostname
            for row in rows
            for url in [
                row.get("cover"),
                row.get("avatar") if batch.payload["authors"] == "file" else "",
                *[p["image"] for p in row.get("parts", [])],
            ]
            if url and url.startswith("https://")
        }
    )
    return render(
        request,
        "portal/import_preview.html",
        {
            "project": project,
            "batch": batch,
            "rows": rows,
            "ready_count": ready,
            "results": results.values(),
            "counts": counts,
            "pending": pending,
            "image_hosts": image_hosts,
        },
    )
