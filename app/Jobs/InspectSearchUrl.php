<?php

namespace App\Jobs;

use App\Models\Blog;
use App\Models\Project;
use App\Models\SearchConnection;
use App\Models\SearchInspection;
use App\Services\SearchConsole\GoogleSearch;
use App\Services\Seo\SeoResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InspectSearchUrl implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 80;

    public int $backoff = 120;

    public function __construct(public int $inspectionId)
    {
        $this->onQueue('seo');
    }

    public function handle(GoogleSearch $google, SeoResolver $resolver): void
    {
        $item = SearchInspection::find($this->inspectionId);
        if (! $item || $item->state !== 'pending') {
            return;
        }
        $project = Project::find($item->project_id);
        $connection = SearchConnection::find($item->connection_id);
        $blog = Blog::where('project_id', $item->project_id)->live()->find($item->blog_id);
        if (! $project || ! $project->seo_verified_at || ! $connection || $connection->project_id !== $item->project_id || $connection->state !== 'connected' || $connection->origin !== ($project->seo_settings['public_url'] ?? '') || $connection->property !== $item->property || ! $blog) {
            $item->update(['state' => 'cancelled']);

            return;
        }
        $blog->setRelation('project', $project);
        if ($resolver->articleUrl($blog) !== $item->url) {
            $item->update(['state' => 'cancelled']);

            return;
        }
        $google->verifyProperty($connection);
        $data = $google->request($connection, 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', ['inspectionUrl' => $item->url, 'siteUrl' => $item->property, 'languageCode' => 'en-US']);
        $result = $data['inspectionResult']['indexStatusResult'] ?? null;
        if (! is_array($result)) {
            throw new \RuntimeException('Google returned no index-status result.');
        }
        // Store a bounded, explicit field set rather than arbitrary upstream payloads.
        $allowed = ['verdict', 'coverageState', 'robotsTxtState', 'indexingState', 'lastCrawlTime', 'pageFetchState', 'googleCanonical', 'userCanonical', 'crawledAs'];
        $clean = [];
        foreach ($allowed as $field) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $clean[$field] = mb_substr($result[$field], 0, 2048);
            }
        }
        $fresh = $connection->fresh();
        $blog = $blog->fresh();
        $project = $project->fresh();
        if (! $fresh || ! $blog || ! $project || ! $project->seo_verified_at || $fresh->state !== 'connected' || $fresh->property !== $item->property || ($project->seo_settings['public_url'] ?? '') !== $connection->origin || $blog->project_id !== $project->id || ! Blog::whereKey($blog->id)->live()->exists()) {
            $item->update(['state' => 'cancelled']);

            return;
        }
        $blog->setRelation('project', $project);
        if ($resolver->articleUrl($blog) !== $item->url) {
            $item->update(['state' => 'cancelled']);

            return;
        }
        $item->update(['state' => 'complete', 'result' => $clean, 'checked_at' => now(), 'detail' => 'Google indexed-version inspection, not a live URL test or indexing submission.']);
    }

    public function failed(?\Throwable $exception): void
    {
        SearchInspection::whereKey($this->inspectionId)->where('state', 'pending')->update(['state' => 'failed', 'detail' => 'Inspection failed after retries. Check Google property permissions, API enablement and quota.']);
    }
}
