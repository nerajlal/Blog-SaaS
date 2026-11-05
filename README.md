# BlogSaaS

Laravel 12 / PHP 8.2+ headless publishing application. Users organize projects in workspaces, write rich-text/image sections, categorize posts, and publish immediately or on a schedule. Published content is available through a read-only REST API. The responsive landing page takes visual inspiration from https://task19.com/ while using original BlogSaaS content and UI illustrations.

## Local setup

Requires PHP 8.2+ (including PDO for your database, DOM, mbstring, fileinfo, OpenSSL), Composer, and Node.js 22.12+.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Configure your database in `.env`. For SQLite, create `database/database.sqlite`, then:

```sh
php artisan migrate
npm ci
npm run build
php artisan serve
```

`composer dev` runs the development server, queue listener, logs, and Vite. Demo data is optional: `php artisan db:seed` creates `admin@gmail.com` / `password`, and is deliberately disabled in production. Never seed demo data on a live installation. Rerunning this seeder replaces demo data and rotates the demo project's key.

## Permissions and content

Workspace owners, admins, and editors can manage projects and posts. Members have read-only project access. Workspace membership is checked independently of original project/post authorship. Legacy projects without a workspace remain accessible to their original owner. Workspace switching and creation are available in the sidebar; mobile navigation includes the same controls. Team invitation and role-management screens are not implemented.

Uploads accept JPG, PNG, GIF, or WebP files up to 2 MB each. This repository deliberately stores public images in `public/storage`; **do not run `storage:link`** with this disk configuration. Keep the tracked `.htaccess` in that directory. On Nginx or another server, explicitly prohibit script execution in `/storage/` and serve it only as static content. Include `public/storage` in persistent storage and backups.

Section HTML is sanitized on write and on read, including legacy content. Script/event attributes, embeds, and inline images are removed. Use the section image field for images. Basic headings, paragraphs, lists, emphasis, safe links, and supported alignment are preserved. Font colors, embedded video, and arbitrary inline styles are not supported.

Blog/section changes use transactions. New uploads are removed if saving fails; superseded images are deleted after a successful commit. Existing unrelated orphan files are not automatically purged. Titles can be changed without changing an existing post's slug.

Publication times use the application's configured timezone (UTC by default), displayed beside the editor. Scheduled posts are filtered by `published_at` at request time; no scheduler is required. Cached/static frontends must revalidate to show newly published content.

## API

```sh
curl 'https://your-domain.example/api/v1/projects/blogs?per_page=20&page=1' \
  -H 'Accept: application/json' \
  -H 'X-API-KEY: YOUR_PROJECT_KEY'
```

The response includes `project`, a `blogs` array with `sections` and `category`, and `meta` (`current_page`, `last_page`, `per_page`, `total`). Default page size is 20, maximum 100. **Existing integrations must paginate** if they previously expected every post in one response. Only published posts whose publication date has arrived are returned. Drafts and future posts remain private.

Project keys are public, read-only identifiers, not credentials for modifying content. Query-string keys remain supported for compatibility; prefer the header to keep keys out of URLs. CORS allows cross-origin GET/OPTIONS without credentials. The API uses stateless middleware with a 120 requests/minute per-IP limit. The separate server-to-server 404 endpoint requires a private telemetry key and has an additional 60 requests/minute limit; it is not enabled for browser CORS. Login allows 5 attempts/minute, registration 3/minute, and password recovery 5/minute.

## Production deployment

1. Configure the web server document root to **this repository's `public/` directory**. Never expose the repository root, SQL dumps, `.env`, or `vendor` as public files. Configure TLS and upload execution restrictions.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `SESSION_SECURE_COOKIE=true`, and `SESSION_ENCRYPT=true`. Preserve the application key between deployments. Configure real database credentials and a shared cache/session store when using multiple instances.
3. Configure a real mail transport and sender, then verify delivery of password reset emails. The default `log` mailer does not deliver email.
4. Back up the database and uploaded media. Run `composer install --no-dev --optimize-autoloader`, `npm ci`, and `npm run build` during deployment. Deploy the resulting `public/build` directory with the application. Remove any development `public/hot` file.
5. Review existing workspace references before applying the new foreign-key migration. Resolve orphan references if any, then run `php artisan migrate --force` and `php artisan optimize`. Do not seed demo accounts.
6. Run `php artisan app:production-check`. It is read-only and exits nonzero when required settings, migrations, assets, or database checks fail. A passing command is not a substitute for checking the actual host's TLS, file permissions, static upload rules, SMTP delivery, monitoring, and backup restoration.
7. Verify registration/login, password recovery, project creation, editing, media uploads, scheduling, tenant isolation, and API requests on staging. Remove or secure any existing demo account before launch.

Billing, subscription enforcement, custom-domain routing, and team invitations are not implemented. Subdomain/custom-domain columns alone do not provide hosted blog sites. This is a headless publishing application, not a completed subscription billing platform.

## Verification

```sh
php artisan test
npm run build
composer audit --locked
npm audit
```

Tests use an isolated in-memory SQLite database. They cover authentication redirects, registration, password reset token lifecycle, throttling, workspace permissions, tenant isolation, category scoping, unsafe uploads, scheduling, sanitized content, API pagination, and CORS.

## Publishing SEO

Project SEO defaults, DNS domain verification, post metadata/robots/social overrides, image alt text/captions, live previews, a resolved SEO API object, article JSON-LD, and sitemap feed are now implemented. Open a project → **SEO settings** to configure it. Consumer websites must render the metadata and proxy their public-origin sitemaps. See [SEO setup and API documentation](docs/seo.md) and the [runnable PHP consumer](examples/headless-php/README.md). Phase 2 adds separate SEO/readability scores, live editorial guidance, saved revision reports, a content-health dashboard, and audited warning reviews. See [editorial assistant documentation](docs/editorial-analysis.md). Phase 3 adds link inventories/orphan reports, approved internal-link suggestions with undo, queued safe HEAD checks, reviewed redirect and metadata CSV changes, and authenticated consumer 404 telemetry. Open **Links & URLs**; configure a supervised SEO queue worker and the scheduler. See [link and redirect operations](docs/links-and-redirects.md). The first Phase 4 slice adds read-only Google Search Console OAuth, private performance reports/CSV and indexed-version inspection. Google credentials and account consent must be configured separately; see [Search Console setup](docs/search-console.md). IndexNow, GA4, scheduled digests and AI remain future work.
