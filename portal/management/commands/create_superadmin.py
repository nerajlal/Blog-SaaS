from getpass import getpass
from django.contrib.auth import get_user_model
from django.contrib.auth.password_validation import validate_password
from django.core.exceptions import ValidationError
from django.core.management.base import BaseCommand, CommandError
from django.core.validators import validate_email


class Command(BaseCommand):
    help = "Create a platform superadmin. Password is entered privately, never supplied as a command argument."

    def add_arguments(self, parser):
        parser.add_argument("--email", required=True)

    def handle(self, *args, **options):
        email = options["email"].strip().lower()
        try:
            validate_email(email)
        except ValidationError as error:
            raise CommandError("Enter a valid email address.") from error
        User = get_user_model()
        if (
            User.objects.filter(email__iexact=email).exists()
            or User.objects.filter(username=email).exists()
        ):
            raise CommandError(
                "This account already exists. No permissions or password were changed. Use Django admin to review it."
            )
        user = User(username=email, email=email, first_name="Superadmin")
        password = getpass("Superadmin password: ")
        if password != getpass("Confirm password: "):
            raise CommandError("Passwords did not match. No account was created.")
        try:
            validate_password(password, user=user)
        except ValidationError as error:
            raise CommandError(" ".join(error.messages)) from error
        User.objects.create_superuser(
            username=email, email=email, first_name="Superadmin", password=password
        )
        self.stdout.write(
            self.style.SUCCESS(
                "Superadmin created. Sign in at /login and open /superadmin."
            )
        )
