<?php

namespace App\Jobs;

use App\Models\SeoLinkRun;
use App\Services\Links\LinkGraph;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildLinkGraph implements ShouldQueue
{
    use Queueable;
    public int $tries = 2;
    public int $timeout = 60;
    public int $backoff = 10;
    public function __construct(public int $runId) { $this->onQueue('seo'); }
    public function handle(LinkGraph $graph): void
    {
        $run = SeoLinkRun::find($this->runId);
        if (!$run || $run->status !== 'building') return;
        $run = $graph->step($run);
        if ($run->status === 'building') self::dispatch($run->id)->afterCommit();
    }
    public function failed(?\Throwable $exception): void
    {
        SeoLinkRun::whereKey($this->runId)->where('status', 'building')->update(['status' => 'failed']);
    }
}
