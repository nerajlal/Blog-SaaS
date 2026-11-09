# Sidebar menus and their uses

This describes the current Django application on the `main` branch. URLs below use `{project_id}` for the selected project's ID, for example `1`.

## Main menus

| Sidebar menu | What it is used for | URL | Access |
| --- | --- | --- | --- |
| **Projects** | View projects in the active workspace, see story totals, create a project, open its blogs or integration instructions, and delete projects when permitted. | `/admin/projects` | Workspace members can view. Owners, admins, and editors can create; owners/admins can delete. |
| **Blogs** | View the selected project's stories and categories. Create/edit stories, save drafts, publish or schedule, move stories, and open their social versions using the platform logos. | `/admin/projects/{project_id}` | Members can view. Owners, admins, and editors can write and manage stories/categories. |
| **Content health** | Review local SEO/readability checks, word count, missing metadata, and image alternatives. Analyze all project stories, open individual reports, mark content reviewed, or prepare AI SEO suggestions for review. | `/admin/projects/{project_id}/seo/health` | Members can view. Owners, admins, and editors can analyze and record reviews. |
| **Links & URLs** | View detected links, identify published stories without detected incoming links, rebuild the link graph, and queue public link checks. Individual story screens offer related-reading suggestions with explicit insertion and guarded undo. | `/admin/projects/{project_id}/links` | Members can view. Owners, admins, and editors can run link actions. |
| **Analytics & Search** | Three tabs: Search Console reports/inspection, Google Analytics 4 top pages and traffic sources, and website URL setup, verification, and sitemap XML generation. | `/admin/projects/{project_id}/search` | Owners/admins. |
| **API & Docs** | Open the admin developer page explaining the content API and integration approach. The project's actual API key and project-specific setup instructions are available through its Integration button. | `/admin/docs` | Signed-in users. |

## Workspace and account controls

| Control | Use |
| --- | --- |
| **BlogSaaS logo** | Return to the Projects overview. |
| **Workspace names** | Switch the active workspace. The chosen workspace is visually marked. Only workspaces the signed-in user belongs to are shown. |
| **+ New workspace** | Create another workspace for a business, client, or team. The creator becomes its owner. |
| **Project name above Blogs** | Identifies the project that the menus below apply to. It is a heading, not a separate menu link. |
| **Account name/email** | Shows the currently signed-in account. |
| **Sign out** | Ends the current login session. |

## Where the other features are

**AI studio** and **Integration** were removed from the sidebar as requested; the features remain accessible:

- **AI studio:** use **Create with AI** on the Blogs page, the editor's AI action, or **Improve SEO with AI** on Content health.
- **Social versions:** use the LinkedIn, Reddit, Facebook, or Instagram logo beneath a story. Saved versions reopen without another AI request; a missing version is adapted from the saved blog once.
- **Integration:** use the project's **Integration** button to see its read-only API key and website setup instructions.

## Visibility and behavior

- Projects and API & Docs are general navigation. Project-specific menus appear after opening a project-related page.
- Analytics & Search appears only for users with project-management permission.
- The selected menu stays highlighted. Related screens keep their parent selected: story editing and AI/social screens select Blogs; an individual SEO report selects Content health; story link suggestions select Links & URLs.
- Legacy projects without a workspace are accessible only to their direct owner.
- Content-health scores are local editorial guidance, not Google ranking scores or factual-quality guarantees. Missing-image checks are not applicable when no image is attached.
- Claude requires server configuration, an approved project profile, enabled workspace usage, and a running worker. Search Console requires separate Google configuration and domain verification.
- AI generation, whole-project analysis, link tasks, and Search Console jobs need the background worker. For local development, `python manage.py runlocal` starts the web server and worker together. See [run.md](run.md).

Project details opens through **Tell AI about your project** on the project page. It is no longer listed in the sidebar.

Integration contains API credentials and setup guidance. The separate website URL/path settings page has been retired; existing links redirect to Integration. Per-article SEO stays in the story editor.

Analytics & Search keeps the website URL and article path only for sitemap and domain-based tools. Basic content API integration does not require this setup.
