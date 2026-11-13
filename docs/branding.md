# WritePost branding

The app uses the supplied WritePost brand kit:

- Name and wordmark: **WritePost**.
- Royal Blue: **#2B4EFF**.
- Near Black: **#101820**.
- White: **#FFFFFF**.
- Typeface: **Inter** with system sans-serif fallbacks.
- Logo: the supplied pen/paper-plane emblem with the horizontal wordmark.

UI SVGs in `portal/static/portal/brand/` come from `WritePost_Brand_Kit`.
Only their outer viewboxes have been tightened for UI placement; the supplied
artwork, wordmark, colours and internal spacing are preserved. The icon-only
artwork supplies the favicon. The white logo is used on the blue publishing hub.

The name is updated throughout the public landing page, login/registration,
client sidebar, superadmin, AI labels, password-reset emails, integration guides,
starter downloads and project documentation. Primary UI accents use Royal Blue;
success, warning and destructive colours retain their semantic meaning.

## Compatibility and deployment

This is a product rebrand, not a repository or infrastructure rename. Existing
`blogsaas` modules, database tables, environment settings, service names, session
cookies, repository and `blogsaas.task19.com` remain unchanged. Existing customers'
projects, API keys, content, URLs and credentials are not rewritten.

New downloads use `writepost-react.zip` and `writepost-wordpress.zip`. The
WordPress starter supports the old shortcode/options while presenting WritePost.
The React starter prefers `WRITEPOST_URL` / `WRITEPOST_API_KEY`, with legacy
variable fallbacks. New custom-domain setups use `_writepost-verification` and
`writepost=`; existing verification records and challenge paths continue working.

The existing support mailbox remains `trywryter@gmail.com` until a replacement is
provided. Product pages label this **Contact support** rather than inventing an
unconfigured email address or domain.

After committing/pushing and pulling on the server, run collectstatic and restart
`blogsaas-web` and `blogsaas-worker` as in the deployment guide. No database
migration is required for this rebrand. Keep deployed nginx domain configurations
and certificates unchanged.
