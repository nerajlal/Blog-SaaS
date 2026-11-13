# Landing page blog feed

The public `/blog` page lists published posts from the WritePost blog project. Each post opens its own public detail page at `/blog/<slug>`, with article metadata, images, author information, and sanitized post content. The landing page can show its three most recent posts and links to those same detail pages. “Blog” links to `/blog` from the desktop and mobile headers. The API key is read only by Django from the environment; it is never rendered into browser HTML or JavaScript.

Set `WRITEPOST_BLOG_API_KEY` in `.env.django` to the blog project's API key. The project must exist in the same WritePost database and have published posts. The public blog detail pages are served by the WritePost platform, so they do not depend on a client website URL or hosted-blog setup.

Keep the key private. If it has been pasted into a shared conversation or otherwise exposed, rotate the project API key in WritePost and configure the replacement value on the server. Restart the web service after changing the environment. No migration is needed.
