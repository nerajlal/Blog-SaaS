"""Bounded, preview-first import of the exported post workbook format."""

import base64
import html
import hashlib
import json
import posixpath
import re
import zipfile
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone as dt_timezone
from html.parser import HTMLParser
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError
from django import forms
from django.core.exceptions import ValidationError
from django.core.files.uploadedfile import SimpleUploadedFile
from django.core.files.storage import default_storage
from django.db import transaction
from django.conf import settings
from django.utils import timezone
from django.utils.html import strip_tags
from django.utils.text import slugify
from .models import Blog, BlogSection, Category, Project, PostImport, User
from .services import (
    clean_html,
    public_url,
    fetch_public,
    save_image,
    analyze,
    can_access,
)

NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
ZONES = [
    ("Asia/Kolkata", "India (Asia/Kolkata)"),
    ("UTC", "UTC"),
    ("Europe/London", "London"),
    ("America/New_York", "New York"),
    ("America/Los_Angeles", "Los Angeles"),
    ("Australia/Sydney", "Sydney"),
]


class UploadForm(forms.Form):
    recover_html = forms.BooleanField(
        required=False,
        initial=True,
        label="Recover truncated HTML using the plain-text column",
        help_text="Rows with incomplete HTML use their plain text and show a warning. Broken embedded images are left blank. Uncheck to skip those rows instead.",
    )
    workbook = forms.FileField(label="Excel workbook (.xlsx)")
    date_timezone = forms.ChoiceField(
        choices=ZONES, initial="Asia/Kolkata", label="Timezone of dates in the file"
    )
    authors = forms.ChoiceField(
        choices=[
            ("profile", "Use my current author profile"),
            ("file", "Preserve exported author details"),
        ],
        label="Author details",
    )

    def clean_workbook(self):
        f = self.cleaned_data["workbook"]
        if not f.name.lower().endswith(".xlsx") or f.size > 5 * 1024 * 1024:
            raise ValidationError("Upload an .xlsx file up to 5 MB.")
        return f


def xml(data):
    if b"<!DOCTYPE" in data.upper() or b"<!ENTITY" in data.upper():
        raise ValidationError("This workbook contains unsupported XML definitions.")
    return ET.fromstring(data)


def read_rows(upload):
    try:
        with zipfile.ZipFile(upload) as archive:
            info = archive.infolist()
            if (
                len(info) > 1000
                or sum(x.file_size for x in info) > 20 * 1024 * 1024
                or any(x.flag_bits & 1 for x in info)
            ):
                raise ValidationError(
                    "Workbook is too large or encrypted. Export a smaller, unprotected workbook."
                )
            strings = []
            if "xl/sharedStrings.xml" in archive.namelist():
                strings = [
                    "".join(n.text or "" for n in item.findall(".//s:t", NS))
                    for item in xml(archive.read("xl/sharedStrings.xml")).findall(
                        "s:si", NS
                    )
                ]
            workbook = xml(archive.read("xl/workbook.xml"))
            properties = workbook.find("s:workbookPr", NS)
            epoch = (
                datetime(1904, 1, 1)
                if properties is not None
                and properties.get("date1904") in ["1", "true"]
                else datetime(1899, 12, 30)
            )
            relations = {
                r.get("Id"): r.get("Target")
                for r in xml(archive.read("xl/_rels/workbook.xml.rels"))
                if r.get("TargetMode") != "External"
            }
            sheet = workbook.find("s:sheets/s:sheet", NS)
            target = relations[
                sheet.get(
                    "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id"
                )
            ]
            path = posixpath.normpath(
                target.lstrip("/") if target.startswith("/") else "xl/" + target
            )
            if not path.startswith("xl/worksheets/"):
                raise ValidationError("Unsupported worksheet location.")
            root = xml(archive.read(path))
            rows = []
            cells_count = 0
            for row in root.findall("s:sheetData/s:row", NS):
                values = {}
                for cell in row:
                    cells_count += 1
                    if cells_count > 10000:
                        raise ValidationError(
                            "Workbook contains too many cells. Maximum 100 posts per import."
                        )
                    if cell.find("s:f", NS) is not None:
                        raise ValidationError(
                            "Formulas are not supported. Export cell values only."
                        )
                    address = cell.get("r", "")
                    if not re.fullmatch(r"[A-Z]{1,2}[1-9][0-9]*", address):
                        raise ValidationError("Unsupported cell address.")
                    value = cell.find("s:v", NS)
                    value = value.text or "" if value is not None else ""
                    if cell.get("t") == "s":
                        value = strings[int(value)]
                    elif cell.get("t") == "inlineStr":
                        value = "".join(
                            n.text or "" for n in cell.findall(".//s:t", NS)
                        )
                    if len(value) > 200000:
                        raise ValidationError("A workbook cell is too large.")
                    values[re.sub(r"[0-9]", "", address)] = value
                if any(values.values()):
                    rows.append((row.get("r"), values))
                if len(rows) > 101:
                    raise ValidationError("Import at most 100 posts at a time.")
            if len(rows) < 2:
                raise ValidationError("The workbook contains no posts.")
            header = {column: name.strip() for column, name in rows[0][1].items()}
            if len(set(header.values())) != len(header):
                raise ValidationError("Column names must be unique.")
            if not {"Title", "Slug"}.issubset(header.values()) or not {
                "Content (HTML)",
                "Content (Plain Text)",
            }.intersection(header.values()):
                raise ValidationError(
                    "Expected Title, Slug and Content (HTML) or Content (Plain Text) columns."
                )
            return [
                (int(number), {header.get(k, k): v for k, v in values.items()})
                for number, values in rows[1:]
            ], epoch
    except (
        zipfile.BadZipFile,
        ET.ParseError,
        KeyError,
        IndexError,
        ValueError,
        TypeError,
        OSError,
        RuntimeError,
    ) as exc:
        raise ValidationError(
            "Cannot read this workbook. Upload an unprotected .xlsx export using the expected columns."
        ) from exc


def date_value(value, zone, epoch):
    if not value.strip():
        return None
    try:
        dt = (
            epoch + timedelta(days=float(value))
            if re.fullmatch(r"\d+(\.\d+)?", value.strip())
            else datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
        )
        if not 1970 <= dt.year <= 2100:
            raise ValueError()
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=ZoneInfo(zone))
            if dt.astimezone(dt_timezone.utc).astimezone(ZoneInfo(zone)).replace(
                tzinfo=None
            ) != dt.replace(tzinfo=None):
                raise ValueError()
        return dt.isoformat()
    except (ValueError, OverflowError, ZoneInfoNotFoundError) as exc:
        raise ValidationError(
            "Invalid date. Use YYYY-MM-DD HH:MM:SS and select the correct timezone."
        ) from exc


class ContentParts(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.parts = [{"html": "", "image": "", "alt": "", "caption": ""}]
        self.in_caption = False
        self.blocked = 0

    def handle_starttag(self, tag, attrs):
        if tag in ["script", "style", "iframe", "object", "svg", "template"]:
            self.blocked += 1
        if self.blocked:
            return
        values = dict(attrs)
        if tag == "img":
            if values.get("src"):
                self.parts.append(
                    {
                        "html": "",
                        "image": values["src"],
                        "alt": values.get("alt") or "",
                        "caption": "",
                    }
                )
        elif tag == "figcaption" and len(self.parts) > 1:
            self.in_caption = True
        else:
            self.parts[-1]["html"] += self.get_starttag_text()

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if self.blocked:
            if tag in ["script", "style", "iframe", "object", "svg", "template"]:
                self.blocked -= 1
            return
        if tag == "figcaption":
            self.in_caption = False
        else:
            self.parts[-1]["html"] += "</" + tag + ">"

    def handle_data(self, data):
        if not self.blocked:
            key = "caption" if self.in_caption else "html"
            self.parts[-1][key] += data

    def handle_entityref(self, name):
        self.handle_data("&" + name + ";")

    def handle_charref(self, name):
        self.handle_data("&#" + name + ";")


def image_ref(value):
    if not value:
        return ""
    if value.startswith("data:"):
        match = re.fullmatch(
            r"data:image/(?:png|jpeg|webp|gif);base64,([A-Za-z0-9+/=\r\n]+)", value
        )
        if not match or len(value) > 3 * 1024 * 1024:
            raise ValidationError("Invalid or oversized embedded image.")
        try:
            data = base64.b64decode(re.sub(r"\s+", "", match.group(1)), validate=True)
        except ValueError as exc:
            raise ValidationError("Embedded image is incomplete or invalid.") from exc
        if len(data) > 2 * 1024 * 1024:
            raise ValidationError("Embedded image exceeds 2 MB.")
        return value
    url = public_url(value.strip())
    if not url.startswith("https://"):
        raise ValidationError("Image URLs must use public HTTPS addresses.")
    return url


def preview(upload, project, zone, recover_html=True):
    rows, epoch = read_rows(upload)
    output, seen = [], set()
    for number, source in rows:
        row = {
            "row": number,
            "title": source.get("Title", "").strip(),
            "slug": source.get("Slug", "").strip(),
            "errors": [],
            "warnings": [],
        }
        try:
            if not row["title"] or len(row["title"]) > 255:
                raise ValidationError(
                    "Title is required and must be at most 255 characters."
                )
            if not row["slug"]:
                row["slug"] = slugify(row["title"])[:240]
            if not re.fullmatch(r"[-a-zA-Z0-9_]{1,255}", row["slug"]):
                raise ValidationError(
                    "Slug must contain letters, numbers, hyphens or underscores."
                )
            if row["slug"] in seen:
                raise ValidationError("Duplicate slug within this workbook.")
            seen.add(row["slug"])
            existing = Blog.objects.filter(slug=row["slug"]).first()
            if existing:
                if existing.project_id != project.pk:
                    raise ValidationError(
                        "Slug is already used by another project. Change it in the file."
                    )
                row["skip"] = True
                row["warnings"].append(
                    "Already exists in this project; will be skipped."
                )
            category = source.get("Category", "").strip() or "General"
            if len(category) > 255:
                raise ValidationError("Category must be at most 255 characters.")
            markup = source.get("Content (HTML)", "").strip()
            if not markup:
                plain = source.get("Content (Plain Text)", "").strip()
                markup = "".join(
                    "<p>" + html.escape(p).replace("\n", "<br>") + "</p>"
                    for p in re.split(r"\n\s*\n", plain)
                    if p
                )
            parser = ContentParts()
            parser.feed(markup)
            parser.close()
            expected_images = len(re.findall(r"<img\b", markup, re.I))
            if expected_images > sum(bool(p["image"]) for p in parser.parts):
                plain = source.get("Content (Plain Text)", "").strip()
                if not recover_html or not plain:
                    raise ValidationError(
                        "HTML contains incomplete image data. Enable plain-text recovery or repair this row before importing."
                    )
                markup = "".join(
                    "<p>" + html.escape(p).replace("\n", "<br>") + "</p>"
                    for p in re.split(r"\n\s*\n", plain)
                    if p
                )
                parser = ContentParts()
                parser.feed(markup)
                parser.close()
                row["warnings"].append(
                    "Incomplete HTML image data: recovered the full article from plain text and left the broken embedded images blank. Original rich formatting could not be recovered."
                )
            if not strip_tags(clean_html(markup)).strip() and not any(
                p["image"] for p in parser.parts
            ):
                raise ValidationError("Post content is empty.")
            parts = parser.parts
            urls = {image_ref(p["image"]) for p in parts if p["image"]}
            for value in re.split(r"[\r\n,;]+", source.get("Section Images", "")):
                if value.strip():
                    url = image_ref(value)
                    if url not in urls:
                        parts.append(
                            {"html": "", "image": url, "alt": "", "caption": ""}
                        )
                        urls.add(url)
            for p in parts:
                p["html"] = clean_html(p["html"])
                p["image"] = image_ref(p["image"])
                p["alt"] = html.unescape(p["alt"])[:500]
                p["caption"] = html.unescape(p["caption"])
            cover, avatar = (
                image_ref(source.get("Cover Image", "")),
                image_ref(source.get("Author Avatar", "")),
            )
            if len(parts) > 21:
                raise ValidationError("A post may contain at most 20 section images.")
            status = source.get("Status", "Published").strip().lower() or "published"
            if status not in ["published", "draft", "scheduled"]:
                raise ValidationError("Status must be Published, Draft or Scheduled.")
            published = date_value(source.get("Published At", ""), zone, epoch)
            if status == "scheduled" and not published:
                raise ValidationError("Scheduled posts require a publication date.")
            if (
                status == "published"
                and published
                and datetime.fromisoformat(published) > timezone.now()
            ):
                status = "scheduled"
                row["warnings"].append(
                    "Future publication date: this post will be scheduled."
                )
            excerpt = (
                source.get("Excerpt", "").strip()
                or " ".join(
                    html.unescape(
                        strip_tags(" ".join(part["html"] for part in parts))
                    ).split()
                )[:240]
            )
            if len(excerpt) > 1000 or len(source.get("Image Credits", "")) > 255:
                raise ValidationError(
                    "Excerpt or image credits exceed the supported length."
                )
            if (
                len(source.get("Author Name", "")) > 255
                or len(source.get("Author Title", "")) > 255
            ):
                raise ValidationError("Author details are too long.")
            row.update(
                category=category,
                status=status,
                published_at=published,
                created_at=date_value(source.get("Created At", ""), zone, epoch),
                updated_at=date_value(source.get("Updated At", ""), zone, epoch),
                parts=parts,
                cover=cover,
                avatar=avatar,
                author_name=source.get("Author Name", ""),
                author_title=source.get("Author Title", ""),
                excerpt=excerpt,
                credits=source.get("Image Credits", ""),
                image_count=len(urls) + bool(cover),
            )
        except ValidationError as exc:
            row["errors"] += exc.messages
        output.append(row)
    if len(json.dumps(output).encode()) > 5 * 1024 * 1024:
        raise ValidationError("Post content exceeds the 5 MB import limit.")
    return output


def download_image(url, folder, saved, cache):
    if not url:
        return ""
    if (folder, url) in cache:
        return cache[(folder, url)]
    if url.startswith("data:"):
        data = base64.b64decode(re.sub(r"\s+", "", url.split(",", 1)[1]), validate=True)
    else:
        response = fetch_public(url, binary=True)
        if response["status"] != 200:
            raise ValidationError(
                "An image could not be downloaded. Check its URL and availability."
            )
        data = response["body"]
    path = save_image(SimpleUploadedFile("image", data), folder)
    saved.append(path)
    cache[(folder, url)] = path
    return path


def store_result(batch, index, result):
    if index in batch.results:
        return
    batch.results = {**batch.results, index: result}
    if len(batch.results) >= batch.payload["total"]:
        batch.payload = {**batch.payload, "rows": []}
    batch.save(update_fields=["results", "payload", "updated_at"])


def process_row(item):
    batch = PostImport.objects.get(
        pk=item.payload["import_id"], project=item.project, user=item.user
    )
    index = str(item.payload["index"])
    if not batch.confirmed_at or index in batch.results:
        return
    row = batch.payload["rows"][int(index)]
    saved, cache = [], {}
    result = {"title": row["title"]}
    try:
        if not can_access(item.user, item.project, "update"):
            raise ValidationError("Workspace access is no longer available.")
        if Blog.objects.filter(slug=row["slug"]).exists():
            result.update(
                state="skipped", detail="Slug already exists; no post was changed."
            )
        else:
            cover = download_image(row["cover"], "banners", saved, cache)
            avatar = (
                download_image(row["avatar"], "avatars", saved, cache)
                if batch.payload["authors"] == "file"
                else ""
            )
            parts = [
                {
                    **p,
                    "local_image": download_image(p["image"], "sections", saved, cache),
                }
                for p in row["parts"]
            ]
            with transaction.atomic():
                Project.objects.select_for_update().get(pk=item.project_id)
                if not can_access(
                    User.objects.get(pk=item.user_id), item.project, "update"
                ):
                    raise ValidationError("Workspace access is no longer available.")
                if Blog.objects.filter(slug=row["slug"]).exists():
                    raise ValidationError(
                        "Slug was added while importing; no post was changed."
                    )
                category = Category.objects.filter(
                    project=item.project, name__iexact=row["category"]
                ).first()
                if not category:
                    category_slug = slugify(row["category"])[:220] or "category"
                    if (
                        Category.objects.filter(
                            project=item.project, slug=category_slug
                        ).exists()
                        or category_slug == "category"
                    ):
                        category_slug += (
                            "-"
                            + hashlib.sha256(row["category"].encode()).hexdigest()[:12]
                        )
                    category, _ = Category.objects.get_or_create(
                        project=item.project,
                        slug=category_slug,
                        defaults={"name": row["category"]},
                    )
                author = User.objects.get(pk=item.user_id)
                now = timezone.now()
                blog = Blog.objects.create(
                    project=item.project,
                    user=author,
                    title=row["title"],
                    slug=row["slug"],
                    category=category,
                    content=parts[0]["html"],
                    excerpt=row["excerpt"],
                    cover_image=cover,
                    image_credits=row["credits"],
                    status=row["status"],
                    published_at=datetime.fromisoformat(row["published_at"])
                    if row["published_at"] and row["status"] != "draft"
                    else now
                    if row["status"] == "published"
                    else None,
                    created_at=datetime.fromisoformat(row["created_at"])
                    if row["created_at"]
                    else now,
                )
                if batch.payload["authors"] == "file":
                    Blog.objects.filter(pk=blog.pk).update(
                        author_name=row["author_name"],
                        author_title=row["author_title"],
                        author_avatar=avatar,
                        author_bio="",
                        author_social_links={},
                    )
                    blog.refresh_from_db()
                for order, part in enumerate(parts[1:]):
                    BlogSection.objects.create(
                        blog=blog,
                        text_content=part["html"],
                        image_path=part["local_image"],
                        image_alt=part["alt"],
                        image_caption=part["caption"],
                        order=order,
                    )
                if row["updated_at"]:
                    Blog.objects.filter(pk=blog.pk).update(
                        updated_at=datetime.fromisoformat(row["updated_at"])
                    )
                analyze(blog)
                result.update(state="created", detail="Post imported.", blog_id=blog.pk)
                locked = PostImport.objects.select_for_update().get(pk=batch.pk)
                store_result(locked, index, result)
            return
    except Exception as exc:
        result.update(
            state="failed",
            detail=" ".join(exc.messages)[:400]
            if isinstance(exc, ValidationError)
            else "Import failed for this row. No post was created; check the images and retry.",
        )
    for path in saved:
        default_storage.delete(path.removeprefix(settings.MEDIA_URL))
    with transaction.atomic():
        locked = PostImport.objects.select_for_update().get(pk=batch.pk)
        store_result(locked, index, result)
