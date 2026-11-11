import io
import json
import tempfile
import uuid
from datetime import timedelta
from unittest.mock import patch, Mock
from django.conf import settings
from django.core.exceptions import ValidationError
from django.core.files.uploadedfile import SimpleUploadedFile
from django.core.management import call_command
from django.db import connection
from django.test import TestCase, Client, override_settings
from django.urls import reverse
from django.utils import timezone
from . import ai
from .models import *
from .services import *


class PortalTests(TestCase):
    def setUp(self):
        self.owner = User.objects.create_user(
            username="owner@example.com",
            email="owner@example.com",
            password="GoodPassword492!",
            first_name="Owner",
        )
        self.ws = Workspace.objects.create(
            owner=self.owner, name="Studio", slug="studio"
        )
        Membership.objects.create(workspace=self.ws, user=self.owner, role="owner")
        self.owner.current_workspace = self.ws
        self.owner.save()
        self.project = Project.objects.create(
            user=self.owner,
            workspace=self.ws,
            name="Perfume shop",
            description="Discovery fragrances",
        )
        self.category = Category.objects.create(
            project=self.project, name="Guides", slug="guides"
        )
        self.blog = Blog.objects.create(
            project=self.project,
            user=self.owner,
            category=self.category,
            title="A fresh everyday scent",
            status="draft",
        )
        BlogSection.objects.create(
            blog=self.blog,
            text_content="<h2>Try samples</h2><p>Find a scent you enjoy.</p>",
        )
        self.client.force_login(self.owner)

    def profile(self):
        profile = AiProfile.objects.create(
            project=self.project,
            data={
                "name": "Perfume shop",
                "summary": "A sample perfume shop",
                "audience": "First-time fragrance buyers",
                "facts": "Three 5 ml samples",
            },
            revision=1,
        )
        AiProfileVersion.objects.create(
            project=self.project, approved_by=self.owner, revision=1, data=profile.data
        )
        AiSettings.objects.create(
            scope=ai.scope(self.project), enabled=True, monthly_limit=5
        )
        return profile

    def test_all_main_screens_render(self):
        self.profile()
        routes = [
            ("projects", {}),
            ("admin_docs", {}),
            ("project", {"pk": self.project.pk}),
            ("integration", {"pk": self.project.pk}),
            ("create_blog", {"pk": self.project.pk}),
            ("edit_blog", {"blog_id": self.blog.pk}),
            ("profile", {"pk": self.project.pk}),
            ("studio", {"pk": self.project.pk}),
            ("health", {"pk": self.project.pk}),
            ("report", {"blog_id": self.blog.pk}),
            ("links", {"pk": self.project.pk}),
            ("blog_links", {"blog_id": self.blog.pk}),
            ("telemetry", {"pk": self.project.pk}),
            ("batches", {"pk": self.project.pk, "kind": "metadata"}),
            ("batches", {"pk": self.project.pk, "kind": "redirects"}),
            ("search", {"pk": self.project.pk}),
        ]
        for name, kwargs in routes:
            with self.subTest(route=name):
                self.assertEqual(
                    self.client.get(reverse(name, kwargs=kwargs)).status_code, 200
                )

    def test_retired_seo_settings_redirects_to_integration(self):
        self.assertRedirects(
            self.client.get(reverse("seo_settings", args=[self.project.pk])),
            reverse("integration", args=[self.project.pk]),
        )

    def test_editor_slug_validation_and_update(self):
        from .forms import BlogForm

        data = {
            "title": self.blog.title,
            "slug": "custom-fragrance-guide",
            "category": self.category.pk,
            "status": "draft",
        }
        form = BlogForm(data, instance=self.blog, project=self.project)
        self.assertTrue(form.is_valid(), form.errors)
        form.save()
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.slug, "custom-fragrance-guide")
        other = Blog.objects.create(
            project=self.project, user=self.owner, title="Other", slug="already-used"
        )
        data["slug"] = other.slug
        form = BlogForm(data, instance=self.blog, project=self.project)
        self.assertFalse(form.is_valid())
        self.assertIn("slug", form.errors)
        data["slug"] = "invalid/path"
        form = BlogForm(data, instance=self.blog, project=self.project)
        self.assertFalse(form.is_valid())
        self.assertIn("slug", form.errors)
        data["slug"] = ""
        form = BlogForm(data, instance=self.blog, project=self.project)
        self.assertTrue(form.is_valid(), form.errors)
        self.assertEqual(form.cleaned_data["slug"], "custom-fragrance-guide")

    def test_editor_font_sizes_survive_sanitization(self):
        markup = '<p><span class="ql-size-large" onclick="alert(1)">Large text</span><span class="unknown">Other</span></p>'
        cleaned = clean_html(markup)
        self.assertIn('class="ql-size-large"', cleaned)
        self.assertNotIn("onclick", cleaned)
        self.assertNotIn('class="unknown"', cleaned)
        self.assertIn(
            'class="ql-size-24px"',
            clean_html('<span class="ql-size-24px">Sized</span>'),
        )
        self.assertNotIn(
            "class=", clean_html('<span class="ql-size-999px">Invalid</span>')
        )

    def test_google_hub_tabs_and_route_actions(self):
        for tab in ("analytics", "sitemap"):
            self.assertEqual(
                self.client.get(
                    reverse("analytics_hub", args=[self.project.pk, tab])
                ).status_code,
                200,
            )
        self.assertEqual(
            self.client.get(
                reverse("search_action", args=[self.project.pk, "connect"])
            ).resolver_match.url_name,
            "search_action",
        )
        with override_settings(
            GOOGLE_CLIENT_ID="", GOOGLE_CLIENT_SECRET="", GOOGLE_REDIRECT_URI=""
        ):
            self.assertContains(
                self.client.get(
                    reverse("analytics_hub", args=[self.project.pk, "analytics"])
                ),
                "disabled",
            )

    @override_settings(
        GOOGLE_CLIENT_ID="client",
        GOOGLE_CLIENT_SECRET="secret",
        GOOGLE_REDIRECT_URI="http://localhost/callback",
    )
    def test_analytics_oauth_state_scope_and_callback(self):
        url = reverse("analytics_hub", args=[self.project.pk, "analytics"])
        response = self.client.post(url, {"operation": "connect"})
        self.assertEqual(response.status_code, 302)
        self.assertIn("analytics.readonly", response.url)
        flow = self.client.session["google_oauth"]
        self.assertEqual(flow["service"], "analytics")
        self.assertEqual(
            self.client.get(
                reverse("google_callback"), {"state": "invalid", "code": "fake"}
            ).status_code,
            400,
        )
        self.client.post(url, {"operation": "connect"})
        flow = self.client.session["google_oauth"]
        response_mock = Mock()
        response_mock.json.return_value = {
            "scope": "https://www.googleapis.com/auth/analytics.readonly",
            "access_token": "fake",
            "refresh_token": "fake-refresh",
        }
        with patch("portal.views.requests.post", return_value=response_mock):
            response = self.client.get(
                reverse("google_callback"), {"state": flow["state"], "code": "fake"}
            )
        self.assertRedirects(response, url)
        self.assertTrue(
            AnalyticsConnection.objects.filter(project=self.project).exists()
        )
        self.assertFalse(SearchConnection.objects.filter(project=self.project).exists())

    def test_analytics_property_selection_reporting_and_disconnect(self):
        conn = AnalyticsConnection.objects.create(
            project=self.project, tokens={"access_token": "fake"}
        )
        url = reverse("analytics_hub", args=[self.project.pk, "analytics"])
        properties = {
            "accountSummaries": [
                {
                    "propertySummaries": [
                        {"property": "properties/123", "displayName": "Store"}
                    ]
                }
            ]
        }
        with patch("portal.google_tools.google_request", return_value=properties):
            self.assertEqual(
                self.client.post(
                    url, {"operation": "select", "property": "properties/999"}
                ).status_code,
                400,
            )
            self.assertEqual(
                self.client.post(
                    url, {"operation": "select", "property": "properties/123"}
                ).status_code,
                200,
            )
        dates = {
            "operation": "report",
            "start_date": str(timezone.localdate() - timedelta(days=7)),
            "end_date": str(timezone.localdate() - timedelta(days=1)),
        }
        with patch(
            "portal.google_tools.google_request",
            side_effect=[
                {
                    "rows": [
                        {
                            "dimensionValues": [{"value": "/blog/test"}],
                            "metricValues": [
                                {"value": "4"},
                                {"value": "5"},
                                {"value": "6"},
                                {"value": "0.5"},
                            ],
                        }
                    ]
                },
                {"rows": []},
            ],
        ) as google:
            self.assertContains(self.client.post(url, dates), "/blog/test")
            self.assertEqual(google.call_count, 2)
        conn.refresh_from_db()
        self.assertEqual(conn.report["rows"][0]["metrics"][2], "6")
        self.assertEqual(
            self.client.post(
                url, {**dates, "end_date": str(timezone.localdate())}
            ).status_code,
            400,
        )
        self.client.post(url, {"operation": "disconnect"})
        self.assertFalse(AnalyticsConnection.objects.filter(pk=conn.pk).exists())

    def test_sitemap_setup_preserves_defaults_and_requires_verification(self):
        self.project.seo_settings = {
            "site_name": "Keep",
            "public_url": "https://old.example.com",
        }
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        SearchConnection.objects.create(
            project=self.project, origin="https://old.example.com"
        )
        url = reverse("analytics_hub", args=[self.project.pk, "sitemap"])
        self.assertEqual(
            self.client.post(
                url,
                {"public_url": "https://example.com", "blog_path": "/articles/{slug}"},
            ).status_code,
            302,
        )
        self.project.refresh_from_db()
        self.assertEqual(self.project.seo_settings["site_name"], "Keep")
        self.assertIsNone(self.project.seo_verified_at)
        self.assertFalse(SearchConnection.objects.filter(project=self.project).exists())
        self.assertEqual(
            self.client.get(
                reverse("sitemap_download", args=[self.project.pk])
            ).status_code,
            409,
        )
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        self.blog.status, self.blog.published_at = (
            "published",
            timezone.now() - timedelta(days=1),
        )
        self.blog.save()
        response = self.client.get(reverse("sitemap_download", args=[self.project.pk]))
        self.assertContains(response, "https://example.com/articles/" + self.blog.slug)
        self.blog.seo_settings = {"robots_index": "noindex"}
        self.blog.save()
        self.assertNotContains(
            self.client.get(reverse("sitemap_download", args=[self.project.pk])),
            self.blog.slug,
        )

    def test_long_paths_use_bounded_unique_hash_indexes(self):
        from django.db import IntegrityError, transaction

        long_path = "/" + "a" * 950
        redirect = RedirectRule.objects.create(
            project=self.project, source_path=long_path, target_path="/new"
        )
        self.assertEqual(len(redirect.source_hash), 64)
        self.assertEqual(redirect.source_path, long_path)
        event, created = NotFoundEvent.objects.get_or_create(
            project=self.project, path=long_path
        )
        self.assertTrue(created)
        self.assertEqual(len(event.path_hash), 64)
        for model, values in [
            (RedirectRule, {"source_path": long_path, "target_path": "/other"}),
            (NotFoundEvent, {"path": long_path}),
        ]:
            with self.assertRaises(IntegrityError), transaction.atomic():
                model.objects.create(project=self.project, **values)
        redirect.source_path = "/changed"
        redirect.save(update_fields=["source_path"])
        redirect.refresh_from_db()
        self.assertEqual(redirect.source_hash, hashlib.sha256(b"/changed").hexdigest())

    def test_linkedin_copy_open_uses_existing_social_result(self):
        profile = self.profile()
        run = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            profile_version=self.project.ai_versions.first(),
            blog=self.blog,
            action="social",
            platform="linkedin",
            state="complete",
            fingerprint="test",
            result={
                "title": "Title",
                "body": "Existing LinkedIn post",
                "hashtags": "#Perfume",
                "carousel": "",
                "warnings": "",
            },
        )
        response = self.client.get(reverse("ai_run", args=[self.project.pk, run.pk]))
        self.assertContains(response, "data-linkedin-copy")
        self.assertContains(response, "Existing LinkedIn post")
        self.assertContains(response, 'rel="noopener noreferrer"')
        self.assertEqual(AiRun.objects.count(), 1)
        run.platform = "reddit"
        run.save()
        self.assertNotContains(
            self.client.get(reverse("ai_run", args=[self.project.pk, run.pk])),
            "data-linkedin-copy",
        )

    def test_superadmin_access_is_strict_and_not_visible_to_clients(self):
        for route in ("superadmin", "superadmin_usage_export"):
            self.assertEqual(self.client.get(reverse(route)).status_code, 403)
        self.owner.is_staff = True
        self.owner.save()
        self.assertEqual(self.client.get(reverse("superadmin")).status_code, 403)
        self.assertNotContains(
            self.client.get(reverse("projects")), 'href="/superadmin"'
        )
        self.client.logout()
        self.assertEqual(self.client.get(reverse("superadmin")).status_code, 302)

    def test_superadmin_token_usage_filters_and_csv(self):
        self.owner.is_superuser = self.owner.is_staff = True
        self.owner.save()
        AiRun.objects.create(
            project=self.project,
            user=self.owner,
            action="social",
            platform="linkedin",
            state="complete",
            fingerprint="x",
            model="claude",
            usage={
                "input_tokens": 123,
                "output_tokens": 45,
                "cache_read_input_tokens": 8,
                "output_tokens_details": {"thinking_tokens": 20},
            },
            input={"secret_prompt": "PRIVATE_PROMPT_MARKER"},
            result={"body": "PRIVATE_RESULT_MARKER"},
        )
        AiRun.objects.create(
            project=self.project,
            user=self.owner,
            action="draft",
            state="failed",
            fingerprint="y",
            usage={},
        )
        response = self.client.get(reverse("superadmin"), {"month": "all"})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context["total"]["input_tokens"], 123)
        self.assertEqual(response.context["total"]["output_tokens"], 45)
        self.assertEqual(response.context["total"]["missing_usage"], 1)
        self.assertContains(response, "owner@example.com")
        self.assertNotContains(response, "PRIVATE_PROMPT_MARKER")
        self.assertNotContains(response, "PRIVATE_RESULT_MARKER")
        self.assertNotContains(response, self.project.api_key)
        self.assertEqual(response["Cache-Control"], "private, no-store")
        export = self.client.get(reverse("superadmin_usage_export"), {"month": "all"})
        self.assertContains(export, "cache_read_input_tokens")
        self.assertNotContains(export, "PRIVATE_PROMPT_MARKER")
        response = self.client.get(
            reverse("superadmin"), {"month": "all", "q": "not-an-existing-client"}
        )
        self.assertEqual(response.context["total"]["requests"], 0)
        self.assertEqual(
            self.client.get(reverse("superadmin"), {"month": "invalid"}).status_code,
            400,
        )

    def test_superadmin_month_filter_and_workspace_reservations(self):
        self.owner.is_superuser = self.owner.is_staff = True
        self.owner.save()
        old = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            action="draft",
            state="complete",
            fingerprint="old",
            usage={"input_tokens": 500},
            created_at=timezone.now() - timedelta(days=70),
        )
        AiSettings.objects.create(
            scope=ai.scope(self.project), enabled=True, monthly_limit=12
        )
        AiUsage.objects.create(
            scope=ai.scope(self.project),
            month=timezone.now().strftime("%Y-%m"),
            requests=3,
        )
        response = self.client.get(reverse("superadmin"))
        self.assertEqual(response.context["total"]["requests"], 0)
        self.assertEqual(response.context["workspaces_usage"][0]["reserved"], 3)
        self.assertEqual(
            self.client.get(reverse("superadmin"), {"month": "all"}).context["total"][
                "input_tokens"
            ],
            500,
        )

    def test_create_superadmin_command_and_existing_account_protection(self):
        from django.core.management.base import CommandError

        with patch(
            "portal.management.commands.create_superadmin.getpass",
            return_value="StrongUniqueAdmin492!pine",
        ):
            call_command(
                "create_superadmin", email="platform@example.com", stdout=io.StringIO()
            )
        user = User.objects.get(email="platform@example.com")
        self.assertTrue(user.is_superuser and user.is_staff)
        self.assertTrue(user.check_password("StrongUniqueAdmin492!pine"))
        with self.assertRaises(CommandError):
            call_command("create_superadmin", email=self.owner.email)
        self.owner.refresh_from_db()
        self.assertFalse(self.owner.is_superuser)
        with (
            patch(
                "portal.management.commands.create_superadmin.getpass",
                return_value="password",
            ),
            self.assertRaises(CommandError),
        ):
            call_command("create_superadmin", email="weak@example.com")
        self.assertFalse(User.objects.filter(email="weak@example.com").exists())

    def test_superadmin_login_and_navigation_skip_client_creation(self):
        self.owner.is_superuser = self.owner.is_staff = True
        self.owner.save()
        for route in ("home", "login", "projects"):
            self.assertRedirects(self.client.get(reverse(route)), reverse("superadmin"))
        response = self.client.get(reverse("superadmin"))
        self.assertContains(response, "Superadmin navigation")
        self.assertContains(response, "Account management")
        self.assertNotContains(response, "+ New workspace")
        self.assertNotContains(response, 'href="/admin/projects"')
        count = Workspace.objects.count()
        self.assertRedirects(
            self.client.post(
                reverse("workspace_create"), {"name": "Should not create"}
            ),
            reverse("superadmin"),
        )
        self.assertEqual(Workspace.objects.count(), count)
        self.client.logout()
        response = self.client.post(
            reverse("login"),
            {"username": self.owner.email, "password": "GoodPassword492!"},
        )
        self.assertRedirects(response, reverse("superadmin"))
        self.assertEqual(
            self.client.get(reverse("admin:portal_aisettings_changelist")).status_code,
            200,
        )

    def test_superadmin_sections_have_separate_pages(self):
        self.owner.is_superuser = self.owner.is_staff = True
        self.owner.save()
        sections = {
            "superadmin": "overview",
            "superadmin_clients": "clients",
            "superadmin_workspaces": "workspaces",
            "superadmin_usage": "usage",
        }
        for route, section in sections.items():
            response = self.client.get(reverse(route))
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.context["admin_section"], section)
            for key in ("clients", "workspaces", "usage"):
                if key == section:
                    self.assertContains(response, 'id="' + key + '"')
                else:
                    self.assertNotContains(response, 'id="' + key + '"')
        self.owner.is_superuser = False
        self.owner.save()
        for route in sections:
            self.assertEqual(self.client.get(reverse(route)).status_code, 403)

    def test_landing_and_auth_pages(self):
        self.client.logout()
        for url in ["/", "/docs", "/login", "/register", "/forgot-password"]:
            with self.subTest(url=url):
                self.assertEqual(self.client.get(url).status_code, 200)
        self.assertEqual(
            self.client.post(
                "/login", {"username": self.owner.email, "password": "GoodPassword492!"}
            ).status_code,
            302,
        )
        self.assertEqual(self.client.get("/logout").status_code, 405)

    def test_registration_creates_workspace_and_unique_email(self):
        self.client.logout()
        values = {
            "first_name": "New author",
            "email": "new@example.com",
            "password1": "GoodPassword482!",
            "password2": "GoodPassword482!",
            "company_name": "Scent Studio",
        }
        self.assertEqual(self.client.post("/register", values).status_code, 302)
        user = User.objects.get(email="new@example.com")
        self.assertTrue(Membership.objects.filter(user=user, role="owner").exists())
        self.assertIsNotNone(user.current_workspace_id)
        self.client.logout()
        self.assertEqual(self.client.post("/register", values).status_code, 200)
        self.assertEqual(User.objects.filter(email="new@example.com").count(), 1)

    def test_csrf_and_post_only_deletion(self):
        client = Client(enforce_csrf_checks=True)
        client.force_login(self.owner)
        self.assertEqual(
            client.post(
                reverse("project_delete", args=[self.project.pk]),
                {"project_name": self.project.name},
            ).status_code,
            403,
        )
        self.assertEqual(
            self.client.get(
                reverse("project_delete", args=[self.project.pk])
            ).status_code,
            405,
        )
        self.assertEqual(
            self.client.post(
                reverse("project_delete", args=[self.project.pk]),
                {"project_name": "wrong"},
            ).status_code,
            400,
        )
        self.assertTrue(Project.objects.filter(pk=self.project.pk).exists())

    def test_role_and_cross_workspace_isolation(self):
        editor = User.objects.create_user(username="editor", email="editor@example.com")
        Membership.objects.create(user=editor, workspace=self.ws, role="editor")
        self.client.force_login(editor)
        self.assertEqual(
            self.client.get(reverse("edit_blog", args=[self.blog.pk])).status_code, 200
        )
        self.assertEqual(
            self.client.post(
                reverse("profile_action", args=[self.project.pk, "settings"]),
                {"enabled": "1", "monthly_limit": 5},
            ).status_code,
            403,
        )
        self.assertEqual(
            self.client.post(
                reverse("project_delete", args=[self.project.pk]),
                {"project_name": self.project.name},
            ).status_code,
            403,
        )
        outsider = User.objects.create_user(
            username="outsider", email="out@example.com"
        )
        self.client.force_login(outsider)
        for name in ["project", "profile", "studio", "integration"]:
            self.assertEqual(
                self.client.get(reverse(name, args=[self.project.pk])).status_code, 403
            )

    def test_blogs_screen_excludes_integration(self):
        result = self.client.get(reverse("project", args=[self.project.pk]))
        self.assertContains(result, "Your posts")
        self.assertNotContains(result, "Project API key")
        result = self.client.get(reverse("integration", args=[self.project.pk]))
        self.assertContains(result, "Project API key")
        self.assertNotContains(result, "Your posts")

    def test_create_edit_and_optimistic_conflict(self):
        values = {
            "title": "A perfume guide",
            "category": self.category.pk,
            "status": "draft",
            "robots_index": "inherit",
            "sections-TOTAL_FORMS": 1,
            "sections-INITIAL_FORMS": 0,
            "sections-MIN_NUM_FORMS": 1,
            "sections-MAX_NUM_FORMS": 100,
            "sections-0-text_content": "<p>Useful draft</p><script>alert(1)</script>",
            "sections-0-order": 0,
        }
        response = self.client.post(
            reverse("create_blog", args=[self.project.pk]), values
        )
        self.assertEqual(response.status_code, 302)
        blog = Blog.objects.get(title="A perfume guide")
        self.assertNotIn("script", blog.sections.first().text_content)
        values.update(
            {
                "editor_revision": "old",
                "sections-INITIAL_FORMS": 1,
                "sections-0-id": blog.sections.first().pk,
                "sections-0-blog": blog.pk,
            }
        )
        response = self.client.post(reverse("edit_blog", args=[blog.pk]), values)
        self.assertEqual(response.status_code, 409)
        blog.refresh_from_db()
        self.assertEqual(blog.title, "A perfume guide")

    def test_private_autosave_versions_and_tombstones(self):
        url = reverse("autosave", args=[self.blog.pk])
        base = revision(self.blog)
        data = {
            "version": 0,
            "base_hash": base,
            "snapshot": {
                "title": "Private draft",
                "sections": [{"text_content": "<p>Draft</p><script>x</script>"}],
            },
        }
        self.assertEqual(
            self.client.post(
                url, json.dumps(data), content_type="application/json"
            ).status_code,
            200,
        )
        self.assertEqual(
            self.client.post(
                url, json.dumps(data), content_type="application/json"
            ).status_code,
            409,
        )
        self.blog.refresh_from_db()
        self.assertNotEqual(self.blog.title, "Private draft")
        self.assertEqual(
            self.client.get(url).json()["snapshot"]["title"], "Private draft"
        )
        data.update({"version": 1, "discard": True})
        self.assertEqual(
            self.client.post(
                url, json.dumps(data), content_type="application/json"
            ).json()["version"],
            2,
        )
        self.assertEqual(self.client.get(url).json()["snapshot"], {})

    def test_api_published_only_pagination_and_contract(self):
        Blog.objects.create(
            project=self.project,
            user=self.owner,
            title="Scheduled",
            status="published",
            published_at=timezone.now() + timedelta(days=2),
        )
        self.blog.status = "published"
        self.blog.save()
        response = self.client.get(
            "/api/v1/projects/blogs", HTTP_X_API_KEY=self.project.api_key
        )
        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["meta"]["total"], 1)
        for key in [
            "version",
            "robots",
            "open_graph",
            "twitter",
            "schema",
            "warnings",
            "public_url",
        ]:
            self.assertIn(key, payload["blogs"][0]["seo"])
        self.assertNotIn("seo_settings", payload["blogs"][0])
        self.assertEqual(
            payload["blogs"][0]["seo"]["robots"], {"index": True, "follow": True}
        )
        self.assertEqual(self.client.get("/api/v1/projects/blogs").status_code, 401)
        self.assertEqual(
            self.client.get(
                "/api/v1/projects/blogs?per_page=101",
                HTTP_X_API_KEY=self.project.api_key,
            ).status_code,
            400,
        )

    def test_sitemap_and_canonical_require_verified_domain(self):
        url = "/api/v1/projects/sitemap.xml"
        self.assertEqual(
            self.client.get(url, HTTP_X_API_KEY=self.project.api_key).status_code, 409
        )
        self.project.seo_settings = {
            "public_url": "https://example.com",
            "article_path": "/news/{slug}",
        }
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        self.blog.status = "published"
        self.blog.save()
        response = self.client.get(url, HTTP_X_API_KEY=self.project.api_key)
        self.assertContains(response, "/news/" + self.blog.slug)
        self.blog.seo_settings = {"robots_index": "noindex"}
        self.blog.save()
        self.assertNotContains(
            self.client.get(url, HTTP_X_API_KEY=self.project.api_key),
            "/news/" + self.blog.slug,
        )

    def test_csv_review_apply_and_guarded_undo(self):
        before = self.blog.seo_settings.copy()
        raw = (
            "blog_id,"
            + ",".join(META_FIELDS)
            + "\n"
            + str(self.blog.pk)
            + ",New title,New description,perfume,index,follow\n"
        )
        batch = preview_csv(
            self.project,
            self.owner,
            "metadata",
            SimpleUploadedFile("meta.csv", raw.encode()),
            "Improve metadata",
        )
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.seo_settings, before)
        commit_batch(batch)
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.seo_settings["title"], "New title")
        self.blog.seo_settings = {"title": "Newer work"}
        self.blog.save()
        with self.assertRaises(ValidationError):
            commit_batch(batch, True)

    def test_redirect_cycles_and_lookup_contract(self):
        with self.assertRaises(ValidationError):
            validate_redirects(
                [
                    {"source_path": "/a", "target_path": "/b"},
                    {"source_path": "/b", "target_path": "/a"},
                ]
            )
        self.project.seo_settings = {"public_url": "https://example.com"}
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        RedirectRule.objects.create(
            project=self.project,
            source_path="/old",
            target_path="/new",
            status_code=301,
        )
        response = self.client.get(
            "/api/v1/projects/redirect?path=/old", HTTP_X_API_KEY=self.project.api_key
        )
        self.assertEqual(
            response.json()["redirect"], {"status": 301, "location": "/new"}
        )

    def test_telemetry_secret_origin_and_event_dedup(self):
        secret = token()
        self.project.telemetry_key_hash = digest(secret)
        self.project.seo_settings = {"public_url": "https://example.com"}
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        data = {
            "origin": "https://example.com",
            "path": "/missing?private=value",
            "event_id": str(uuid.uuid4()),
        }
        url = "/api/v1/projects/not-found"
        self.assertEqual(
            self.client.post(
                url, json.dumps(data), content_type="application/json"
            ).status_code,
            401,
        )
        for _ in range(2):
            self.assertEqual(
                self.client.post(
                    url,
                    json.dumps(data),
                    content_type="application/json",
                    HTTP_X_TELEMETRY_KEY=secret,
                ).status_code,
                204,
            )
        event = NotFoundEvent.objects.get()
        self.assertEqual(event.path, "/missing")
        self.assertEqual(event.hits, 1)

    def test_profiles_approval_and_encryption(self):
        profile = self.profile()
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT data FROM portal_aiprofile WHERE id=%s", [profile.pk]
            )
            raw = cursor.fetchone()[0]
        self.assertNotIn("perfume", raw)
        values = {**profile.data, "revision": 1, "summary": "New business facts"}
        self.assertEqual(
            self.client.post(
                reverse("profile", args=[self.project.pk]), values
            ).status_code,
            302,
        )
        self.assertEqual(
            ai.approved(self.project).data["summary"], "New business facts"
        )
        self.assertEqual(
            self.client.post(
                reverse("profile", args=[self.project.pk]), values
            ).status_code,
            409,
        )

    @override_settings(
        AI_ENABLED=True,
        ANTHROPIC_API_KEY="test-key",
        CLAUDE_MODEL="test-model",
        AI_MONTHLY_REQUEST_LIMIT=5,
    )
    def test_ai_queue_idempotency_usage_and_worker(self):
        self.profile()
        key = uuid.uuid4()
        data = {"brief": "Choose a scent"}
        run = ai.enqueue(self.project, self.owner, "outline", data.copy(), key)
        again = ai.enqueue(self.project, self.owner, "outline", data.copy(), key)
        self.assertEqual(run.pk, again.pk)
        self.assertEqual(AiUsage.objects.get().requests, 1)
        response = Mock()
        response.status_code = 200
        response.headers = {}
        response.raise_for_status.return_value = None
        response.headers = {"request-id": "test-request"}
        response.json.return_value = {
            "stop_reason": "end_turn",
            "content": [
                {
                    "type": "text",
                    "text": json.dumps(
                        {
                            "title": "Fresh scent",
                            "outline": "Explore samples first",
                            "warnings": "",
                        }
                    ),
                }
            ],
            "usage": {"input_tokens": 100, "output_tokens": 50},
        }
        with patch("portal.ai.requests.post", return_value=response):
            call_command("work", "--once", stdout=io.StringIO())
        run.refresh_from_db()
        self.assertEqual(run.state, "complete")
        self.assertEqual(run.usage["input_tokens"], 100)
        self.assertEqual(
            self.client.get(
                reverse("ai_run", args=[self.project.pk, run.pk])
            ).status_code,
            200,
        )

    @override_settings(
        AI_ENABLED=True, ANTHROPIC_API_KEY="test-key", CLAUDE_MODEL="test-model"
    )
    def test_ai_stale_review_and_private_draft_creation(self):
        self.profile()
        version = ai.approved(self.project)
        run = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            profile_version=version,
            action="draft",
            request_key=uuid.uuid4(),
            fingerprint="f",
            state="complete",
            result={
                "title": "A perfume draft",
                "excerpt": "Useful perfume tips",
                "body_html": "<p>Try samples.</p>",
                "seo_description": "Fragrance discovery",
                "warnings": "",
            },
        )
        response = self.client.post(
            reverse("ai_run", args=[self.project.pk, run.pk]),
            {**run.result, "revision": 1, "reviewed": "on"},
        )
        self.assertEqual(response.status_code, 302)
        for _ in range(2):
            self.assertEqual(
                self.client.post(
                    reverse("ai_run_action", args=[self.project.pk, run.pk, "draft"]),
                    {"category_id": self.category.pk},
                ).status_code,
                302,
            )
        created = Blog.objects.get(title="A perfume draft")
        self.assertEqual(created.status, "draft")
        self.assertEqual(Blog.objects.filter(title="A perfume draft").count(), 1)
        AiProfileVersion.objects.create(
            project=self.project, approved_by=self.owner, revision=2, data=version.data
        )
        self.assertTrue(ai.stale(run))
        self.assertContains(
            self.client.get(reverse("ai_run", args=[self.project.pk, run.pk])),
            "Source article or approved profile changed",
        )

    def test_public_url_and_sanitizer(self):
        for url in [
            "http://127.0.0.1",
            "http://localhost",
            "http://169.254.169.254",
            "file:///etc/passwd",
            "https://example.com:8080",
            "https://user:pass@example.com",
        ]:
            with (
                self.subTest(url=url),
                self.assertRaises((ValidationError, ValueError)),
            ):
                public_url(url)
        result = clean_html(
            '<script>evil()</script><p onclick="evil()">Safe <a href="javascript:evil()">link</a></p>'
        )
        self.assertNotIn("script", result)
        self.assertNotIn("onclick", result)
        self.assertNotIn("javascript", result)
        with (
            patch("portal.services.dns.resolver.resolve", return_value=["127.0.0.1"]),
            self.assertRaises(ValidationError),
        ):
            fetch_public("https://example.com")

    def test_legacy_import_dry_run_and_password_compatibility(self):
        from django.contrib.auth.hashers import check_password
        import bcrypt

        hashed = (
            bcrypt.hashpw(b"OldPassword492!", bcrypt.gensalt())
            .decode()
            .replace("$2b$", "$2y$")
        )
        self.assertTrue(check_password("OldPassword492!", "bcrypt$" + hashed))
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json") as out:
            json.dump(
                {"format": "blogsaas-laravel-export-v1", "tables": {"users": []}}, out
            )
            out.flush()
            call_command("import_laravel", out.name, stdout=io.StringIO())
        self.assertEqual(User.objects.count(), 1)

    def test_cors_is_limited_to_verified_project_origin(self):
        self.project.seo_settings = {"public_url": "https://example.com"}
        self.project.seo_verified_at = timezone.now()
        self.project.save()
        result = self.client.options(
            "/api/v1/projects/blogs", HTTP_ORIGIN="https://example.com"
        )
        self.assertEqual(result.status_code, 204)
        self.assertEqual(result["Access-Control-Allow-Origin"], "https://example.com")
        self.assertEqual(
            self.client.options(
                "/api/v1/projects/blogs", HTTP_ORIGIN="https://evil.example"
            ).status_code,
            403,
        )
        result = self.client.get(
            "/api/v1/projects/blogs",
            HTTP_X_API_KEY=self.project.api_key,
            HTTP_ORIGIN="https://example.com",
        )
        self.assertEqual(result["Access-Control-Allow-Origin"], "https://example.com")

    def test_batch_expiry_prevents_apply(self):
        batch = ChangeBatch.objects.create(
            project=self.project,
            user=self.owner,
            kind="metadata",
            reason="Expired preview",
            changes=[],
            expires_at=timezone.now() - timedelta(minutes=1),
        )
        with self.assertRaises(ValidationError):
            commit_batch(batch)

    def test_image_normalization_and_rejection(self):
        from PIL import Image

        image = io.BytesIO()
        Image.new("RGB", (20, 20), "red").save(image, format="PNG")
        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
        ):
            path = save_image(
                SimpleUploadedFile(
                    "picture.php", image.getvalue(), content_type="image/png"
                )
            )
            self.assertTrue(path.endswith(".jpg"))
            self.assertTrue(
                default_storage.exists(path.removeprefix(settings.MEDIA_URL))
            )
            with self.assertRaises(ValidationError):
                save_image(
                    SimpleUploadedFile("bad.svg", b"<svg><script>x</script></svg>")
                )

    def test_oauth_callback_rejects_missing_and_wrong_state(self):
        self.assertEqual(
            self.client.get("/admin/search-console/callback?state=bad").status_code, 400
        )
        session = self.client.session
        session["google_oauth"] = {
            "state": "correct",
            "project": self.project.pk,
            "verifier": "test",
            "time": timezone.now().timestamp(),
        }
        session.save()
        self.assertEqual(
            self.client.get(
                "/admin/search-console/callback?state=incorrect"
            ).status_code,
            400,
        )

    @override_settings(
        AI_ENABLED=True, ANTHROPIC_API_KEY="test-key", CLAUDE_MODEL="test-model"
    )
    def test_cancelled_run_does_not_block_next_request_or_call_provider(self):
        self.profile()
        run = ai.enqueue(
            self.project, self.owner, "outline", {"brief": "First"}, uuid.uuid4()
        )
        self.client.post(
            reverse("ai_run_action", args=[self.project.pk, run.pk, "cancel"])
        )
        new = ai.enqueue(
            self.project, self.owner, "outline", {"brief": "Second"}, uuid.uuid4()
        )
        self.assertNotEqual(new.pk, run.pk)
        with patch("portal.ai.requests.post") as provider:
            call_command("work", "--once", stdout=io.StringIO())
            provider.assert_not_called()

    @override_settings(
        AI_ENABLED=True, ANTHROPIC_API_KEY="test-key", CLAUDE_MODEL="test-model"
    )
    def test_truncated_provider_output_fails_without_publishing(self):
        self.profile()
        run = ai.enqueue(
            self.project, self.owner, "outline", {"brief": "First"}, uuid.uuid4()
        )
        response = Mock()
        response.status_code = 200
        response.headers = {}
        response.raise_for_status.return_value = None
        response.json.return_value = {"stop_reason": "max_tokens", "content": []}
        with patch("portal.ai.requests.post", return_value=response):
            call_command("work", "--once", stdout=io.StringIO())
        run.refresh_from_db()
        self.assertEqual(run.state, "failed")
        self.assertEqual(Blog.objects.live().count(), 0)


class ImportTests(TestCase):
    def test_apply_core_export_preserves_ids_and_relations(self):
        payload = {
            "format": "blogsaas-laravel-export-v1",
            "tables": {
                "users": [
                    {
                        "id": 12,
                        "email": "legacy@example.com",
                        "name": "Legacy Writer",
                        "password": "unsupported",
                        "current_workspace_id": 20,
                    }
                ],
                "workspaces": [
                    {
                        "id": 20,
                        "owner_id": 12,
                        "name": "Legacy studio",
                        "slug": "legacy-studio",
                    }
                ],
                "workspace_user": [
                    {"workspace_id": 20, "user_id": 12, "role": "owner"}
                ],
                "projects": [
                    {
                        "id": 30,
                        "user_id": 12,
                        "workspace_id": 20,
                        "name": "Legacy publication",
                        "api_key": "pk_legacy",
                        "subdomain": None,
                        "seo_settings": "{}",
                    }
                ],
                "categories": [
                    {"id": 40, "project_id": 30, "name": "Guides", "slug": "guides"}
                ],
                "blogs": [
                    {
                        "id": 50,
                        "project_id": 30,
                        "user_id": 12,
                        "category_id": 40,
                        "title": "Legacy post",
                        "slug": "legacy-story",
                        "content": "<p>Legacy text</p>",
                        "status": "draft",
                        "seo_settings": "{}",
                    }
                ],
                "blog_sections": [
                    {
                        "id": 60,
                        "blog_id": 50,
                        "text_content": "<p>Original section</p>",
                        "order": 0,
                    }
                ],
                "ai_profiles": [
                    {
                        "id": 70,
                        "project_id": 30,
                        "revision": 1,
                        "data": {
                            "name": "Legacy publication",
                            "summary": "Old facts",
                            "audience": "Readers",
                        },
                    }
                ],
            },
        }
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json") as out:
            json.dump(payload, out)
            out.flush()
            call_command("import_laravel", out.name, "--apply", stdout=io.StringIO())
        self.assertEqual(Blog.objects.get(pk=50).category_id, 40)
        self.assertEqual(User.objects.get(pk=12).current_workspace_id, 20)
        self.assertEqual(AiProfile.objects.get(pk=70).data["summary"], "Old facts")
        created = Blog.objects.create(project_id=30, user_id=12, title="Next post")
        self.assertGreater(created.pk, 50)


class CrawlerTests(TestCase):
    def test_wildcard_and_longest_robots_rules(self):
        policy = RobotsPolicy()
        policy.parse(
            [
                "User-agent: *",
                "Disallow: /private",
                "Allow: /private/public",
                "Disallow: /*?secret=*",
            ]
        )
        self.assertFalse(
            policy.can_fetch("TryWryter-AI", "https://example.com/private/account")
        )
        self.assertTrue(
            policy.can_fetch("TryWryter-AI", "https://example.com/private/public")
        )
        self.assertFalse(
            policy.can_fetch("TryWryter-AI", "https://example.com/page?secret=value")
        )


class AiDiagnosticsTests(TestCase):
    def test_provider_errors_are_specific_and_never_include_raw_response(self):
        import requests

        response = requests.Response()
        response.status_code = 401
        response._content = b'{"message":"secret credential or prompt"}'
        detail = ai.failure_detail(requests.HTTPError(response=response))
        self.assertIn("invalid or expired", detail)
        self.assertNotIn("secret", detail)
        self.assertIn("timed out", ai.failure_detail(requests.ReadTimeout()))

    @override_settings(
        AI_ENABLED=True, ANTHROPIC_API_KEY="test-only", CLAUDE_MODEL="test-model"
    )
    def test_draft_budget_and_truncation_diagnostics_are_preserved(self):
        user = User.objects.create_user(
            username="diagnostics", email="diagnostics@example.com"
        )
        project = Project.objects.create(user=user, name="Diagnostics")
        run = AiRun.objects.create(
            project=project,
            user=user,
            action="draft",
            fingerprint="diagnostics",
            model="test-model",
            input={},
        )
        response = Mock()
        response.status_code = 200
        response.headers = {"request-id": "req_diagnostic"}
        response.raise_for_status.return_value = None
        response.json.return_value = {
            "stop_reason": "max_tokens",
            "content": [],
            "usage": {"input_tokens": 100, "output_tokens": 8192},
        }
        with patch("portal.ai.requests.post", return_value=response) as provider:
            with self.assertRaisesMessage(ValidationError, "output limit"):
                ai.generate(run)
            self.assertEqual(provider.call_args.kwargs["json"]["max_tokens"], 8192)
            self.assertEqual(provider.call_args.kwargs["timeout"], (8, 180))
        run.refresh_from_db()
        self.assertEqual(run.request_id, "req_diagnostic")
        self.assertEqual(run.usage["stop_reason"], "max_tokens")
        self.assertEqual(run.result, {})


class LocalLauncherTests(TestCase):
    @override_settings(DEBUG=True)
    def test_local_launcher_starts_both_processes_and_cleans_up(self):
        worker = Mock()
        web = Mock()
        worker.poll.return_value = None
        web.poll.return_value = None
        with patch(
            "portal.management.commands.runlocal.subprocess.Popen",
            side_effect=[worker, web],
        ) as launch:
            call_command("runlocal", "--port", "8012", stdout=io.StringIO())
        self.assertEqual(launch.call_count, 2)
        self.assertEqual(launch.call_args_list[0].args[0][-1], "work")
        self.assertEqual(
            launch.call_args_list[1].args[0][-2:], ["runserver", "127.0.0.1:8012"]
        )
        worker.terminate.assert_called_once()
        web.terminate.assert_called_once()


class SocialShortcutTests(TestCase):
    setUp = PortalTests.setUp
    profile = PortalTests.profile

    def test_platform_buttons_prefill_story_and_filter_saved_versions_without_charges(
        self,
    ):
        self.profile()
        for platform in ["linkedin", "reddit", "facebook", "instagram"]:
            AiRun.objects.create(
                project=self.project,
                user=self.owner,
                blog=self.blog,
                action="social",
                platform=platform,
                state="complete",
                fingerprint=platform,
                result={
                    "title": "Example",
                    "body": "Example body",
                    "hashtags": "",
                    "carousel": "",
                    "warnings": "",
                },
            )
        response = self.client.get(reverse("project", args=[self.project.pk]))
        for name in [
            "LinkedIn version",
            "Reddit version",
            "Facebook version",
            "Instagram version",
        ]:
            self.assertContains(response, name)
        for platform in ["linkedin", "reddit", "facebook", "instagram"]:
            response = self.client.get(
                reverse("studio", args=[self.project.pk]),
                {"blog": self.blog.pk, "platform": platform},
            )
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.context["form"]["action"].value(), "social")
            self.assertEqual(response.context["form"]["platform"].value(), platform)
            self.assertEqual(response.context["blog"].pk, self.blog.pk)
            self.assertEqual(
                {item.platform for item in response.context["runs"]}, {platform}
            )
        self.assertEqual(WorkItem.objects.count(), 0)
        self.assertEqual(AiUsage.objects.count(), 0)

    def test_platform_history_pagination_preserves_story_and_platform(self):
        for number in range(13):
            AiRun.objects.create(
                project=self.project,
                user=self.owner,
                blog=self.blog,
                action="social",
                platform="linkedin",
                fingerprint=str(number),
                state="complete",
            )
        response = self.client.get(
            reverse("studio", args=[self.project.pk]),
            {"blog": self.blog.pk, "platform": "linkedin"},
        )
        self.assertContains(
            response, f"blog={self.blog.pk}&amp;platform=linkedin&amp;page=2"
        )

    @override_settings(
        AI_ENABLED=True, ANTHROPIC_API_KEY="test-key", CLAUDE_MODEL="test-model"
    )
    def test_direct_version_uses_existing_article_and_reopens_saved_content(self):
        self.profile()
        url = reverse(
            "social_version", args=[self.project.pk, self.blog.pk, "linkedin"]
        )
        self.assertEqual(self.client.get(url).status_code, 200)
        self.assertEqual(AiRun.objects.count(), 0)
        response = self.client.post(url, {"request_key": uuid.uuid4()})
        run = AiRun.objects.get()
        self.assertRedirects(
            response, reverse("ai_run", args=[self.project.pk, run.pk])
        )
        self.assertEqual(run.action, "social")
        self.assertEqual(run.blog_id, self.blog.pk)
        self.assertIn("Find a scent you enjoy", run.input["article"]["body"])
        self.assertEqual(run.input["article"]["title"], self.blog.title)
        self.assertEqual(Blog.objects.count(), 1)
        run.state = "complete"
        run.result = {
            "title": "Saved version",
            "body": "Existing LinkedIn text",
            "hashtags": "",
            "carousel": "",
            "warnings": "",
        }
        run.edited = {**run.result, "body": "My manually edited LinkedIn text"}
        run.save()
        with override_settings(AI_ENABLED=False):
            response = self.client.post(url, {"request_key": uuid.uuid4()})
            self.assertRedirects(
                response, reverse("ai_run", args=[self.project.pk, run.pk])
            )
        self.assertEqual(AiRun.objects.count(), 1)
        self.assertEqual(WorkItem.objects.count(), 1)
        self.assertEqual(AiUsage.objects.get().requests, 1)
        run.refresh_from_db()
        self.assertEqual(run.edited["body"], "My manually edited LinkedIn text")

    def test_version_creation_checks_project_and_write_permissions(self):
        outsider_project = Project.objects.create(
            user=self.owner, name="Different project"
        )
        foreign = Blog.objects.create(
            project=outsider_project, user=self.owner, title="Different article"
        )
        self.assertEqual(
            self.client.post(
                reverse("social_version", args=[self.project.pk, foreign.pk, "reddit"]),
                {"request_key": uuid.uuid4()},
            ).status_code,
            404,
        )
        viewer = User.objects.create_user(
            username="reader-social", email="reader-social@example.com"
        )
        Membership.objects.create(user=viewer, workspace=self.ws, role="viewer")
        self.client.force_login(viewer)
        self.assertEqual(
            self.client.post(
                reverse(
                    "social_version", args=[self.project.pk, self.blog.pk, "reddit"]
                ),
                {"request_key": uuid.uuid4()},
            ).status_code,
            403,
        )
        self.assertEqual(AiRun.objects.count(), 0)

    def test_shortcuts_cannot_select_a_foreign_story(self):
        other = Project.objects.create(user=self.owner, name="Another publication")
        foreign = Blog.objects.create(
            project=other, user=self.owner, title="Foreign post"
        )
        self.assertEqual(
            self.client.get(
                reverse("studio", args=[self.project.pk]),
                {"blog": foreign.pk, "platform": "linkedin"},
            ).status_code,
            404,
        )


class SeoQualityTests(TestCase):
    setUp = PortalTests.setUp
    profile = PortalTests.profile

    def test_checks_use_seo_title_and_do_not_penalize_absent_images(self):
        self.blog.title = "A very long editorial headline " + (
            "for fragrance readers " * 5
        )
        self.blog.seo_settings = {
            "title": "Fresh perfume: a useful sampling guide",
            "description": "A clear practical guide to fresh perfume, scent families and sampling choices for readers exploring their next everyday fragrance.",
            "focus_keyphrase": "fresh perfume",
        }
        self.blog.save()
        report = analyze(self.blog).report
        checks = {row["key"]: row for row in report["checks"]}
        self.assertTrue(checks["title"]["passed"])
        self.assertTrue(checks["focus_title"]["passed"])
        self.assertEqual(checks["cover_alt"]["status"], "not_applicable")
        self.assertEqual(checks["section_alt"]["status"], "not_applicable")
        applicable = [row for row in report["checks"] if row["passed"] is not None]
        self.assertEqual(
            report["seo_score"],
            round(sum(row["passed"] for row in applicable) / len(applicable) * 100),
        )

    def test_publishing_reviewing_and_other_stories_do_not_invalidate_content_checks(
        self,
    ):
        analysis = analyze(self.blog)
        self.blog.status = "published"
        self.blog.published_at = timezone.now()
        self.blog.content_reviewed_at = timezone.now()
        self.blog.save()
        Blog.objects.create(
            project=self.project, user=self.owner, title="An unrelated new post"
        )
        self.assertFalse(analysis_is_stale(analysis, self.blog))
        self.blog.sections.first().delete()
        self.assertTrue(analysis_is_stale(analysis, self.blog))

    def test_analyze_all_queues_and_covers_more_than_fifty_stories(self):
        Blog.objects.bulk_create(
            [
                Blog(
                    project=self.project,
                    user=self.owner,
                    title=f"Story {n}",
                    slug=f"quality-story-{n}",
                )
                for n in range(55)
            ]
        )
        self.client.post(reverse("health", args=[self.project.pk]))
        job = WorkItem.objects.get(kind="seo_analyze")
        self.assertEqual(job.payload["total"], 56)
        call_command("work", "--once", stdout=io.StringIO())
        job.refresh_from_db()
        self.assertEqual(job.state, "pending")
        self.assertEqual(job.payload["processed"], 50)
        call_command("work", "--once", stdout=io.StringIO())
        job.refresh_from_db()
        self.assertEqual(job.state, "complete")
        self.assertEqual(job.payload["processed"], 56)
        self.assertEqual(SeoAnalysis.objects.count(), 56)
        response = self.client.get(reverse("health", args=[self.project.pk]))
        self.assertContains(response, "Analyze all 56 posts")
        self.assertEqual(len(response.context["rows"]), 25)

    def test_reviewed_metadata_is_applied_without_changing_the_article(self):
        self.profile()
        before = revision(self.blog)
        text = self.blog.sections.first().text_content
        result = {
            "excerpt": "A summary",
            "seo_title": "Fresh perfume: a useful sampling guide",
            "seo_description": "A practical guide to finding fresh perfume, understanding scent families and sampling fragrances before choosing an everyday bottle.",
            "focus_keyphrase": "fresh perfume",
            "warnings": "",
        }
        run = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            blog=self.blog,
            profile_version=ai.approved(self.project),
            action="metadata",
            fingerprint="seo-test",
            state="complete",
            input={"blog_revision": before},
            result=result,
            reviewed_at=timezone.now(),
        )
        response = self.client.post(
            reverse("ai_run_action", args=[self.project.pk, run.pk, "apply-seo"])
        )
        self.assertRedirects(response, reverse("report", args=[self.blog.pk]))
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.seo_settings["title"], result["seo_title"])
        self.assertEqual(self.blog.seo_settings["focus_keyphrase"], "fresh perfume")
        self.assertEqual(self.blog.sections.first().text_content, text)
        self.assertEqual(self.blog.status, "draft")
        self.assertFalse(analysis_is_stale(self.blog.analyses.latest("id"), self.blog))

    def test_ai_draft_creation_saves_seo_metadata_and_analyzes_immediately(self):
        self.profile()
        result = {
            "title": "An editorial guide to fragrance discovery",
            "excerpt": "Try samples before choosing a fragrance.",
            "body_html": "<h2>Fresh perfume choices</h2><p>Explore fresh perfume through sampling.</p>",
            "seo_title": "Fresh perfume: a practical discovery guide",
            "seo_description": "A practical guide to fresh perfume, sampling different scent families and choosing an everyday fragrance that suits your preferences.",
            "focus_keyphrase": "fresh perfume",
            "warnings": "",
        }
        run = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            profile_version=ai.approved(self.project),
            action="draft",
            fingerprint="new-seo-draft",
            state="complete",
            reviewed_at=timezone.now(),
            result=result,
        )
        response = self.client.post(
            reverse("ai_run_action", args=[self.project.pk, run.pk, "draft"]),
            {"category_id": self.category.pk},
        )
        run.refresh_from_db()
        created = Blog.objects.get(pk=run.output_blog_id)
        self.assertRedirects(response, reverse("edit_blog", args=[created.pk]))
        self.assertEqual(created.seo_settings["title"], result["seo_title"])
        self.assertEqual(created.seo_settings["focus_keyphrase"], "fresh perfume")
        self.assertEqual(created.status, "draft")
        self.assertFalse(analysis_is_stale(created.analyses.latest("id"), created))

    def test_new_generation_requires_seo_fields_but_older_saved_results_remain_editable(
        self,
    ):
        old = {
            "title": "A useful guide",
            "excerpt": "Summary",
            "body_html": "<p>Text</p>",
            "seo_description": "A clear and useful practical description for readers who want to discover fragrance samples and understand scent families.",
            "warnings": "",
        }
        self.assertEqual(ai.validate_result("draft", old)["seo_title"], "")
        with self.assertRaises(ValidationError):
            ai.validate_result("draft", old, strict=True)
        new = {
            **old,
            "seo_title": "Fresh perfume: a useful sampling guide",
            "focus_keyphrase": "fresh perfume",
        }
        self.assertEqual(
            ai.validate_result("draft", new, strict=True)["focus_keyphrase"],
            "fresh perfume",
        )

    @override_settings(GOOGLE_CLIENT_ID="mock-id", GOOGLE_CLIENT_SECRET="mock-secret")
    def test_google_login_flow(self):
        # 1. Login page displays google login button for unauthenticated users
        self.client.logout()
        resp = self.client.get(reverse("login"))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, "Continue with Google")
        self.assertContains(resp, reverse("google_auth_start"))

        # 2. Starting Google auth redirects to Google with state
        resp = self.client.get(reverse("google_auth_start"))
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(
            resp["Location"].startswith("https://accounts.google.com/o/oauth2/v2/auth")
        )
        session = self.client.session
        flow = session.get("google_login_oauth")
        self.assertIsNotNone(flow)
        self.assertIn("state", flow)

        # 3. Callback creates new user and workspace on successful OAuth
        with patch("requests.post") as mock_post, patch("requests.get") as mock_get:
            mock_post.return_value = Mock(
                ok=True,
                status_code=200,
                json=lambda: {"access_token": "ya29.mock_token"},
            )
            mock_get.return_value = Mock(
                ok=True,
                status_code=200,
                json=lambda: {
                    "email": "newgoogleuser@example.com",
                    "email_verified": True,
                    "given_name": "Google",
                    "family_name": "Tester",
                    "name": "Google Tester",
                },
            )
            callback_resp = self.client.get(
                reverse("google_auth_callback"),
                {"state": flow["state"], "code": "mock-auth-code"},
            )
            self.assertRedirects(callback_resp, reverse("projects"))
            created_user = User.objects.get(email="newgoogleuser@example.com")
            self.assertEqual(created_user.first_name, "Google")
            self.assertEqual(created_user.username, created_user.email)
            self.assertIsNotNone(created_user.current_workspace)
            self.assertTrue(
                Membership.objects.filter(user=created_user, role="owner").exists()
            )

    def test_scheduled_status_becomes_public_at_due_time(self):
        due = timezone.now() + timedelta(days=2)
        url = reverse("blog_action", args=[self.blog.pk, "status"])
        response = self.client.post(
            url, {"status": "scheduled", "published_at": due.isoformat()}
        )
        self.assertEqual(response.status_code, 302)
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.status, "scheduled")
        self.assertFalse(Blog.objects.live().filter(pk=self.blog.pk).exists())
        with patch(
            "portal.models.timezone.now", return_value=due + timedelta(seconds=1)
        ):
            self.assertTrue(Blog.objects.live().filter(pk=self.blog.pk).exists())
            result = self.client.get(
                reverse("api_blog", args=[self.blog.slug]),
                HTTP_X_API_KEY=self.project.api_key,
            )
            self.assertEqual(result.status_code, 200)
        self.blog.status = "draft"
        self.blog.save()
        with patch("portal.models.timezone.now", return_value=due + timedelta(days=1)):
            self.assertFalse(Blog.objects.live().filter(pk=self.blog.pk).exists())

    def test_bad_schedule_does_not_change_post(self):
        url = reverse("blog_action", args=[self.blog.pk, "status"])
        original = (self.blog.status, self.blog.published_at, self.blog.revision)
        for value in (
            "",
            "not-a-date",
            "2026-99-99T12:30",
            (timezone.now() - timedelta(days=1)).isoformat(),
        ):
            response = self.client.post(
                url, {"status": "scheduled", "published_at": value}
            )
            self.assertEqual(response.status_code, 400)
            self.blog.refresh_from_db()
            self.assertEqual(
                (self.blog.status, self.blog.published_at, self.blog.revision), original
            )
        self.assertEqual(self.client.post(url, {"status": "invalid"}).status_code, 400)

    def test_google_login_errors_redirect_safely(self):
        self.client.logout()
        with override_settings(GOOGLE_CLIENT_ID="", GOOGLE_CLIENT_SECRET=""):
            self.assertRedirects(
                self.client.get(reverse("google_auth_start")), reverse("login")
            )
        for query in ({}, {"state": "bad", "code": "bad"}, {"error": "access_denied"}):
            self.assertRedirects(
                self.client.get(reverse("google_auth_callback"), query),
                reverse("login"),
            )
        with override_settings(GOOGLE_CLIENT_ID="test", GOOGLE_CLIENT_SECRET="test"):
            self.client.get(reverse("google_auth_start"))
            flow = self.client.session["google_login_oauth"]
            import requests

            with patch(
                "portal.views.requests.post",
                side_effect=requests.Timeout("private-provider-detail"),
            ):
                result = self.client.get(
                    reverse("google_auth_callback"),
                    {"state": flow["state"], "code": "fake"},
                    follow=True,
                )
            self.assertEqual(result.status_code, 200)
            self.assertContains(result, "temporarily unavailable")
            self.assertNotContains(result, "private-provider-detail")
            self.client.get(reverse("google_auth_start"))
            flow = self.client.session["google_login_oauth"]
            with patch("portal.views.requests.post", return_value=Mock(ok=False)):
                self.assertRedirects(
                    self.client.get(
                        reverse("google_auth_callback"),
                        {"state": flow["state"], "code": "fake"},
                    ),
                    reverse("login"),
                )

    def test_existing_google_style_username_can_log_in_by_email(self):
        user = User.objects.create_user(
            username="legacygoogle",
            email="legacygoogle@example.com",
            password="StrongLegacy492!",
        )
        self.client.logout()
        response = self.client.post(
            reverse("login"), {"username": user.email, "password": "StrongLegacy492!"}
        )
        self.assertRedirects(response, reverse("projects"))
        self.assertEqual(int(self.client.session["_auth_user_id"]), user.pk)
        user.refresh_from_db()
        self.assertEqual(user.username, "legacygoogle")

    @override_settings(AI_MONTHLY_REQUEST_LIMIT=5)
    def test_quota_meter_uses_enforced_platform_limit(self):
        AiSettings.objects.create(
            scope=ai.scope(self.project), enabled=True, monthly_limit=100
        )
        AiUsage.objects.create(
            scope=ai.scope(self.project),
            month=timezone.now().strftime("%Y-%m"),
            requests=5,
        )
        response = self.client.get(reverse("profile", args=[self.project.pk]))
        self.assertEqual(response.context["effective_limit"], 5)
        self.assertEqual(response.context["usage_percent"], 100)
        self.assertContains(response, "of 5 requests")

    def test_saved_blog_preview_is_private_formatted_and_read_only(self):
        self.blog.content = (
            "<h2>Preview heading</h2><p>Saved content</p><script>alert(1)</script>"
        )
        self.blog.save()
        original_revision = self.blog.revision
        response = self.client.get(reverse("blog_preview", args=[self.blog.pk]))
        self.assertContains(response, "<h2>Preview heading</h2>", html=True)
        self.assertContains(response, self.blog.title)
        self.assertContains(response, "Find a scent you enjoy.")
        self.assertNotContains(response, "<script>")
        self.assertEqual(response["X-Frame-Options"], "SAMEORIGIN")
        self.assertIn("no-store", response["Cache-Control"])
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.revision, original_revision)
        outsider = User.objects.create_user(
            username="outsider@preview.com",
            email="outsider@preview.com",
            password="GoodPassword492!",
        )
        self.client.force_login(outsider)
        self.assertEqual(
            self.client.get(reverse("blog_preview", args=[self.blog.pk])).status_code,
            403,
        )
        self.client.logout()
        self.assertEqual(
            self.client.get(reverse("blog_preview", args=[self.blog.pk])).status_code,
            302,
        )

    def test_health_overview_counts_all_pages_and_excludes_stale_scores(self):
        analysis = analyze(self.blog)
        for index in range(25):
            Blog.objects.create(
                project=self.project, user=self.owner, title=f"Unanalyzed post {index}"
            )
        response = self.client.get(reverse("health", args=[self.project.pk]))
        self.assertEqual(response.status_code, 200)
        summary = response.context["health_summary"]
        self.assertEqual(summary["total"], 26)
        self.assertEqual(summary["fresh"], 1)
        self.assertEqual(summary["unanalysed"], 25)
        self.assertEqual(summary["average_seo"], analysis.report["seo_score"])
        self.assertEqual(len(response.context["rows"]), 25)
        self.assertContains(response, "Posts quick view")
        self.assertContains(response, "conic-gradient")
        self.blog.title = "Changed title makes analysis stale"
        self.blog.save()
        response = self.client.get(reverse("health", args=[self.project.pk]))
        self.assertEqual(response.context["health_summary"]["stale"], 1)
        self.assertEqual(response.context["health_summary"]["fresh"], 0)
        self.assertIsNone(response.context["health_summary"]["average_seo"])
        self.assertEqual(SeoAnalysis.objects.filter(blog=self.blog).count(), 1)

    def test_trywryter_branding_preserves_public_api_paths(self):
        from django.template.loader import render_to_string

        self.client.logout()
        for route in ("home", "login"):
            response = self.client.get(reverse(route))
            self.assertContains(response, "TryWryter")
            self.assertNotContains(response, "BlogSaaS")
        self.assertEqual(
            render_to_string("portal/reset_subject.txt").strip(),
            "Reset your TryWryter password",
        )
        self.assertEqual(reverse("api_blogs"), "/api/v1/projects/blogs")

    def test_workspace_rename_requires_management_role(self):
        url = reverse("workspace_rename", args=[self.ws.pk])
        response = self.client.post(url, {"name": "Shopify Apps"})
        self.assertEqual(response.status_code, 302)
        self.ws.refresh_from_db()
        self.assertEqual(self.ws.name, "Shopify Apps")
        self.assertEqual(self.client.get(url).status_code, 405)
        self.assertEqual(self.client.post(url, {"name": "   "}).status_code, 400)
        other = User.objects.create_user(
            username="workspaceeditor@example.com",
            email="workspaceeditor@example.com",
            password="GoodPassword492!",
        )
        membership = Membership.objects.create(
            user=other, workspace=self.ws, role="editor"
        )
        self.client.force_login(other)
        self.assertEqual(self.client.post(url, {"name": "Blocked"}).status_code, 403)
        membership.role = "admin"
        membership.save()
        self.assertEqual(self.client.post(url, {"name": "SaaS"}).status_code, 302)
        self.ws.refresh_from_db()
        self.assertEqual(self.ws.name, "SaaS")

    def test_project_card_edit_preserves_connections_and_requires_management(self):
        key, workspace = self.project.api_key, self.project.workspace_id
        url = reverse("project_edit", args=[self.project.pk])
        response = self.client.post(
            url, {"name": "Updated project", "description": "New description"}
        )
        self.assertEqual(response.status_code, 302)
        self.project.refresh_from_db()
        self.assertEqual(self.project.name, "Updated project")
        self.assertEqual(self.project.description, "New description")
        self.assertEqual(
            (self.project.api_key, self.project.workspace_id), (key, workspace)
        )
        self.assertEqual(self.client.post(url, {"name": "  "}).status_code, 400)
        self.assertEqual(self.client.get(url).status_code, 405)
        editor = User.objects.create_user(
            username="cardeditor@example.com",
            email="cardeditor@example.com",
            password="GoodPassword492!",
        )
        Membership.objects.create(user=editor, workspace=self.ws, role="editor")
        self.client.force_login(editor)
        self.assertEqual(self.client.post(url, {"name": "Blocked"}).status_code, 403)
