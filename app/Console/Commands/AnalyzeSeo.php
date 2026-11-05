<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\Project;
use App\Services\Seo\AnalysisText;
use App\Services\Seo\ContentAnalyzer;
use Illuminate\Console\Command;

class AnalyzeSeo extends Command
{
    protected $signature = 'seo:analyze {--project= : Limit to a project ID}';
    protected $description = 'Index existing editorial keys and capture SEO/readability reports in bounded batches';

    public function handle(ContentAnalyzer $analyzer): int
    {
        $id = $this->option('project');
        if ($id !== null && (!ctype_digit((string) $id) || !Project::whereKey($id)->exists())) {
            $this->error('Provide an existing numeric project ID.');
            return self::FAILURE;
        }
        $scope = fn () => Blog::query()->when($id !== null, fn ($q) => $q->where('project_id', $id));
        $affected = [];
        $scope()->chunkById(50, function ($blogs) use (&$affected) {
            foreach ($blogs as $blog) {
                $title = AnalysisText::key($blog->title);
                $focus = AnalysisText::key($blog->seo_settings['focus_keyphrase'] ?? null);
                if ($title !== $blog->seo_title_key || $focus !== $blog->seo_focus_key) {
                    Blog::withoutTimestamps(fn () => Blog::whereKey($blog->id)->update(['seo_title_key' => $title, 'seo_focus_key' => $focus]));
                    $affected[$blog->project_id] = true;
                }
            }
        });
        if ($affected) Project::withoutTimestamps(fn () => Project::whereIn('id', array_keys($affected))->increment('seo_content_version'));
        $count = 0;
        $scope()->chunkById(20, function ($blogs) use ($analyzer, &$count) {
            foreach ($blogs as $blog) { $analyzer->capture($blog); $count++; }
        });
        $this->info('Analyzed '.$count.' stories. No article text or public modification dates were changed.');
        return self::SUCCESS;
    }
}
