from django.contrib import admin
from django.contrib.auth.admin import UserAdmin
from .models import User, Workspace, Membership, Project, Category, Blog, AiSettings

admin.site.register(User, UserAdmin)
for model in [Workspace, Membership, Project, Category, Blog]:
    admin.site.register(model)

admin.site.site_header = "WritePost platform administration"
admin.site.site_title = "WritePost administration"
admin.site.index_title = "Platform overview"
admin.site.index_template = "portal/superadmin_index.html"


@admin.register(AiSettings)
class AiSettingsAdmin(admin.ModelAdmin):
    list_display = ("scope", "enabled", "monthly_limit")
    search_fields = ("scope",)

    def has_module_permission(self, request):
        return request.user.is_active and request.user.is_superuser

    def has_view_permission(self, request, obj=None):
        return request.user.is_active and request.user.is_superuser

    has_add_permission = has_view_permission
    has_change_permission = has_view_permission
    has_delete_permission = has_view_permission
