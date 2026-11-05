<?php

namespace App\Services\Seo;

use App\Models\Blog;
use App\Models\Project;
use App\Models\SeoAnalysis;
use App\Support\RichText;

final class ContentAnalyzer
{
    public const VERSION = '2026.11.editorial.1';
    public const MAX_BODY_BYTES = 500000;

    public function __construct(private SeoResolver $resolver) {}

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) $item = $this->canonicalize($item);
        }
        return $value;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function contextHash(Project $project): string
    {
        return $this->hash(['project_id' => $project->id, 'name' => $project->name, 'description' => $project->description, 'settings' => $project->seo_settings ?? [], 'verified' => (bool) $project->seo_verified_at, 'version' => (int) $project->seo_content_version]);
    }

    public function reviewDue(Blog $blog): bool
    {
        $date = $blog->content_reviewed_at ?? $blog->updated_at ?? $blog->created_at;
        return $date ? $date->lt(now()->subDays(90)) : false;
    }

    public function fingerprint(Blog $blog): string
    {
        return $this->hash([
            'rules' => self::VERSION, 'context' => $this->contextHash($blog->project),
            'title' => $blog->title, 'slug' => $blog->slug, 'excerpt' => $blog->excerpt,
            'legacy_content' => $blog->content, 'seo' => array_filter($blog->seo_settings ?? [], fn ($value, $key) => $value !== null && $value !== '' && $value !== 'inherit' && !($key === 'schema_type' && $value === 'BlogPosting'), ARRAY_FILTER_USE_BOTH),
            'cover' => [$blog->cover_image, $blog->cover_image_alt, (bool) $blog->cover_image_decorative],
            'sections' => $blog->sections->map(fn ($section) => [$section->text_content, $section->image_path, $section->image_alt, $section->image_decorative])->all(),
            'important' => (bool) $blog->is_cornerstone, 'reviewed_at' => $blog->content_reviewed_at?->toIso8601String(), 'review_due' => $this->reviewDue($blog),
            'publication' => $blog->status === 'published' && $blog->published_at?->isFuture() ? 'scheduled' : $blog->status,
        ]);
    }

    public function isCurrent(Blog $blog, ?SeoAnalysis $analysis): bool
    {
        return $analysis && $analysis->project_id === $blog->project_id && $analysis->rules_version === self::VERSION && hash_equals($analysis->input_hash, $this->fingerprint($blog));
    }

    private function finding(string $id, string $label, string $status, string $message, int $weight = 1): array
    {
        return compact('id', 'label', 'status', 'message', 'weight');
    }

    private function score(array $findings): ?int
    {
        $eligible = array_filter($findings, fn ($f) => in_array($f['status'], ['pass', 'warning'], true));
        $total = array_sum(array_column($eligible, 'weight'));
        if (!$total) return null;
        $passed = array_sum(array_column(array_filter($eligible, fn ($f) => $f['status'] === 'pass'), 'weight'));
        return (int) round(100 * $passed / $total);
    }

    public function analyze(Blog $blog): array
    {
        $hash = $this->fingerprint($blog);
        $locale = $blog->project->seo_settings['locale'] ?? 'en';
        $english = preg_match('/^en(?:-|$)/i', $locale) === 1;
        $html = $blog->sections->map(fn ($section) => $section->text_content)->implode("\n");
        if (trim($html) === '') $html = RichText::clean($blog->content);
        $result = ['rules_version' => self::VERSION, 'input_hash' => $hash, 'context_hash' => $this->contextHash($blog->project), 'locale' => $locale,
            'analyzed_at' => now()->toIso8601String(), 'review_due' => $this->reviewDue($blog), 'is_cornerstone' => (bool) $blog->is_cornerstone,
            'notes' => ['Scores summarize these editorial checks, not search rankings. Warnings never block publishing.', 'Length targets are adjustable editorial heuristics; they are not search-engine requirements.'],
            'metrics' => [], 'seo' => ['score' => null, 'findings' => []], 'readability' => ['score' => null, 'findings' => []]];
        if (strlen($html) > self::MAX_BODY_BYTES) {
            $message = 'This story exceeds the 500 KB analysis limit. Its content is not truncated or scored; publishing remains available.';
            $result['seo']['findings'][] = $this->finding('analysis_limit', 'Analysis unavailable', 'unavailable', $message, 0);
            $result['readability']['findings'][] = $this->finding('analysis_limit', 'Analysis unavailable', 'unavailable', $message, 0);
            return $result;
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
        $text = SeoResolver::text($html);
        $words = AnalysisText::words($text);
        $wordCount = count($words);
        $xpath = new \DOMXPath($document);
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $headings[] = ['level' => (int) substr($heading->nodeName, 1), 'text' => $heading->textContent];
        }
        $links = 0;
        foreach ($xpath->query('//a[@href]') as $link) {
            if (preg_match('~^(https?://|/(?!/))~i', $link->getAttribute('href'))) $links++;
        }
        $images = $blog->sections->filter(fn ($s) => (bool) $s->image_path)->map(fn ($s) => ['alt' => $s->image_alt, 'decorative' => $s->image_decorative])->values()->all();
        if ($blog->cover_image) $images[] = ['alt' => $blog->cover_image_alt, 'decorative' => $blog->cover_image_decorative];
        $missingAlt = count(array_filter($images, fn ($image) => !$image['decorative'] && SeoResolver::text($image['alt']) === ''));
        $seo = $this->resolver->resolve($blog);
        $phrase = $blog->seo_settings['focus_keyphrase'] ?? '';
        $hasPhrase = AnalysisText::normalize($phrase) !== '';
        $seoFindings = [];
        $add = function ($id, $label, $pass, $success, $warning, $weight = 1) use (&$seoFindings) {
            $seoFindings[] = $this->finding($id, $label, $pass ? 'pass' : 'warning', $pass ? $success : $warning, $weight);
        };
        $add('title_present', 'Search title', $seo['title'] !== '', 'A search title is available.', 'Add a story title or SEO title.', 2);
        $seoFindings[] = $this->finding('title_length', 'Search title length', $english ? (mb_strlen($seo['title']) >= 20 && mb_strlen($seo['title']) <= 70 ? 'pass' : 'warning') : 'unavailable', $english ? mb_strlen($seo['title']).' characters. This checker suggests 20–70; keep the title accurate and useful.' : 'English title-length guidance is not applied to this language.');
        $add('description_present', 'Search description', $seo['description'] !== '', 'A description is available, including inherited values.', 'Write a description or add article text from which to derive one.', 2);
        $seoFindings[] = $this->finding('description_length', 'Description length', $english ? (mb_strlen($seo['description']) >= 70 && mb_strlen($seo['description']) <= 180 ? 'pass' : 'warning') : 'unavailable', $english ? mb_strlen($seo['description']).' characters. This checker suggests 70–180; search engines may display other text.' : 'English description-length guidance is not applied to this language.');
        $add('body_present', 'Article content', $text !== '', 'The delivered article contains readable text.', 'Add article text. Empty editor markup does not count.', 2);
        $add('focus_present', 'Focus keyphrase', $hasPhrase, 'A private editorial keyphrase is set.', 'Add an optional focus keyphrase to enable relevant checks.');
        foreach (['keyword_title' => ['Keyphrase in search title', $seo['title']], 'keyword_description' => ['Keyphrase in description', $seo['description']], 'keyword_intro' => ['Keyphrase in opening text', implode(' ', array_slice($words, 0, 100))], 'keyword_slug' => ['Keyphrase in URL slug', $blog->slug ?? ''], 'keyword_headings' => ['Keyphrase in a section heading', implode(' ', array_column($headings, 'text'))]] as $id => [$label, $content]) {
            $status = !$english ? 'unavailable' : (!$hasPhrase || ($id === 'keyword_headings' && !$headings) || ($id === 'keyword_slug' && !$blog->slug) ? 'not_applicable' : (AnalysisText::contains($content, $phrase) ? 'pass' : 'warning'));
            $message = !$english ? 'English keyphrase matching is not applied to this language.' : (!$hasPhrase ? 'Set a focus keyphrase to use this check.' : ($id === 'keyword_slug' && !$blog->slug ? 'Save the story to check its stable URL slug.' : ($id === 'keyword_headings' && !$headings ? 'No section headings to check.' : 'Checks the whole normalized phrase. Use it only where it reads naturally; do not repeat it just for a score.')));
            $seoFindings[] = $this->finding($id, $label, $status, $message);
        }
        $bodyH1 = count(array_filter($headings, fn ($heading) => $heading['level'] === 1));
        $add('body_heading', 'Section heading levels', $bodyH1 === 0, 'Sections do not add another H1.', 'Consider H2/H3 for sections; the public page usually supplies the article H1.');
        $lastLevel = 1; $skipped = false;
        foreach ($headings as $heading) { if ($heading['level'] > $lastLevel + 1) $skipped = true; $lastLevel = $heading['level']; }
        $add('heading_order', 'Heading sequence', !$skipped, 'Heading levels follow a logical sequence.', 'A heading level is skipped. Review the section hierarchy.');
        $seoFindings[] = $this->finding('useful_links', 'Helpful links', $links ? 'pass' : ($wordCount >= 300 ? 'warning' : 'not_applicable'), $links ? $links.' internal/external HTTP links found. Their destinations have not been checked.' : 'Consider a useful source or related article where it helps readers. Short stories are not penalized for having no links.');
        $seoFindings[] = $this->finding('image_alt', 'Image descriptions', !$images ? 'not_applicable' : ($missingAlt ? 'warning' : 'pass'), !$images ? 'No cover or section images to check.' : ($missingAlt ? $missingAlt.' non-decorative image(s) need descriptive alt text.' : 'Images have alt text or are explicitly decorative.'), 2);
        $titleKey = AnalysisText::key($blog->title);
        $focusKey = AnalysisText::key($phrase);
        $duplicates = function (?string $key, string $column) use ($blog): int {
            if (!$key || !$blog->project_id) return 0;
            return Blog::where('project_id', $blog->project_id)->where($column, $key)->when($blog->exists, fn ($q) => $q->whereKeyNot($blog->id))->count();
        };
        $duplicateTitles = $duplicates($titleKey, 'seo_title_key');
        $duplicatePhrases = $duplicates($focusKey, 'seo_focus_key');
        $add('duplicate_title', 'Duplicate article titles', $duplicateTitles === 0, 'No other article in this project has the same normalized title.', $duplicateTitles.' other article(s) in this project have the same title. Distinguish them if they serve different purposes.');
        $seoFindings[] = $this->finding('duplicate_focus', 'Repeated focus keyphrase', !$hasPhrase ? 'not_applicable' : ($duplicatePhrases ? 'warning' : 'pass'), !$hasPhrase ? 'No keyphrase to compare.' : ($duplicatePhrases ? $duplicatePhrases.' other article(s) target this phrase. Check whether their intent overlaps.' : 'No other article in this project uses this exact normalized phrase.'));
        $seoFindings[] = $this->finding('domain_ready', 'Public domain', $blog->project->seo_verified_at ? 'info' : 'warning', $blog->project->seo_verified_at ? 'Domain verified. Check the actual frontend output separately.' : 'Verify the project domain to enable canonical/schema/sitemap output.', 0);
        $seoFindings[] = $this->finding('indexing', 'Indexing preference', 'info', $seo['robots']['index'] ? 'This story requests indexing once published; discovery and ranking are not guaranteed.' : 'This story intentionally requests noindex and will be excluded from the sitemap.', 0);
        $seoFindings[] = $this->finding('content_review', 'Editorial freshness', $this->reviewDue($blog) ? 'warning' : 'pass', $this->reviewDue($blog) ? 'No content edit/review in 90 days. Confirm that facts and links are still useful.' : 'Content was edited or reviewed within the last 90 days.', 0);
        $result['seo'] = ['score' => $this->score($seoFindings), 'findings' => $seoFindings];
        $result['metrics'] = ['word_count' => $english ? $wordCount : null, 'heading_count' => count($headings), 'link_count' => $links, 'image_count' => count($images), 'missing_alt_count' => $missingAlt, 'duplicate_titles' => $duplicateTitles, 'duplicate_keyphrases' => $duplicatePhrases];
        $result['readability'] = $this->readability($xpath, $text, $wordCount, $english);
        $result['notes'][] = 'Readability uses English-only word/sentence heuristics. Passive voice, syllables, synonyms, and inclusive language are not analyzed.';
        return $result;
    }

    private function readability(\DOMXPath $xpath, string $text, int $wordCount, bool $english): array
    {
        if (!$english || !$wordCount) {
            return ['score' => null, 'findings' => [$this->finding('readability_unavailable', 'Readability unavailable', 'unavailable', !$english ? 'This version supports English word and sentence analysis only. Structural SEO checks still run.' : 'Add readable article text to enable readability checks.', 0)]];
        }
        $sentences = array_values(array_filter(preg_split('/(?<=[.!?])\s+/u', $text) ?: [], fn ($s) => count(AnalysisText::words($s)) > 0));
        $lengths = array_map(fn ($s) => count(AnalysisText::words($s)), $sentences);
        $longSentences = count(array_filter($lengths, fn ($count) => $count > 25));
        $average = round($wordCount / max(1, count($sentences)), 1);
        $paragraphLengths = [];
        foreach ($xpath->query('//p|//li|//blockquote') as $paragraph) {
            // Count only the innermost prose block so nested lists/quotes are not counted twice.
            if ($xpath->query('.//p|.//li', $paragraph)->length === 0) $paragraphLengths[] = count(AnalysisText::words($paragraph->textContent));
        }
        if (!$paragraphLengths) $paragraphLengths = [$wordCount];
        $longParagraphs = count(array_filter($paragraphLengths, fn ($count) => $count > 150));
        $starts = array_map(fn ($s) => mb_strtolower(AnalysisText::words($s)[0] ?? '', 'UTF-8'), $sentences);
        $repeated = false;
        for ($i = 2; $i < count($starts); $i++) if ($starts[$i] !== '' && $starts[$i] === $starts[$i - 1] && $starts[$i] === $starts[$i - 2]) $repeated = true;
        $segments = preg_split('~<h[1-6]\b[^>]*>.*?</h[1-6]>~isu', $xpath->document->saveHTML($xpath->document->getElementsByTagName('body')->item(0))) ?: [];
        $maxSectionWords = max(array_map(fn ($html) => count(AnalysisText::words(SeoResolver::text($html))), $segments) ?: [0]);
        $findings = [
            $this->finding('sentence_length', 'Long sentences', $longSentences / max(1, count($sentences)) <= .25 ? 'pass' : 'warning', $longSentences.' of '.count($sentences).' sentences exceed 25 words. Review long sentences for clarity.'),
            $this->finding('average_sentence', 'Average sentence length', $average <= 20 ? 'pass' : 'warning', 'Average: '.$average.' words. This heuristic suggests around 20 or fewer; preserve meaning and natural flow.'),
            $this->finding('paragraph_length', 'Paragraph length', $longParagraphs === 0 ? 'pass' : 'warning', $longParagraphs ? $longParagraphs.' paragraph(s) exceed 150 words. Consider logical breaks.' : 'No prose blocks exceed 150 words.'),
            $this->finding('heading_spacing', 'Subheading spacing', $wordCount < 300 ? 'not_applicable' : ($maxSectionWords <= 350 ? 'pass' : 'warning'), $wordCount < 300 ? 'No subheading requirement for a short story.' : 'Longest section: '.$maxSectionWords.' words. Consider a useful subheading above roughly 350 words.'),
            $this->finding('sentence_starts', 'Consecutive sentence openings', $repeated ? 'warning' : 'pass', $repeated ? 'Three consecutive sentences begin with the same word. Vary them when it improves the writing.' : 'No three consecutive sentences begin with the same word.'),
        ];
        return ['score' => $this->score($findings), 'findings' => $findings, 'metrics' => ['sentence_count' => count($sentences), 'average_sentence_words' => $average, 'long_sentences' => $longSentences, 'long_paragraphs' => $longParagraphs, 'longest_section_words' => $maxSectionWords]];
    }

    public function capture(Blog $blog): SeoAnalysis
    {
        $blog->load(['project', 'sections', 'user']);
        $result = $this->analyze($blog);
        $reviewDate = $blog->content_reviewed_at ?? $blog->updated_at ?? $blog->created_at;
        $validUntil = !$result['review_due'] && $reviewDate ? $reviewDate->copy()->addDays(90) : null;
        return $blog->seoAnalyses()->firstOrCreate(['rules_version' => self::VERSION, 'input_hash' => $result['input_hash']], ['project_id' => $blog->project_id, 'context_hash' => $result['context_hash'], 'valid_until' => $validUntil, 'missing_metadata' => collect($result['seo']['findings'])->contains(fn ($finding) => in_array($finding['id'], ['title_present', 'description_present'], true) && $finding['status'] === 'warning'), 'result' => $result, 'created_at' => now()]);
    }

    public function withReviews(Blog $blog, array $result): array
    {
        $reviews = $blog->seoReviews()->where('project_id', $blog->project_id)->where('input_hash', $result['input_hash'])->where('rules_version', self::VERSION)->latest('id')->get()->unique('rule_id')->keyBy('rule_id');
        foreach (['seo', 'readability'] as $group) {
            foreach ($result[$group]['findings'] as &$finding) {
                $review = $reviews->get($finding['id']);
                $finding['review'] = $review && $review->action === 'dismiss' ? ['reason' => $review->reason, 'at' => $review->created_at->toIso8601String()] : null;
            }
        }
        return $result;
    }
}
