# Private editor autosave

Existing posts now have an autosave panel in the edit form. After 1.5 seconds without typing, text fields, SEO overrides, publishing selections and section text/image descriptions are saved as a private recovery snapshot. A ten-second checkpoint also handles continuous typing. Draft saving never changes the saved post, its publication status, timestamps, public API output or editorial reports. **Update Post** remains the explicit action that applies changes, including edits to published posts.

Reloading shows an explicit **Restore draft** / **Discard recovery draft** choice. Drafts are private to the authenticated editor, project and post. Restoring replaces form text and rebuilds saved sections; review it before updating. A warning identifies snapshots whose saved post has changed. A separate **Download text backup** button exports the current form text as JSON; it is an emergency text backup, not an image backup or an import format.

New file uploads are deliberately not autosaved. Select images and click Update Post while the page remains open. Restore rebuilds section controls, so any newly selected section image files must be selected again. Existing saved section image references can be recovered, but only references already belonging to that post are accepted. The browser warns before leaving when text has not been acknowledged by the server or new files remain selected.

Saving, saved and failed states are explicit. Network errors or session expiry do not display a false Saved message. A timeout can leave the client unsure whether the server committed; retry may show a conflict rather than overwriting a newer snapshot. Keep that tab open, download a text backup, and review the saved post/recovery draft in another tab. A server outage cannot guarantee draft recovery unless a successful save was acknowledged.

One encrypted snapshot is stored per editor/project/post in `blog_autosaves`. Laravel's application key must remain stable to decrypt it. Rich section HTML is sanitized before persistence; arbitrary fields and foreign image references are rejected. Authenticated editor permissions and CSRF protection apply; the route is absent from the public API. The payload is capped at 2 MB of text, with at most 100 sections and 40 save requests/minute per IP. Other editors cannot read or discard your private snapshot. Moves isolate old-project snapshots from the destination project.

Each save uses optimistic version checks, preventing older tabs from overwriting newer snapshots. The saved post revision is also checked, including sections. Manual updates from the current editor include that revision guard and use asynchronous submission: validation/conflict responses keep form text and selected files in the current page. A successful manual update deletes only the submitting editor's known draft version. Discard leaves a version tombstone so an old tab cannot silently recreate an outdated draft. Snapshots remain until saved/discarded or the owning user/post/project is deleted; no automatic time-based purge is currently supplied.

This release covers **existing-post edit pages**. The new-post creation form still requires its initial manual save. Autosave does not create posts, upload images in the background, publish automatically, or synchronize competing editors' text.

## Deployment

Apply the migration and rebuild assets after deploying the source changes:

```sh
php artisan migrate --force
npm ci --no-audit --no-fund
npm run build
php artisan optimize
```

On the 512 MB droplet, use the previously configured swap or build assets on another machine. Preserve `.env`, `APP_KEY`, media and database backups. The application key on your local machine is independent from the droplet's key; do not copy the local `.env` to the droplet. Queue workers are not needed for autosave.

Tests use isolated databases and browser fixtures. They cover published-content isolation, encrypted snapshots, project/role/user separation, sanitation, concurrent tabs, stale source revisions, moves, draft deletion, dynamic section recovery, explicit restoration and manual-save error recovery. No live website data is changed by these tests.
