# WordPress shortcode starter

Upload the ZIP through Plugins → Add New → Upload Plugin and activate. Open
Settings → WritePost and enter the platform HTTPS origin and project API key.
Create a page with a Shortcode block containing `[writepost_posts]`.

The plugin fetches on the WordPress server, escapes text, sanitizes post HTML with
wp_kses_post, handles unavailable responses and paginates published posts. It does
not cache posts. Keep WordPress and hosting caches disabled for this shortcode
page, or configure timely invalidation to avoid retaining unpublished content.

This is an embedded reader: it does not create native WordPress posts, import media,
register WordPress REST endpoints, synchronize deletions, or add individual SEO
metadata/canonical URLs/sitemap entries. Individual posts use `?twpost=slug` on the
shortcode page; WordPress may canonicalize these query URLs to the page itself.
Use the hosted subdomain option for standalone SEO-ready post pages, or implement
native post sync and SEO-plugin integration before relying on this shortcode for
organic search. Theme CSS can affect output. Test on staging before production.

Official references:
https://developer.wordpress.org/reference/functions/wp_remote_get/
https://developer.wordpress.org/reference/functions/add_shortcode/

## Upgrading from the previous starter

Deactivate the previous TryWryter starter plugin before activating this version.
The new shortcode is `[writepost_posts]`; existing `[trywryter_posts]` placements
remain supported. Previously saved platform URL and key options are used as
fallbacks until you save the new WritePost settings.
