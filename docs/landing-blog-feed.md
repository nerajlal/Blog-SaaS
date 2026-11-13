# Landing page blog feed

The public `/blog` page lists published posts from the WritePost blog project, and the landing page can show its three most recent posts. “Blog” links to `/blog` from the desktop and mobile headers. The API key is read only by Django from the environment; it is never rendered into browser HTML or JavaScript.

Set `WRITEPOST_BLOG_API_KEY` in `.env.django` to the blog project's API key. The project must exist in the same WritePost database, have published posts, and have a public article URL configured (an enabled hosted blog or a verified public URL). The landing feed stays hidden until it can link to published articles.

Keep the key private. If it has been pasted into a shared conversation or otherwise exposed, rotate the project API key in WritePost and configure the replacement value on the server. Restart the web service after changing the environment. No migration is needed.
