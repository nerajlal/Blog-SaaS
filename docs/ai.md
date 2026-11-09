# Claude AI integration proposal

Reviewed: 7 November 2026. Status: discussion and planning only; no API integration or paid requests implemented.

## My recommendation

Pilot Claude as an optional writing assistant inside the blog editor. Start with small, useful actions that writers can review and apply individually. Evaluate real output before committing to Claude as the default provider or selling AI credits.

Our advantage should be project-aware writing: suggestions use the publication's audience, tone, approved facts, and existing content. A generic chat box would add less value than assistance beside the fields people are already editing.

Do not make AI a dependency for creating, editing, saving, or publishing a story. Resolve the reported production login 500 before deploying this feature; its cause is still unconfirmed.

## What the official documentation confirms

Use Anthropic's native Claude Messages API at `https://api.anthropic.com/v1/messages`, authenticated with a server-side API key and the API-version header required by the selected API version. Obtain the key through the Claude Console. See the [official API overview](https://platform.claude.com/docs/en/api/overview).

API usage is billed separately from Claude chat plans; any included API credits depend on the subscription and current terms. Do not assume a chat subscription provides unlimited API access. See [API billing and subscriptions](https://support.claude.com/en/articles/9876003-why-is-claude-api-usage-billed-separately-from-my-paid-claude-plan).

Select an available Claude model after evaluating writing quality, latency, and cost on our own briefs. Keep the model configurable rather than hard-coding a model generation or assuming the most expensive model is necessary. Use the [official model pricing](https://platform.claude.com/docs/en/about-claude/pricing) when estimating costs.

Anthropic states that commercial API inputs and outputs are not used for training by default. This is not a zero-retention or regional-hosting guarantee; confirm applicable terms, retention, processing location, and any opt-in arrangements before sending confidential customer material. See [Anthropic's training-data policy](https://privacy.anthropic.com/en/articles/7996868-is-my-data-used-for-model-training).

The proposed first release will send selected project context directly to Claude without enabling browsing tools. Website analysis requires our bounded, protected fetch-and-extract workflow; source-backed research would be a separate feature. Do not present ordinary generated text as verified research.

These are provider statements, not results of a benchmark on our own content. Pricing and model availability should be rechecked when implementing.

## Suggested features

| Priority | Feature | Expected behavior |
| --- | --- | --- |
| First | Title suggestions | Return a few alternatives from the topic or current story. Apply only the selected title. |
| First | SEO description and excerpt | Produce separate suggestions grounded in the actual article. Validate field lengths locally. |
| First | Outline builder | Use audience, purpose, tone, and supplied facts to propose headings before drafting. |
| First | Rewrite selected text | Offer clearer, shorter, or differently toned wording with a before/after preview. |
| Next | Draft from an approved outline | Generate bounded sections, leaving unknown facts visibly marked for review. |
| Next | Project writing profile | Store audience, language, brand voice, preferred terminology, and approved company facts. |
| Later | Translation | Preserve names and links; require editorial review of meaning and terminology. |
| Later | Internal-link wording | Suggest anchors only for authorized, existing project stories; validate destinations server-side. |
| Later | Research with citations | Separate workflow with retrieval, source display, and human verification. |

Leave auto-publishing, bulk unattended article creation, image generation, and autonomous website changes out of the first release. Do not claim keyword search volumes, ranking guarantees, plagiarism certification, or verified facts from text generation alone.

## Editor experience

Add an “AI assistant” button in the create/edit story screen. Open a compact panel with explicit actions rather than adding another dashboard section.

1. Writer selects an action and the text or fields to send.
2. Show the relevant context and explain that it will be sent to Claude.
3. Generate into a separate suggestion area with loading, failure, and retry states.
4. Offer Apply, Copy, and Discard. Replacing existing content requires an explicit choice.
5. Applying changes updates the editor; existing autosave may save them privately. Publishing still requires the normal user action.

A response arriving after the writer has changed the target field must not overwrite those edits. Keep the suggestion available and ask the writer to review it against the current text. New-post forms should work too, without requiring an existing blog ID; do not imply that new-post autosave already exists.

## Fit with this Laravel project

Use Laravel's HTTP client behind a small `AiProvider` interface and an Anthropic Claude adapter. No additional Node runtime or Python service is needed for API calls. Use the native Messages request/response format rather than assuming OpenAI Chat Completions compatibility. Keep the system instructions, message content blocks, text extraction, stop reasons, output limits, usage parsing, and optional thinking settings inside the adapter. Select and pin a supported Anthropic API version during implementation.

Proposed configuration: `AI_ENABLED`, `AI_PROVIDER`, `ANTHROPIC_API_KEY`, and `CLAUDE_MODEL`, loaded server-side through Laravel configuration. Keep the production endpoint fixed or administrator-controlled. Never expose the key in Blade, browser JavaScript, public APIs, or logs.

Authorize every request against the project. Use the existing project update permission for generating/applying writing suggestions; only owners/admins may enable paid workspace usage. Enforce the same workspace boundaries for stored suggestions, polling, and cancellations.

For longer requests, use database-backed jobs and a status endpoint. On the 512 MB test droplet, start with one worker and bounded payloads, context, output, and job timeouts. Hosted inference avoids loading model weights onto the droplet, but PHP, MySQL, Nginx, and workers still share its small memory budget. Measure memory before enabling concurrent generation.

Reuse the existing rich-text sanitizer. Treat model output as untrusted data: validate JSON structure, permitted fields, lengths, and links before applying it. Never execute generated code or grant the model database/publishing tools. Treat article content as source material, not privileged instructions.

## Costs and reliability

Start with a platform-owned API key for an internal pilot and strict per-workspace allowances. Customer-owned encrypted keys could come later if users need independent billing; that adds key rotation, validation, and support work.

Record provider/model, action, user/workspace/project IDs, token usage, timestamps, status, and provider request ID. Avoid storing full prompts or unpublished content in general application logs. If saved suggestions need history, define access, encryption, retention, and deletion explicitly.

Reserve allowance atomically before dispatch so simultaneous requests cannot bypass limits. Reconcile against actual usage, and prevent repeated clicks from dispatching duplicate work. Local cancellation may not stop provider billing after submission. Do not automatically retry ambiguous timeouts as though they were free; cap retry/backoff behavior and handle rate limits visibly.

Calculate cost from the selected model's current input, output, reasoning, cache, and tool charges where applicable. No fixed “cost per blog” estimate is justified yet. Track cost per accepted suggestion and per accepted draft, including discarded generations and retries. Decide plan pricing only after measuring actual pilot usage.

## Rollout and acceptance criteria

1. Confirm account access, available model, billing limits, and applicable data terms. Store credentials directly in server configuration.
2. Evaluate a small set of representative project briefs and existing posts in the provider playground. Compare factual faithfulness, voice, editing time, latency, and cost; do not choose from benchmarks alone.
3. Build title/description suggestions and selected-text rewriting behind a feature flag, initially for internal users.
4. Test authorization, cross-workspace access, output sanitization, stale-editor protection, quota concurrency, malformed responses, rate limits, timeouts, and provider outages using HTTP fakes.
5. Pilot on real writing tasks, then expand to outlines and section drafts if quality and costs meet agreed targets.

Success means writers accept useful suggestions with less editing effort, costs stay within workspace limits, and existing writing/publishing works during an API outage.

## Decisions for our discussion

- Is the first goal improving existing writing or generating a first draft from a brief? My preference: editing assistance first, then outline-to-draft.
- Which languages and kinds of publications should the pilot cover?
- Should we fund an internal pilot before offering AI to all workspace members? My preference: yes, with a small explicit spending cap.
- What content may be sent to an external provider, and how long should we retain saved suggestions?
- Which measurements would justify enabling the feature for paying customers?

No API key is needed in chat. Claude through the Anthropic API is the chosen provider. This file proposes the design; model choice and implementation details remain open for discussion.


## Companion social content for each blog

Extend the project-aware Claude workflow so a blog can produce a coordinated social content pack for LinkedIn, Reddit, Facebook, and Instagram. This proposal interprets “corresponding” content as posts derived from the same blog, not competitor monitoring or scraping social accounts.

Generate from the current blog text plus the approved project profile. Each platform gets its own format and voice rather than identical copy pasted everywhere. Preview results in a Social content panel on the blog editor or a dedicated per-blog screen reached from the project's Blogs list.

| Platform | Proposed output |
| --- | --- |
| LinkedIn | Professional insight post with an opening hook, key takeaways, and a relevant call to action. |
| Reddit | Discussion-oriented title and body, plus an optional transparent affiliation disclosure; use a user-selected community's supplied rules. |
| Facebook | Conversational post with a short summary, reader question, and optional blog link. |
| Instagram | Caption with hook, readable paragraphs, optional hashtags, and a carousel slide-text outline; use the project's chosen link destination. |

Keep every output editable and independently selectable. Start with Generate, Regenerate selected platform, Copy, and Export. No platform account access, auto-posting, scheduling, image generation, or automatic subreddit selection is included in the first release.

Generate only from approved brand facts and the selected article version. Do not invent product claims, testimonials, engagement figures, or first-person customer experiences. Treat hashtags as suggestions, not verified trending terms. Reddit drafts must not imply an unaffiliated personal recommendation when the author represents the business.

If the article is unpublished, use a clearly marked link placeholder unless a valid public URL is already known. Publication readiness should be checked before export with a live link. Record which article/profile version produced each pack and mark it stale when either changes. Never silently replace a user's edited social drafts.

Allow separate platform choices and output-length preferences. Keep practical platform limits configurable and verify current official platform documentation when implementing; do not assume API posting permissions or permanent character limits. Budget social generation separately from article generation, retain accepted outputs, and avoid regenerating all platforms for a one-platform edit.

This remains a Claude text-generation feature. Direct social publishing, if requested later, would need a separate plan for provider permissions, account connections, approval, delivery status, and platform-specific rules. The corresponding detailed workflow is in [ai_imple.md](ai_imple.md).

## Implementation status on `ai-based`

The initial profile, approval, bounded website analysis, outline/draft, saved-story writing suggestions, and per-platform social text workflows are now implemented behind disabled-by-default Claude configuration. See [operational documentation](docs/ai-tools.md) for setup and current limits. No live API quality/cost benchmark has been performed. Later research, automatic social publishing, scheduling, translation, internal-link automation, and image generation remain future work. No automatic age-based retention deletion is configured.
