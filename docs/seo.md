# Publishing SEO: Phases 1–2

Implemented: project identity/defaults, DNS domain verification, per-post metadata, image descriptions/captions, live search/social previews, resolved public SEO JSON, connected article schema, and a publication-aware sitemap feed. Phase 2 adds [editorial SEO/readability checks and content health](editorial-analysis.md). Phase 3 adds [link inventories, suggestions, redirects, safe HEAD checks, CSV metadata workflows and consumer 404 reporting](links-and-redirects.md). Read-only Search Console performance and index inspection are available with separate [Google setup](search-console.md); GA4, IndexNow and AI remain future work.

## Configure a publication

1. Open a project and choose **SEO settings**. Workspace owners/admins can manage these settings. Editors can edit post-level metadata; members remain read-only. Legacy projects without workspaces use their original owner.
2. Set the publication name, language tag, public HTTPS origin, article URL pattern, and title template. Optional defaults include a description, publisher identity/logo, social image, media origin, and index/follow preferences.
3. Save. Add the displayed DNS TXT name/value at your DNS provider, then select **Check DNS verification**. Do not add it as an HTML tag or upload it as a file. DNS propagation may take time. The server needs DNS TXT lookup access.
4. Connect your public website to the API and render its SEO output in initial HTML. A verified domain alone does not add tags to another website or configure hosting.

Supported article patterns contain exactly one `{slug}` at the end, for example `/blog/{slug}`, `/{slug}`, or `/journal/{slug}/`. Supported title variables are `{title}` and `{site_name}`. Public/media origins require HTTPS and no path, custom port, credentials, query, or fragment. IP addresses and local/internal/test hostnames are not accepted.

Changing the public origin rotates the challenge and clears verification. Keeping the origin unchanged preserves verification. Verification is a stored ownership check, not continuous DNS monitoring or an uptime check. No public-site HTTP crawling is performed.

Without verification, published article content and ordinary metadata remain available. Canonical/public article URLs, schema, and the sitemap feed are withheld. This prevents publishing authoritative URLs for an unverified origin; drafts remain private regardless of SEO settings.

## Editor and inheritance

The story editor includes SEO title/description, a private focus keyphrase, canonical override, indexing/following overrides, Article/BlogPosting/disabled schema choice, and independent Open Graph/X text/image overrides.

Resolution order:

- Title: explicit post title → project title template using the article title and publication name.
- Description: explicit post description → project fallback description → excerpt/body text, shortened to a snippet.
- Social text: explicit per-network override → Open Graph/general metadata fallback.
- Social image: explicit per-network override → cover image → project social image.
- Index/follow: explicit post choice → project default → index/follow.
- Canonical: post override → verified origin plus article route. Unverified projects have no resolved canonical.

A focus keyphrase is used by the private editorial assistant for exact-phrase guidance. It is not published as metadata. Public responses hide the raw `seo_settings`, focus keyphrase, verification token, user relation, and project relation. Existing API clients may ignore the additive `seo`, `cover_image_url`, and section `image_url` fields.

Cover and section images have editable alt text/captions and a decorative flag. Render decorative images with `alt=""`; otherwise render the supplied description. Image uploads are still optional and use existing validation/cleanup behavior. Project media origin controls absolute `/storage/` URLs; otherwise `APP_URL` is used. Set a publicly reachable HTTPS media origin in production.

The previews are approximate. Character counts are editorial guidance, not a ranking score or a guarantee of search/social appearance. Search engines and social platforms can display different text. The consumer must safely render the returned fields.

## API contract

Authenticate with `X-API-KEY`; legacy `api_key` query parameters remain supported. The header takes precedence. These remain public read-only project identifiers, not mutation credentials.

| Endpoint | Output |
| --- | --- |
| `GET /api/v1/projects/blogs?page=1&per_page=20` | Existing paginated list plus an additive resolved `seo` object on each post. |
| `GET /api/v1/projects/blogs/{slug}` | `{ "project": "…", "blog": { …, "seo": { … } } }` for one live post in the keyed project. |
| `GET /api/v1/projects/sitemap.xml?page=1` | XML sitemap page, up to 1,000 live source records per page; `X-Sitemap-Pages` reports the page count. |

The metadata object includes `version: 1`, title, description, canonical/public URL, locale, robots booleans, `open_graph`, `twitter`, a JSON-LD `schema` object, and configuration warnings. No paid external provider is required.

Single-post/list/sitemap queries enforce publication status and time. A draft, scheduled-future, foreign-project, or unknown slug returns 404 from the single-post endpoint. Unverified sitemaps return 409. Unauthorized keys return 401. Metadata responses use `Cache-Control: no-store`; clients implementing their own cache must schedule expiration/revalidation correctly.

The schema graph links publisher, website, webpage, breadcrumbs, and article using stable IDs. It includes only actual available fields; no fabricated reviews/ratings. Missing author/image/publication information is not invented. Schema output is data, not executable HTML or a promise of rich results. A custom schema builder is not included.

## Public-origin sitemap integration

The sitemap endpoint is a **feed to proxy**, not the public publication's root sitemap. Send the key in a server-side header. Do not submit the authenticated API URL or a URL containing the key to search engines.

Serve a sitemap index on the verified publication origin, using `X-Sitemap-Pages` to point to public-origin sitemap page routes. Each route proxies the corresponding feed page. The reference PHP consumer implements `/sitemap.xml` and `/blog-sitemap-N.xml` for this purpose. Filtering can produce empty pages, which are valid.

Only due/published, indexable, self-canonical article URLs are included. Drafts, future posts, noindex articles, and posts with a different canonical are excluded. Modification dates use the later of publishing/update time. Disabling article schema does not remove an otherwise eligible URL from the sitemap.

No webhook-based cache purge is implemented. Update HTML, article lists, sitemap pages, and sitemap indexes on publish/edit/unpublish/delete/move, and at future publication times if you cache these resources.

## Reference frontend and verification

See [the runnable PHP consumer](../examples/headless-php/README.md). It renders tags server-side, serializes JSON-LD with safe escaping, supplies public sitemap routes, and includes image captions/descriptions. Its default route is `/blog/{slug}`; adapt the router if you choose a different project route.

Automated tests cover validation, exact TXT matching/lookup failures, authorization, inheritance, verified/unverified states, raw-data privacy, publication visibility, schema links, sitemap exclusion, and editor persistence. Browser checks cover responsive settings/editor forms, live previews, real rendered tags/JSON-LD, sitemap proxy/index behavior, and script-breakout prevention using an isolated fixture.

No real customer domain was verified during development. Configure your own origin and DNS challenge before enabling canonical/schema/sitemap output for that publication. Local XAMPP migrations were applied; other deployments must run `php artisan migrate --force` as part of their normal reviewed deployment.

Link inventories, internal-link approvals, redirect/metadata CSV workflows and server-only 404 telemetry are documented in [Links and URL lifecycle](links-and-redirects.md). These require consumer integration and background operations as described there.
