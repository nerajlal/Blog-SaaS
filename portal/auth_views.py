from django.contrib.auth.views import PasswordResetView
from django.core.exceptions import ValidationError
from django.http import HttpResponse
from .services import rate_limit


class LimitedPasswordResetView(PasswordResetView):
    subject_template_name = "portal/reset_subject.txt"
    def dispatch(self, request, *args, **kwargs):
        if request.method == "POST":
            try:
                rate_limit(request, "password-reset", 5, 60)
            except ValidationError:
                return HttpResponse(
                    "Too many reset requests. Try again later.", status=429
                )
        return super().dispatch(request, *args, **kwargs)
