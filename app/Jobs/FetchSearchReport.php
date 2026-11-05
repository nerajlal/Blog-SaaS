<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\SearchConnection;
use App\Models\SearchReport;
use App\Services\SearchConsole\GoogleSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchSearchReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 80;

    public int $backoff = 120;

    public function __construct(public int $reportId)
    {
        $this->onQueue('seo');
    }

    public function handle(GoogleSearch $google): void
    {
        $report = SearchReport::find($this->reportId);
        if (! $report || $report->state !== 'pending') {
            return;
        }
        $project = Project::find($report->project_id);
        $connection = SearchConnection::find($report->connection_id);
        if (! $project || ! $project->seo_verified_at || ! $connection || $connection->project_id !== $report->project_id || $connection->state !== 'connected' || $connection->origin !== ($project->seo_settings['public_url'] ?? '') || $connection->origin !== $report->origin || $connection->property !== $report->property) {
            $report->update(['state' => 'cancelled', 'detail' => 'Origin, property or connection changed.']);

            return;
        }
        $google->verifyProperty($connection);
        $data = $google->request($connection, 'POST', 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($connection->property).'/searchAnalytics/query', [
            'startDate' => $report->start_date, 'endDate' => $report->end_date, 'dimensions' => ['date'], 'type' => 'web', 'dataState' => 'final', 'rowLimit' => 100, 'aggregationType' => 'auto',
            // Filter broad domain properties to this exact consumer origin.
            'dimensionFilterGroups' => [['filters' => [['dimension' => 'page', 'operator' => 'includingRegex', 'expression' => '^'.preg_quote($report->origin.'/', '~')]]]],
        ]);
        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $date = $row['keys'][0] ?? '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $report->start_date || $date > $report->end_date) {
                continue;
            }
            $rows[] = ['date' => $date, 'clicks' => max(0, (float) ($row['clicks'] ?? 0)), 'impressions' => max(0, (float) ($row['impressions'] ?? 0)), 'ctr' => max(0, (float) ($row['ctr'] ?? 0)), 'position' => max(0, (float) ($row['position'] ?? 0))];
        }
        // Disconnection or property changes during a fetch must not resurrect private data.
        $current = $connection->fresh();
        $project = $project->fresh();
        if (! $current || ! $project || ! $project->seo_verified_at || $current->state !== 'connected' || $current->property !== $report->property || ($project->seo_settings['public_url'] ?? '') !== $report->origin) {
            $report->update(['state' => 'cancelled']);

            return;
        }
        $report->update(['state' => 'complete', 'rows' => $rows, 'fetched_at' => now(), 'detail' => count($rows) ? 'Final web-search data grouped by date.' : 'Google returned no rows. No-data is not proof of zero traffic.']);
        $current->update(['health' => 'Last performance fetch succeeded.', 'checked_at' => now()]);
    }

    public function failed(?\Throwable $exception): void
    {
        SearchReport::whereKey($this->reportId)->where('state', 'pending')->update(['state' => 'failed', 'detail' => 'Google fetch failed after retries. Check API access, quotas and connection permissions, then try again.']);
    }
}
