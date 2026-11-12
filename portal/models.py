import hashlib
import secrets
import uuid
from django.contrib.auth.models import AbstractUser
from django.db import models
from django.db.models import Q
from django.utils import timezone
from django.utils.text import slugify
from .fields import EncryptedJSONField


def api_key():
    return "pk_" + secrets.token_hex(24)


def token():
    return secrets.token_urlsafe(32)


def batch_expiry():
    return timezone.now() + __import__("datetime").timedelta(hours=1)


class User(AbstractUser):
    username = models.CharField(max_length=254, unique=True)
    email = models.EmailField(unique=True)
    author_name = models.CharField(max_length=255, blank=True)
    author_title = models.CharField(max_length=255, blank=True)
    author_avatar = models.CharField(max_length=500, blank=True)
    current_workspace = models.ForeignKey(
        "Workspace", null=True, blank=True, on_delete=models.SET_NULL, related_name="+"
    )


class Timestamped(models.Model):
    created_at = models.DateTimeField(default=timezone.now)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        abstract = True


class Workspace(Timestamped):
    owner = models.ForeignKey(
        User, on_delete=models.CASCADE, related_name="owned_workspaces"
    )
    name = models.CharField(max_length=255)
    slug = models.SlugField(unique=True, max_length=255)


class Membership(models.Model):
    workspace = models.ForeignKey(
        Workspace, on_delete=models.CASCADE, related_name="memberships"
    )
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name="memberships")
    role = models.CharField(
        max_length=10, choices=[(x, x) for x in ["owner", "admin", "editor", "viewer"]]
    )

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["workspace", "user"], name="unique_membership"
            )
        ]


class Project(Timestamped):
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    workspace = models.ForeignKey(
        Workspace,
        null=True,
        blank=True,
        on_delete=models.CASCADE,
        related_name="projects",
    )
    name = models.CharField(max_length=255)
    description = models.TextField(blank=True)
    subdomain = models.SlugField(max_length=255, unique=True, default=uuid.uuid4)
    custom_domain = models.CharField(max_length=255, blank=True)
    api_key = models.CharField(max_length=100, default=api_key, unique=True)
    seo_settings = models.JSONField(default=dict)
    seo_domain_token = models.CharField(max_length=100, default=token)
    seo_verified_at = models.DateTimeField(null=True, blank=True)
    telemetry_key_hash = models.CharField(max_length=64, blank=True)

    def __str__(self):
        return self.name


class Category(models.Model):
    project = models.ForeignKey(
        Project, on_delete=models.CASCADE, related_name="categories"
    )
    name = models.CharField(max_length=255)
    slug = models.SlugField(max_length=255)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["project", "slug"], name="unique_project_category"
            )
        ]

    def __str__(self):
        return self.name


class BlogQuerySet(models.QuerySet):
    def live(self):
        return self.filter(status__in=["published", "scheduled"]).filter(
            Q(status="published", published_at__isnull=True)
            | Q(published_at__lte=timezone.now())
        )


class Blog(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE, related_name="blogs")
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    category = models.ForeignKey(
        Category, null=True, blank=True, on_delete=models.SET_NULL
    )
    title = models.CharField(max_length=255)
    slug = models.SlugField(max_length=255, unique=True)
    excerpt = models.TextField(blank=True)
    content = models.TextField(blank=True)
    status = models.CharField(
        max_length=12,
        choices=[
            ("draft", "Draft"),
            ("published", "Published"),
            ("scheduled", "Scheduled"),
        ],
        default="draft",
    )
    published_at = models.DateTimeField(null=True, blank=True)
    cover_image = models.CharField(max_length=500, blank=True)
    cover_image_alt = models.CharField(max_length=500, blank=True)
    cover_image_caption = models.TextField(blank=True)
    cover_image_decorative = models.BooleanField(default=False)
    image_credits = models.CharField(max_length=255, blank=True)
    author_name = models.CharField(max_length=255, blank=True)
    author_title = models.CharField(max_length=255, blank=True)
    author_avatar = models.CharField(max_length=500, blank=True)
    seo_settings = models.JSONField(default=dict)
    is_cornerstone = models.BooleanField(default=False)
    content_reviewed_at = models.DateTimeField(null=True, blank=True)
    revision = models.PositiveIntegerField(default=1)
    objects = BlogQuerySet.as_manager()

    class Meta:
        indexes = [models.Index(fields=["project", "status", "published_at"])]

    def save(self, *args, **kwargs):
        from .services import clean_html

        if not self.pk and self.user_id:
            for field, value in {
                "author_name": self.user.author_name or self.user.first_name,
                "author_title": self.user.author_title,
                "author_avatar": self.user.author_avatar,
            }.items():
                if not getattr(self, field):
                    setattr(self, field, value)
        self.content = clean_html(self.content)
        if not self.slug:
            self.slug = (
                (slugify(self.title)[:220] or "story") + "-" + uuid.uuid4().hex[:12]
            )
        super().save(*args, **kwargs)


class BlogSection(models.Model):
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE, related_name="sections")
    text_content = models.TextField(blank=True)
    image_path = models.CharField(max_length=500, blank=True)
    image_alt = models.CharField(max_length=500, blank=True)
    image_caption = models.TextField(blank=True)
    image_decorative = models.BooleanField(default=False)
    order = models.PositiveIntegerField(default=0)

    class Meta:
        ordering = ["order", "id"]

    def save(self, *args, **kwargs):
        from .services import clean_html

        self.text_content = clean_html(self.text_content)
        super().save(*args, **kwargs)


class BlogAutosave(Timestamped):
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE)
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    base_hash = models.CharField(max_length=64)
    version = models.PositiveIntegerField(default=0)
    snapshot = EncryptedJSONField(default=dict)

    class Meta:
        constraints = [
            models.UniqueConstraint(fields=["blog", "user"], name="unique_autosave")
        ]


class SeoAnalysis(Timestamped):
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE, related_name="analyses")
    input_hash = models.CharField(max_length=64)
    report = models.JSONField(default=dict)


class SeoReview(Timestamped):
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE)
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    input_hash = models.CharField(max_length=64)
    note = models.TextField(blank=True)
    state = models.CharField(max_length=20, default="reviewed")


class Link(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE)
    target_blog = models.ForeignKey(
        Blog, null=True, on_delete=models.SET_NULL, related_name="+"
    )
    url = models.URLField(max_length=2048)
    anchor = models.CharField(max_length=500, blank=True)
    status = models.CharField(max_length=30, default="unchecked")
    detail = models.CharField(max_length=500, blank=True)
    checked_at = models.DateTimeField(null=True)


class LinkEdit(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE)
    section = models.ForeignKey(BlogSection, on_delete=models.CASCADE)
    before = models.TextField()
    after_hash = models.CharField(max_length=64)
    reverted_at = models.DateTimeField(null=True)


class RedirectRule(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    source_path = models.CharField(max_length=1000)
    source_hash = models.CharField(max_length=64, editable=False)
    target_path = models.CharField(max_length=1000)
    status_code = models.PositiveSmallIntegerField(default=301)
    enabled = models.BooleanField(default=True)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["project", "source_hash"], name="unique_redirect_hash"
            )
        ]

    def save(self, *args, **kwargs):
        self.source_hash = hashlib.sha256(self.source_path.encode("utf-8")).hexdigest()
        if kwargs.get("update_fields") is not None:
            kwargs["update_fields"] = set(kwargs["update_fields"]) | {"source_hash"}
        super().save(*args, **kwargs)


class ChangeBatch(Timestamped):
    expires_at = models.DateTimeField(default=batch_expiry)
    context_hash = models.CharField(max_length=64, blank=True)
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    kind = models.CharField(max_length=20)
    reason = models.CharField(max_length=500)
    changes = EncryptedJSONField(default=list)
    state = models.CharField(max_length=20, default="preview")


class NotFoundEvent(models.Model):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    path = models.CharField(max_length=1000)
    path_hash = models.CharField(max_length=64, editable=False)
    day = models.DateField(default=timezone.localdate)
    hits = models.PositiveIntegerField(default=1)
    last_seen = models.DateTimeField(default=timezone.now)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["project", "path_hash", "day"], name="unique_404_day_hash"
            )
        ]

    def save(self, *args, **kwargs):
        self.path_hash = hashlib.sha256(self.path.encode("utf-8")).hexdigest()
        if kwargs.get("update_fields") is not None:
            kwargs["update_fields"] = set(kwargs["update_fields"]) | {"path_hash"}
        super().save(*args, **kwargs)


class TelemetryReceipt(models.Model):
    created_at = models.DateTimeField(default=timezone.now)
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    event_id = models.CharField(max_length=100)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["project", "event_id"], name="unique_404_receipt"
            )
        ]


class SearchConnection(Timestamped):
    project = models.OneToOneField(Project, on_delete=models.CASCADE)
    tokens = EncryptedJSONField(default=dict)
    property = models.CharField(max_length=2048, blank=True)
    origin = models.URLField(max_length=2048)
    state = models.CharField(max_length=20, default="connected")


class SearchReport(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    connection = models.ForeignKey(SearchConnection, on_delete=models.CASCADE)
    start_date = models.DateField()
    end_date = models.DateField()
    rows = models.JSONField(default=list)
    state = models.CharField(max_length=20, default="pending")
    detail = models.CharField(max_length=500, blank=True)


class SearchInspection(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    blog = models.ForeignKey(Blog, on_delete=models.CASCADE)
    connection = models.ForeignKey(SearchConnection, on_delete=models.CASCADE)
    result = models.JSONField(default=dict)
    state = models.CharField(max_length=20, default="pending")


class AiProfile(Timestamped):
    project = models.OneToOneField(
        Project, on_delete=models.CASCADE, related_name="ai_profile"
    )
    data = EncryptedJSONField(default=dict)
    revision = models.PositiveIntegerField(default=1)


class AiProfileVersion(Timestamped):
    project = models.ForeignKey(
        Project, on_delete=models.CASCADE, related_name="ai_versions"
    )
    approved_by = models.ForeignKey(User, on_delete=models.CASCADE)
    revision = models.PositiveIntegerField()
    data = EncryptedJSONField(default=dict)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["project", "revision"], name="unique_ai_approval"
            )
        ]


class AiSettings(models.Model):
    scope = models.CharField(max_length=100, unique=True)
    enabled = models.BooleanField(default=False)
    monthly_limit = models.PositiveIntegerField(default=20)


class AiUsage(models.Model):
    scope = models.CharField(max_length=100)
    month = models.CharField(max_length=7)
    requests = models.PositiveIntegerField(default=0)

    class Meta:
        constraints = [
            models.UniqueConstraint(fields=["scope", "month"], name="unique_ai_usage")
        ]


class AiRun(Timestamped):
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    profile_version = models.ForeignKey(
        AiProfileVersion, null=True, on_delete=models.CASCADE
    )
    blog = models.ForeignKey(Blog, null=True, blank=True, on_delete=models.CASCADE)
    output_blog = models.ForeignKey(
        Blog, null=True, on_delete=models.SET_NULL, related_name="+"
    )
    action = models.CharField(max_length=30)
    platform = models.CharField(max_length=20, blank=True)
    request_key = models.UUIDField(default=uuid.uuid4, unique=True)
    fingerprint = models.CharField(max_length=64)
    state = models.CharField(max_length=20, default="pending")
    input = EncryptedJSONField(default=dict)
    result = EncryptedJSONField(default=dict)
    edited = EncryptedJSONField(null=True)
    revision = models.PositiveIntegerField(default=1)
    reviewed_at = models.DateTimeField(null=True)
    model = models.CharField(max_length=100, blank=True)
    request_id = models.CharField(max_length=255, blank=True)
    usage = models.JSONField(default=dict)
    detail = models.CharField(max_length=500, blank=True)


class WorkItem(Timestamped):
    kind = models.CharField(max_length=30)
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    project = models.ForeignKey(Project, on_delete=models.CASCADE)
    payload = EncryptedJSONField(default=dict)
    state = models.CharField(max_length=20, default="pending")
    detail = models.CharField(max_length=500, blank=True)


class RateBucket(models.Model):
    key = models.CharField(max_length=64, unique=True)
    count = models.PositiveIntegerField(default=0)


class AnalyticsConnection(Timestamped):
    project = models.OneToOneField(Project, on_delete=models.CASCADE)
    tokens = EncryptedJSONField(default=dict)
    property = models.CharField(max_length=100, blank=True)
    report = models.JSONField(default=dict)
