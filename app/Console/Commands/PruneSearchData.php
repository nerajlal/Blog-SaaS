<?php

namespace App\Console\Commands;

use App\Models\SearchInspection;
use App\Models\SearchReport;
use Illuminate\Console\Command;

class PruneSearchData extends Command
{
    protected $signature = 'seo:prune-search';

    protected $description = 'Prune Search Console reports and inspections after 90 days';

    public function handle(): int
    {
        foreach ([SearchReport::class, SearchInspection::class] as $model) {
            $model::where('created_at', '<', now()->subDays(90))->delete();
            $model::where('state', 'pending')->where('created_at', '<', now()->subDay())->update(['state' => 'failed', 'detail' => 'The queued operation expired. Check the SEO worker, then request a new fetch.']);
        }
        $this->info('Search Console diagnostics pruned.');

        return self::SUCCESS;
    }
}
