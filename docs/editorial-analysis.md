# Editorial assistant and content health (Phase 2)

Phase 2 adds advisory SEO/readability analysis to the existing headless publishing system. It does not predict rankings, crawl external URLs, rewrite content, or block publishing.

## Where to use it

- **Story editor → Search and sharing → Editorial assistant:** live checks across all sanitized sections, separate scores, metrics, and an optional cornerstone designation.
- **Project → Content health:** paginated story overview with fresh-report status, per-score columns, filters, and a bounded refresh action for the current page.
- **Saved report:** full findings, rule version/language/date, refresh action, explicit content-review action, and editorial decisions with reasons.

Owners/admins/editors can analyze and record decisions wherever they can edit the project. Read-only members may view saved reports/health, but cannot preview unsaved content, refresh reports, or make decisions. All routes require login and project membership. Private keyphrases, hashes, review history, raw analyses, and cornerstone/review fields stay out of the public content API.

## Checks in this release

| Group | Checks |
| --- | --- |
| Metadata | Resolved title and description presence; English length guidance. Inherited defaults count as metadata. |
| Keyphrase | Optional target; exact normalized phrase in title, description, first 100 words, stable slug, and available headings. Missing targets skip dependent checks. |
| Structure | Readable body text, heading hierarchy, extra section H1s, and useful HTTP links where appropriate. |
| Images | Non-decorative cover/section images need alt text. Images explicitly marked decorative are accepted with empty alt text. No-image stories are not penalized. |
| Project context | Duplicate normalized article titles and exact focus phrases within the same project, including drafts. This does not attempt semantic cannibalization detection or compare resolved SEO-title templates. |
| Publication | Domain readiness, indexing intent, and review freshness. These informational/editorial checks have zero score weight. |
| Readability | English sentence/average length, paragraph length, subheading spacing, and three repeated consecutive sentence openings. |

No synonym, search-intent, passive-voice, syllable/Flesch, inclusive-language, or multilingual linguistic engine is included. Language comes from project settings, not automatic language detection. Unsupported rules are shown as **unavailable**, not failed. Non-English projects still get language-independent structural checks; their word count/readability score is unavailable.

The engine reads the sanitized text returned by the publishing service, not hidden editor markup or script content. Legacy body content is sanitized before analysis. It never checks whether a linked destination actually works; that belongs to a later link-checking phase.

## Scoring and limits

Rules are versioned as `2026.11.editorial.1`. Each applicable scored check contributes its configured weight. A pass earns that weight, a warning earns zero, and the score is the rounded percentage of earned/applicable weight. Unavailable, not-applicable, informational, and zero-weight checks are excluded from the denominator. No applicable checks means a null/unavailable score, not zero.

Title/description/body presence and image descriptions have weight 2; other scored checks have weight 1. Domain/indexing/freshness checks have weight 0. SEO and readability are scored independently.

Editorial heuristics, not search-engine rules:

- English search title: 20–70 characters; description: 70–180 characters.
- Opening keyphrase check: first 100 word tokens.
- A long sentence exceeds 25 words; guidance triggers when more than 25% are long. Average-length guidance uses 20 words.
- Long paragraphs exceed 150 words; section spacing guidance uses roughly 350 words. Stories under 300 words are not required to add subheadings or links.
- Three consecutive sentences with the same first word produce a suggestion.
- Content not edited or explicitly reviewed in 90 days is marked for review.

There is no target keyword density and no reward for repeating phrases mechanically. Useful, accurate writing takes precedence over a score. Simple punctuation-based sentence splitting can misread abbreviations and specialized prose; authors can document an intentional exception.

Stories over 500,000 bytes of combined sanitized body HTML receive unavailable analysis with an explanation. Content is not silently truncated and publishing still works. Live requests allow up to 100 sections; ordinary upload/publishing validation remains unchanged.

## Live requests and saved revisions

Live analysis is debounced by 800 ms, cancels superseded requests, and does not write posts or reports. Scores clear immediately when the draft changes. Request failures/rate limits do not disable editing or publication; rate limits retry after the server's interval. Current limits: 90 preview requests/minute, 30 per-story refreshes/minute, and 10 bounded page refreshes/minute per IP.

Successful create/update/move/status actions capture a saved analysis. Capture is idempotent for an identical input hash/rules version. Historical snapshots are retained when the revision changes. The fingerprint covers sanitized sections, metadata, images/alt choices, article identity, project defaults/verification, cornerstone state, review timing, and publication state.

A project content-context counter changes when stories are created/saved/moved/deleted. This conservatively marks other reports outdated, even if a particular duplicate count ultimately stays the same. Updating project defaults or rule versions also invalidates relevant reports. Ninety-day expiry is tracked so reports do not appear current after the freshness threshold passes. Outdated scores are hidden until refreshed.

Direct SQL edits/imports bypass normal model hooks. Run the backfill command after such changes. Viewing a health page does not silently recompute or mutate reports.

## Editorial decisions

A writer can dismiss or restore a **current warning** with a reason of 5–500 characters. The audit stores author ID, rule, rule version, input fingerprint, action, reason, and time. The latest decision for that exact revision is displayed; earlier decisions remain recorded. Arbitrary rule names, stale hashes, and cross-project mutations are rejected.

Analysis and review history are scoped to their original project. Moving a story does not expose the source workspace’s editorial reasons to target-project members. Unclassified older history remains stored privately; fresh scoped reports are generated by the backfill.

Reviewed warnings remain part of the raw score. Decisions do not hide the rule or manufacture a higher score. A changed content/context/rules fingerprint requires a new decision.

**Mark content reviewed** explicitly records editorial attention, captures current checks, and clears a due review flag. It does not change article text or the public `updated_at`/sitemap modification date. Ordinary story saves count as editing/reviewing that story.

## Content-health filters

Available filters: all stories, needs fresh analysis, missing resolved metadata, missing image descriptions, duplicate article titles, repeated focus keyphrases, not reviewed in 90 days, and cornerstone content.

Missing metadata uses current saved reports, so start by analyzing legacy content. Image/duplicate/review/cornerstone filters use stored article data. A missing explicit override is not automatically a problem: a useful inherited title/description passes. Health pages contain up to 20 stories and refresh at most that page's 20 validated project-owned IDs in one action.

## Existing installations and operations

Deploy the new migration, build frontend assets, and backfill reports:

```sh
php artisan migrate --force
npm ci
npm run build
php artisan seo:analyze
# Or limit the backfill:
php artisan seo:analyze --project=123
```

The CLI indexes missing normalized title/keyphrase keys before analyzing stories in batches of 20. It preserves article text and public modification dates. There is no external provider, AI charge, or API credential to configure.

The additive migration tolerates partial MySQL DDL application without dropping existing records. Local XAMPP migration/backfill were verified, in addition to isolated SQLite tests. Older report/review retention is not automatically purged; define an administrative retention policy if a high-volume publication needs one.

## Verification

Tests cover separate scores, sanitized multi-section content, empty/unsupported/oversized states, image/decorative behavior, duplicate isolation, stale inputs/settings/rules/context/time, preview privacy, public-API exclusions, low-score publication, review reasons/restoration/escaping, read-only permissions, bounded refreshes, and non-destructive backfill/review actions. Browser checks cover live feedback, multi-section word counts, publication, saved decisions, cornerstone filtering, and desktop/mobile layouts.

Phase 3 now supplies bounded link graphs/suggestions, reviewed redirects, selected outgoing HEAD checks, bulk metadata workflows and authenticated 404 telemetry. See [Links and URL lifecycle](links-and-redirects.md). External analytics and AI remain later phases.
