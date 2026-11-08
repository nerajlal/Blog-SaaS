import uuid
from django import template

register = template.Library()


@register.simple_tag
def new_request_key():
    return str(uuid.uuid4())


@register.simple_tag
def asset_version(path):
    from pathlib import Path
    from django.contrib.staticfiles import finders

    found = finders.find(path)
    return str(Path(found).stat().st_mtime_ns) if found else "1"
