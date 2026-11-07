<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use Illuminate\Console\Command;

class MaintainAiRuns extends Command
{
    protected $signature = 'ai:maintain';

    protected $description = 'Mark abandoned AI jobs failed without retrying paid work or deleting content';

    public function handle(): int
    {
        $expired = AiRun::where(function ($query) {
            $query->where(fn ($q) => $q->where('state', 'running')->where('updated_at', '<', now()->subMinutes(5)))
                ->orWhere(fn ($q) => $q->where('state', 'pending')->where('created_at', '<', now()->subMinutes(30)));
        })->update(['state' => 'failed', 'detail' => 'Task expired without an automatic retry. Check the queue worker and provider usage before generating again.']);
        $this->info($expired.' abandoned tasks marked failed. Stored content was retained.');

        return self::SUCCESS;
    }
}
