# Server-rendered SEO reference consumer

This is a small, runnable PHP frontend, not an additional publishing/admin application. It demonstrates crawlable metadata, escaped text attributes, safely serialized JSON-LD, public-origin sitemaps, image descriptions, and immediate visibility of publish/unpublish changes. It deliberately does not cache responses.

Requires PHP with cURL, JSON, and XMLWriter. Keep the project key in the server environment. Run the Laravel application on another port:

```sh
BLOGSAAS_URL=http://127.0.0.1:8124 \
BLOGSAAS_API_KEY=YOUR_PROJECT_KEY \
PUBLIC_SITE_URL=https://your-verified-publication.example.com \
php -S 127.0.0.1:8130 examples/headless-php/router.php
```

Open `http://127.0.0.1:8130/` locally. For deployment, route requests to this script on your configured HTTPS public origin, with the project's article URL pattern set to `/blog/{slug}`. Do not expose environment values or serve the repository root. Configure the API's own `APP_URL` (or project media origin) to the externally reachable HTTPS media origin.

Public routes:

- `/`: published article list with pagination.
- `/blog/{slug}`: published/due article HTML including its resolved SEO object; drafts/future stories return 404.
- `/sitemap.xml`: sitemap index containing only public-origin sitemap URLs.
- `/blog-sitemap-N.xml`: proxies page N of BlogSaaS's authenticated sitemap feed.
- `/robots.txt`: crawler access file advertising the public sitemap index.

Verify the project's public domain in SEO settings using its DNS TXT challenge. Without verification, article content and ordinary metadata still work, while canonical/schema/sitemap output is withheld. Local testing does not prove control of a production domain. The project API remains read-only and publication filtering is enforced by BlogSaaS.

The API returns sanitized section HTML. Only use this example with your trusted BlogSaaS installation. When adapting it to another content source, apply equivalent server-side HTML sanitization. Structured data is serialized with JSON HEX flags so article text cannot close the JSON-LD script element. Do not substitute unescaped string concatenation.

If you add caching, expire/revalidate HTML, lists, sitemap pages, and the sitemap index at scheduled publication times and on updates/unpublishing/deletion/moves. No webhook integration is currently implemented. Add your real site's layout, accessible navigation, error pages, privacy/crawler policies, and deployment-specific caching configuration before using this as a production publication.

## Redirects and missing-page reports

The router checks the redirect lookup API before serving GET/HEAD requests. Exact path rules produce real 301/302/307/308 Location responses or a targetless 410. Keep the publishing service available; lookup failures return 503 rather than guessing a rule. Incoming query strings are not forwarded to replacement paths.

Optionally set `BLOGSAAS_TELEMETRY_KEY` to the server-only secret created in the project’s **404 reports** page. `PUBLIC_SITE_URL` must exactly match the verified origin. Missing routes/articles submit a UUID-tagged 404 event with a two-second cURL budget, stripping query strings and referrer credentials before transmission. Public 404s still work when telemetry is unavailable; publishing outages remain 503 and are not reported as missing pages. See [operations and API contract](../../docs/links-and-redirects.md).
