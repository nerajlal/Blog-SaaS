# Claude project knowledge and writing tools

Implemented on branch `ai-based`. This is an optional, disabled-by-default feature. No live Anthropic request was used during development; tests use HTTP fakes, and browser QA uses an isolated SQLite fixture.

## Included workflows

- **Project details:** structured business information, products/features, audience, tone, approved facts, link destinations, boundaries, and social profile URLs.
- **Approval:** owners/admins save and approve versioned profile snapshots. Draft edits do not replace the last approved snapshot. Profiles and suggestion payloads/results are encrypted at rest using Laravel's application key.
- **Website analysis:** up to three explicitly selected public HTML pages on one origin, or pasted source text. Network destinations and every redirect are validated; connections are pinned to vetted public DNS addresses. robots.txt is checked, pages and headers are bounded, and cross-origin redirects are rejected. Analysis produces editable proposals and source notes; selected fields are copied into draft details only by explicit user action, then approved separately.
- **Writing:** topic/brief → reviewed outline → reviewed draft → private story in the normal editor. Category selection is required. Suggestions for titles, excerpts/SEO descriptions, and selected-text rewrites are available for saved stories through copy/apply by the writer.
- **Social content:** LinkedIn, Reddit, Facebook, and Instagram variants generated separately from a saved blog and approved project profile. Editable fields, copy buttons, saved-text export, independent generation, source freshness warnings, and public-link placeholders are included. Instagram carousel output is text only. Reddit guidance and affiliation are supplied by the user; community compliance is not asserted.
- **Operations:** database-queued jobs, permission/source checks before and after generation, no automatic provider retry, monthly request reservations shared by workspace, duplicate-request protection, one active task per workspace, status polling, cancellation, and abandoned-task maintenance.

Nothing auto-publishes, posts externally, creates media, connects a social account, or crawls an entire site. A model cannot independently remember project context: the application supplies the approved snapshot each time.

## Setup

First run the new migration and build assets on the target checkout:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
```

Keep the existing `APP_KEY`. Rotating it would make encrypted profiles and suggestions unreadable unless a deliberate key migration is performed.

Copy the optional settings from `.env.example` into server configuration. Set `AI_ENABLED=true`, provide `ANTHROPIC_API_KEY`, and choose an available model in `CLAUDE_MODEL`. The endpoint is fixed to Anthropic's native Messages API. `ANTHROPIC_API_VERSION` defaults to `2026-10-21`; confirm the chosen account/model supports that version before enabling. `ANTHROPIC_WORKSPACE_ID` is optional for a workspace-scoped Anthropic key, and needed when the key/account requires that header. This provider workspace is different from a BlogSaaS workspace.

`AI_MONTHLY_REQUEST_LIMIT` sets the server ceiling for each BlogSaaS workspace (default 50). An owner/admin must also enable usage and choose an allowance in Project details; this applies to all projects in that workspace. Legacy projects have their own scope.

Run one dedicated worker, under the same application user used for the other queue workers:

```bash
php artisan queue:work database --queue=ai --tries=1 --timeout=85 --memory=96 --sleep=3 --max-jobs=25
```

Use a service manager to restart this process when it exits. Keep the database connection's `retry_after` greater than 85 seconds (the default 90 is sufficient). Do not run multiple AI workers on the 512 MB test droplet before measuring total memory usage. Hosted inference avoids loading model weights locally, but PHP, MySQL and the rest of the app still use RAM.

The existing Laravel scheduler should run every minute. It invokes `ai:maintain` every five minutes, marking running jobs older than five minutes or pending jobs older than thirty minutes failed without retrying them. The manual equivalent is:

```bash
php artisan ai:maintain
```

This command does not delete stored content. Existing scheduler setup should be reused rather than duplicated.

After configuration changes, rebuild config caches as used by the deployment and restart queue workers (`php artisan queue:restart`). Restore normal writable permissions for `storage` and `bootstrap/cache` if commands were run as root.

## Quotas and billing

The local allowance counts reserved requests, including failed/cancelled requests. It is intentionally not refunded automatically: a timed-out or cancelled request may already have been billed by Anthropic. The adapter makes one request, with bounded input/output and no automatic retries.

Usage stores provider request ID and available token counts. Request caps are not dollar-denominated spending guarantees. Set spending controls in Anthropic's Console as well, and recheck actual model, caching, and other applicable rates. Pricing, account credits, supported models and data terms must be checked when enabling the feature:

- [Anthropic API overview](https://platform.claude.com/docs/en/api/overview)
- [Model pricing](https://platform.claude.com/docs/en/about-claude/pricing)
- [Commercial training-data policy](https://privacy.anthropic.com/en/articles/7996868-is-my-data-used-for-model-training)

## Review and operational limits

Generated facts remain proposals. Source IDs and text are displayed for human verification; the application does not prove semantic factual correctness or verify every model citation. Manual adoption requires review and cannot overwrite profile changes made since analysis. No live search, ranking estimates, or trending-hashtag verification is included.

Website importing can fail for JavaScript-only pages, restrictive robots rules, HTTP errors, or cross-origin redirects (including HTTP→HTTPS or www changes). Enter the final public origin or paste relevant text. The importer does not execute JavaScript, authenticate to websites, upload files, or fetch image content.

Generated social content for unpublished stories uses `[BLOG_URL]`. A user may supply a clean public URL only for a currently published story; the application rejects query strings and common admin/preview paths. That is not proof that the public frontend is deployed or the page is live: users still review the destination before sharing. Social profile URLs are context, not account authorization.

Existing article/profile changes make applicable results stale. Review/draft conversion is blocked for stale results; saved text remains exportable with a stale warning. Text edits made in the suggestion screen must be saved before exporting or converting to a story. The normal blog editor remains the place to publish.

Stored suggestions and source text are retained until their parent blog/project or user is deleted; no automatic age-based deletion is configured. A future retention policy needs an explicit decision. Approved profile versions are retained for reproducibility. Scope-based quota/settings rows contain counters and configuration, not article/profile content.

The production login 500 reported before this branch still requires the droplet's Laravel log to diagnose; this work does not claim to resolve that separate server error.

## Validation

Run `php artisan test`, then `npm run build`. AI tests fake all provider calls and cover profile approval/encryption, optimistic edits, role boundaries, consent, disabled configuration, quota reservations, duplicate requests, outline-to-draft creation, sanitization, source staleness, foreign-blog rejection, provider failures, access revocation, source adoption, and public website restrictions. Browser QA covers responsive screens and the review/save flow.
