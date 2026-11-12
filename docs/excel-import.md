# Excel post import

Open a project → **Import Excel** beside **New post**.

1. Upload the `.xlsx` export, choose the timezone of its dates and author handling.
2. Review the title, slug, category, publication status/date, image count and row warnings.
3. Confirm. Ready rows run as individual background jobs. Existing/invalid rows are skipped.
4. The progress page refreshes and links to successfully imported posts. Failed rows can be corrected and uploaded again; already imported slugs are skipped.

Uploading and previewing never creates posts, downloads images, or uses AI credits.
Only confirmation queues creation. Published imported posts become public through
the API and enabled hosted blog; future publication dates are scheduled. Drafts stay private.

## Expected format

First worksheet, headers in row one. Required: `Title`, `Slug`, and at least one of
`Content (HTML)` / `Content (Plain Text)`. A blank slug is generated from the title.
Optional supported columns: `Category`, `Status`, `Published At`, `Author Name`,
`Author Title`, `Author Avatar`, `Cover Image`, `Image Credits`, `Excerpt`,
`Section Images`, `Created At`, `Updated At`. The export's `ID` is not reused as an
internal database ID. Unknown columns are ignored.

HTML is preferred, sanitized and split at embedded images into the main post body
and ordered image sections, preserving captions and alt text where present. Plain
text is escaped and split into paragraphs when HTML is absent. Section Images
accepts URLs separated by newlines, commas or semicolons; URLs already embedded
in HTML are not appended a second time. Blank excerpts use text from the article.

Some exports truncate HTML cells at Excel's 32,767-character limit, particularly
when base64 images are embedded. The upload screen has an explicit plain-text
recovery option. If enabled, incomplete image markup falls back to the full plain
text with a row warning; broken embedded images are left blank and the original
rich formatting is not retained. Otherwise the affected rows are invalid and skipped. Complete
embedded base64 JPEG/PNG/WebP/GIF images are supported and converted to WebP.

External image URLs must use public HTTPS. Downloads use DNS-pinned requests,
reject non-public addresses, limit redirects and sizes, and decode/re-encode with
Pillow. Images are limited to 2 MB each; existing image pixel limits apply. Images
are saved as optimized WebP with the shared featured/section/avatar size limits.
An image failure fails that row; downloaded files are cleaned up and no partial
post/category is committed.

## Limits and safety

- Maximum 100 posts, 5 MB upload, 20 MB uncompressed workbook, 10,000 cells,
  5 MB parsed payload, and 20 image sections per post.
- No formula evaluation, macros or external workbook relationships. DTD/entities,
  encrypted ZIP entries and unsupported worksheet locations are rejected.
- Duplicate slugs within a workbook are flagged; existing project slugs are skipped.
  Slugs used by another project are errors. Existing posts are never overwritten.
- Authors can use the importing user's current profile, or retain exported author
  fields. Saving a post later refreshes its author from that user's current profile.
- Only users with post-edit access can import. Preview records are private to their
  creator and project. Confirmation requires CSRF and an explicit checkbox.
- Confirmation is idempotent; each row has a recorded result. Expired previews
  cannot be confirmed. Unconfirmed previews expire after 24 hours and are cleaned
  when that user next visits the project's import screen.
- Temporary content is encrypted at rest and cleared after all row results are
  recorded. Filenames, row results and progress metadata remain for review.
- Background jobs recheck access, and image downloads do not hold database locks.
  No automatic retry is made after worker interruption. Re-uploading safely skips
  slugs already imported.

## Deployment

After pushing and pulling the feature:

```bash
cd /var/www/Blog-SaaS
.venv/bin/python manage.py migrate
.venv/bin/python manage.py collectstatic --noinput
chown -R www-data:www-data .local/static
systemctl restart blogsaas-web blogsaas-worker
```

No new Python dependencies are required. Keep the background worker running.
The application and nginx upload limits must allow a 5 MB workbook. Keep `.local`
owned by `www-data`, as in the normal deployment guide.
