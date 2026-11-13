from django.http import HttpResponse
from django.utils.cache import patch_cache_control, patch_vary_headers


class PrivatePagesMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        response = self.get_response(request)
        if request.path.startswith(("/admin/", "/api/")):
            patch_cache_control(response, private=True, no_store=True)
        return response


class PublicApiCorsMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        from .models import Project

        site = request.headers.get("Origin", "")
        public = (
            request.path.startswith("/api/v1/projects/")
            and request.path != "/api/v1/projects/not-found"
        )
        if public and request.method == "OPTIONS":
            allowed = bool(
                site
                and Project.objects.filter(
                    seo_verified_at__isnull=False, seo_settings__public_url=site
                ).exists()
            )
            response = HttpResponse(status=204 if allowed else 403)
            if allowed:
                response["Access-Control-Allow-Origin"] = site
                response["Access-Control-Allow-Methods"] = "GET, OPTIONS"
                response["Access-Control-Allow-Headers"] = "X-API-KEY, Content-Type"
                patch_vary_headers(response, ["Origin"])
            return response
        response = self.get_response(request)
        project = getattr(request, "api_project", None)
        if (
            public
            and site
            and project
            and project.seo_verified_at
            and project.seo_settings.get("public_url") == site
        ):
            response["Access-Control-Allow-Origin"] = site
            patch_vary_headers(response, ["Origin"])
        return response


class HostedDomainMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        from urllib.parse import urlsplit
        from django.conf import settings
        from .models import HostedSite

        host = request.get_host().split(":")[0].lower()
        if host not in [
            urlsplit(settings.PUBLIC_URL).hostname,
            "localhost",
            "127.0.0.1",
            "testserver",
            "[::1]",
        ]:
            site = (
                HostedSite.objects.select_related("project").filter(domain=host).first()
            )
            if not site or not site.enabled or not site.verified_at:
                return HttpResponse(status=404)
            if request.path in [
                "/.well-known/writepost-hosted",
                "/.well-known/trywryter-hosted",
            ] and request.method in [
                "GET",
                "HEAD",
            ]:
                return HttpResponse(site.verification_token, content_type="text/plain")
            if not site.activated_at:
                return HttpResponse(
                    "Hosted blog setup is pending HTTPS activation.", status=404
                )
            request.hosted_site = site
            request.urlconf = "portal.hosted_urls"
        return self.get_response(request)
