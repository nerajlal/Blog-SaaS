<?php

namespace App\Services\Seo;

use App\Models\Blog;
use Illuminate\Support\Str;

final class SeoResolver
{
    public static function text(?string $value): string
    {
        $value = preg_replace('~</?(?:p|div|h[1-6]|li|br|blockquote)[^>]*>~i', ' ', $value ?? '');
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $value) ?? '');
    }

    public function articleUrl(Blog $blog): ?string
    {
        $settings = $blog->project->seo_settings ?? [];
        if (!$blog->slug || !$blog->project->seo_verified_at || empty($settings['public_url'])) {
            return null;
        }
        return rtrim($settings['public_url'], '/').str_replace('{slug}', rawurlencode($blog->slug), $settings['article_path'] ?? '/blog/{slug}');
    }

    public function resolve(Blog $blog): array
    {
        $project = $blog->project;
        $settings = $project->seo_settings ?? [];
        $seo = $blog->seo_settings ?? [];
        $site = self::text($settings['site_name'] ?? $project->name);
        $template = $settings['title_template'] ?? '{title} | {site_name}';
        $title = self::text($seo['title'] ?? '') ?: str_replace(['{title}', '{site_name}'], [self::text($blog->title), $site], $template);
        $body = $blog->sections->map(fn ($s) => $s->text_content)->implode(' ');
        $description = self::text($seo['description'] ?? '') ?: self::text($settings['description'] ?? '')
            ?: Str::limit(self::text($blog->excerpt ?: $body ?: $blog->content ?: $project->description), 160, '…');
        $url = $this->articleUrl($blog);
        $canonical = $url ? ($seo['canonical_url'] ?? null) ?: $url : null;
        $coverImage = $this->imageUrl($blog->cover_image, $settings);
        $image = $seo['og_image_url'] ?? null;
        if (!$image) {
            $image = $coverImage ?: ($settings['social_image_url'] ?? null);
        }
        $index = match ($seo['robots_index'] ?? 'inherit') {
            'index' => true, 'noindex' => false, default => (bool) ($settings['index'] ?? true),
        };
        $follow = match ($seo['robots_follow'] ?? 'inherit') {
            'follow' => true, 'nofollow' => false, default => (bool) ($settings['follow'] ?? true),
        };
        $ogTitle = self::text($seo['og_title'] ?? '') ?: $title;
        $ogDescription = self::text($seo['og_description'] ?? '') ?: $description;
        $resolved = [
            'version' => 1,
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonical,
            'public_url' => $url,
            'locale' => $settings['locale'] ?? 'en',
            'robots' => ['index' => $index, 'follow' => $follow],
            'open_graph' => ['type' => 'article', 'site_name' => $site, 'title' => $ogTitle, 'description' => $ogDescription, 'url' => $canonical, 'image_url' => $image, 'image_alt' => $image === $coverImage && !$blog->cover_image_decorative ? self::text($blog->cover_image_alt) : ''],
            'twitter' => ['card' => ($seo['x_image_url'] ?? $image) ? 'summary_large_image' : 'summary', 'title' => self::text($seo['x_title'] ?? '') ?: $ogTitle, 'description' => self::text($seo['x_description'] ?? '') ?: $ogDescription, 'image_url' => $seo['x_image_url'] ?? $image],
            'schema' => [],
            'warnings' => $url ? [] : ['Verify the project public domain before canonical URLs, structured data, and sitemap URLs can be generated.'],
        ];
        if ($canonical && ($seo['schema_type'] ?? 'BlogPosting') !== 'none') {
            $resolved['schema'] = $this->schema($blog, $resolved, $settings, $seo['schema_type'] ?? 'BlogPosting');
        }
        return $resolved;
    }

    public function imageUrl(?string $path, array $settings): ?string
    {
        if (!$path) {
            return null;
        }
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }
        if (!str_starts_with($path, '/storage/')) {
            return null;
        }
        return rtrim($settings['media_url'] ?? config('app.url'), '/').$path;
    }

    private function schema(Blog $blog, array $seo, array $settings, string $type): array
    {
        $origin = rtrim($settings['public_url'], '/');
        $canonical = $seo['canonical_url'];
        $publisher = ['@type' => $settings['publisher_type'] ?? 'Organization', '@id' => $origin.'/#publisher', 'name' => self::text($settings['publisher_name'] ?? '') ?: $seo['open_graph']['site_name']];
        if (!empty($settings['publisher_logo_url'])) {
            $publisher[($settings['publisher_type'] ?? 'Organization') === 'Person' ? 'image' : 'logo'] = ['@type' => 'ImageObject', 'url' => $settings['publisher_logo_url']];
        }
        $article = ['@type' => $type, '@id' => $canonical.'#article', 'headline' => self::text($blog->title), 'description' => $seo['description'], 'url' => $canonical,
            'mainEntityOfPage' => ['@id' => $canonical.'#webpage'], 'publisher' => ['@id' => $origin.'/#publisher'], 'inLanguage' => $seo['locale']];
        $author = self::text($blog->author_name ?: $blog->user?->name);
        if ($author !== '') {
            $article['author'] = ['@type' => 'Person', 'name' => $author];
        }
        if ($blog->published_at) {
            $article['datePublished'] = $blog->published_at->toIso8601String();
        }
        if ($blog->updated_at) {
            $article['dateModified'] = $blog->updated_at->toIso8601String();
        }
        if ($seo['open_graph']['image_url']) {
            $article['image'] = [$seo['open_graph']['image_url']];
        }
        return ['@context' => 'https://schema.org', '@graph' => [
            $publisher,
            ['@type' => 'WebSite', '@id' => $origin.'/#website', 'url' => $origin.'/', 'name' => $seo['open_graph']['site_name'], 'publisher' => ['@id' => $origin.'/#publisher']],
            ['@type' => 'WebPage', '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $seo['title'], 'isPartOf' => ['@id' => $origin.'/#website'], 'breadcrumb' => ['@id' => $canonical.'#breadcrumb']],
            ['@type' => 'BreadcrumbList', '@id' => $canonical.'#breadcrumb', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $seo['open_graph']['site_name'], 'item' => $origin.'/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => self::text($blog->title), 'item' => $canonical],
            ]],
            $article,
        ]];
    }
}
