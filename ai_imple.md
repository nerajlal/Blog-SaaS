# Project-aware AI blogging: implementation plan

Date: 7 November 2026
Provider: Claude through the Anthropic API.
Status: proposal only. No application code, migrations, API calls, or deployment included.
Related document: [Claude evaluation and integration opinions](ai.md).

## Product goal

Each project should have a reusable business profile. A user supplies their website, products or services, features, audience, and writing preferences. The AI helps organize this information into a reviewed project knowledge base. Future blog drafts use that approved knowledge together with the writer's topic and instructions.

For example, a perfume store could describe its fragrance collections, scent notes, sizes, positioning, shipping policy, and target customers. Its blogs should then reflect that particular store rather than inventing generic perfume products or unsupported claims.

“Keep in mind” means our application stores approved information and sends the relevant parts with each AI request. We should not depend on the model remembering previous requests or train a separate model for each customer.

## Project cards and navigation

On `/admin/projects`, retain Blogs and Integration and add a quieter **Project details** action near the project title or in a small card menu. Avoid three equally prominent buttons crowding the footer.

- **Blogs:** stories, categories, and writing actions.
- **Integration:** API key, website setup, and technical SEO integration.
- **Project details:** business information, website sources, writing preferences, and AI knowledge review.

Suggested page: `/admin/projects/{project}/details`. Show profile state on the card: Not configured, Needs review, or Ready for AI. A saved business profile should remain useful even when AI is disabled.

## What the user enters

Keep the first form short, with expandable sections. Users should be able to save incomplete details and finish later.

| Section | Fields | Perfume-store example |
| --- | --- | --- |
| Business basics | Display name, website URL, business type, short description | An online perfume retailer |
| Products and services | Product/collection names, links, descriptions, distinguishing features | Citrus collection; 30 ml and 50 ml bottles |
| Audience | Customer type, region, language, experience level | Gift buyers and first-time fragrance shoppers |
| Positioning | Brand story, differentiators, values | Accessible discovery sets; only if confirmed |
| Practical facts | Shipping regions, returns, care guidance, support links | Link to the current delivery policy |
| Writing preferences | Tone, language, spelling, length preference, terms to use or avoid | Warm, descriptive English; avoid exaggerated promises |
| Content goals | Education, discovery, product explanation, conversions | Help readers choose scent families |
| Boundaries | Prohibited claims, topics, competitors, sensitive details | No invented longevity, ingredient, or allergy claims |
| Blog destinations | Public blog URL/pattern and approved call-to-action links | Store collection page and contact page |

Only business name, a basic description, and audience need be required initially. Website importing is optional; users can provide all information manually. Keep these details separate from the existing SEO domain verification settings. Entering a website URL is not proof of domain ownership.

## Website analysis workflow

1. User enters the website URL and chooses Analyze website.
2. Explain which public pages will be read and that selected content will be sent to Claude through the Anthropic API.
3. Fetch a limited set of selected public pages: home, about, relevant products/collections, and policy pages. Show the selected URLs before analysis.
4. Extract useful text and metadata; remove navigation repetition, scripts, and irrelevant markup.
5. Ask AI to propose a structured business summary, product facts, audience, tone, and gaps. Each factual proposal must reference a fetched source or a user-supplied statement.
6. Show a review screen separating confirmed inputs, website-derived suggestions, inferred suggestions, and unknown information.
7. User edits, accepts, or rejects suggestions, then explicitly approves a profile version for blog generation.

Website content must not silently replace manual details. Flag conflicting information for review. If a site is inaccessible, blocked, or requires JavaScript rendering, allow manual entry or pasted text rather than pretending the analysis succeeded.

Do not crawl an entire site in the first release. Begin with a small explicit page count and content-size/time limits, respect crawl restrictions, and avoid login areas, checkout pages, and customer information.

A website fetcher requires server-side URL protections: public HTTP(S) destinations only; block private, local, reserved, and cloud metadata addresses; check DNS and every redirect; restrict ports, response sizes, content types, and timeouts. Treat website instructions as untrusted source text. The model must not receive permission to execute page instructions or fetch arbitrary URLs itself.

## Claude integration decisions

Use Anthropic's native Messages API through a server-side Laravel HTTP adapter. Proposed configuration: `AI_ENABLED`, `AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY`, and `CLAUDE_MODEL`. Choose a supported API version and model during implementation; keep credentials out of the browser and source control. See the [official API overview](https://platform.claude.com/docs/en/api/overview).

Our application stores and versions project knowledge, then supplies the relevant approved facts with each Claude request. Claude chat Projects, conversation history, and subscription features are not the storage mechanism for this SaaS. Do not assume that calling the API automatically reads a website or remembers a customer's business.

Handle native response content blocks, stop reasons, token usage, and errors explicitly. Validate structured analysis against our own schema before accepting it. Truncated, refused, or malformed output must not become an approved profile or silently saved complete draft.

Estimate analysis and drafting costs using the selected model's current input/output, caching, thinking, and tool pricing where applicable. API billing is separate from chat access; account credits and terms need confirmation. See [official pricing](https://platform.claude.com/docs/en/about-claude/pricing). No paid request is authorized or implemented by this plan.

## Stored knowledge and approval

Proposed logical records; exact schema is a later implementation decision:

- **Project profile:** editable business details and writing preferences, scoped to one project.
- **Sources:** manually supplied statements or selected website pages, URLs, retrieval time, extraction status, and bounded source text.
- **Facts:** individual statements with source references and review status. Keep uncertain AI inferences visibly separate from approved facts.
- **Profile versions:** immutable approved snapshots with approver and approval time.
- **Analysis runs:** pending/running/succeeded/failed state, source references, provider/model, and usage metadata.
- **Generation runs:** requested topic, approved profile version, selected context, output state, and usage metadata.

For an MVP, structured JSON plus source records may be sufficient. A vector database and embeddings are not prerequisites. Start with predictable selection by product, collection, topic, and user choice. Add retrieval infrastructure only when profile size and measured quality justify it.

Keep draft profile changes separate from the last approved version. Updating the website creates a new reviewable proposal; it must not automatically alter the approved profile or rewrite existing posts.

## Blog creation experience

On the project's Blogs page, add **Create with AI** alongside the existing manual writing action.

1. Select a topic or ask for topic suggestions based on the approved profile.
2. Choose the relevant products/collections, reader intent, language, tone, and approximate length.
3. Display the profile version and source facts that will guide generation. Let the writer add a brief or exclude facts.
4. Generate an outline for approval.
5. Generate the draft from the approved outline and relevant profile context.
6. Show title, excerpt, body/sections, and SEO description in a preview. Highlight factual gaps and unsupported claims for review.
7. Explicitly save as a draft and open the normal editor. Publishing remains a separate user action.

Do not create a published post directly from a generation response. A late response must not overwrite text the writer has already edited. Existing-post rewrites require a before/after preview and explicit application. New-post recovery and existing-post autosave are distinct workflows; implement recovery for generation runs without assuming the current create form already autosaves.

The draft should use only approved product facts. Missing attributes should be omitted or flagged, not guessed. Suggested links must be selected from approved destinations and validated by the application. AI cannot guarantee factual accuracy, originality, search rankings, or suitability of product claims.

## Perfume-store walkthrough

Assume the user manually confirms these illustrative facts:

- Store: Example Fragrances.
- Collection: Citrus Discovery Set.
- Product sizes: three 5 ml samples.
- Audience: customers exploring fresh fragrances.
- Tone: friendly and practical.
- Destination: the store's approved discovery-set page.

Topic: “How to choose a fresh fragrance for everyday use.”

The assistant could propose an educational outline explaining fragrance families and sampling, then mention the approved discovery set naturally. It must not invent an eight-hour longevity guarantee, organic certification, skin-safety assurance, stock status, or shipping promise. Those require explicit reliable information and, where necessary, editorial verification.

Prices, availability, promotions, and delivery promises should be treated as time-sensitive. Do not embed them from an old profile without reconfirmation.

## Permissions, privacy, and costs

Workspace owners/admins manage profile approval, website analysis, and paid AI enablement. Editors can use approved profiles to generate drafts and propose corrections; viewers remain read-only. Legacy project access stays limited to its owner. Recheck authorization when jobs execute and when results are viewed or applied.

Never share profile context between projects or workspaces, even when the same user owns both. Deleting a project should remove its profiles, source snapshots, and generation records according to the defined retention policy. Pending jobs must handle deleted projects gracefully. Provider-side requests already submitted may continue and incur charges.

Keep the API key on the server. Make external processing explicit before sending unpublished information. Avoid personal customer data, credentials, and full-content application logs. Define retention and any provider-side file deletion before offering uploads.

Apply workspace budgets, bounded context/output, duplicate-request protection, and usage tracking to both website analysis and blog generation. Reserve allowance atomically before dispatch. Account for retries and reasoning/tool charges where applicable. Show useful failure messages without losing manual input or blocking ordinary writing.

Use hosted inference and a bounded queue worker for the small test droplet. Start with a single worker, measure memory and request duration, and keep retries limited. Do not run a model locally on the 512 MB server.

## Delivery phases

| Phase | Scope | Completion condition |
| --- | --- | --- |
| 1 | Project details form and manual profile approval | Users can save/review business information; no AI dependency |
| 2 | Bounded website import and AI profile proposal | Source-backed suggestions can be reviewed without overwriting manual data |
| 3 | Topic, outline, and draft generation | Approved project context produces a reviewable draft; nothing auto-publishes |
| 4 | Profile updates, usage visibility, quality improvements | Refreshes require approval; costs and accepted-output quality are measurable |

Before releasing AI, diagnose the reported production login 500 and confirm the deployment/asset build process works reliably.

## Acceptance checks for later implementation

- Two different businesses receive distinct, appropriate context with no cross-project leakage.
- An editor cannot approve profile facts or enable spending; a viewer cannot generate content.
- URL importing blocks internal network destinations, unsafe redirects, oversized pages, and unsupported content.
- Website text cannot override application instructions or cause unauthorized tool execution.
- Failed analysis retains manual data; failed generation preserves the brief and existing draft.
- Unknown facts remain unknown; conflicting and time-sensitive facts are surfaced for review.
- New profile versions do not silently change old drafts or published posts.
- Duplicate clicks and concurrent requests cannot bypass quotas.
- Cancel, timeout, rate-limit, and deleted-project cases have defined behavior.
- Every draft identifies the approved profile version used and requires a normal publication action.

## Recommended first agreement

Build a reusable Project details profile first, then a reviewed website analysis flow, then project-aware blog generation. Keep website setup under Integration and ordinary writing under Blogs. This makes the business knowledge the foundation for future AI features rather than asking users to repeat their business description for every article.

Open decisions: default profile fields; who may approve facts; the initial website page limit; generation allowance and pilot budget; source/suggestion retention; initial languages; and whether a first release should support pasted website text before automatic crawling.


## Companion social content: LinkedIn, Reddit, Facebook, and Instagram

### Scope and entry points

For each blog in `/admin/projects/{project}`, offer a **Social content** action alongside its writing actions. The blog editor should also link to this screen. This is per-blog content: opening project 6 must use the selected blog and project 6's approved business information, never another publication's profile.

Interpret the requested feature as generating matching social posts for the blog. Competitor analysis, finding a competitor's accounts, and collecting Reddit discussions are separate potential requirements and are not included here.

The first release prepares text for users to review and share manually. It does not connect accounts or post externally.

### User flow

1. Writer saves the current article as a draft or selects a persisted revision. Explain that generation uses that saved version, so unsaved editor changes are not silently omitted.
2. Writer opens Social content and selects LinkedIn, Reddit, Facebook, Instagram, or any subset.
3. Confirm the article, project profile, language, goal, author/business relationship, intended destination, and tone. Platform preferences may override general tone without changing brand facts.
4. Show a compact summary of the information being sent to Claude. If the project profile is not ready, offer to complete it rather than invent brand details.
5. Generate suggestions into separate platform tabs/cards. Display progress and errors independently so one failed result does not discard successful results.
6. Writer edits, saves, regenerates a selected platform, copies, or exports its text. Replacing an edited output requires explicit confirmation.
7. Before marking the pack ready, review factual claims, disclosure, destination links, and platform-specific guidance. Show when the pack was last reviewed.

Persisted article text is preferable to a second hidden version of the blog. A create form should first save a draft through the normal creation flow, then offer social generation. Generating social content must not publish or change the blog's status.

### Outputs and additional inputs

| Platform | Output fields | Optional user inputs |
| --- | --- | --- |
| LinkedIn | Hook, post body, takeaway/CTA, destination URL, optional hashtags | Personal author or company page voice; target professional audience |
| Reddit | Proposed title, discussion body, affiliation disclosure, optional link placement | Intended subreddit, pasted community rules, post type, whether promotion/links are allowed |
| Facebook | Opening line, body, reader question or CTA, optional link | Business-page or personal-author voice; concise or explanatory style |
| Instagram | Caption, CTA, optional hashtags, slide-by-slide carousel text | Caption-only or carousel outline; slide count; approved bio/link destination |

Use plain editable fields rather than hiding the entire pack in one large generated response. Carousel output means proposed slide copy only, not rendered images or an uploaded carousel. Do not imply Instagram captions provide a clickable blog-link experience; let the user select appropriate destination wording and verify current platform behavior during implementation.

The generated pack should preserve the article's central meaning while adapting the angle and formatting. Avoid repetitive canned hooks and excessive hashtags. Do not claim best posting times, trending hashtags, reach predictions, or community acceptance without actual supporting data.

### Example: perfume blog

Article: “How to choose a fresh fragrance for everyday use,” based on the approved perfume-store profile.

- LinkedIn: a useful brand or retail perspective on helping customers explore fragrance preferences.
- Reddit: an honest discussion prompt about choosing fresh scent families, using the selected community's rules and stating business affiliation where relevant. Avoid disguised testimonials or unsolicited promotional links.
- Facebook: a friendly short summary with a question about the reader's preferred scent family and an optional article link.
- Instagram: concise caption plus a five-slide educational outline covering scent families, sampling, preferences, everyday use, and a relevant next step.

All versions use the same approved product facts. None may add an unverified longevity guarantee, ingredient, certification, stock claim, or invented customer experience.

### URLs and profile settings

Extend Project details with optional official social profile URLs and platform writing preferences. These help maintain correct names, approved destinations, and tone; saving a URL does not authorize access to an account.

Store the public article URL separately from the admin editor URL. Never export an admin route or preview token as the blog link. If a draft has no confirmed public destination, show `[BLOG_URL]` as a visible placeholder and label the content as not ready to share. When it becomes available, propose replacing the placeholder and require review before overwriting manual text.

If campaign tracking is enabled later, construct query parameters in application code from approved settings. Do not ask the model to invent URLs, account handles, or tracking values.

### Storage, freshness, and safety

Add logical social-pack and platform-variant records scoped to project, blog, and user/workspace permissions. Record article revision/hash, approved profile version, platform preferences, generated suggestion, edited output, model, usage, timestamps, and review state. Separate generated suggestions from user edits.

Suggested states: not generated, generating, draft, reviewed, stale, and failed. “Reviewed” means approved for manual sharing, not posted. Do not show a Published status without a real later delivery integration.

When the source blog or approved business facts change, mark existing packs stale and show the reason. Preserve their contents until the user reviews or explicitly regenerates them. Check source versions both when queuing and when completing a job; late responses must not replace newer edits. Authorize access on every action, including exporting and polling, and remove related records when their blog/project is deleted.

Platform constraints must be validated locally against configurable limits checked during implementation. Treat community rules as untrusted input, not authority to reveal data or bypass application restrictions. If Reddit rules are not supplied, label the draft as requiring community review and do not imply it is compliant.

### Delivery sequence and checks

1. Extend the project profile with optional social destinations and preferences.
2. Add per-blog social generation and editable saved outputs using the chosen Claude adapter.
3. Add selected-platform regeneration, copy/export, source-version freshness indicators, and usage visibility.
4. Consider account connections, media creation, scheduling, and publishing only under a separate future scope.

Acceptance checks: generation stays within the selected project; outputs preserve supported facts; per-platform failures remain recoverable; unsaved edits are protected; stale results are flagged; placeholders cannot be mistaken for live URLs; affiliation is represented honestly; exports contain no private admin links; and quota/concurrency controls cover every platform request.

No social account credentials are required for this initial scope. Application implementation remains pending.

## Implementation status on `ai-based`

The project-details/profile approval flow, selected-page or pasted-source analysis, outline-to-private-draft flow, saved-blog writing tools, and per-platform social generation/review/export have been implemented. Each social platform is generated independently. The initial record design uses encrypted profile/version/run records; bounded source snapshots live inside analysis runs rather than a separate search/vector index. Quotas count requests rather than guaranteeing a monetary budget.

Configuration and one dedicated queue worker are required before live use. No paid provider requests or external social posting were performed during development. See [docs/ai-tools.md](docs/ai-tools.md) for implementation details, validation, and remaining limitations. Retention remains explicit: no daily automatic deletion of old suggestions has been configured. Future phases such as connected social publishing, research retrieval, and media generation are still deferred.
