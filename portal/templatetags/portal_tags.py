import uuid
from django import template

register = template.Library()


@register.simple_tag
def new_request_key():
    return str(uuid.uuid4())
