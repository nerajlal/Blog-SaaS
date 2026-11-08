"""Start the local website and background worker together."""

import subprocess
import sys
from pathlib import Path
from django.conf import settings
from django.core.management.base import BaseCommand


class Command(BaseCommand):
    help = "Run the development web server and database worker together (Ctrl+C stops both)."

    def add_arguments(self, parser):
        parser.add_argument("--port", type=int, default=8001)

    def handle(self, *args, **options):
        if not settings.DEBUG:
            self.stderr.write("Use separate managed web/worker services in production.")
            return
        command = [sys.executable, str(Path(settings.BASE_DIR) / "manage.py")]
        children = []
        try:
            # Reuse a worker already started for this checkout; the worker lock prevents duplicates.
            children.append(subprocess.Popen(command + ["work"]))
            children.append(
                subprocess.Popen(
                    command + ["runserver", f"127.0.0.1:{options['port']}"]
                )
            )
            self.stdout.write(
                f"Website: http://127.0.0.1:{options['port']} — background worker enabled. Ctrl+C stops both."
            )
            children[1].wait()
        except KeyboardInterrupt:
            pass
        finally:
            for child in children:
                if child.poll() is None:
                    child.terminate()
            for child in children:
                try:
                    child.wait(timeout=8)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait()
