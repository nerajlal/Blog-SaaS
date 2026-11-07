from django.core.management.base import BaseCommand
from django.urls import reverse, resolve


class Command(BaseCommand):
    help = "Verify that the Django routes for all functional areas resolve."

    def handle(self, *args, **kwargs):
        cases = {
            "home": {},
            "login": {},
            "register": {},
            "password_reset": {},
            "projects": {},
            "project": {"pk": 1},
            "integration": {"pk": 1},
            "create_blog": {"pk": 1},
            "edit_blog": {"blog_id": 1},
            "autosave": {"blog_id": 1},
            "profile": {"pk": 1},
            "studio": {"pk": 1},
            "ai_run": {"pk": 1, "run_id": 1},
            "seo_settings": {"pk": 1},
            "health": {"pk": 1},
            "links": {"pk": 1},
            "telemetry": {"pk": 1},
            "search": {"pk": 1},
            "batches": {"pk": 1, "kind": "metadata"},
            "api_blogs": {},
            "api_sitemap": {},
            "api_redirect": {},
            "api_not_found": {},
        }
        for name, values in cases.items():
            resolve(reverse(name, kwargs=values))
        self.stdout.write(
            self.style.SUCCESS(
                f"{len(cases)} functional-area entry points resolve. This is a routing check, not proof of semantic parity."
            )
        )
