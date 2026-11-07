# BlogSaaS — Django edition

Branch **`python`** is the Django conversion of `ai-based`. The active Python application is `blogsaas/` + `portal/`, started with `manage.py`. The existing PHP files are retained as migration/reference material; they are not called by Django. `main` and `ai-based` remain separate.

## Run locally

Python 3.12 or newer is recommended. No PHP, Composer, npm, Redis, or external API credentials are required to run the Django app locally.

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.lock
cp .env.django.example .env.django
python manage.py migrate
python manage.py runserver 127.0.0.1:8001
```

Open **http://127.0.0.1:8001**, then register an account. Port 8001 avoids colliding with an existing Laravel server on 8000. Django uses `.env.django` and `.local/db.sqlite3` by default; it does not read Laravel's `.env` or connect to its existing database. Local-only secret material is generated under ignored `.local/` when DEBUG is enabled; keep it stable to read encrypted local data.

Run the database worker in another terminal:

```bash
source .venv/bin/activate
python manage.py work
```

One worker processes AI requests, link graph/check jobs, Search Console reports, and URL inspections. Jobs are claimed atomically and are not automatically retried after an ambiguous provider failure. No paid provider call happens until the administrator explicitly configures and enables the service.

## Included application areas

- Landing page, registration, login/logout, password reset and workspace switching/creation.
- Workspace roles, project creation/deletion, categories, story sections, sanitized rich text, validated media uploads, scheduling, project moves and private versioned autosave.
- Separate Blogs and Integration screens, project API keys, published-only paginated JSON API and verified-domain sitemap.
- SEO defaults/overrides, TXT domain verification, canonical/social metadata and schema, content-health snapshots/reviews, cornerstone fields, internal-link graph/suggestions, guarded insertion/undo and queued public link checks.
- Reviewed metadata/redirect CSV changes with expiry and guarded undo, redirect lookup, secret-authenticated deduplicated 404 telemetry.
- Read-only Search Console OAuth, verified matching properties, queued performance reports and URL inspection, CSV export.
- Approved encrypted project profiles, optional protected website analysis, Claude outline→reviewed private draft generation, writing suggestions, LinkedIn/Reddit/Facebook/Instagram text, review/export, quotas, cancellation and stale-source checks.

The implementation is a native Django rewrite, not a line-for-line PHP transpilation. SEO scoring uses versioned Django rules and should be recomputed after import. Backend form/action endpoints use Django CSRF-protected POST forms; public read API URLs and the SEO response contract are preserved.

## Validate

```bash
python manage.py check
python manage.py test portal
python manage.py check_parity
python manage.py makemigrations --check --dry-run
python manage.py collectstatic --noinput
```

Browser QA uses a separate fixture database and mocks are used for paid/external integrations. `check_parity` verifies route coverage, not an assertion of identical internals or exhaustive production compatibility.

See **[Django migration and deployment](docs/django-migration.md)** for database import, media, optional MySQL, Gunicorn/Nginx, provider settings, and current limitations. The previous README is preserved in [Laravel reference](docs/laravel-reference.md).
