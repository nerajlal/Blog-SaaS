<?php

namespace App\Services\Links;

use App\Models\Blog;
use App\Models\SeoLink;
use App\Models\SeoLinkRun;
use App\Models\Project;
use App\Services\Seo\ContentAnalyzer;
use App\Services\Seo\SeoResolver;
use App\Services\Seo\AnalysisText;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LinkGraph
{
    public function __construct(private UrlTools $urls, private SeoResolver $seo, private ContentAnalyzer $analysis) {}

    public function context(Project $project): string
    {
        return hash('sha256', 'links.1|'.$this->analysis->contextHash($project));
    }

    public function ready(Project $project): ?SeoLinkRun
    {
        if (!$project->seo_verified_at) return null;
        return SeoLinkRun::where('project_id', $project->id)->where('context_hash', $this->context($project))->whereIn('status', ['complete', 'partial'])->latest('id')->first();
    }

    public function start(Project $project): SeoLinkRun
    {
        abort_unless($project->seo_verified_at && !empty($project->seo_settings['public_url']), 422, 'Configure and verify the public domain before building a link graph.');
        return DB::transaction(function () use ($project) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            return SeoLinkRun::firstOrCreate(['project_id' => $project->id, 'context_hash' => $this->context($project), 'status' => 'building'], ['max_blog_id' => $project->blogs()->max('id') ?? 0]);
        });
    }

    /** One transaction indexes at most 20 stories, 200 distinct URLs/story, 500 KB HTML/story. */
    public function step(SeoLinkRun $run): SeoLinkRun
    {
        return DB::transaction(function () use ($run) {
            $run = SeoLinkRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'building') return $run;
            $project = Project::findOrFail($run->project_id);
            if (!hash_equals($run->context_hash, $this->context($project))) { $run->update(['status' => 'stale']); return $run; }
            $blogs = $project->blogs()->where('id', '>', $run->cursor)->where('id', '<=', $run->max_blog_id)->with('sections')->orderBy('id')->limit(20)->get();
            foreach ($blogs as $blog) {
                $blog->setRelation('project', $project);
                $extracted = $this->extract($blog);
                $run->omitted += $extracted['omitted'];
                foreach ($extracted['links'] as $link) {
                    $target = $link['kind'] === 'internal' ? $this->target($project, $link['url']) : null;
                    SeoLink::updateOrCreate(['run_id' => $run->id, 'blog_id' => $blog->id, 'url_hash' => hash('sha256', $link['url'])], [
                        'project_id' => $project->id, 'url' => $link['url'], 'kind' => $link['kind'], 'anchor' => $link['anchor'], 'occurrences' => $link['occurrences'], 'target_blog_id' => $target?->id,
                    ]);
                }
                $run->cursor = $blog->id; $run->processed++;
            }
            if (!hash_equals($run->context_hash, $this->context($project->fresh()))) $run->status = 'stale';
            elseif ($blogs->count() < 20 || $run->cursor >= $run->max_blog_id) $run->status = $run->omitted ? 'partial' : 'complete';
            $run->save(); return $run;
        });
    }

    public function extract(Blog $blog): array
    {
        $project = $blog->project;
        $base = $this->seo->articleUrl($blog);
        if (!$base) return ['links' => [], 'omitted' => 1];
        $html = $blog->sections->map(fn ($s) => $s->text_content)->implode("\n");
        if (trim($html) === '') $html = RichText::clean($blog->content);
        if (strlen($html) > 500000) return ['links' => [], 'omitted' => 1];
        $doc = new \DOMDocument('1.0', 'UTF-8'); $prior = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
        $links = []; $omitted = 0; $origin = $this->urls->origin($project->seo_settings['public_url']);
        foreach ($doc->getElementsByTagName('a') as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto:|tel:)~i', $href)) continue;
            try { $url = $this->urls->url($href, $base); } catch (\Throwable) { $omitted++; continue; }
            $key = hash('sha256', $url);
            if (isset($links[$key])) { $links[$key]['occurrences']++; continue; }
            if (count($links) >= 200) { $omitted++; continue; }
            $links[$key] = ['url' => $url, 'kind' => $this->urls->origin($url) === $origin ? 'internal' : 'external', 'anchor' => Str::limit(SeoResolver::text($node->textContent), 250, '…'), 'occurrences' => 1];
        }
        return ['links' => array_values($links), 'omitted' => $omitted];
    }

    public function target(Project $project, string $url): ?Blog
    {
        if ($this->urls->origin($url) !== $this->urls->origin($project->seo_settings['public_url'])) return null;
        try { $path = $this->urls->path(parse_url($url, PHP_URL_PATH) ?: '/'); } catch (\Throwable) { return null; }
        $template = $project->seo_settings['article_path'] ?? '/blog/{slug}';
        $regex = '~^'.str_replace(preg_quote('{slug}', '~'), '([^/]+)', preg_quote($template, '~')).'$~';
        if (!preg_match($regex, $path, $matches)) return null;
        return $project->blogs()->where('slug', rawurldecode($matches[1]))->first();
    }

    public function suggestions(Blog $blog): array
    {
        $project = $blog->project;
        if (!$project->seo_verified_at) return ['items' => [], 'note' => 'Verify the public domain first.'];
        $extracted = $this->extract($blog);
        if ($extracted['omitted']) return ['items' => [], 'note' => 'This story exceeds the complete extraction limits or has unsupported URLs. Review its links manually.'];
        $existing = [];
        foreach ($extracted['links'] as $link) { $target = $link['kind'] === 'internal' ? $this->target($project, $link['url']) : null; if ($target) $existing[$target->id] = true; }
        $stop = ['the','and','for','with','from','that','this','your','our','you','are','how','what','into','about'];
        $tokens = array_slice(array_values(array_diff(array_unique(explode(' ', AnalysisText::normalize($blog->title.' '.($blog->seo_settings['focus_keyphrase'] ?? '')))), $stop)), 0, 20);
        $candidates = $project->blogs()->live()->whereKeyNot($blog->id)->orderByDesc('is_cornerstone')->latest('id')->limit(200)->get(['id','project_id','title','slug','category_id','seo_settings','is_cornerstone']);
        $items = [];
        foreach ($candidates as $candidate) {
            if (isset($existing[$candidate->id])) continue;
            $candidate->setRelation('project', $project); $url = $this->seo->articleUrl($candidate);
            $settings = $candidate->seo_settings ?? [];
            $index = match ($settings['robots_index'] ?? 'inherit') { 'index' => true, 'noindex' => false, default => $project->seo_settings['index'] ?? true };
            if (!$url || !$index || (!empty($settings['canonical_url']) && $settings['canonical_url'] !== $url)) continue;
            $overlap = array_values(array_filter(array_intersect($tokens, explode(' ', AnalysisText::normalize($candidate->title))), fn ($token) => mb_strlen($token) >= 3));
            $sameCategory = $blog->category_id && $candidate->category_id === $blog->category_id;
            $score = count($overlap) * 2 + ($sameCategory ? 3 : 0);
            if (!$score) continue;
            $reasons = []; if ($sameCategory) $reasons[] = 'Same category'; if ($overlap) $reasons[] = 'Shared title/keyphrase terms: '.implode(', ', array_slice($overlap, 0, 4));
            $items[] = ['blog' => $candidate, 'url' => $url, 'score' => $score, 'reason' => implode('. ', $reasons)];
        }
        usort($items, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $b['blog']->id <=> $a['blog']->id);
        return ['items' => array_slice($items, 0, 8), 'note' => 'Suggestions rank up to 200 recent/cornerstone live, indexable, self-canonical stories by category and exact terms. They are not semantic or AI recommendations.'];
    }
}
