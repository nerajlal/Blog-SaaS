# Google Search Console integration

Open a project → **Search performance**. This first Phase 4 release implements read-only OAuth, verified-origin property selection, queued performance snapshots, CSV reports and indexed-version inspection. IndexNow, GA4, independent rank tracking, workspace-wide reports, automatic scheduled syncs and email digests are not implemented. No Google account is connected automatically.

## Server setup

1. Create a Google Cloud **Web application** OAuth client and enable the **Google Search Console API** in that Cloud project. Configure the OAuth consent screen, audience and test users or publishing/verification status as required by Google. A test-mode grant can expire; do not treat it as a permanent production connection.
2. Register the exact callback URI `https://YOUR_CMS_HOST/admin/search-console/callback`. For local development, use the Google-supported localhost redirect URI for the actual host/port. The URI must match both Google configuration and the environment value; route names alone do not register it.
3. Set server-only environment values:

   ```dotenv
   GOOGLE_SEARCH_CLIENT_ID=...
   GOOGLE_SEARCH_CLIENT_SECRET=...
   GOOGLE_SEARCH_REDIRECT_URI=https://YOUR_CMS_HOST/admin/search-console/callback
   ```

   Do not use `VITE_` prefixes, commit secrets, or put them into browser bundles. After changing configuration, rebuild Laravel's config cache as appropriate. Protect the application key: Laravel encrypts both stored access and refresh tokens with that key. Losing/rotating it without a compatible migration invalidates connections.
4. Configure HTTPS, secure encrypted sessions and server access logs that redact callback query strings. Authorization codes and OAuth state arrive through a callback URL; they should not be retained in logs. The app never renders access/refresh tokens or returns them from models.
5. Verify the project's public origin using its existing DNS challenge. Separately, ensure the connecting Google account has access to a Search Console property covering that origin. BlogSaaS verification does not grant Google access or create a Google property.
6. Sign in as a workspace owner/admin, choose **Connect Google read-only**, complete consent, load matching properties, then select a property. Reconnection replaces this project's local connection and deletes its prior reports. One connection per project is supported.

OAuth requests only `https://www.googleapis.com/auth/webmasters.readonly`, offline access and explicit consent. Session state binds the callback to the initiating user, project and origin, expires after ten minutes and is consumed once. The callback rechecks permissions and domain verification. Denied/invalid authorization does not create a connection. Access tokens refresh under a database lock; raw upstream token responses and secrets are not displayed in error messages.

Matching properties are filtered from Google's accessible-property list: root URL-prefix properties must equal the verified origin plus `/`; Domain properties must contain that hostname at a domain-label boundary. Unverified properties and unrelated properties are excluded. Subdirectory URL-prefix properties are deliberately unsupported. Access is checked again before property selection and before report/inspection operations, rather than trusting a submitted property string.

## Performance reports

Select a Pacific-calendar date range of at most 90 days within the past 16 months, ending at least three days ago. Submit **Fetch final performance** and reload after the SEO queue finishes. There is one fetch per project per hour, including pending/failed attempts.

The Google Search Analytics request uses web search, finalized data, date grouping, automatic aggregation and an anchored page filter for the exact publication origin. Filtering is also applied to broad Domain properties so sibling domains/protocols are excluded. Page-filtered aggregation can differ from Google's property-wide dashboard totals. Up to 90 daily rows are expected; row limit is 100. This is not a query/page keyword explorer or a guarantee of exhaustive Google data.

The dashboard shows source, property, origin filter, requested dates, fetch time, daily clicks/impressions/CTR/average position, and recent operation states. Aggregate CTR is total clicks divided by total impressions. Aggregate position is weighted by impressions. Missing days are not filled with zero. An empty successful response displays no-data explicitly, not invented traffic. A pending/failed new report does not replace a prior successful report; always inspect its displayed range and fetch time.

CSV export is private to the current project/connection/property and carries source and date context. Reports are not emailed or publicly exposed. Permissions are restricted to owners/admins because the Google account may reveal business-sensitive data. Disconnect deletes the connection's reports and inspections using database cascades.

## Index inspection

Select a live project article from the 100 newest live stories. Requests use the article's verified public URL, not an arbitrary submitted URL. Per-project limits are one inspection per story per 24 hours and 100 inspections per rolling 24 hours. Google imposes its own additional property/account quotas, which can be shared with other applications.

The queued operation rechecks project origin, live story membership, current URL, selected property and Google access. It stores a bounded set of index-status fields, including verdict, coverage, crawl/fetch state and canonical URLs. Results show when they were checked and may become outdated. Moved, deleted, unpublished or changed-URL stories cancel pending work.

**Inspection is Google's indexed-version status. It is not a live URL test, a crawl request, or an indexing submission.** A successful API operation does not guarantee that Google will index a page or improve its ranking.

## Workers, cleanup and revocation

Run a supervised worker:

```sh
php artisan queue:work --queue=seo,default --tries=2 --timeout=85
```

Use a queue retry-after greater than the worker timeout (120 seconds recommended). Search jobs have an 80-second timeout, bounded HTTP timeouts, two attempts and a two-minute retry delay. Worker supervision/process timeouts must work on the deployment host. Google's 401/403/429 responses produce generic authorization/permission/quota guidance; no unlimited retries run. Configure `QUEUE_CONNECTION` to a persistent queue, not `sync`, for production.

Run Laravel's scheduler every minute. Daily `seo:prune-search` deletes reports/inspections older than 90 days and marks pending work older than a day failed. Tokens remain until disconnect/reconnect or project deletion. Backups and infrastructure logs require their own retention controls. Changing/unverifying the public origin hides prior reports and prevents new work until a matching verified connection exists; old snapshots remain subject to retention.

**Disconnect** immediately deletes this project's local credentials/reports, then requests Google token revocation. Revocation can affect other connections using the same Google account/app grant. If Google cannot be reached, local deletion still succeeds and the UI instructs the user to remove the app grant in their Google account. Server-held tokens must not be copied to personal tools or reused for other tenants.

## Validation limits and official references

Automated tests fake Google's endpoints and prevent stray network requests. They cover OAuth state/replay/denial, encryption, permissions, property/domain boundaries, refresh, origin filtering, job cancellation, export isolation, quotas, cleanup and disconnect failures. Local SQLite/MySQL migrations, production assets and desktop/mobile screens are checked independently. Google OAuth consent, real account permissions, live quota behavior and actual metrics must still be validated after environment configuration; local fixtures do not establish a live connection.

- [Google web-server OAuth flow](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Accessible Search Console properties](https://developers.google.com/webmaster-tools/v1/sites/list)
- [Search Analytics query contract and aggregation rules](https://developers.google.com/webmaster-tools/v1/searchanalytics/query)
- [Indexed-version URL Inspection API](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect)
