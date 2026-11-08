# Django conversion: migration and deployment

## Branch and runtime boundary

All conversion work belongs to `python`, branched from `ai-based`. The Laravel and AI branches are unchanged. Django starts through `manage.py`, `blogsaas.wsgi`, or `blogsaas.asgi`; it never boots PHP or uses Laravel's ORM. The PHP directories remain as a reference and to support an explicit legacy export. Do not serve the repository root or `public/index.php` as the Django application.

The default Django database and media directory are `.local/db.sqlite3` and `.local/media`, separate from the existing Laravel database and `storage/app/public`. No existing users/content have been imported automatically. Register a new local account, or rehearse the import against a fresh destination database.

## Configuration

Copy `.env.django.example` to `.env.django`. Never put keys in Git. The Django process intentionally does not load `.env`.

For production configure:

- `DJANGO_DEBUG=false`, a strong stable `DJANGO_SECRET_KEY`, the actual host in `DJANGO_ALLOWED_HOSTS`, and the HTTPS origin in `DJANGO_CSRF_TRUSTED_ORIGINS`.
- `DJANGO_PUBLIC_URL=https://blogsaas.task19.com` for absolute uploaded-media URLs. Imported project-level `media_url` settings also apply.
- A stable `DJANGO_ENCRYPTION_KEY` if encryption should be independent of the signing key. If omitted, encryption derives from `DJANGO_SECRET_KEY`. Rotating either effective encryption key requires an explicit data-key migration.
- A real email backend and `EMAIL_HOST`, `EMAIL_PORT`, `EMAIL_HOST_USER`, `EMAIL_HOST_PASSWORD`, `EMAIL_USE_TLS`, and `DEFAULT_FROM_EMAIL` for password reset delivery. Local mode uses the console email backend.
- Optional `AI_ENABLED`, `ANTHROPIC_API_KEY`, `CLAUDE_MODEL`, `ANTHROPIC_API_VERSION`, `ANTHROPIC_WORKSPACE_ID`, and `AI_MONTHLY_REQUEST_LIMIT`. An owner/admin must also enable the workspace allowance and approve a profile. Request caps are not dollar spending guarantees; use provider-side spend controls.
- Optional `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` ending in `/admin/search-console/callback`. Set up consent and read-only Search Console access in Google separately.

The HTTP API enables browser CORS only for the requesting project's verified public origin. No credentialed wildcard CORS is enabled. The media origin and the public blog origin can differ; set each deliberately.

`DJANGO_TRUST_PROXY=true` enables trust in `X-Forwarded-Proto`. Use this only behind the documented loopback-bound Gunicorn/Nginx configuration that overwrites this header. Leave it disabled when requests can reach Django directly from untrusted sources.

Secure cookies, HTTPS redirects, HSTS, content-type protection, and framing protection are enabled with production settings. HSTS preload is an explicit operator/domain decision and is not automatically enabled; Django's deployment checker will report that advisory until it is chosen. Do not deploy with DEBUG enabled.

## Database choices

SQLite is sufficient for local development and a low-traffic test instance with one worker. Transactions use immediate mode, a busy timeout, optimistic editor/profile revisions, and conditional worker claims. It is not a recommendation for a write-heavy multi-worker deployment.

For a distinct MySQL database, install its native Django driver in addition to the locked application dependencies:

```bash
pip install 'mysqlclient>=2.2,<3'
```

Then set `DJANGO_DB_ENGINE=mysql`, `DJANGO_DB_NAME`, `DJANGO_DB_USER`, `DJANGO_DB_PASSWORD`, and optionally `DJANGO_DB_HOST`/`DJANGO_DB_PORT`. Use a **new** database name and user, never the live Laravel schema. System build dependencies for mysqlclient depend on the server OS. PyMySQL is installed for legacy export/import tooling compatibility, not substituted for Django's native MySQL backend.

The MySQL configuration is provided; verification in this conversion was performed with isolated SQLite databases. Test MySQL and concurrent operations in staging before production cutover.

## Rehearse legacy data import

Back up the original database, uploaded media, and private Laravel `.env` before cutover. Keep both application keys. Stop writes or take a consistent snapshot during the final export.

1. In the original Laravel-capable checkout, run the read-only exporter with a new private output path:

   ```bash
   mkdir -p .local
   php tools/export_laravel.php .local/laravel-export.json
   ```

   This JSON contains password hashes, private drafts, unpublished business data and potentially decrypted provider tokens. It is created with mode 0600 and must stay outside Git and public directories. Never upload it to a ticket or paste it in chat. The exporter requires the original Laravel key to decrypt encrypted fields and refuses to overwrite an existing file.

2. Create and migrate a fresh Django database. Validate the export without writing:

   ```bash
   python manage.py migrate
   python manage.py import_laravel .local/laravel-export.json
   ```

3. Review the table counts and verify the destination is empty, then apply explicitly:

   ```bash
   python manage.py import_laravel .local/laravel-export.json --apply
   ```

   Import is transactional and refuses a populated destination. IDs and core relationships are preserved; supported Laravel bcrypt password hashes remain usable and are upgraded by Django on login. Unsupported password formats require password reset. Provider tokens, profiles, and private snapshots are re-encrypted with the Django key. In-flight AI work is imported as failed rather than replayed.

4. Copy the approved uploaded-media tree from the Laravel backup into `DJANGO_MEDIA_ROOT`, preserving `blogs/banners`, `blogs/avatars`, and `blogs/sections`. Do not copy executable files or overwrite unrelated destination media. File copying is deliberately a separate operator action, not an automatic destructive import step.

5. Compare user/workspace/project/story/section counts, published API output, category links, permissions, image URLs and account login. Recompute SEO reports and link graphs. Review imported private drafts and AI output against current source/profile versions. Reconnect Google if tokens/scopes are not valid for the destination environment.

The importer covers the core model families and retains the original export as the authoritative migration artifact. Laravel-specific derived analysis, link-run cursors, event context hashes, old autosave field naming, or reviewed-batch shapes can differ; validate these in a real-data staging rehearsal. Recreate incompatible pending/reviewed operations rather than automatically replaying them. The export/import tests use representative fixtures, not the user's production dataset.

No cutover is implied by the presence of the importer. Keep the Laravel deployment available until the Django staging acceptance checks and backups have been reviewed.

## Test-droplet deployment

The Django branch does not require npm or PHP-FPM to run. Install Python/venv and dependencies, configure the Django environment, then:

```bash
python manage.py migrate
python manage.py collectstatic --noinput
python manage.py check --deploy
```

Start the web process bound to loopback:

```bash
gunicorn blogsaas.wsgi:application --bind 127.0.0.1:8001 --workers 1 --threads 2 --timeout 90
```

Run one separate `python manage.py work` process under a service manager. On the 512 MB droplet, measure total PHP/MySQL/Gunicorn/worker memory before running both old and new applications together. Hosted AI inference does not load model weights locally. Requests to provider APIs happen in the worker, not in a browser request.

Replace the Laravel `try_files` and PHP-FPM locations for this Django virtual host; do not leave an old exact `/index.php` FastCGI handler active. Example Nginx locations inside an HTTPS server block:

```nginx
location /static/ {
    alias /var/www/Blog-SaaS/.local/static/;
    add_header X-Content-Type-Options nosniff always;
}
location /storage/ {
    alias /var/www/Blog-SaaS/.local/media/;
    add_header X-Content-Type-Options nosniff always;
    location ~* \.(php[0-9]?|phtml|phar|cgi|pl|py|sh|html?|svg)$ { deny all; }
}
location / {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_pass http://127.0.0.1:8001;
}
```

Retain the existing certificate and HTTPS redirect, configure `client_max_body_size`, and test with `nginx -t`. Limit direct access to Gunicorn and enable `DJANGO_TRUST_PROXY` only with the trusted proxy setup. Use a dedicated application OS user; only the private database/media/runtime directories need write access. Never expose `.env`, `.env.django`, `.local`, exports, or repository source as static directories.

## Intentional implementation differences and limits

- Form and action endpoints use Django CSRF-protected POST forms. Some legacy internal admin action URLs are different; the public blogs/sitemap/redirect/telemetry endpoints retain their contract.
- SEO scoring is deterministic but has its own Django rule version. Historical scores are not a benchmark proving identical output to PHP.
- Website analysis reads selected public HTML pages only, checks robots rules, validates/pins public DNS addresses, bounds responses, and rejects cross-origin redirects. JavaScript-only or blocked pages require pasted text.
- AI output is reviewable, not guaranteed factual. Social content is text preparation/export only. No posting, scheduling to social networks, image generation, or source verification service is implied.
- A paused/dead worker can leave tasks pending until it resumes. Stale tasks are marked failed without automatic paid retries. No automatic age-based deletion of AI content is configured.
- Uploaded replacement/deleted-story files are not automatically garbage-collected. Plan explicit, reference-aware media retention/cleanup after migration rather than deleting user files during conversion.
- Live Anthropic calls, real Google OAuth, actual DNS verification, MySQL staging, real-data import and production cutover require environment/account access and have not been performed by this conversion.

### Draft-generation diagnostics

Full drafts have an 8,192-token output ceiling and a 180-second read timeout; smaller tasks keep a 4,096-token ceiling and a 90-second read timeout. These are maximum allowances, not promised output sizes or fixed costs. Provider request IDs, HTTP status, stop reasons and returned token usage are retained even when response validation fails. User-facing errors use controlled descriptions rather than raw provider responses or credentials. Failed tasks are not automatically retried. Restart long-running workers after updating backend code, then explicitly generate a new request from the reviewed outline if needed.

### Social version shortcuts

Each story row now has LinkedIn, Reddit, Facebook, and Instagram version buttons. They open the saved story in AI studio with the platform and a relevant brief selected. A verified public article URL is prefilled only for currently published stories; other stories keep the link-placeholder workflow. Saved suggestion history is filtered by that story/platform, including when paginating. Opening a shortcut performs no paid generation and posts nothing externally; the writer still reviews the context, consents, and submits generation.
