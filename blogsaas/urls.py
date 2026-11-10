from django.conf import settings
from django.conf.urls.static import static
from django.contrib import admin
from django.contrib.auth import views as auth
from django.urls import path
from portal import views as v
from portal import google_tools
from portal import superadmin
from portal.auth_views import LimitedPasswordResetView

urlpatterns = [
    path("", v.home, name="home"),
    path(
        "admin/projects/<int:pk>/search/tools/<str:tab>",
        google_tools.hub,
        name="analytics_hub",
    ),
    path(
        "admin/projects/<int:pk>/sitemap.xml",
        google_tools.sitemap_download,
        name="sitemap_download",
    ),
    path("docs", v.home, name="docs"),
    path("admin/docs", v.admin_docs, name="admin_docs"),
    path("login", v.sign_in, name="login"),
    path("register", v.register, name="register"),
    path("logout", v.sign_out, name="logout"),
    path(
        "forgot-password",
        LimitedPasswordResetView.as_view(
            template_name="portal/password.html",
            email_template_name="portal/reset_email.txt",
            success_url="/forgot-password/sent",
        ),
        name="password_reset",
    ),
    path(
        "forgot-password/sent",
        auth.PasswordResetDoneView.as_view(template_name="portal/password_done.html"),
        name="password_reset_done",
    ),
    path(
        "reset-password/<uidb64>/<token>",
        auth.PasswordResetConfirmView.as_view(
            template_name="portal/password.html", success_url="/reset-password/done"
        ),
        name="password_reset_confirm",
    ),
    path(
        "reset-password/done",
        auth.PasswordResetCompleteView.as_view(
            template_name="portal/password_done.html"
        ),
        name="password_reset_complete",
    ),
    path("workspaces", v.workspace_action, name="workspace_create"),
    path("workspaces/<int:pk>/switch", v.workspace_action, name="workspace_switch"),
    path("admin/login", v.sign_in, name="admin_login"),
    path("admin/projects", v.projects, name="projects"),
    path("admin/projects/<int:pk>", v.project_detail, name="project"),
    path(
        "admin/projects/<int:pk>/integration",
        v.project_detail,
        {"integration": True},
        name="integration",
    ),
    path("admin/projects/<int:pk>/delete", v.project_delete, name="project_delete"),
    path(
        "admin/projects/<int:pk>/categories", v.category_action, name="category_create"
    ),
    path(
        "admin/projects/<int:pk>/categories/<int:category_id>/delete",
        v.category_action,
        name="category_delete",
    ),
    path("admin/projects/<int:pk>/blogs/create", v.edit_blog, name="create_blog"),
    path("admin/blogs/<int:blog_id>/edit", v.edit_blog, name="edit_blog"),
    path(
        "admin/projects/<int:pk>/blogs/<int:blog_id>/social/<str:platform>",
        v.social_version,
        name="social_version",
    ),
    path("admin/blogs/<int:blog_id>/autosave", v.autosave, name="autosave"),
    path("admin/blogs/<int:blog_id>/seo/report", v.report, name="report"),
    path("admin/blogs/<int:blog_id>/links", v.blog_links, name="blog_links"),
    path("admin/blogs/<int:blog_id>/<str:action>", v.blog_action, name="blog_action"),
    path("admin/projects/<int:pk>/details", v.profile, name="profile"),
    path(
        "admin/projects/<int:pk>/details/<str:action>",
        v.profile_action,
        name="profile_action",
    ),
    path("admin/projects/<int:pk>/ai", v.studio, name="studio"),
    path("admin/projects/<int:pk>/ai/<int:run_id>", v.ai_run, name="ai_run"),
    path(
        "admin/projects/<int:pk>/ai/<int:run_id>/<str:action>",
        v.ai_run,
        name="ai_run_action",
    ),
    path("admin/projects/<int:pk>/seo", v.seo_settings, name="seo_settings"),
    path("admin/projects/<int:pk>/seo/verify", v.verify_domain, name="verify_domain"),
    path("admin/projects/<int:pk>/seo/health", v.health, name="health"),
    path(
        "admin/projects/<int:pk>/seo/health/status/<int:job_id>",
        v.health_status,
        name="health_status",
    ),
    path("admin/projects/<int:pk>/links", v.links, name="links"),
    path("admin/projects/<int:pk>/not-found", v.telemetry, name="telemetry"),
    path("admin/search-console/callback", v.google_callback, name="google_callback"),
    path("admin/projects/<int:pk>/search", v.search_console, name="search"),
    path(
        "admin/projects/<int:pk>/search/reports/<int:report_id>",
        v.search_export,
        name="search_export",
    ),
    path(
        "admin/projects/<int:pk>/search/<str:operation>",
        v.search_console,
        name="search_action",
    ),
    path(
        "admin/projects/<int:pk>/metadata",
        v.batches,
        {"kind": "metadata"},
        name="metadata",
    ),
    path(
        "admin/projects/<int:pk>/redirects",
        v.batches,
        {"kind": "redirects"},
        name="redirects",
    ),
    path("admin/projects/<int:pk>/tools/<str:kind>", v.batches, name="batches"),
    path(
        "admin/projects/<int:pk>/tools/<str:kind>/<str:operation>",
        v.batches,
        name="batch_operation",
    ),
    path(
        "admin/projects/<int:pk>/tools/<str:kind>/<int:batch_id>/<str:operation>",
        v.batches,
        name="batch_action",
    ),
    path("api/v1/projects/blogs", v.api_blogs, name="api_blogs"),
    path("api/v1/projects/blogs/<slug:slug>", v.api_blogs, name="api_blog"),
    path("api/v1/projects/sitemap.xml", v.api_sitemap, name="api_sitemap"),
    path("api/v1/projects/redirect", v.api_redirect, name="api_redirect"),
    path("api/v1/projects/not-found", v.api_not_found, name="api_not_found"),
    path("superadmin", superadmin.dashboard, name="superadmin"),
    path(
        "superadmin/clients",
        superadmin.dashboard,
        {"section": "clients"},
        name="superadmin_clients",
    ),
    path(
        "superadmin/workspaces",
        superadmin.dashboard,
        {"section": "workspaces"},
        name="superadmin_workspaces",
    ),
    path(
        "superadmin/usage",
        superadmin.dashboard,
        {"section": "usage"},
        name="superadmin_usage",
    ),
    path(
        "superadmin/usage.csv", superadmin.usage_export, name="superadmin_usage_export"
    ),
    path("django-admin/", admin.site.urls),
]
if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
