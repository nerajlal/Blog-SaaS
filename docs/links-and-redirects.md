# Links and URL lifecycle

Open a project → **Links & URLs**. Verify its public HTTPS origin in SEO settings first. Owners/admins manage redirects, bulk metadata and telemetry secrets. Editors can index/check links and approve suggestions. Read-only members can inspect links and suggestions. All records and mutation routes are project scoped.

## Link inventory and suggestions

**Rebuild link index** queues a saved-content snapshot. Each job indexes at most 20 stories, each at most 500 KB sanitized HTML and 200 distinct HTTP(S) links. Relative links resolve against the article URL; fragments are removed, repeated URLs are counted, and mail/tel/fragment-only links are ignored. Unsupported URLs and oversized stories produce an explicitly partial report. This is an article inventory, not a crawl of consumer navigation or backlinks.

An orphan is a live article without an incoming link from another live article in the current snapshot. Draft links and self-links do not count. Partial reports list possible orphans only. Story/section changes, moves and project SEO changes invalidate the index. Query-builder/raw SQL changes bypass Eloquent events: rebuild after external maintenance.

Open a story in the inventory or use the editor’s **Review saved story’s internal-link suggestions** link. Recommendations rank up to 200 recent/cornerstone live, indexable, self-canonical targets using category and exact title/keyphrase terms. They explain their reasoning; they do not use semantic AI. Save editor changes before reviewing suggestions. Approval appends one Related reading section, guarded against changes to the saved story. Undo removes only the unchanged appended section and records the reversal. Existing paragraphs are never silently rewritten.

## Background operations

Run a supervised worker with the database/Redis queue connection:

```sh
php artisan queue:work --queue=seo,default --tries=2 --timeout=85
```

The queue connection should not be `sync` in production. Its retry-after must exceed the longest job timeout (120 seconds recommended). Failed jobs require operator review/retry. Reports refresh on page reload, not by polling. For an operator-run inventory without HTTP requests:

```sh
php artisan seo:links --project=PROJECT_ID
```

Selected saved URLs can be checked, at most 20 per request, with a one-hour cooldown and a 200-distinct-URL daily project budget. Checks use HEAD only, verify TLS, disable proxy use, validate every DNS A/AAAA result as public, pin an approved address, and revalidate each redirect. IP literals, credentials, private/reserved addresses and nonstandard ports are blocked. Responses are classified as healthy, redirected, broken (404/410), restricted (including HEAD 405), temporary error, or unconfirmed. Temporary failures retry once through the queue.

Requests allow at most three redirects, 32 KB headers, six seconds per hop and a 15-second overall HTTP budget. The worker has a 45-second job timeout; the operating system’s DNS resolver can block outside cURL’s request timer. Operate workers with process timeouts and restricted network egress as defense in depth. No page body is downloaded. A successful HEAD does not prove content quality, and a restricted response is not proof of a broken link. This release checks selected URLs; it does not run recurring automatic crawls.

## Redirects

Owners/admins open **Manage redirects**. Create a rule or upload CSV, review the resulting rules, then apply the preview within one hour. Previews do not change public behavior. Rules support exact origin-relative paths with 301, 302, 307, 308, or targetless 410. Regex, cross-origin destinations, query matching and automatic homepage redirects are unsupported. Live article source URLs, home/robots/sitemaps, self-redirects, chains and cycles are rejected. A source that later becomes a live article is ignored by lookup so the live article wins.

After a deletion/move, explicitly review the old path and decide whether it needs a replacement, 410, or an ordinary 404. Moving/deleting a story does not create a redirect automatically. Domain changes require new verification; old-origin rules remain historical and do not apply to the new origin.

CSV header: `source_path,target_path,status_code`. Imports accept 1–100 rows/128 KB, replace matching sources and preserve other rules. Preview removal separately to delete a rule. Export contains all rules for the current origin (maximum 1,000). Audit history records the actor, reason, before/after rules and application/reversal times. Undo requires unchanged current rules and validates restored rules against live articles.

A headless API cannot redirect your customer’s website on its own. Before serving GET/HEAD requests, the consumer calls:

```http
GET /api/v1/projects/redirect?path=%2Fold-story
X-API-KEY: PUBLIC_PROJECT_READ_KEY
```

Response: `{"redirect":null}` or `{"redirect":{"status":301,"location":"/blog/replacement"}}`. For 410, location is null. Return that HTTP status/Location on the **consumer origin**. Drop incoming queries rather than forwarding secrets. The reference PHP consumer implements this contract with local-path validation. No API-side Location header is used, and rules are not cached in this reference.

## Bulk metadata

Open **Bulk metadata** to export pages of 100 stories (`X-Export-Pages` gives total pages). Edit CSV fields: `blog_id,title,description,focus_keyphrase,robots_index,robots_follow`. Here `title` is the SEO title, not the article title. An empty cell clears that override. Other SEO fields remain intact. Imports accept 1–100 distinct project story IDs/128 KB. Formula-like export cells are prefixed with an apostrophe and decoded on reimport.

Preview shows each story’s before/after values. Apply within one hour; any intervening project/content change requires a fresh preview. Application is transactional and refreshes editorial reports. Undo restores metadata only if every affected story still belongs to this project and its metadata matches the applied snapshot. Audit history is retained. This is a bounded CSV workflow, not a WordPress/plugin settings migration or full content backup.

## Authenticated 404 reporting

Open **404 reports**, create a server-only key and copy it once. Rotation immediately revokes the old key. Only its SHA-256 hash is persisted; the one-time plaintext travels through the authenticated session. Keep the key out of JavaScript, URLs, source control and logs. Configure the reference consumer with `BLOGSAAS_TELEMETRY_KEY` and `PUBLIC_SITE_URL`.

```http
POST /api/v1/projects/not-found
Content-Type: application/json
X-Telemetry-Key: SERVER_ONLY_SECRET

{"event_id":"3c7c531a-3d7b-4ac4-bc60-c3b4fb40f215","origin":"https://your-verified-site.example.com","path":"/missing?query=discarded","referrer":"https://example.com/start?query=discarded"}
```

Returns 204. A public read API key cannot submit events. Origin must exactly match the currently verified project origin. Query strings/fragments are stripped; referrers keep only validated public hostname origin/path. Credentials, IPs, user agents and request bodies are not retained. Daily counts are deduplicated by project/event UUID for seven days. Limits: 60 submissions/minute per IP, 10,000 unique events and 1,000 distinct paths per project/day, plus the shared API limit. Reports cover events submitted by a trusted consumer; they are not independently verified visitor analytics.

The reference sends a best-effort server-to-server report on missing routes/articles, with a two-second total cURL budget. A telemetry outage must not replace the public 404 with an error. Upstream publishing outages (503) and deliberate 410 responses are not reported as 404s. This adds latency to missing responses; higher traffic consumers can use their own bounded asynchronous delivery with stable UUID retries.

Run the Laravel scheduler every minute (`php artisan schedule:run`) to execute daily retention. `php artisan seo:prune-links` removes 404 aggregates older than 90 days and deduplication receipts older than seven days, expires unused previews, and prunes older inactive link snapshots while preserving the newest per project. Application logs, backups and external infrastructure need their own retention policies. Audit batches remain retained; no destructive audit purge is supplied.

## Verification and deployment limits

Tests cover tenant/role boundaries, stale snapshots, extraction/deduplication, orphan detection, explicit insertion/guarded undo, safe DNS/IP/redirect checking, redirect preview/apply/removal/undo, CSV imports, metadata conflicts, secret rotation, event deduplication, query stripping and retention. HTTP security tests use controlled DNS/response fixtures; they do not scan third-party sites. Desktop/mobile Chromium checks exercise the admin pages and real local consumer 301/410/404 behavior against isolated SQLite fixtures. MySQL apply/undo checks use rollback-only fixtures.

Production still requires real DNS verification, frontend integration, supervised queue workers, the scheduler, working backups/monitoring and staging verification. Read-only Search Console performance and inspection are now available with separate Google configuration; see [Search Console setup](search-console.md). Independent rank tracking, IndexNow and AI remain later work.
