# React / Next.js starter

A server-rendered React frontend for the published-post API. Requires Node 20.9+
(or the minimum version required by the installed Next.js release).

1. Copy `.env.example` to `.env.local`, set the platform HTTPS URL and project key.
2. Run `npm install`, then `npm run dev`. Open `/posts`.
3. For deployment, run `npm run build` and `npm start` with a supported Node version.

The key is read on the server. Do not prefix it with NEXT_PUBLIC_. No CORS setup is
needed for these server-to-server requests. Set the project's public origin and
article path `/posts/{slug}` in Analytics & Search → Sitemap and verify ownership
for canonical URLs, structured data and the API sitemap. Your frontend must also
serve a sitemap on its own domain; the API XML endpoint requires the project key.

Requests use no-store so unpublished/deleted posts are not kept in a content cache.
Add your own theme, error UI, stylesheet for rich-text sizes, and deployment checks.
This is starter source, not an installed plugin or an automatic deploy integration.
Official data fetching guidance: https://react.dev/reference/react/useEffect

The preferred environment variables are `WRITEPOST_URL` and `WRITEPOST_API_KEY`.
Legacy `TRYWRYTER_URL` and `TRYWRYTER_API_KEY` are accepted as fallbacks.
