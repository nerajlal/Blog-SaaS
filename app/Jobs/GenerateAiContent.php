<?php

namespace App\Jobs;

use App\Models\AiRun;
use App\Models\User;
use App\Services\Ai\Claude;
use App\Services\Ai\WebsiteReader;
use App\Services\Ai\WorkspaceAi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GenerateAiContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue('ai');
    }

    public function handle(Claude $claude, WebsiteReader $reader, WorkspaceAi $ai): void
    {
        if (! AiRun::whereKey($this->runId)->where('state', 'pending')->update(['state' => 'running'])) {
            return;
        }
        $run = AiRun::find($this->runId);
        try {
            $user = User::find($run->user_id);
            $ability = $run->action === 'analyze' ? 'manageSeo' : 'update';
            if (! $user || ! Gate::forUser($user)->allows($ability, $run->project) || $ai->scope($run->project) !== $run->scope || $ai->stale($run) || ! DB::table('ai_settings')->where('scope', $run->scope)->value('enabled')) {
                $run->update(['state' => 'cancelled', 'detail' => 'Access, source, profile, or AI settings changed.']);

                return;
            }
            $input = $run->input;
            if ($run->action === 'analyze' && ! empty($input['urls'])) {
                $input['sources'] = array_merge($input['sources'] ?? [], $reader->read($input['urls']));
                $run->update(['input' => $input]);
            }
            $context = ['task' => $run->action, 'platform' => $run->platform, 'approved_profile' => $run->action === 'analyze' ? null : $run->profileVersion?->data, 'brief' => $input];
            $response = $claude->generate($run->action, $context, $run->model);
            DB::transaction(function () use ($response, $ai, $ability) {
                $current = AiRun::lockForUpdate()->find($this->runId);
                if (! $current || $current->state !== 'running') {
                    return;
                }
                $user = User::find($current->user_id);
                $permitted = $user && Gate::forUser($user)->allows($ability, $current->project) && $ai->scope($current->project) === $current->scope && ! $ai->stale($current) && DB::table('ai_settings')->where('scope', $current->scope)->value('enabled');
                $current->update($response + ['state' => $permitted ? 'complete' : 'cancelled', 'detail' => $permitted ? null : 'Source or access changed during generation. Result is not applicable.']);
            });
        } catch (\Throwable) {
            AiRun::whereKey($this->runId)->where('state', 'running')->update(['state' => 'failed', 'detail' => 'Task failed: check AI configuration, provider quota, selected source pages, or response format. No automatic retry was made. Use manual entry if a website is blocked.']);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        AiRun::whereKey($this->runId)->whereIn('state', ['pending', 'running'])->update(['state' => 'failed', 'detail' => 'Worker stopped or task timed out. Check worker health before starting another task; provider billing may already have occurred.']);
    }
}
