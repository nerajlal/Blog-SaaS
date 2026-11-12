from django.urls import path
from . import hosted

urlpatterns = [
    path("", hosted.index),
    path("posts/<slug:slug>", hosted.post),
    path("sitemap.xml", hosted.sitemap),
    path("robots.txt", hosted.robots),
]
