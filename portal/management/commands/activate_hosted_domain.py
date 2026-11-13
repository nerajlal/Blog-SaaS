import requests
from django.core.management.base import BaseCommand, CommandError
from django.utils import timezone
from portal.models import HostedSite


class Command(BaseCommand):
    help = "Activate a verified hosted subdomain after nginx and HTTPS provisioning."

    def add_arguments(self, parser):
        parser.add_argument("domain")

    def handle(self, *args, **options):
        site = HostedSite.objects.filter(
            domain=options["domain"].lower(), enabled=True, verified_at__isnull=False
        ).first()
        if not site:
            raise CommandError("Enable hosting and verify DNS ownership first.")
        try:
            response = requests.get(
                "https://" + site.domain + "/.well-known/writepost-hosted",
                timeout=15,
                allow_redirects=False,
            )
            if response.status_code == 404:
                response = requests.get(
                    "https://" + site.domain + "/.well-known/trywryter-hosted",
                    timeout=15,
                    allow_redirects=False,
                )
            if (
                response.status_code != 200
                or response.text.strip() != site.verification_token
            ):
                raise CommandError(
                    "HTTPS challenge failed. Check DNS, certificate, allowed hosts and nginx proxy routing."
                )
        except requests.RequestException as exc:
            raise CommandError(
                "HTTPS verification failed. Configure a valid certificate and proxy first."
            ) from exc
        activated = HostedSite.objects.filter(
            pk=site.pk,
            domain=site.domain,
            verification_token=site.verification_token,
            enabled=True,
            verified_at=site.verified_at,
        ).update(activated_at=timezone.now(), updated_at=timezone.now())
        if not activated:
            raise CommandError(
                "Domain settings changed during activation; verify and try again."
            )
        self.stdout.write(
            self.style.SUCCESS("Hosted subdomain activated: https://" + site.domain)
        )
