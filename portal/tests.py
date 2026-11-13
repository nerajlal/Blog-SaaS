import io
import json
import tempfile
import uuid
from datetime import timedelta
from unittest.mock import patch, Mock
from django.conf import settings
from django.core.exceptions import ValidationError
from django.core import mail
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

    def test_workspace_staff_access_is_scoped_and_management_only(self):
        staff = User.objects.create_user(
            username="staff@example.com",
            email="staff@example.com",
            password="StaffPassword492!",
        )
        url = reverse("workspace_add_staff", args=[self.ws.pk])
        response = self.client.post(url, {"email": "STAFF@example.com"})
        self.assertEqual(response.status_code, 302)
        self.assertEqual(
            Membership.objects.get(workspace=self.ws, user=staff).role, "editor"
        )
        self.client.post(url, {"email": self.owner.email})
        self.assertEqual(
            Membership.objects.get(workspace=self.ws, user=self.owner).role, "owner"
        )
        self.client.force_login(staff)
        self.assertEqual(
            self.client.post(url, {"email": self.owner.email}).status_code, 403
        )
        self.assertEqual(
            self.client.get(reverse("project", args=[self.project.pk])).status_code, 200
        )
        self.client.force_login(self.owner)
        self.client.post(url, {"email": "unregistered@example.com"})
        invited = User.objects.get(email="unregistered@example.com")
        self.assertTrue(
            Membership.objects.filter(
                workspace=self.ws, user=invited, role="editor"
            ).exists()
        )
        self.assertEqual(len(mail.outbox), 1)
        self.assertIn("/reset-password/", mail.outbox[0].body)

    def test_staff_multi_workspace_selection_rejects_unmanaged_workspaces(self):
        staff = User.objects.create_user(
            username="multi@example.com", email="multi@example.com"
        )
        other = Workspace.objects.create(
            owner=staff, name="Private", slug="private-staff-test"
        )
        url = reverse("workspace_add_staff", args=[self.ws.pk])
        response = self.client.post(
            url,
            {
                "email": staff.email,
                "workspace_selection": "1",
                "workspaces": [self.ws.pk, other.pk],
            },
        )
        self.assertEqual(response.status_code, 403)
        self.assertFalse(
            Membership.objects.filter(workspace=self.ws, user=staff).exists()
        )
        Membership.objects.create(workspace=other, user=self.owner, role="admin")
        response = self.client.post(
            url,
            {
                "email": staff.email,
                "workspace_selection": "1",
                "workspaces": [self.ws.pk, other.pk],
            },
        )
        self.assertEqual(response.status_code, 302)
        self.assertEqual(
            Membership.objects.filter(user=staff, role="editor").count(), 2
        )
        self.assertEqual(
            self.client.post(
                url, {"email": staff.email, "workspace_selection": "1"}
            ).status_code,
            400,
        )

    def test_staff_removal_revokes_access_but_preserves_posts(self):
        staff = User.objects.create_user(
            username="remove@example.com", email="remove@example.com"
        )
        member = Membership.objects.create(workspace=self.ws, user=staff, role="editor")
        url = reverse("workspace_remove_staff", args=[self.ws.pk, member.pk])
        self.client.force_login(staff)
        self.assertEqual(self.client.post(url).status_code, 403)
        self.client.force_login(self.owner)
        self.assertEqual(self.client.get(url).status_code, 405)
        self.assertEqual(self.client.post(url).status_code, 302)
        self.assertFalse(Membership.objects.filter(pk=member.pk).exists())
        self.assertTrue(User.objects.filter(pk=staff.pk).exists())
        self.assertTrue(Blog.objects.filter(pk=self.blog.pk).exists())
        owner_member = Membership.objects.get(workspace=self.ws, user=self.owner)
        self.assertEqual(
            self.client.post(
                reverse("workspace_remove_staff", args=[self.ws.pk, owner_member.pk])
            ).status_code,
            403,
        )
        self.client.force_login(staff)
        self.assertEqual(
            self.client.get(reverse("project", args=[self.project.pk])).status_code, 403
        )

    def test_staff_freeze_blocks_and_restores_workspace_access(self):
        staff = User.objects.create_user(
            username="freeze@example.com", email="freeze@example.com"
        )
        member = Membership.objects.create(workspace=self.ws, user=staff, role="editor")
        url = reverse("workspace_freeze_staff", args=[self.ws.pk, member.pk])
        self.client.force_login(staff)
        self.assertEqual(self.client.post(url, {"action": "freeze"}).status_code, 403)
        self.client.force_login(self.owner)
        self.assertEqual(self.client.post(url, {"action": "freeze"}).status_code, 302)
        member.refresh_from_db()
        self.assertTrue(member.is_frozen)
        self.assertContains(self.client.get(reverse("projects")), "Frozen")
        self.client.force_login(staff)
        self.assertEqual(
            self.client.get(reverse("project", args=[self.project.pk])).status_code, 403
        )
        self.assertEqual(
            self.client.post(
                reverse("workspace_switch", args=[self.ws.pk])
            ).status_code,
            404,
        )
        self.client.force_login(self.owner)
        self.assertEqual(self.client.post(url, {"action": "unfreeze"}).status_code, 302)
        member.refresh_from_db()
        self.assertFalse(member.is_frozen)
        self.assertEqual(member.role, "editor")
        self.client.force_login(staff)
        self.assertEqual(
            self.client.get(reverse("project", args=[self.project.pk])).status_code, 200
        )

    def test_hosted_blog_publication_and_private_content(self):
        from .models import HostedSite
        from .services import article_url
        from datetime import timedelta

        site = HostedSite.objects.create(project=self.project, enabled=True)
        url = reverse("hosted_index", args=[self.project.subdomain])
        detail = reverse("hosted_post", args=[self.project.subdomain, self.blog.slug])
        self.client.logout()
        self.assertEqual(self.client.get(detail).status_code, 404)
        self.assertNotContains(self.client.get(url), self.blog.title)
        self.blog.status = "scheduled"
        self.blog.published_at = timezone.now() + timedelta(days=1)
        self.blog.save()
        self.assertEqual(self.client.get(detail).status_code, 404)
        self.blog.status = "published"
        self.blog.published_at = timezone.now()
        self.blog.content = "<script>alert(1)</script><p>Safe body</p>"
        self.blog.save()
        self.assertContains(self.client.get(url), self.blog.title)
        response = self.client.get(detail)
        self.assertContains(response, "Safe body")
        self.assertNotContains(response, "alert(1)")
        sitemap = reverse("hosted_sitemap", args=[self.project.subdomain])
        self.assertContains(self.client.get(sitemap), self.blog.slug)
        self.assertIn("/publications/", article_url(self.blog))
        self.blog.seo_settings = {"robots_index": "noindex"}
        self.blog.save()
        self.assertNotContains(self.client.get(sitemap), self.blog.slug)
        site.enabled = False
        site.save()
        self.assertEqual(self.client.get(url).status_code, 404)

    def test_hosted_domain_isolates_admin_and_requires_activation(self):
        from .models import HostedSite
        from django.test import override_settings

        site = HostedSite.objects.create(
            project=self.project,
            enabled=True,
            domain="blog.example.com",
            verified_at=timezone.now(),
        )
        with override_settings(ALLOWED_HOSTS=["testserver", "blog.example.com"]):
            self.assertEqual(
                self.client.get("/", HTTP_HOST=site.domain).status_code, 404
            )
            self.assertContains(
                self.client.get("/.well-known/trywryter-hosted", HTTP_HOST=site.domain),
                site.verification_token,
            )
            site.activated_at = timezone.now()
            site.save()
            self.assertContains(
                self.client.get("/", HTTP_HOST=site.domain), self.project.name
            )
            self.assertEqual(
                self.client.get("/admin/projects", HTTP_HOST=site.domain).status_code,
                404,
            )
            self.assertContains(
                self.client.get("/robots.txt", HTTP_HOST=site.domain),
                "https://blog.example.com/sitemap.xml",
            )

    def test_hosted_settings_require_management(self):
        staff = User.objects.create_user(
            username="hostedstaff", email="hostedstaff@example.com"
        )
        Membership.objects.create(workspace=self.ws, user=staff, role="editor")
        url = reverse("hosted_setup", args=[self.project.pk])
        self.client.force_login(staff)
        self.assertEqual(self.client.post(url, {"enabled": "1"}).status_code, 403)
        self.client.force_login(self.owner)
        self.assertEqual(
            self.client.post(url, {"enabled": "1", "domain": "localhost"}).status_code,
            400,
        )
        self.assertEqual(
            self.client.post(
                url, {"enabled": "1", "domain": "blog.example.com"}
            ).status_code,
            302,
        )
        site = self.project.hosted_site
        self.assertTrue(site.enabled)
        self.assertIsNone(site.verified_at)
        self.assertEqual(self.client.get(url).status_code, 200)

    def test_hosted_dns_verification_and_https_activation(self):
        from django.core.management.base import CommandError

        site = HostedSite.objects.create(
            project=self.project, enabled=True, domain="blog.example.com"
        )
        url = reverse("hosted_setup", args=[self.project.pk])
        with patch("portal.hosted.dns.resolver.resolve", return_value=[]):
            self.assertEqual(
                self.client.post(url, {"operation": "verify"}).status_code, 400
            )
        record = Mock(strings=[("trywryter=" + site.verification_token).encode()])
        with patch("portal.hosted.dns.resolver.resolve", return_value=[record]):
            self.assertEqual(
                self.client.post(url, {"operation": "verify"}).status_code, 302
            )
        site.refresh_from_db()
        self.assertIsNotNone(site.verified_at)
        with patch(
            "portal.management.commands.activate_hosted_domain.requests.get",
            return_value=Mock(status_code=200, text="wrong-project"),
        ):
            with self.assertRaises(CommandError):
                call_command(
                    "activate_hosted_domain", site.domain, stdout=io.StringIO()
                )
        with patch(
            "portal.management.commands.activate_hosted_domain.requests.get",
            return_value=Mock(status_code=200, text=site.verification_token),
        ):
            call_command("activate_hosted_domain", site.domain, stdout=io.StringIO())
        site.refresh_from_db()
        self.assertIsNotNone(site.activated_at)
        self.assertEqual(
            self.client.post(
                url, {"enabled": "1", "domain": "posts.example.com"}
            ).status_code,
            302,
        )
        site.refresh_from_db()
        self.assertIsNone(site.verified_at)
        self.assertIsNone(site.activated_at)

    def test_platform_integration_downloads_are_scoped_zip_files(self):
        import zipfile

        for platform, expected in [
            ("react", "app/posts/[slug]/page.jsx"),
            ("wordpress", "writepost-posts.php"),
        ]:
            url = reverse("integration_download", args=[self.project.pk, platform])
            response = self.client.get(url)
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response["Content-Type"], "application/zip")
            with zipfile.ZipFile(io.BytesIO(response.content)) as archive:
                self.assertIn(
                    "writepost-" + platform + "/" + expected, archive.namelist()
                )
                self.assertFalse(
                    any("node_modules" in name for name in archive.namelist())
                )
        self.assertEqual(
            self.client.get(
                reverse("integration_download", args=[self.project.pk, "unknown"])
            ).status_code,
            404,
        )
        outsider = User.objects.create_user(
            username="outsider-download", email="outsider-download@example.com"
        )
        self.client.force_login(outsider)
        self.assertEqual(
            self.client.get(
                reverse("integration_download", args=[self.project.pk, "wordpress"])
            ).status_code,
            403,
        )

    def test_integration_guide_uses_project_specific_code_and_closed_sections(self):
        response = self.client.get(reverse("integration", args=[self.project.pk]))
        self.assertEqual(response.status_code, 200)
        self.assertContains(
            response, "http://testserver/api/v1/projects/blogs?page=1&amp;per_page=12"
        )
        self.assertContains(response, self.project.api_key)
        self.assertContains(response, 'data-copy-code="integration-shopify"')
        self.assertNotContains(response, '<details class="platform-guide" open>')
        self.assertNotContains(response, "Set up Shopify blog subdomain")
        self.assertContains(response, "API integration on your existing store")
        from .integration_examples import examples

        code = examples("https://platform.example.com", "pk_test")
        self.assertIn("response.json()", code["react"])
        self.assertIn("encodeURIComponent(slug)", code["shopify"])
        self.assertIn("wp_safe_remote_get", code["wordpress"])

    def test_ai_studio_form_does_not_require_context_checkbox(self):
        from .forms import AiRequestForm

        form = AiRequestForm(
            data={
                "action": "outline",
                "request_key": str(uuid.uuid4()),
                "brief": "A post about fragrance",
            }
        )
        self.assertNotIn("consent", form.fields)
        self.assertTrue(form.is_valid(), form.errors)

    def test_customer_ai_branding_hides_provider_names(self):
        from .templatetags.portal_tags import customer_ai_message

        self.assertEqual(
            customer_ai_message("Claude timed out"), "WritePost AI timed out"
        )
        self.assertNotIn(
            "claude", customer_ai_message("claude-opus-example response failed").lower()
        )
        self.assertNotIn(
            "anthropic", customer_ai_message("Anthropic API error").lower()
        )
        self.client.logout()
        response = self.client.get(reverse("home"))
        self.assertNotContains(response, "Claude")
        self.assertNotContains(response, "Anthropic")

    def test_legacy_ai_body_migration_moves_only_safe_single_sections(self):
        import importlib
        from django.apps import apps

        migration = importlib.import_module(
            "portal.migrations.0009_move_ai_body_to_main_content"
        )
        run = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            action="draft",
            fingerprint="legacy-body",
            output_blog=self.blog,
        )
        original = self.blog.sections.get().text_content
        migration.move_ai_bodies(apps, connection.schema_editor())
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.content, original)
        self.assertFalse(self.blog.sections.exists())
        section = BlogSection.objects.create(
            blog=self.blog,
            text_content="Extra section",
            image_path="/storage/preserved.jpg",
        )
        self.blog.content = ""
        self.blog.save()
        migration.move_ai_bodies(apps, connection.schema_editor())
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.content, "")
        self.assertTrue(BlogSection.objects.filter(pk=section.pk).exists())
        from .forms import SectionFormSet

        formset = SectionFormSet(
            data={"sections-TOTAL_FORMS": "0", "sections-INITIAL_FORMS": "0"},
            instance=Blog(project=self.project, user=self.owner),
            prefix="sections",
        )
        self.assertTrue(formset.is_valid(), formset.errors)

    def test_simple_ai_studio_defaults_to_post_and_generates_full_draft(self):
        url = reverse("studio", args=[self.project.pk])
        response = self.client.get(url)
        self.assertEqual(response.context["selected_type"], "post")
        self.assertEqual(response.context["form"]["action"].value(), "draft")
        self.assertNotContains(response, '<label for="id_action">')
        self.assertContains(response, "Generate post")
        with patch("portal.views.ai.enqueue", return_value=Mock(pk=999)) as enqueue:
            response = self.client.post(
                url,
                {
                    "create_type": "post",
                    "brief": "Write about perfume sampling",
                    "request_key": str(uuid.uuid4()),
                },
            )
        self.assertEqual(response.status_code, 302)
        self.assertEqual(enqueue.call_args.args[2], "draft")
        self.assertIsNone(enqueue.call_args.args[5])

    def test_simple_ai_social_uses_selected_post_and_reopens_existing_version(self):
        url = reverse("studio", args=[self.project.pk])
        response = self.client.get(url, {"create": "linkedin"})
        self.assertContains(response, 'name="blog_id"')
        self.assertContains(response, self.blog.title)
        self.assertEqual(response.context["selected_type"], "linkedin")
        data = {
            "create_type": "linkedin",
            "blog_id": self.blog.pk,
            "request_key": str(uuid.uuid4()),
        }
        with patch("portal.views.ai.enqueue", return_value=Mock(pk=999)) as enqueue:
            self.assertEqual(self.client.post(url, data).status_code, 302)
        self.assertEqual(enqueue.call_args.args[2], "social")
        self.assertEqual(enqueue.call_args.args[5].pk, self.blog.pk)
        self.assertEqual(enqueue.call_args.args[6], "linkedin")
        saved = AiRun.objects.create(
            project=self.project,
            user=self.owner,
            blog=self.blog,
            action="social",
            platform="linkedin",
            state="complete",
            fingerprint="simple-social",
        )
        with patch("portal.views.ai.enqueue") as enqueue:
            response = self.client.post(url, data)
            enqueue.assert_not_called()
        self.assertEqual(
            response.url, reverse("ai_run", args=[self.project.pk, saved.pk])
        )
        self.assertEqual(
            self.client.post(
                url, {"create_type": "linkedin", "request_key": str(uuid.uuid4())}
            ).status_code,
            400,
        )

    def test_workspace_ai_defaults_enabled_without_overriding_pause(self):
        scope = ai.scope(self.project)
        config, created = AiSettings.objects.get_or_create(scope=scope)
        self.assertTrue(created)
        self.assertTrue(config.enabled)
        config.enabled = False
        config.save(update_fields=["enabled"])
        self.client.get(reverse("profile", args=[self.project.pk]))
        config.refresh_from_db()
        self.assertFalse(config.enabled)

    def test_saving_post_refreshes_its_author_profile_and_api_values(self):
        self.owner.author_name = "Updated Writer"
        self.owner.author_title = "Fragrance specialist"
        self.owner.author_avatar = "/storage/avatars/updated.jpg"
        self.owner.save()
        staff = User.objects.create_user(
            username="author-editor",
            email="author-editor@example.com",
            author_name="Another Editor",
        )
        Membership.objects.create(workspace=self.ws, user=staff, role="editor")
        self.client.force_login(staff)
        section = self.blog.sections.get()
        data = {
            "title": self.blog.title,
            "category": self.category.pk,
            "status": "published",
            "editor_revision": revision(self.blog),
            "author_name": "Stale name",
            "author_title": "Stale title",
            "sections-TOTAL_FORMS": "1",
            "sections-INITIAL_FORMS": "1",
            "sections-0-id": section.pk,
            "sections-0-blog": self.blog.pk,
            "sections-0-text_content": section.text_content,
            "sections-0-order": "0",
        }
        response = self.client.post(reverse("edit_blog", args=[self.blog.pk]), data)
        self.assertEqual(response.status_code, 302)
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.user_id, self.owner.pk)
        payload = blog_payload(self.blog)
        self.assertEqual(payload["author_name"], "Updated Writer")
        self.assertEqual(payload["author_title"], "Fragrance specialist")
        self.assertEqual(payload["author_avatar"], "/storage/avatars/updated.jpg")
        self.owner.author_title = ""
        self.owner.author_avatar = ""
        self.owner.save()
        data["editor_revision"] = revision(self.blog)
        self.assertEqual(
            self.client.post(
                reverse("edit_blog", args=[self.blog.pk]), data
            ).status_code,
            302,
        )
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.author_avatar, "")
        self.assertEqual(self.blog.author_title, "")

    def test_webp_upload_preserves_transparency_and_resizes_images(self):
        from PIL import Image

        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
        ):
            for folder, dimensions, maximum in [
                ("avatars", (1200, 600), 512),
                ("banners", (3000, 1500), 2560),
                ("sections", (100, 50), 100),
            ]:
                source = io.BytesIO()
                Image.new("RGBA", dimensions, (255, 0, 0, 0)).save(source, format="PNG")
                path = save_image(
                    SimpleUploadedFile(
                        "photo.png", source.getvalue(), content_type="image/png"
                    ),
                    folder,
                )
                self.assertTrue(path.endswith(".webp"))
                with default_storage.open(
                    path.removeprefix(settings.MEDIA_URL), "rb"
                ) as saved:
                    image = Image.open(saved)
                    self.assertEqual(image.format, "WEBP")
                    self.assertEqual(image.width, maximum)
                    self.assertEqual(image.height, maximum // 2)
                    self.assertEqual(image.getpixel((0, 0))[3], 0)
                    self.assertFalse(image.getexif())

    def test_author_bio_and_social_profiles_save_and_reach_api(self):
        url = reverse("author_profile")
        response = self.client.post(
            url,
            {
                "author_name": "Writer",
                "author_bio": "Writes about fragrances.",
                "social_linkedin": "https://www.linkedin.com/in/writer",
                "social_website": "https://writer.example.com",
            },
        )
        self.assertEqual(response.status_code, 302)
        self.owner.refresh_from_db()
        self.assertEqual(self.owner.author_bio, "Writes about fragrances.")
        self.assertEqual(
            self.owner.author_social_links["linkedin"],
            "https://www.linkedin.com/in/writer",
        )
        blog = Blog.objects.create(
            project=self.project, user=self.owner, title="New author details"
        )
        payload = blog_payload(blog)
        self.assertEqual(payload["author_bio"], self.owner.author_bio)
        self.assertEqual(payload["author_social_links"], self.owner.author_social_links)
        self.assertContains(self.client.get(url), "Author description")
        self.assertContains(self.client.get(url), "LinkedIn profile URL")
        from .forms import AuthorProfileForm

        for bad in [
            "javascript:alert(1)",
            "http://example.com",
            "https://localhost/profile",
        ]:
            form = AuthorProfileForm({"social_linkedin": bad}, instance=self.owner)
            self.assertFalse(form.is_valid())
            self.assertIn("social_linkedin", form.errors)
        form = AuthorProfileForm({"author_bio": "x" * 2001}, instance=self.owner)
        self.assertFalse(form.is_valid())

    def test_editor_author_caution_tracks_profile_completeness(self):
        url = reverse("create_blog", args=[self.project.pk])
        self.assertContains(self.client.get(url), "Complete your author profile:")
        self.owner.author_name = "Writer"
        self.owner.author_title = "Editor"
        self.owner.author_avatar = "/storage/avatar.webp"
        self.owner.author_bio = "Writes about fragrances."
        self.owner.save()
        response = self.client.get(url)
        self.assertNotContains(response, "Complete your author profile:")
        self.assertContains(response, "data-author-profile-warning hidden")
        self.assertEqual(self.owner.author_profile_missing, [])
        self.assertEqual(
            self.client.get(reverse("author_profile") + "?modal=1").status_code, 200
        )

    def test_staff_popup_groups_one_email_with_multiple_workspaces(self):
        staff = User.objects.create_user(
            username="grouped-staff", email="grouped@example.com"
        )
        other = Workspace.objects.create(
            owner=self.owner, name="Other managed workspace", slug="grouped-workspace"
        )
        Membership.objects.create(workspace=other, user=self.owner, role="owner")
        first = Membership.objects.create(workspace=self.ws, user=staff, role="editor")
        second = Membership.objects.create(
            workspace=other, user=staff, role="editor", is_frozen=True
        )
        response = self.client.get(reverse("projects"))
        self.assertContains(response, 'data-staff-email="grouped@example.com"', count=1)
        self.assertContains(
            response, reverse("workspace_remove_staff", args=[self.ws.pk, first.pk])
        )
        self.assertContains(
            response, reverse("workspace_freeze_staff", args=[other.pk, second.pk])
        )
        self.assertContains(response, "Frozen")
        self.assertContains(response, "Other managed workspace")

    def test_project_posts_show_featured_thumbnail_and_author(self):
        self.blog.cover_image = "/storage/blogs/banners/feature.webp"
        self.blog.cover_image_alt = "Featured fragrance"
        self.blog.author_name = "Example Author"
        self.blog.save()
        response = self.client.get(reverse("project", args=[self.project.pk]))
        self.assertContains(response, "/storage/blogs/banners/feature.webp")
        self.assertContains(response, 'alt="Featured fragrance"')
        self.assertContains(response, "By Example Author")
        self.assertContains(response, reverse("blog_preview", args=[self.blog.pk]))
        self.blog.cover_image = ""
        self.blog.save()
        self.assertContains(
            self.client.get(reverse("project", args=[self.project.pk])),
            "No featured image",
        )

    def test_gallery_deduplicates_images_and_scopes_to_current_workspace(self):
        self.blog.cover_image = "/storage/shared.webp"
        self.blog.author_avatar = "/storage/avatar.webp"
        self.blog.save()
        section = self.blog.sections.get()
        section.image_path = "/storage/shared.webp"
        section.save()
        self.owner.author_avatar = "/storage/avatar.webp"
        self.owner.save()
        other_user = User.objects.create_user(
            username="gallery-other", email="gallery-other@example.com"
        )
        other_ws = Workspace.objects.create(
            owner=other_user, name="Private workspace", slug="gallery-private"
        )
        other_project = Project.objects.create(
            user=other_user, workspace=other_ws, name="Private project"
        )
        Blog.objects.create(
            user=other_user,
            project=other_project,
            title="Private post",
            cover_image="/storage/private-image.webp",
        )
        response = self.client.get(reverse("gallery"))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context["page"].paginator.count, 1)
        self.assertContains(response, "shared.webp")
        self.assertNotContains(response, "private-image.webp")
        assets = {asset["path"]: asset for asset in response.context["assets"]}
        self.assertEqual(assets["/storage/shared.webp"]["count"], 2)
        self.assertEqual(len(assets["/storage/shared.webp"]["uses"]), 1)
        self.assertContains(response, "sidebar-gallery-link is-current")
        self.assertEqual(
            self.client.get(
                reverse("gallery"), {"project": other_project.pk}
            ).status_code,
            404,
        )
        response = self.client.get(
            reverse("gallery"), {"type": "section", "q": self.blog.title}
        )
        self.assertEqual(response.context["page"].paginator.count, 1)
        self.assertIn("Section image", response.context["assets"][0]["types"])
        self.assertNotContains(response, "avatar.webp")

    def test_gallery_paginates_and_allows_viewers_without_private_workspace_leaks(self):
        for index in range(26):
            Blog.objects.create(
                project=self.project,
                user=self.owner,
                title="Image " + str(index),
                cover_image="/storage/image-" + str(index) + ".webp",
            )
        viewer = User.objects.create_user(
            username="gallery-viewer",
            email="gallery-viewer@example.com",
            current_workspace=self.ws,
        )
        Membership.objects.create(workspace=self.ws, user=viewer, role="viewer")
        self.client.force_login(viewer)
        response = self.client.get(reverse("gallery"))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.context["assets"]), 24)
        self.assertEqual(response.context["page"].paginator.count, 26)
        self.assertEqual(
            len(self.client.get(reverse("gallery"), {"page": 2}).context["assets"]), 2
        )
        self.assertEqual(self.client.post(reverse("gallery")).status_code, 403)

    def test_writepost_rebrand_retains_old_diagnostics_and_dns_compatibility(self):
        from .templatetags.portal_tags import customer_ai_message

        self.assertEqual(
            customer_ai_message("TryWryter AI timed out."), "WritePost AI timed out."
        )
        site = HostedSite.objects.create(
            project=self.project,
            enabled=True,
            domain="blog.example.com",
            verified_at=timezone.now(),
        )
        with override_settings(ALLOWED_HOSTS=["testserver", "blog.example.com"]):
            for path in [
                "/.well-known/writepost-hosted",
                "/.well-known/trywryter-hosted",
            ]:
                self.assertContains(
                    self.client.get(path, HTTP_HOST=site.domain),
                    site.verification_token,
                )
        with patch(
            "portal.management.commands.activate_hosted_domain.requests.get",
            side_effect=[
                Mock(status_code=404),
                Mock(status_code=200, text=site.verification_token),
            ],
        ):
            call_command("activate_hosted_domain", site.domain, stdout=io.StringIO())
        site.refresh_from_db()
        self.assertIsNotNone(site.activated_at)

    def test_system_administration_uses_writepost_brand_assets(self):
        administrator = User.objects.create_superuser(
            username="writepost-admin",
            email="writepost-admin@example.com",
            password="AdminBrandPassword492!",
        )
        self.client.force_login(administrator)
        response = self.client.get(reverse("admin:index"))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "WritePost administration")
        self.assertContains(response, "portal/brand/writepost-white.svg")
        self.assertNotContains(response, "TryWryter")

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
                    {"category_id": self.category.pk, "status": "draft"},
                ).status_code,
                302,
            )
        created = Blog.objects.get(title="A perfume draft")
        self.assertEqual(created.status, "draft")
        self.assertEqual(created.content, run.result["body_html"])
        self.assertFalse(created.sections.exists())
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
            self.assertTrue(path.endswith(".webp"))
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
            policy.can_fetch("WritePost-AI", "https://example.com/private/account")
        )
        self.assertTrue(
            policy.can_fetch("WritePost-AI", "https://example.com/private/public")
        )
        self.assertFalse(
            policy.can_fetch("WritePost-AI", "https://example.com/page?secret=value")
        )


class AiDiagnosticsTests(TestCase):
    def test_provider_errors_are_specific_and_never_include_raw_response(self):
        import requests

        response = requests.Response()
        response.status_code = 401
        response._content = b'{"message":"secret credential or prompt"}'
        detail = ai.failure_detail(requests.HTTPError(response=response))
        self.assertIn("contact support", detail)
        self.assertIn("HTTP 401", detail)
        self.assertNotIn("Anthropic", detail)
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
        self.assertEqual(created.status, "published")
        self.assertIsNotNone(created.published_at)
        self.assertTrue(Blog.objects.live().filter(pk=created.pk).exists())
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

    def test_writepost_branding_preserves_public_api_paths(self):
        from django.template.loader import render_to_string

        self.client.logout()
        for route in ("home", "login"):
            response = self.client.get(reverse(route))
            self.assertContains(response, "WritePost")
            self.assertNotContains(response, "BlogSaaS")
            self.assertNotContains(response, "TryWryter")
            self.assertContains(response, "portal/brand/writepost-horizontal.svg")
        self.assertEqual(
            render_to_string("portal/reset_subject.txt").strip(),
            "Reset your WritePost password",
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

    def test_section_reorder_saves_without_moving_images_between_sections(self):
        from .forms import SectionFormSet

        first = self.blog.sections.first()
        first.image_path = "/storage/blogs/sections/first.jpg"
        first.save()
        second = BlogSection.objects.create(
            blog=self.blog,
            text_content="<p>Second section</p>",
            image_path="/storage/blogs/sections/second.jpg",
            order=1,
        )
        data = {
            "sections-TOTAL_FORMS": "2",
            "sections-INITIAL_FORMS": "2",
            "sections-MIN_NUM_FORMS": "1",
            "sections-MAX_NUM_FORMS": "100",
        }
        for index, section in enumerate((first, second)):
            data.update(
                {
                    f"sections-{index}-id": str(section.pk),
                    f"sections-{index}-blog": str(self.blog.pk),
                    f"sections-{index}-text_content": section.text_content,
                    f"sections-{index}-order": str(1 - index),
                    f"sections-{index}-image_alt": "",
                    f"sections-{index}-image_caption": "",
                }
            )
        formset = SectionFormSet(data, instance=self.blog, prefix="sections")
        self.assertTrue(formset.is_valid(), formset.errors)
        formset.save()
        self.assertEqual(
            list(self.blog.sections.values_list("pk", flat=True)), [second.pk, first.pk]
        )
        first.refresh_from_db()
        self.assertEqual(first.image_path, "/storage/blogs/sections/first.jpg")

    def test_author_profile_applies_to_new_posts_without_overwriting_existing(self):
        original_author = self.blog.author_name
        response = self.client.post(
            reverse("author_profile"),
            {"author_name": "Alex Writer", "author_title": "Content specialist"},
        )
        self.assertEqual(response.status_code, 302)
        self.owner.refresh_from_db()
        self.assertEqual(self.owner.author_name, "Alex Writer")
        self.blog.refresh_from_db()
        self.assertEqual(self.blog.author_name, original_author)
        from .forms import BlogForm

        new = Blog(project=self.project, user=self.owner)
        form = BlogForm(project=self.project, instance=new)
        self.assertEqual(form.initial["author_name"], "Alex Writer")
        self.owner.author_avatar = "/storage/blogs/avatars/profile.jpg"
        self.owner.save()
        created = Blog.objects.create(
            project=self.project, user=self.owner, title="New post"
        )
        self.assertEqual(created.author_name, "Alex Writer")
        self.assertEqual(created.author_title, "Content specialist")
        self.assertEqual(created.author_avatar, self.owner.author_avatar)
        custom = Blog.objects.create(
            project=self.project,
            user=self.owner,
            title="Custom author",
            author_name="Guest Author",
            author_title="Guest",
            author_avatar="/storage/blogs/avatars/guest.jpg",
        )
        self.assertEqual(custom.author_name, "Guest Author")
        self.assertEqual(custom.author_title, "Guest")
        self.assertEqual(custom.author_avatar, "/storage/blogs/avatars/guest.jpg")
        self.client.logout()
        self.assertEqual(self.client.get(reverse("author_profile")).status_code, 302)

    def test_account_settings_modal_and_password_change(self):
        url = reverse("author_profile") + "?modal=1"
        response = self.client.get(url)
        self.assertContains(response, "Author profile")
        self.assertContains(response, "Account settings")
        self.assertNotContains(response, "<html")
        response = self.client.post(
            url,
            {
                "operation": "password",
                "password-old_password": "wrong",
                "password-new_password1": "NewSafePassword492!",
                "password-new_password2": "NewSafePassword492!",
            },
        )
        self.assertEqual(response.status_code, 400)
        self.owner.refresh_from_db()
        self.assertTrue(self.owner.check_password("GoodPassword492!"))
        response = self.client.post(
            url,
            {
                "operation": "password",
                "password-old_password": "GoodPassword492!",
                "password-new_password1": "NewSafePassword492!",
                "password-new_password2": "NewSafePassword492!",
            },
        )
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "Password changed")
        self.owner.refresh_from_db()
        self.assertTrue(self.owner.check_password("NewSafePassword492!"))
        self.assertEqual(int(self.client.session["_auth_user_id"]), self.owner.pk)
        self.assertNotContains(response, "NewSafePassword492!")

    def test_account_settings_only_updates_own_display_names(self):
        original_email = self.owner.email
        response = self.client.post(
            reverse("author_profile") + "?modal=1",
            {
                "operation": "account",
                "account-first_name": "Updated",
                "account-last_name": "Writer",
                "email": "other@example.com",
                "is_superuser": "1",
            },
        )
        self.assertEqual(response.status_code, 200)
        self.owner.refresh_from_db()
        self.assertEqual(self.owner.first_name, "Updated")
        self.assertEqual(self.owner.email, original_email)
        self.assertFalse(self.owner.is_superuser)


class ExcelImportTests(TestCase):
    setUp = PortalTests.setUp

    def workbook(self, posts, formula=False):
        import zipfile
        from xml.sax.saxutils import escape

        columns = [
            "ID",
            "Title",
            "Slug",
            "Category",
            "Status",
            "Published At",
            "Author Name",
            "Author Title",
            "Author Avatar",
            "Cover Image",
            "Image Credits",
            "Excerpt",
            "Content (Plain Text)",
            "Content (HTML)",
            "Section Images",
            "Created At",
            "Updated At",
        ]
        rows = [columns] + [
            [post.get(column, "") for column in columns] for post in posts
        ]
        markup = []
        for index, row in enumerate(rows, 1):
            cells = "".join(
                '<c r="'
                + chr(65 + col)
                + str(index)
                + '" t="inlineStr"><is><t>'
                + escape(str(value))
                + "</t></is>"
                + ("<f>1+1</f>" if formula and index == 2 and col == 1 else "")
                + "</c>"
                for col, value in enumerate(row)
            )
            markup.append('<row r="' + str(index) + '">' + cells + "</row>")
        output = io.BytesIO()
        with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr(
                "xl/workbook.xml",
                '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Posts" sheetId="1" r:id="rId1"/></sheets></workbook>',
            )
            archive.writestr(
                "xl/_rels/workbook.xml.rels",
                '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            )
            archive.writestr(
                "xl/worksheets/sheet1.xml",
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                + "".join(markup)
                + "</sheetData></worksheet>",
            )
        return SimpleUploadedFile(
            "posts.xlsx",
            output.getvalue(),
            content_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        )

    def source(self, **extra):
        return {
            "ID": "188",
            "Title": "Imported perfume guide",
            "Slug": "imported-perfume-guide",
            "Category": "Imported guides",
            "Status": "Published",
            "Published At": "2026-01-02 10:00:00",
            "Author Name": "Exported Writer",
            "Author Title": "Specialist",
            "Content (HTML)": "<p>Introduction.</p>",
            **extra,
        }

    def upload(self, posts, authors="profile"):
        return self.client.post(
            reverse("post_import", args=[self.project.pk]),
            {
                "workbook": self.workbook(posts),
                "authors": authors,
                "date_timezone": "Asia/Kolkata",
            },
        )

    def test_preview_confirmation_and_background_import_with_webp_images(self):
        from .tasks import execute
        from PIL import Image

        image = io.BytesIO()
        Image.new("RGB", (40, 20), "red").save(image, format="PNG")
        source = self.source(
            **{
                "Cover Image": "https://images.example.com/cover.png",
                "Content (HTML)": '<p>Introduction.</p><figure><img src="https://images.example.com/section.png" alt="Perfume samples"><figcaption>Sample set</figcaption></figure><p>After image.</p><script>unsafe()</script>',
                "Section Images": "https://images.example.com/section.png",
            }
        )
        self.owner.author_bio = "Our author"
        self.owner.save()
        before = Blog.objects.count()
        with patch("portal.excel_import.fetch_public") as fetch:
            response = self.upload([source])
            fetch.assert_not_called()
        self.assertEqual(response.status_code, 302)
        batch = PostImport.objects.get()
        self.assertEqual(Blog.objects.count(), before)
        self.assertEqual(WorkItem.objects.count(), 0)
        preview_url = reverse("post_import_preview", args=[self.project.pk, batch.pk])
        self.assertContains(self.client.get(preview_url), "1 new posts ready")
        self.assertEqual(self.client.post(preview_url).status_code, 400)
        for _ in range(2):
            self.assertEqual(
                self.client.post(preview_url, {"confirm": "1"}).status_code, 302
            )
        self.assertEqual(WorkItem.objects.filter(import_batch=batch).count(), 1)
        item = WorkItem.objects.get(import_batch=batch)
        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
            patch(
                "portal.excel_import.fetch_public",
                return_value={"status": 200, "body": image.getvalue()},
            ),
        ):
            execute(item)
            execute(item)
            blog = Blog.objects.get(slug="imported-perfume-guide")
            self.assertNotEqual(blog.pk, 188)
            self.assertEqual(blog.author_bio, "Our author")
            self.assertEqual(blog.published_at.hour, 4)
            self.assertIn("Introduction.", blog.content)
            self.assertNotIn("unsafe()", blog.content)
            self.assertTrue(blog.cover_image.endswith(".webp"))
            self.assertEqual(blog.sections.count(), 1)
            section = blog.sections.get()
            self.assertEqual(section.image_alt, "Perfume samples")
            self.assertEqual(section.image_caption, "Sample set")
            self.assertIn("After image.", section.text_content)
            self.assertTrue(section.image_path.endswith(".webp"))
            self.assertTrue(Blog.objects.live().filter(pk=blog.pk).exists())
            batch.refresh_from_db()
            self.assertEqual(batch.results["0"]["state"], "created")
        self.assertEqual(AiUsage.objects.count(), 0)

    def test_truncated_html_requires_explicit_recovery_and_warns(self):
        from .excel_import import preview

        source = self.source(
            **{
                "Content (HTML)": '<p>Start</p><img src="data:image/png;base64,unfinished',
                "Content (Plain Text)": "Complete article text.\n\nIncluding the ending.",
            }
        )
        rows = preview(self.workbook([source]), self.project, "UTC", recover_html=False)
        self.assertTrue(rows[0]["errors"])
        rows = preview(self.workbook([source]), self.project, "UTC", recover_html=True)
        self.assertFalse(rows[0]["errors"])
        self.assertIn("Including the ending.", rows[0]["parts"][0]["html"])
        self.assertIn("Incomplete HTML image data", rows[0]["warnings"][0])

    def test_complete_embedded_image_is_converted_without_network(self):
        from .tasks import execute
        from PIL import Image
        import base64

        image = io.BytesIO()
        Image.new("RGB", (20, 20), "red").save(image, format="PNG")
        uri = "data:image/png;base64," + base64.b64encode(image.getvalue()).decode()
        self.upload(
            [
                self.source(
                    **{
                        "Content (HTML)": '<p>Start</p><img src="'
                        + uri
                        + '"><p>End</p>'
                    }
                )
            ],
            authors="file",
        )
        batch = PostImport.objects.get()
        url = reverse("post_import_preview", args=[self.project.pk, batch.pk])
        self.assertEqual(self.client.get(url).status_code, 200)
        self.client.post(url, {"confirm": "1"})
        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
            patch("portal.excel_import.fetch_public") as fetch,
        ):
            execute(WorkItem.objects.get(import_batch=batch))
            fetch.assert_not_called()
            blog = Blog.objects.get(slug="imported-perfume-guide")
            self.assertEqual(blog.author_name, "Exported Writer")
            self.assertEqual(blog.author_title, "Specialist")
            self.assertTrue(blog.sections.get().image_path.endswith(".webp"))
            batch.refresh_from_db()
            self.assertEqual(batch.payload["rows"], [])
            self.assertEqual(self.client.get(url).status_code, 200)

    def test_existing_duplicate_and_foreign_slugs_are_not_overwritten(self):
        from .excel_import import preview

        foreign_project = Project.objects.create(user=self.owner, name="Foreign")
        foreign = Blog.objects.create(
            project=foreign_project,
            user=self.owner,
            title="Foreign",
            slug="foreign-post",
        )
        rows = preview(
            self.workbook(
                [
                    self.source(Slug=self.blog.slug),
                    self.source(Slug=foreign.slug),
                    self.source(Slug="repeated"),
                    self.source(Slug="repeated"),
                ]
            ),
            self.project,
            "UTC",
        )
        self.assertTrue(rows[0]["skip"])
        self.assertTrue(rows[1]["errors"])
        self.assertFalse(rows[2]["errors"])
        self.assertTrue(rows[3]["errors"])
        self.assertEqual(Blog.objects.get(pk=foreign.pk).title, "Foreign")

    def test_import_rejects_formulas_invalid_urls_and_missing_headers(self):
        from .excel_import import preview, read_rows, xml

        with self.assertRaises(ValidationError):
            read_rows(self.workbook([self.source()], formula=True))
        with self.assertRaises(ValidationError):
            xml(b'<!DOCTYPE x [<!ENTITY a "boom">]><x/>')
        rows = preview(
            self.workbook(
                [
                    self.source(**{"Cover Image": "http://127.0.0.1/private"}),
                    self.source(
                        Slug="bad-image",
                        **{"Content (HTML)": '<img src="javascript:alert(1)">'},
                    ),
                    self.source(Slug="bad-date", **{"Published At": "not-a-date"}),
                ]
            ),
            self.project,
            "UTC",
        )
        self.assertTrue(all(row["errors"] for row in rows))

    def test_import_permissions_preview_ownership_and_expiration(self):
        viewer = User.objects.create_user(
            username="import-viewer", email="import-viewer@example.com"
        )
        Membership.objects.create(workspace=self.ws, user=viewer, role="viewer")
        self.client.force_login(viewer)
        self.assertEqual(
            self.client.get(reverse("post_import", args=[self.project.pk])).status_code,
            403,
        )
        self.client.force_login(self.owner)
        self.upload([self.source()])
        batch = PostImport.objects.get()
        editor = User.objects.create_user(
            username="import-editor", email="import-editor@example.com"
        )
        Membership.objects.create(workspace=self.ws, user=editor, role="editor")
        self.client.force_login(editor)
        url = reverse("post_import_preview", args=[self.project.pk, batch.pk])
        self.assertEqual(self.client.get(url).status_code, 404)
        self.client.force_login(self.owner)
        PostImport.objects.filter(pk=batch.pk).update(
            created_at=timezone.now() - timedelta(days=2)
        )
        self.assertEqual(self.client.post(url, {"confirm": "1"}).status_code, 400)
        self.assertEqual(WorkItem.objects.count(), 0)

    def test_failed_image_download_leaves_no_partial_post(self):
        from .tasks import execute
        from PIL import Image
        from pathlib import Path

        image = io.BytesIO()
        Image.new("RGB", (10, 10), "red").save(image, format="PNG")
        self.upload(
            [
                self.source(
                    **{
                        "Cover Image": "https://images.example.com/cover.png",
                        "Content (HTML)": '<p>Text</p><img src="https://images.example.com/bad.png">',
                    }
                )
            ]
        )
        batch = PostImport.objects.get()
        self.client.post(
            reverse("post_import_preview", args=[self.project.pk, batch.pk]),
            {"confirm": "1"},
        )
        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
            patch(
                "portal.excel_import.fetch_public",
                side_effect=[
                    {"status": 200, "body": image.getvalue()},
                    {"status": 200, "body": b"not an image"},
                ],
            ),
        ):
            execute(WorkItem.objects.get(import_batch=batch))
            self.assertFalse(
                Blog.objects.filter(slug="imported-perfume-guide").exists()
            )
            self.assertFalse(any(path.is_file() for path in Path(directory).rglob("*")))
        batch.refresh_from_db()
        self.assertEqual(batch.results["0"]["state"], "failed")


class GalleryUploadTests(TestCase):
    setUp = PortalTests.setUp

    def image(self, name="gallery.png"):
        from PIL import Image

        output = io.BytesIO()
        Image.new("RGBA", (80, 40), (255, 0, 0, 0)).save(output, format="PNG")
        return SimpleUploadedFile(name, output.getvalue(), content_type="image/png")

    def test_gallery_direct_uploads_are_webp_and_workspace_scoped(self):
        from PIL import Image

        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
        ):
            response = self.client.post(
                reverse("gallery"),
                {
                    "images": [self.image("one.png"), self.image("two.png")],
                    "project": self.project.pk,
                },
            )
            self.assertEqual(response.status_code, 302)
            self.assertIn("type=uploaded", response.url)
            records = list(GalleryImage.objects.filter(workspace=self.ws))
            self.assertEqual(len(records), 2)
            for record in records:
                self.assertEqual(record.project_id, self.project.pk)
                self.assertTrue(record.path.endswith(".webp"))
                self.assertTrue(record.name.endswith(".webp"))
                with default_storage.open(
                    record.path.removeprefix(settings.MEDIA_URL), "rb"
                ) as saved:
                    image = Image.open(saved)
                    self.assertEqual(image.format, "WEBP")
                    self.assertEqual(image.getpixel((0, 0))[3], 0)
            response = self.client.get(
                reverse("gallery"), {"type": "uploaded", "q": "one.webp"}
            )
            self.assertEqual(response.context["page"].paginator.count, 1)
            self.assertContains(response, "Not attached to a post yet")
            self.assertContains(response, "Copy URL")
            self.assertEqual(
                self.client.get(reverse("gallery"), {"project": self.project.pk})
                .context["page"]
                .paginator.count,
                2,
            )
            outsider = User.objects.create_user(
                username="gallery-upload-outsider",
                email="gallery-upload-outsider@example.com",
            )
            other = Workspace.objects.create(
                owner=outsider, name="Other", slug="gallery-upload-other"
            )
            Membership.objects.create(workspace=other, user=outsider, role="owner")
            outsider.current_workspace = other
            outsider.save()
            self.client.force_login(outsider)
            self.assertEqual(
                self.client.get(reverse("gallery")).context["page"].paginator.count, 0
            )

    def test_gallery_upload_rejects_invalid_files_and_foreign_projects(self):
        self.assertEqual(
            self.client.post(
                reverse("gallery"), {"images": SimpleUploadedFile("bad.svg", b"<svg/>")}
            ).status_code,
            400,
        )
        self.assertEqual(GalleryImage.objects.count(), 0)
        foreign = Project.objects.create(user=self.owner, name="Foreign project")
        self.assertEqual(
            self.client.post(
                reverse("gallery"), {"images": self.image(), "project": foreign.pk}
            ).status_code,
            400,
        )
        self.assertEqual(GalleryImage.objects.count(), 0)
        self.assertEqual(
            self.client.post(
                reverse("gallery"),
                {"images": [self.image(str(i) + ".png") for i in range(6)]},
            ).status_code,
            400,
        )

    def test_gallery_does_not_list_profile_photos(self):
        self.owner.author_avatar = "/storage/profile-only.webp"
        self.owner.save()
        self.blog.author_avatar = "/storage/post-author-only.webp"
        self.blog.save()
        response = self.client.get(reverse("gallery"))
        self.assertEqual(response.context["page"].paginator.count, 0)
        self.assertNotContains(response, "profile-only.webp")
        self.assertNotContains(response, "post-author-only.webp")
        self.assertNotContains(response, "Author photos")

    def test_gallery_failed_batch_cleans_up_prior_files(self):
        from pathlib import Path

        calls = []

        def save_or_fail(upload, folder):
            if calls:
                raise OSError("Storage unavailable")
            path = save_image(upload, folder)
            calls.append(path)
            return path

        with (
            tempfile.TemporaryDirectory() as directory,
            override_settings(MEDIA_ROOT=directory),
            patch("portal.gallery.save_image", side_effect=save_or_fail),
        ):
            self.assertEqual(
                self.client.post(
                    reverse("gallery"),
                    {"images": [self.image("one.png"), self.image("two.png")]},
                ).status_code,
                400,
            )
            self.assertEqual(GalleryImage.objects.count(), 0)
            self.assertFalse(any(path.is_file() for path in Path(directory).rglob("*")))


class GalleryPickerTests(TestCase):
    setUp = PortalTests.setUp

    def asset(self):
        return GalleryImage.objects.create(
            workspace=self.ws,
            uploaded_by=self.owner,
            path="/storage/blogs/gallery/reuse.webp",
            name="Reusable image.webp",
        )

    def test_create_post_reuses_gallery_image_for_featured_and_section(self):
        asset = self.asset()
        data = {
            "title": "Reuse gallery image",
            "category": self.category.pk,
            "status": "draft",
            "content": "<p>Main post</p>",
            "gallery_image": asset.path,
            "sections-TOTAL_FORMS": "1",
            "sections-INITIAL_FORMS": "0",
            "sections-0-gallery_image": asset.path,
            "sections-0-text_content": "<p>Section text</p>",
            "sections-0-order": "0",
        }
        with patch("portal.views.save_image") as upload:
            response = self.client.post(
                reverse("create_blog", args=[self.project.pk]), data
            )
            upload.assert_not_called()
        self.assertEqual(response.status_code, 302)
        blog = Blog.objects.get(title="Reuse gallery image")
        self.assertEqual(blog.cover_image, asset.path)
        self.assertEqual(blog.sections.get().image_path, asset.path)
        self.assertEqual(GalleryImage.objects.count(), 1)
        response = self.client.get(reverse("edit_blog", args=[blog.pk]))
        self.assertContains(response, "Choose from gallery")
        self.assertContains(response, asset.path)

    def test_picker_and_form_reject_cross_workspace_images(self):
        asset = self.asset()
        other_ws = Workspace.objects.create(
            owner=self.owner, name="Other media workspace", slug="other-media"
        )
        foreign = GalleryImage.objects.create(
            workspace=other_ws,
            uploaded_by=self.owner,
            path="/storage/foreign.webp",
            name="Foreign",
        )
        self.owner.author_avatar = "/storage/profile-only.webp"
        self.owner.save()
        response = self.client.get(reverse("gallery_picker", args=[self.project.pk]))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            [row["path"] for row in response.json()["images"]], [asset.path]
        )
        from .forms import BlogForm, SectionForm

        form = BlogForm(
            {
                "title": "Invalid media",
                "category": self.category.pk,
                "status": "draft",
                "gallery_image": foreign.path,
            },
            instance=Blog(project=self.project, user=self.owner),
            project=self.project,
        )
        self.assertFalse(form.is_valid())
        self.assertIn("gallery_image", form.errors)
        section = SectionForm({"gallery_image": foreign.path}, project=self.project)
        self.assertFalse(section.is_valid())
        viewer = User.objects.create_user(
            username="picker-viewer", email="picker-viewer@example.com"
        )
        Membership.objects.create(workspace=self.ws, user=viewer, role="viewer")
        self.client.force_login(viewer)
        self.assertEqual(
            self.client.get(
                reverse("gallery_picker", args=[self.project.pk])
            ).status_code,
            403,
        )

    def test_gallery_selection_survives_autosave_and_rejects_invalid_reference(self):
        asset = self.asset()
        url = reverse("autosave", args=[self.blog.pk])
        payload = {
            "version": 0,
            "base_hash": revision(self.blog),
            "snapshot": {
                "gallery_image": asset.path,
                "sections": [
                    {
                        "section_id": str(self.blog.sections.get().pk),
                        "gallery_image": asset.path,
                    }
                ],
            },
        }
        response = self.client.post(
            url, json.dumps(payload), content_type="application/json"
        )
        self.assertEqual(response.status_code, 200)
        restored = self.client.get(url).json()["snapshot"]
        self.assertEqual(restored["gallery_image"], asset.path)
        self.assertEqual(restored["sections"][0]["gallery_image"], asset.path)
        payload["version"] = response.json()["version"]
        payload["snapshot"]["gallery_image"] = "/storage/unavailable.webp"
        self.assertEqual(
            self.client.post(
                url, json.dumps(payload), content_type="application/json"
            ).status_code,
            400,
        )

    def test_cannot_submit_new_upload_and_gallery_selection_together(self):
        from PIL import Image
        from .forms import BlogForm

        asset = self.asset()
        image = io.BytesIO()
        Image.new("RGB", (10, 10), "red").save(image, format="PNG")
        form = BlogForm(
            {
                "title": "Two images",
                "category": self.category.pk,
                "status": "draft",
                "gallery_image": asset.path,
            },
            files={"banner_image": SimpleUploadedFile("image.png", image.getvalue())},
            instance=Blog(project=self.project, user=self.owner),
            project=self.project,
        )
        self.assertFalse(form.is_valid())
        self.assertIn("gallery_image", form.errors)
