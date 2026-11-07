from django.contrib import admin
from django.contrib.auth.admin import UserAdmin
from .models import User, Workspace, Membership, Project, Category, Blog

admin.site.register(User, UserAdmin)
for model in [Workspace, Membership, Project, Category, Blog]:
    admin.site.register(model)
