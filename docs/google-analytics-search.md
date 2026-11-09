# Analytics & Search

Owners/admins open the project's Analytics & Search menu. The three tabs stay inside admin.

## Google configuration

Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URI in `.env.django`. Create a Google OAuth web client, configure its consent screen and test users if the app is in testing, and enable Search Console API, Google Analytics Admin API, and Google Analytics Data API in its Cloud project. Never put the client secret in frontend code.

Register the exact redirect URI:

- Local: `http://127.0.0.1:8001/admin/search-console/callback`
- Server: `https://blogsaas.task19.com/admin/search-console/callback`

Use the URI matching the environment in GOOGLE_REDIRECT_URI. Restart the server and worker after environment changes. Google may require consent-screen verification for an externally available production OAuth app.

Search Console and Analytics use separate encrypted connections and read-only scopes. Each Connect action starts a separate consent flow; reconnect after changing permissions. Disconnect removes the local connection (it does not revoke Google's account grant). Users can revoke access in their Google account.

## Search Console

In Sitemap, configure the actual public website URL and article route, then verify the displayed TXT record. Connect Google on the Search Console tab and load/select a matching property accessible to that account. Queue a report or URL inspection. The background `manage.py work` process must be running. This does not create a Search Console property or publish the customer's website.

## Google Analytics

Install GA4 on the customer's website separately. Connect Google Analytics, load properties, select an accessible GA4 property, and load a date range ending yesterday or earlier (maximum 90-day difference). Reports show top 25 page paths by views, active users, sessions, engagement rate, and top 25 source/medium rows by sessions. Data covers the selected property, not automatically just this project's articles. Page rows are not additive user/session totals. Google data freshness, thresholds, and reporting identity may affect results.

The reports are fetched on explicit POST actions with bounded network timeouts; no worker is needed for GA4. Read-only Analytics authorization can work independently of sitemap/domain verification. Search Console still requires verified domain setup. No fake statistics are displayed when unconfigured.

## Sitemap

Save the website origin and article route pattern, such as `/blog/{slug}`, then verify the domain via the TXT record. Changing the website clears its verification and old Search Console connection, while preserving other saved project defaults.

Download XML for published, indexable, self-canonical articles. Drafts, future scheduled content, noindex stories, and canonicalized-away stories are omitted. Downloads use the same generator as the API and include up to 1,000 stories per page. Append `?page=2` etc. for additional batches; X-Sitemap-Pages indicates the total source page count. For larger sites the frontend should build a sitemap index referencing its proxied pages. A download is a snapshot, not an automatic Google submission.

Serve/proxy the sitemap at the customer's own domain. The frontend server authenticates to `/api/v1/projects/sitemap.xml` using X-API-KEY; never put secret OAuth tokens in a sitemap URL. Customer routing must match the configured article path. Re-download after changes or proxy dynamically.

## Deployment

```bash
.venv/bin/python manage.py migrate
.venv/bin/python manage.py collectstatic --noinput
systemctl restart blogsaas-web blogsaas-worker
```

Migration 0004 adds the encrypted Analytics connection table. Do not rerun Laravel import.

## Verification

Automated tests cover OAuth state validation/scope checks, separate connections, accessible property selection, GA4 reporting requests/date validation/disconnect, existing route compatibility, URL-change invalidation, and verified/noindex sitemap generation. External Google calls are mocked in tests. Live OAuth, actual account reporting, DNS propagation, and real-data MySQL deployment need environment-specific verification.

References:
- https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/accountSummaries/list
- https://developers.google.com/analytics/devguides/reporting/data/v1/basics
