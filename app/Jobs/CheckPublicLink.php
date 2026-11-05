<?php

namespace App\Jobs;

use App\Models\SeoLinkCheck;
use App\Services\Links\SafeLinkChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckPublicLink implements ShouldQueue
{
    use Queueable;
    public int $tries = 2;
    public int $timeout = 45;
    public int $backoff = 30;
    public function __construct(public int $checkId, public string $token) { $this->onQueue('seo'); }
    public function handle(SafeLinkChecker $checker): void
    {
        $check = SeoLinkCheck::whereKey($this->checkId)->where('token', $this->token)->where('state', 'pending')->first();
        if (!$check) return;
        $result = $checker->check($check->url);
        if ($result['retryable'] && $this->job && config('queue.default') !== 'sync' && $this->attempts() < 2) { $this->release(30); return; }
        unset($result['retryable']);
        SeoLinkCheck::whereKey($check->id)->where('token', $this->token)->update(array_merge($result, ['checked_at' => now()]));
    }
    public function failed(?\Throwable $exception): void
    {
        SeoLinkCheck::whereKey($this->checkId)->where('token', $this->token)->where('state', 'pending')->update(['state' => 'error', 'detail' => 'Worker failed or timed out. Retry this check.', 'checked_at' => now()]);
    }
}
