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


@register.filter
def customer_ai_message(value):
    """Keep historical provider diagnostics out of customer-facing messages."""
    import re

    return re.sub(
        r"\b(?:claude(?:-[a-z0-9]+)*|anthropic|trywryter(?:\s+ai)?)\b",
        "WritePost AI",
        str(value or ""),
        flags=re.I,
    )


@register.filter
def public_media_url(path, defaults):
    from portal.services import image_url

    return image_url(path, defaults)
