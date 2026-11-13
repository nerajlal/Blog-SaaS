# External SEO product research: Rank Math and Yoast

**Reviewed:** 5 November 2026 (Asia/Kolkata).  
**Purpose:** Document competitor capabilities and identify future WritePost features.
**Status:** Research baseline plus implementation tracking. Phases 1–2 are implemented as described below; the remaining backlog is proposed, and no purchase is approved.

## 1. Scope and reading guide

The starting references are [Rank Math SEO Suite](https://rankmath.com/wordpress/plugin/seo-suite/) and [Yoast SEO Premium](https://yoast.com/get-yoast-seo-premium/). Advertising/tracking parameters have been removed. Supporting sources below are the vendors' official documentation, their publisher-maintained WordPress listings, and Google's documentation where marketing claims need qualification.

This inventory covers the principal product families and feature groups relevant to a blog platform, including specialist and newer AI offerings. It is not an exhaustive listing of every schema property, integration, promotional partner deal, or historical release. No paid plugin was installed or tested. Features are documented capabilities, not independent performance measurements.

Labels used below:

- **Core:** Described in general/base-product documentation; this does not promise every advanced option is free.
- **Paid:** Explicitly described as Premium/PRO or a paid offering.
- **Separate/conditional:** Requires another subscription, integration, allowance, or product.
- **Verify:** Official pages conflict, omit the exact entitlement, or present an ambiguous comparison.
- **Proposal:** Our suggested adaptation for WritePost, not a statement about a competitor.

Availability, language support, quotas, and pricing can change. Recheck the linked feature page and checkout before buying or promising parity.

## 2. Product positioning and commercial boundaries

### 2.1 Rank Math

Rank Math combines an editor assistant with technical SEO modules, schema management, reporting, and optional AI services in WordPress. Its setup wizard configures identity, social, and webmaster settings; editor previews show search/social appearance. The suite also advertises MCP tools through which compatible assistants can inspect and manage SEO. These are WordPress capabilities, not an installable Laravel package. [Rank Math suite](https://rankmath.com/wordpress/plugin/seo-suite/)

| Offering | Observed scope and price snapshot |
| --- | --- |
| Free plugin | Free entry product; advanced functionality varies by module. |
| PRO | Own/personal sites; client sites excluded. Sale: USD 5.59/month, billed annually; renewal USD 8.99/month annually. |
| Business | Sale advertises 200 client sites and 20,000 tracked keywords; USD 17.59/month annually, renewal USD 27.99/month annually. |
| Agency | Sale advertises 750 client sites and 75,000 tracked keywords; USD 38.59/month annually, renewal USD 64.99/month annually. |
| Rank Math AI | Separate AI pricing/allowances; plugin offers include trials rather than guaranteed permanent unlimited AI. |

Prices exclude VAT/taxes. The offer page's cards and FAQ disagree on some site limits, keyword allowances, and refresh intervals. Record sale quotas as temporary observations; verify renewal entitlements at checkout. The FAQ says advanced AI linking needs PRO plus a Content AI subscription. [Official pricing, currently redirected to an offer](https://rankmath.com/pricing/)

### 2.2 Yoast

| Offering | Observed scope |
| --- | --- |
| Yoast SEO Free | Base WordPress SEO and writing tools. |
| Yoast SEO Premium | The reviewed page displays USD 118.80/year excluding VAT. It advertises Local, News, and Video SEO plugins, one Google Docs add-on seat, Academy access, and 24/7 human chat/email support. |
| Yoast WooCommerce SEO | A separate commerce-focused offering; do not assume ordinary Premium includes all its commerce features. |
| Yoast SEO AI+ | An additional visibility/commerce package described separately below. |
| Shopify / Google Docs | Separate platform products/add-ons; not equivalent to the WordPress plugin. |

Premium's sales page labels some AI functions beta. Confirm current license/site counts and AI usage limits before purchase. [Premium offering](https://yoast.com/get-yoast-seo-premium/)

## 3. Rank Math feature inventory

### 3.1 Writing assistance and on-page analysis

| Capability | What is provided | Availability note |
| --- | --- | --- |
| Focus keywords | Primary/secondary keywords and keyword-specific recommendations. | PRO documentation advertises unlimited focus keywords; do not confuse these with rank-tracking quotas. |
| Content checks | Keyword placement in title, description, URL, introduction, headings, body, and image attributes; content/link checks. | Core analysis; individual tests vary. |
| Title/content readability | Checks for title qualities, paragraph structure, media, and other presentation factors. | Core analysis. |
| SEO scoring | A numeric score with grouped problems and suggested corrections. | An internal diagnostic, not a search-engine ranking. |
| Pillar content | Marks important articles to support linking strategy. | Documented in the writing workflow. |
| Keyword suggestions/trends | Account-connected suggestions; Trends comparisons and search-intent assistance. | Some functions are PRO/account-dependent. |

These are rule-based recommendations with keyword-specific behavior, not a guarantee that a fully scored page will rank. [Rank Math post analysis documentation](https://rankmath.com/kb/score-100-in-tests/)

### 3.2 Metadata, identity, and social appearance

| Capability | What is provided |
| --- | --- |
| Search metadata | Title/description templates and per-content overrides. |
| Indexing directives | Robots settings, indexing defaults, and advanced snippet directives. |
| Site/entity identity | Person/organization settings and social profiles. |
| Content-type defaults | Settings for posts, pages, archives, taxonomies, authors, and installed content types. |
| Social metadata | Open Graph/Twitter-related defaults, images, and sharing appearance settings. |
| Archive control | Search-appearance and indexing choices for archive-like pages. |

Some options require advanced mode, additional installed plugins, or paid features. [Titles and Meta settings](https://rankmath.com/kb/titles-and-meta/)

### 3.3 Technical SEO and discovery

| Capability | What is provided | Blog-platform relevance |
| --- | --- | --- |
| Canonical/link cleanup | Canonical-related SEO settings and configurable link behavior. | Consistent public URLs and safe link attributes. |
| Breadcrumbs | Configurable navigation trails. | Public frontend must render them. |
| Webmaster verification | Site-verification settings. | Requires control of the actual public domain. |
| robots.txt / llms.txt | Editors/settings for discovery-related files. | Serve at the consumer site's origin. |
| Advanced administration | Settings include .htaccess editing, RSS/other output, and module controls. | Do not expose raw server-config editing in a shared SaaS. |

Source: [General Settings](https://rankmath.com/kb/general-settings/).

| Capability | What is provided | Availability note |
| --- | --- | --- |
| XML sitemap/index | Automatic sitemap configuration and content inclusion/exclusion. | Core sitemap module. |
| Image inclusion/custom URLs | Sitemap controls for media and extra public URLs. | Depends on configuration. |
| News/video discovery | Specialized News and Video sitemap capabilities are advertised. | Verify paid/module requirements. |

Sources: [Sitemap configuration](https://rankmath.com/kb/configure-sitemaps/), [suite overview](https://rankmath.com/wordpress/plugin/seo-suite/).

### 3.4 Structured data

| Capability | What is provided |
| --- | --- |
| Preset schema | Article, product, recipe, event, video, local-business, FAQ, and HowTo examples are advertised. |
| Custom schema builder | Property/group editing and reusable structures. |
| Multiple schemas/templates | Multiple schema objects, saved templates, and conditional placement. |
| Import/validation workflow | Importable schema and validation-tool workflows. |

The custom schema builder is explicitly PRO. Schema support is broader than the list above; plugin support for a type does **not** imply Google currently offers a rich result for it. [Custom schema documentation](https://rankmath.com/kb/schema-generator/), [suite schema examples](https://rankmath.com/wordpress/plugin/seo-suite/).

### 3.5 Images, redirects, and broken URLs

| Capability | What is provided | Important distinction |
| --- | --- | --- |
| Image metadata | Automatic missing alt/title attributes using variables. | Generated text still needs editorial review. |
| Advanced image rules | Caption/description and text replacement/case-related options. | Documentation distinguishes Free and PRO options. |
| 404 monitoring | Logs requested missing URLs, hit counts, and timestamps; advanced mode adds referrer/user-agent context. | Logging incoming 404s is different from scanning outgoing links. |
| Redirect management | Redirect rules managed from dedicated screens, editor workflows, and the 404 monitor. | Actual HTTP handling occurs on the WordPress site. |
| Redirect matching | Supports configurable matching and response-code choices. | Complex rules need loop/chain testing. |

Sources: [Image SEO](https://rankmath.com/kb/image-seo/), [404 monitor](https://rankmath.com/kb/advanced-404-monitor/), [redirections](https://rankmath.com/kb/setting-up-redirections/).

### 3.6 Analytics and external search services

| Capability | What is provided |
| --- | --- |
| Search Console reporting | Query/page performance, clicks, impressions, CTR, and positions. |
| Google Analytics integration | Traffic/performance reporting from connected Google properties. |
| Rank/content performance | Keyword monitoring, historical positions, winning/losing content, and post-level reporting. |
| Index-status inspection | Visibility into crawl/index status using connected Google services. |
| AI traffic reporting | Identifies traffic attributed to AI search/referral sources. |

These require external data access, not just local content inspection; quotas, refresh timing, and history vary by plan. [Analytics documentation](https://rankmath.com/kb/analytics/)

**IndexNow:** Automatic/manual URL notifications, submission history, and API-key management for participating engines. This is a notification workflow, not a guarantee of indexing. [IndexNow documentation](https://rankmath.com/kb/how-to-use-indexnow/)

### 3.7 AI products and automation

| Product | Documented capabilities | Commercial boundary |
| --- | --- | --- |
| Content AI | Article generation; research/topics/outlines; rewriting and writing assistance; SEO-focused tool templates; recommendations; RankBot assistance. | Paid/credit or plan-limited service; trials are not unlimited access. |
| AI Link Genius | Link inventory, in-editor suggestions, bulk URL updates, keyword-to-URL automation, broken-link monitoring, orphan-page discovery, related content, exports. | Confirm plugin plus AI entitlements. |
| AI Visibility | Brand/product monitoring by country and questions; mentions, sentiment, citations, competitors, trends, and an aggregate visibility score. | Separate service/allowance; not equivalent to normal web analytics. |
| MCP tools | Assistant-connected SEO inspection and management. | Exact permissions/plan support must be verified. |

Sources: [Content AI](https://rankmath.com/content-ai/), [AI Link Genius](https://rankmath.com/ai-link-genius/), [publisher's link-feature inventory](https://wordpress.org/plugins/seo-by-rank-math/), [AI Visibility](https://rankmath.com/ai-visibility/), [MCP overview in the suite](https://rankmath.com/wordpress/plugin/seo-suite/).

### 3.8 Specialist and operational capabilities

The suite advertises WooCommerce/EDD, multi-location local SEO, podcast support, bulk SEO editing/import-export, reporting, and builder integrations. Some are paid or require corresponding WordPress products. These are extension areas rather than necessary features for WritePost's first SEO release. Client-site licensing and reporting are not permission to resell the plugin as our own service. [Pricing feature catalog](https://rankmath.com/pricing/)

## 4. Yoast feature inventory

### 4.1 Content SEO and readability

| Capability | What is provided | Availability note |
| --- | --- | --- |
| SEO analysis | Checks keyphrase usage in title, URL, metadata, text, links, and related elements. | Base analysis available across Free and paid products. |
| Actionable feedback | Red/orange/green recommendations while writing. | Diagnostic guidance, not a Google score. |
| Related language | Additional target keywords, synonyms, and word-form recognition. | Premium; language coverage varies. |
| Structure/readability | Paragraph/sentence length, subheading distribution, repeated sentence starts, passive voice, transition words, and text presence. | Standard checks are in Free and Premium. |
| Advanced readability | Word complexity and text alignment assessments. | The current dedicated comparison marks these Premium. |

Sources: [SEO analysis](https://yoast.com/features/seo-analysis/), [readability comparison](https://yoast.com/features/readability-analysis/), [Premium language features](https://yoast.com/get-yoast-seo-premium/).

### 4.2 Search metadata and structured output

| Capability | What is provided |
| --- | --- |
| Titles/descriptions | Editable search appearance with template variables and previews. |
| Canonicals/robots | Canonical output and indexing directives. |
| XML sitemaps | Automatically maintained discovery documents. |
| Schema graph | Connected organization/person, website, webpage, article, and breadcrumb data. |
| Breadcrumbs | Navigation support and primary-category choices. |
| Blocks/media checks | FAQ/HowTo block support, image-alt analysis, and content preview tools. |
| Social output | Social appearance controls; visual social previews are promoted as Premium. |

Sources: [Base product](https://yoast.com/product/yoast-seo-wordpress/), [feature inventory](https://yoast.com/features/), [Premium social previews](https://yoast.com/get-yoast-seo-premium/).

### 4.3 Linking, maintenance, and workflow

| Capability | What is provided | Availability note |
| --- | --- | --- |
| Redirect manager | Creates/manages redirects when URLs change; prompts for deleted content; supports bulk workflows. | Premium/eligible paid bundles. |
| Internal-link suggestions | Related pages suggested during writing. | Premium. |
| Cornerstone content | Marks important pages and supports a deliberate linking structure. | Marking exists in the base product; advanced maintenance varies. |
| Orphan/stale content | Helps identify unlinked content and older important articles. | Premium maintenance features. |
| SEO workouts | Guided tasks to improve a site's content/link structure. | Listed as Premium. |
| SEO task list/dashboard | Prioritized tasks, estimated effort, and content-health overview. | Product/tier details vary. |
| Bulk/migration tools | Imports settings/SEO data and supports administrative workflows. | Verify exact formats and entitlement. |

Sources: [Redirect manager](https://yoast.com/features/redirect-manager/), [internal linking](https://yoast.com/features/internal-linking-suggestions/), [base product](https://yoast.com/product/yoast-seo-wordpress/), [feature timeline](https://yoast.com/features/), [publisher listing](https://wordpress.org/plugins/wordpress-seo/).

### 4.4 Crawl control, AI discoverability, and developer access

| Capability | What is provided | Availability/qualification |
| --- | --- | --- |
| Crawl optimization | Reduces unnecessary WordPress-generated resources/URLs. | Dedicated page says Free and Premium; an older timeline labels Premium. Verify individual settings. |
| Bot blocker | Controls selected AI crawlers. | Premium listing; crawler cooperation and actual enforcement matter. |
| llms.txt | Content-selection/management for an AI-oriented discovery file. | Listed in base/premium materials; not a Google ranking requirement. |
| Schema aggregation | Consolidated structured-data output for AI-oriented consumption. | Timeline lists multiple products; publisher listing labels NLWeb aggregation Premium. Treat exact entitlement as unresolved. |
| SEO REST API | Returns SEO HTML and structured JSON by URL or alongside WordPress REST objects. | Read access for headless integrations, not a generic remote SEO-write API. |
| Abilities API | Exposes SEO, readability, and inclusive-language scores to integrations. | Described in the publisher listing; verify API/version requirements. |

Sources: [Crawl settings](https://yoast.com/features/crawl-settings/), [feature timeline](https://yoast.com/features/), [publisher listing](https://wordpress.org/plugins/wordpress-seo/), [REST API documentation](https://developer.yoast.com/customization/apis/rest-api/).

### 4.5 AI and specialist offerings

| Offering | What is provided |
| --- | --- |
| AI Generate / Optimize | Metadata generation and suggested content improvements. |
| AI Content Planner | Topic/gap-informed planning and structured drafts, as listed in the feature timeline. |
| AI Brand Insights / AI+ | Brand mentions, sentiment, competitor comparison, citations, and custom questions across AI platforms; AI+ advertises weekly analysis and WooCommerce SEO. |
| WooCommerce SEO | Commerce sitemaps, product schema, canonicals, product-focused analysis, and AI metadata. |
| Local SEO | Business/location structured data, multi-location support, and maps. |
| News / Video SEO | Specialist discovery and search-appearance modules. |
| Other integrations | Google Docs workflow, Elementor, Semrush keyword research, and Wincher tracking. External accounts/limits may apply. |

Sources: [AI Generate](https://yoast.com/features/ai-features/ai-generate/), [current feature timeline](https://yoast.com/features/), [AI+ product and commerce package](https://yoast.com/product/yoast-seo-ai-plus/), [Local SEO](https://yoast.com/features/local-seo/), [News SEO](https://yoast.com/features/news-seo/), [Video SEO](https://yoast.com/features/video-seo/), [Premium integrations](https://yoast.com/get-yoast-seo-premium/).

**Entitlement caution:** A timeline entry is evidence that a capability exists, not proof that every Premium customer receives the full AI+ monitoring allowance. Premium, AI+, WooCommerce, and integration quotas must be checked separately.

## 5. Marketing claims we should not copy into WritePost

| Claim or ambiguity | What to document instead |
| --- | --- |
| “A perfect SEO score guarantees ranking.” | A local score measures our checks. It is not a Google score, forecast, or guarantee. |
| “Submitting a URL guarantees instant indexing.” | Submission/notification is separate from search-engine crawling, indexing, and ranking. |
| “FAQ schema will produce Google FAQ rich results.” | Google's current update log says FAQ rich results stopped appearing on 7 May 2026. Competitor pages still advertise older outcomes. |
| “llms.txt improves Google ranking.” | Google's update log explicitly says this file is not needed for Google Search and does not positively or negatively affect visibility/ranking. Other consumers may use it. |
| “robots.txt protects private content.” | Google explains that robots.txt regulates crawler access; it is not authentication or a reliable way to keep a URL out of the index. |
| “Google's Indexing API is a generic blog-submission API.” | Google limits it to supported job-posting or eligible livestream pages. Do not use it indiscriminately for ordinary blog articles. |
| “Adding any schema guarantees rich results.” | Revalidate current eligibility and required fields for each type; valid markup alone is not a display promise. |
| “AI monitoring reports everyone's AI answers.” | Proposed monitoring should expose its sampled prompts, engines, geography, date, and variability. |

Sources for search-engine constraints: [Google documentation updates](https://developers.google.com/search/updates), [robots.txt guidance](https://developers.google.com/search/docs/crawling-indexing/robots/intro), [Indexing API eligibility](https://developers.google.com/search/apis/indexing-api/v3/using-api). Scoring, sampling, and product wording recommendations are our own interpretation.

## 6. What this means for the current WritePost project

### 6.1 Existing foundation versus missing SEO features

These observations come from the repository, not the competitor websites.

| Area | Existing foundation | Missing or incomplete for an SEO module |
| --- | --- | --- |
| Publishing | Titles, stable slugs, drafts, scheduling, authors, categories, sections. | Dedicated per-post SEO metadata and editorial diagnostics. |
| Media | Cover, author, and section image paths. | Cover/section alt text, captions, and social-image overrides. |
| Public API | Published posts with sections/categories and pagination. | A versioned SEO payload and per-post metadata retrieval contract. |
| Landing page | Description, canonical, Open Graph basics. | This does not provide SEO metadata for customer blog posts. |
| Workspace isolation | Project policies and scoped access. | The same boundaries must protect every future SEO setting, crawl job, and integration. |
| Consumer delivery | Customers build their own frontend. | Verified public-domain mapping, URL templates, metadata rendering, and cache revalidation integration. |
| Search data | No SEO analytics integration. | OAuth connections, verified properties, refresh jobs, quotas, and reports. |
| Link management | Saved-article link graph, orphan reports, approved suggestions, safe queued HEAD checks, exact redirects and authenticated 404 telemetry. | Recurring full-site crawls, regex/cross-origin redirects and semantic AI linking. |

Relevant code: `app/Models/Blog.php`, `app/Models/BlogSection.php`, `app/Models/Project.php`, `app/Policies/ProjectPolicy.php`, `app/Http/Controllers/Admin/BlogController.php`, `app/Http/Controllers/Api/BlogApiController.php`, and `resources/views/welcome.blade.php`.

### 6.2 Critical architecture boundary: headless delivery

The following is a **proposed design**, informed by the repository and Yoast's documented headless approach. [Yoast REST API example](https://developer.yoast.com/customization/apis/rest-api/)

| Responsibility | WritePost backend | Customer's public website |
| --- | --- | --- |
| Metadata | Store overrides/defaults; resolve a safe SEO JSON object. | Render title, meta tags, canonical, Open Graph, and X cards in crawlable HTML. |
| Structured data | Generate validated JSON-LD from approved fields. | Serialize safely into the actual public article page. |
| Sitemaps | Supply eligible article URLs and modification dates. | Serve/proxy the sitemap on the public origin and advertise it. |
| robots.txt / crawler controls | Offer validated configuration suggestions. | Serve/enforce rules at the actual origin/edge. |
| Redirects | Store scoped source/target rules and history. | Apply the real HTTP status before rendering. |
| 404 reporting | Receive scoped events and summarize them. | Send events from actual visitor requests. |
| Canonicals and links | Resolve using a verified project origin and route template. | Ensure emitted URLs match the real site structure. |
| Scheduling | Determine publication eligibility. | Revalidate cached pages and discovery documents at the publication time. |

Returning an SEO JSON object from our API does not automatically improve the consumer site's HTML. An integration guide/adapter and an end-to-end rendered-page test are part of the feature, not optional follow-up work.

A sitemap must list the **public article URLs**, not API endpoints, admin edit links, or draft previews. The existing `subdomain` and `custom_domain` columns are not evidence that hosted public blog routes or verified domains already exist.

## 7. Proposed implementation backlog

Everything below is a proposal for a later task. Priority reflects WritePost usefulness and dependencies, not a claim of competitor parity.

### Phase 1 — SEO essentials and consumer contract

| ID | Proposed feature | Completion criteria |
| --- | --- | --- |
| SEO-01 | Project SEO settings | Store brand identity, language, verified public origin, article route template, title template, fallback description, and default social image. |
| SEO-02 | Per-post SEO fields | Add title/description overrides, focus keyphrase, canonical override, robots choices, and social overrides. Default to useful derived values. |
| SEO-03 | Media metadata | Add image alt/caption fields; distinguish intentionally decorative images from missing descriptions. |
| SEO-04 | Search/social previews | Preview resolved metadata, show useful length warnings, and label previews as approximations. |
| SEO-05 | SEO API object | Add an additive, documented payload; preserve current clients and never expose draft metadata through public endpoints. |
| SEO-06 | Article schema | Generate a connected minimal Article/BlogPosting, author, publisher, webpage, and breadcrumb graph from real data. |
| SEO-07 | Consumer example | Demonstrate server-rendered metadata and JSON-LD on one reference frontend. |
| SEO-08 | Publication-aware sitemap | Include only live, canonical, indexable consumer URLs; update on publish, edit, move, and unpublish. |

**Phase 1 release gate:** A public test article renders the intended title, description, canonical, social tags, and valid JSON-LD; its URL appears in the correct sitemap. Drafts/future posts and cross-tenant metadata remain inaccessible.

### Phase 2 — Deterministic editor assistant

| ID | Proposed feature | Completion criteria |
| --- | --- | --- |
| SEO-09 | Explainable checks | Check metadata completeness, keyphrase placement, headings, intro, links, and image descriptions with clear corrective messages. |
| SEO-10 | Readability | Begin with sentence/paragraph length and subheading spacing; expose language limitations before adding linguistic checks. |
| SEO-11 | Separate diagnostic scores | Keep SEO and readability distinct; version the rule set and report unsupported checks as unavailable rather than failures. |
| SEO-12 | Content health overview | Filter missing metadata, absent alt text, duplicate targets/titles, and stale content within a project. |
| SEO-13 | Review workflow | Save analysis by content revision, support ignored warnings with reasons, and avoid blocking publication over subjective scoring. |

Run analyses against the normalized, sanitized content that will actually be delivered, across **all** sections. Do not count hidden HTML, toolbar controls, or editor-only markup as article content.

### Phase 3 — Linking and URL lifecycle (implemented within documented limits)

| ID | Proposed feature | Completion criteria |
| --- | --- | --- |
| SEO-14 | Link graph and orphan reports | Parse internal/external links using the verified public origin; scope graph data to a project. |
| SEO-15 | Suggested internal links | Start with category/text relevance and explain recommendations; insert only after approval. |
| SEO-16 | Redirect manager | Support explicit redirects and reviewed move/deletion handling; detect self-redirects, cycles, chains, and unsafe targets. |
| SEO-17 | Broken-link checks | Use queued, bounded HTTP checks with retries and meaningful status classification. |
| SEO-18 | 404 telemetry | Accept authenticated/scoped consumer events, aggregate counts, and apply retention rules. |
| SEO-19 | Import/export | Preview bulk metadata/redirect changes, report validation errors, and provide rollback/audit history. |

Do not silently rewrite article bodies or create a homepage redirect for every deleted URL. A deleted article may need a replacement, a deliberate 404, or a 410 depending on the editorial decision.

### Phase 4 — Verified integrations and analytics (partially implemented; Google configuration pending)

| ID | Proposed feature | Completion criteria |
| --- | --- | --- |
| SEO-20 | Search Console | Connect verified properties using OAuth; encrypted tokens, revocation, scoped access, and connection health. |
| SEO-21 | Performance dashboard | Persist clicks/impressions/CTR/position with source, date range, freshness, and empty-data states. |
| SEO-22 | Index-status checks | Respect property verification, API permissions, quotas, and retries; distinguish inspection from submission. |
| SEO-23 | IndexNow | Enable only with verified-origin key hosting and appropriate consumer publication hooks. |
| SEO-24 | Reports | Project/workspace reports and optional scheduled digests with explicit recipients and controls. |

Rank tracking from Search Console is not the same as a location/device-specific independent SERP-tracking service. Decide which product we intend to offer before choosing a provider or naming the feature.

### Phase 5 — Optional AI and specialist modules

| ID | Proposed feature | Dependency or limitation |
| --- | --- | --- |
| SEO-25 | AI metadata suggestions | Provider, budget/credits, tenant isolation, reviewable diffs, and no automatic publication. |
| SEO-26 | Draft planning/rewrites | Explicit context-sharing policy and preservation of author edits. |
| SEO-27 | Semantic linking | Enough project content, evaluation examples, and a reversible insertion workflow. |
| SEO-28 | AI brand visibility | A sampled monitoring service with documented prompts, engines, costs, and uncertainty. |
| SEO-29 | llms.txt / crawler preferences | Consumer-origin integration; do not market as guaranteed Google visibility or content protection. |
| SEO-30 | Local/news/video schema | Build only for an actual audience and current eligibility requirements. |
| SEO-31 | Commerce/podcast extensions | Separate product scope and data model; not part of a basic blog SEO release. |
| SEO-32 | Assistant/MCP integration | Narrow permissions, authenticated tool calls, audit history, and explicit control over mutations. |

## 8. Proposed data and API design for later review

These names are illustrative design options, **not migrations or implemented endpoints**.

| Proposed entity | Candidate fields |
| --- | --- |
| `project_seo_settings` | `project_id`, public origin/verification status, route template, brand/publisher identity, locale, title template, fallback description/social image. |
| `blog_seo` | `blog_id`, SEO title/description, canonical override, robots settings, primary/related keyphrases, Open Graph/X overrides, schema choices. |
| Media metadata | Cover/section alt text, decorative flag, caption, dimensions, and optional social-image alt. |
| `seo_analysis` | Project/blog/revision, rules version, language, input hash, findings, scores, and analysis time. |
| `content_links` | Project/source blog, target URL/blog, anchor text, relation, check status, and checked time. |
| `redirects` | Project/public origin, source path, destination, status, enabled flag, reason, creator, and audit metadata. |
| `seo_connections` | Workspace/project, provider, verified property, encrypted credentials, scopes, expiry, and health. |
| `seo_metrics` / jobs | Provider/date dimensions, freshness, quotas, retries, and aggregate metrics. |

Suggested metadata inheritance: **post override → project default/template → derived article value**. Validate the resolved result, not only the fields the author typed. Keep canonical and social URLs absolute; resolve relative media paths against the actual configured media origin.

Suggested additive response shape:

```json
{
  "id": 123,
  "title": "Example story",
  "slug": "example-story",
  "seo": {
    "title": "Example story | Example publication",
    "description": "An editorially reviewed description.",
    "canonical_url": "https://publication.example/blog/example-story",
    "robots": { "index": true, "follow": true },
    "open_graph": {
      "title": "Example story",
      "description": "An editorially reviewed description.",
      "image_url": "https://media.example/story.jpg"
    },
    "schema": {
      "@context": "https://schema.org",
      "@type": "BlogPosting",
      "headline": "Example story"
    }
  }
}
```

This abbreviated schema only illustrates API structure; it is not a complete rich-result-ready Article example. The future generator must include the relevant real author, publisher, image, URL, and publication/update information and validate current requirements. Keep internal keywords, draft analyses, OAuth data, and private performance metrics out of this public object.

## 9. Engineering acceptance criteria and unresolved decisions

Before implementation, decide:

1. Will we only support customer-owned frontends, or also host article pages ourselves?
2. How will customers verify public origins and configure article routes and media origins?
3. Which languages must the initial checker support? How will unsupported language checks be presented?
4. Should SEO essentials be available to every account? What is the actual paid-product boundary?
5. Who can alter project identity, crawl controls, or redirects versus edit per-post metadata?
6. Which frontend integration is the reference implementation?
7. Are AI and external analytics in scope, with what provider costs and retention periods?
8. How will scheduled publications and API consumers trigger cache invalidation?

Acceptance criteria for the later implementation:

- Every read, write, job, crawl result, and provider connection enforces workspace/project authorization.
- Previews, metadata, schema, and crawler-facing files never leak unpublished or other tenants' content.
- Metadata text is escaped; URLs and protocols are validated; JSON-LD serialization cannot break out of its script element.
- Link checking/import-by-URL cannot reach private networks, localhost, cloud metadata endpoints, or unsafe redirected destinations; apply time/body/redirect limits.
- OAuth credentials remain encrypted and absent from logs/public APIs. Provider failures do not prevent ordinary editing/publishing.
- Content suggestions are reviewable and reversible. AI use has explicit limits and does not silently send unrelated tenant data.
- Schema matches visible facts; do not invent authors, ratings, business locations, or reviews.
- Redirect behavior, origin rules, noindex handling, sitemap membership, and rendered HTML are tested on the real consumer origin.
- A content revision or rules change invalidates stale analysis. Jobs and publication hooks are idempotent.
- Bulk operations have validation previews, audit history, bounded batches, and recoverable failures.

**Recommended first implementation:** Phase 1, followed by Phase 2. Establish trustworthy metadata delivery and explainable editorial checks before paid AI, dashboards, or a broad schema marketplace.

## 10. Research limitations and refresh checklist

- This is public-document research. UI behavior, feature quality, and commercial allowances have not been independently tested in paid accounts.
- Exact per-setting Free/PRO entitlements are left conditional where source text loses comparison checkmarks or conflicts with another official page.
- Rank Math promotion cards conflict with portions of their FAQ. Yoast timeline, dedicated feature pages, and current publisher listing also differ on a few entitlements. Do not resolve these by guessing.
- Prices above are dated snapshots, not a purchase recommendation or a proposed WritePost price list.
- Recheck pricing, licensing, AI quotas, API permissions, supported languages, and current search-engine policies before implementation.
- Build original workflows and code suited to Laravel/headless delivery. Feature research does not authorize copying vendor branding, proprietary implementation, or making unsupported SEO promises.

### Implementation update — Phases 1–2

Implemented in the repository: project SEO defaults, owner/admin settings permissions, DNS TXT domain verification, per-post metadata/robots/social overrides, cover/section alt text and captions, approximate live previews, a private focus-keyphrase field, connected article JSON-LD, single-post SEO retrieval, and a publication-aware paginated sitemap feed. The reference PHP consumer renders metadata server-side and serves a public-origin sitemap index/pages. See [setup and API documentation](seo.md) and [reference consumer](../examples/headless-php/README.md).

The local XAMPP schema has been migrated. Real customer DNS verification and frontend integration are still required per publication. Phase 2 now includes versioned, separate SEO/readability scores; exact keyphrase, metadata, structure, image, and duplicate checks; live draft feedback; saved revision reports; cornerstone/freshness tracking; content-health filters; and audited warning decisions. English-only writing heuristics are explicit; other languages retain structural checks. See [editorial assistant documentation](editorial-analysis.md). Phase 3 now adds project-scoped link inventories and possible-orphan reports, deterministic suggestions with approved appended links/guarded undo, queued safe HEAD checks, exact-path redirect management, CSV redirect/metadata previews with audit/undo, and authenticated 404 telemetry with retention. Consumer integration, verified domains, supervised SEO queue workers and the scheduler are required. No automatic redirects are created for story deletion/moves. See [link and URL operations](links-and-redirects.md). Phase 4 now includes read-only Search Console OAuth with encrypted tokens, property-scoped performance snapshots/CSV and indexed-version inspections. Google client configuration and live account consent are still required and untested. IndexNow, GA4, scheduled digests, independent rank tracking and AI remain unimplemented. See [Search Console setup](search-console.md).

## 11. Feature comparison — WritePost vs Rank Math vs Yoast

Each row compares the same feature across exactly three product columns. The feature name appears in the WritePost cell to keep the table to three columns.

**Status key:** **Available** = implemented in our repository or documented by the vendor; **Partial** = a limited implementation; **Not built** = absent from WritePost; **WordPress** = supplied by the host platform rather than the SEO plugin; **Conditional** = plan, add-on, integration, or entitlement must be checked. Phase references point to the roadmap in section 7; implementation status is stated explicitly per feature.

Competitor entries summarize the dated research and linked sources in sections 2–5. They describe the product families, not a promise that every feature is included in the free plugin or a single subscription.

| Ours — WritePost | Theirs — Rank Math | Theirs — Yoast |
| --- | --- | --- |
| **Platform:** Available — standalone Laravel headless CMS. | WordPress SEO plugin and connected services. | WordPress SEO plugin; separate platform products also exist. |
| **Writing, drafts, categories, scheduling:** Available — built into our CMS. | WordPress supplies the publishing workflow. | WordPress supplies the publishing workflow. |
| **Multiple projects/workspaces:** Available — project separation and workspace membership. | Multiple WordPress sites; own/client-site licensing varies. Not the same workspace model. | WordPress sites and product licensing. Not the same workspace model. |
| **Roles and permissions:** Partial — owner/admin/editor write access and read-only members; no invitation/role-management screens. | WordPress roles plus documented feature-access controls. | WordPress roles; verify product-specific access controls. |
| **Project SEO setup/defaults:** Available — settings form with publication identity/defaults and DNS verification; no guided wizard. | Available — site identity and SEO setup. | Available — configuration wizard and site identity. |
| **Per-post SEO title and description:** Available — editable overrides with inheritance. | Available — metadata overrides. | Available — metadata overrides. |
| **Reusable metadata templates:** Available — {title}/{site_name} template and project defaults. | Available — title/meta templates. | Available — metadata variables/templates. |
| **Canonical URLs:** Available — verified-origin URLs and per-post overrides. | Available — canonical controls. | Available — canonical output. |
| **Index/noindex and robots metadata:** Available — project defaults and post overrides delivered through the API. | Available — robots/indexing controls. | Available — robots/indexing controls. |
| **Search-result preview:** Available — live, approximate title/description preview. | Available — snippet preview. | Available — content/search preview. |
| **Open Graph/X metadata:** Available — per-post/network overrides and resolved API output. | Available — social metadata settings. | Available — social metadata settings. |
| **Social appearance previews:** Available — approximate Open Graph card/text/image preview; no separate X-layout simulator. | Available — social snippet previews; verify tier. | Paid — Premium social previews. |
| **Focus keyword/keyphrase:** Available — private primary keyphrase with exact English phrase checks; no synonyms/semantic analysis. | Available — primary/secondary focus keywords; PRO advertises unlimited. | Available — one keyphrase in Free; expanded targeting in Premium. |
| **Related terms/synonyms:** Not built — later Phase 2 enhancement. | Conditional — keyword suggestions and AI/research tools; do not assume identical linguistic analysis. | Paid — related keyphrases, synonyms, and word forms. |
| **Live on-page SEO checks:** Available — debounced, server-side analysis of sanitized draft sections. | Available — grouped keyword/content checks. | Available — real-time SEO analysis. |
| **SEO score and corrective feedback:** Available — explainable, versioned weighted checks with separate raw scores and review decisions. | Available — numeric SEO scoring and recommendations. | Available — traffic-light SEO guidance. |
| **Readability analysis:** Available — English sentence/paragraph/heading heuristics; unavailable checks are explicitly excluded. | Available — title/content readability checks. | Available — sentence, paragraph, structure, and language checks; advanced checks vary by tier. |
| **Important/pillar content marking:** Available — private cornerstone flag and health filter. | Available — pillar-content designation. | Available — cornerstone-content designation. |
| **Article/BlogPosting structured data:** Available — connected JSON-LD graph for verified projects. | Available — article schema. | Available — article within connected schema output. |
| **Custom schema builder/templates:** Not built — optional after basic article schema. | Paid — custom schema, templates, and display conditions. | Connected schema and developer customization; no equivalent general visual builder established by this research. |
| **FAQ/HowTo content and schema:** Not built — optional specialist scope. | Available — relevant blocks/schema. | Available — relevant blocks/schema. Neither entry promises current Google rich results. |
| **Breadcrumbs:** Partial — home/article BreadcrumbList schema; no configurable visual breadcrumb widget. | Available — configurable breadcrumbs. | Available — breadcrumbs and category controls. |
| **Image alt text/captions:** Available — cover/section fields, decorative flag, and reference frontend rendering. | Available — image-metadata automation; advanced options vary by tier. | Available — image-alt analysis; image entry uses WordPress media/editor fields. |
| **XML sitemap:** Available — publication-aware feed plus reference public-origin sitemap index/proxy. | Available — sitemap generation/configuration. | Available — automatic XML sitemaps. |
| **News/video sitemaps and discovery:** Not built — optional Phase 5. | Conditional — specialist sitemap modules. | Conditional — News/Video modules; reviewed Premium offer includes them. |
| **robots.txt management:** Partial — static application file only; no per-customer configuration. | Available — editor/settings. | Available — WordPress SEO/file/crawl controls; verify specific workflow. |
| **llms.txt management:** Not built — optional Phase 5. | Available — documented configuration. | Available — documented generation/content selection. |
| **Crawler/bot preferences:** Not built — needs customer-origin enforcement. | Documented robots-related controls; dedicated blocking parity not established. | Paid — bot blocker; enforcement/cooperation limits still apply. |
| **Headless content API:** Available — published posts, sections, categories, and pagination. | A WordPress installation supplies content APIs; Laravel compatibility is not implied. | WordPress supplies content APIs. |
| **Headless SEO metadata API:** Available — additive SEO JSON in lists and single-post endpoint. | Equivalent SEO API contract not established by this research. | Available — read-only SEO HTML/JSON REST output. |
| **Internal-link suggestions:** Partial — category/exact-term recommendations across 200 eligible candidates; explicit appended section approval and guarded undo, no semantic AI. | Conditional — AI Link Genius and associated entitlements. | Paid — internal-link recommendations. |
| **Link inventory/orphaned content:** Available — bounded saved-article snapshots and live incoming-link orphan reports; navigation/backlinks excluded, partial extraction labelled. | Available in documented link tools; advanced/AI features conditional. | Paid — orphan-content/internal-link maintenance tools. |
| **Stale-content review/task dashboard:** Partial — content-health filters, report freshness, duplicate flags, 90-day reviews; no guided task planner. | Documented content/performance overview; exact stale-task parity not established. | Available — dashboard/task list; stale-cornerstone tools are Premium. |
| **Redirect manager:** Partial — reviewed exact same-origin paths, 301/302/307/308/410, chain/cycle/live-source guards and undo; consumer integration required, no regex or automatic move/deletion rules. | Available — redirection module; advanced options vary. | Paid — redirect manager. |
| **Incoming 404 monitoring:** Available — separate server-secret consumer events, UUID deduplication, daily path counts and scheduled 90-day retention; not an automatic crawl. | Available — dedicated simple/advanced monitor. | Redirect management is documented; an equivalent incoming-request logger was not established. |
| **Outgoing broken-link scanning:** Partial — selected indexed URLs checked through bounded queued HEAD requests, public DNS pinning, retries and restricted/error classification; no recurring crawler. | Conditional — documented broken-link/link-health tools. | A dedicated general outgoing-link scanner was not established by this research. |
| **Bulk SEO import/export:** Partial — bounded metadata and redirect CSV workflows with reviewed previews, atomic apply, audit and conflict-guarded undo; not WordPress settings migration. | Available — bulk editing/import-export; scope varies. | Available — migration/admin workflows; verify exact formats and tier. |
| **Search Console reporting/index inspection:** Partial — read-only OAuth, accessible origin-matching properties, queued finalized daily metrics/CSV and indexed-version inspection; Google setup/consent and live validation pending. | Available — connected Google reporting and index-status tools. | Equivalent native reporting/inspection suite not established; do not equate verification or Wincher with Search Console reporting. |
| **GA4 analytics dashboard:** Not built — optional Phase 4. | Available — connected analytics module; entitlement varies. | Equivalent native GA4 dashboard not established by this research. |
| **Keyword rank tracking/history:** Not built — proposed Phase 4. | Available — tracker with plan limits/history. | Conditional — Wincher integration and its limits. |
| **IndexNow URL notifications:** Not built — proposed Phase 4. | Available — automatic/manual notification workflow. | Equivalent capability not established by this research. |
| **SEO reports/digests:** Partial — private project Search Console snapshots and CSV exports; no scheduled digests or workspace-wide reports. | Available — performance/email reporting; plan differences apply. | Equivalent scheduled client-report package not established by this research. |
| **AI title/description generation:** Not built — optional Phase 5. | Conditional — Content AI subscription/allowance. | Paid — AI metadata tools in eligible products. |
| **AI drafts, planning, and rewrites:** Not built — optional Phase 5. | Conditional — Content AI tools. | Conditional — AI Optimize/Content Planner; product and allowance vary. |
| **AI brand visibility:** Not built — optional Phase 5. | Separate/conditional — AI Visibility. | Separate/conditional — AI Brand Insights/AI+; confirm Premium allowances. |
| **Local business SEO:** Not built — optional Phase 5. | Available — local-business tools; multi-location/advanced features conditional. | Available via Local SEO; included in the reviewed Premium offer. |
| **Commerce/podcast-specific SEO:** Not built — separate future product scope. | Conditional — WooCommerce, EDD, and podcast features. | Separate/conditional — WooCommerce SEO; podcast parity not established. |
| **AI-assistant automation:** Not built — optional Phase 5. | Available — advertised MCP tools; verify access and mutation permissions. | Documented Abilities API for analysis scores; do not assume equivalent MCP write automation. |
| **Google Docs/editor ecosystem:** Not built — no external editor integration. | WordPress/builder integrations; a comparable Docs product was not established. | Conditional — Docs add-on and WordPress editor/builder integrations. |
| **Training and managed support:** No packaged Academy or 24/7 support offering implemented. | Documentation and paid support; service terms vary. | Premium advertises Academy access and 24/7 human chat/email support. |

**Implementation order for ours:** Phases 1–3 are implemented within the limits and deployment requirements described above. Phase 4 is partially implemented: configure/validate Google access next; IndexNow, GA4 and scheduled digests remain future work, followed by AI and specialist modules (Phase 5). An entry marked “not established” is a research limit, not proof that the vendor cannot provide it.
