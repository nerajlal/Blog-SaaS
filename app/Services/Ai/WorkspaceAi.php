<?php

namespace App\Services\Ai;

use App\Jobs\GenerateAiContent;
use App\Models\AiProfileVersion;
use App\Models\AiRun;
use App\Models\Blog;
use App\Models\Project;
use App\Services\Editor\BlogRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceAi
{
    public function scope(Project $project): string
    {
        return $project->workspace_id ? 'workspace-'.$project->workspace_id : 'project-'.$project->id;
    }

    public function approved(Project $project): ?AiProfileVersion
    {
        return AiProfileVersion::where('project_id', $project->id)->latest('id')->first();
    }

    public function stale(AiRun $run): bool
    {
        if ($run->profile_version_id && $this->approved($run->project)?->id !== $run->profile_version_id) {
            return true;
        }
        if ($run->blog_id && (! $run->blog || $run->blog->project_id !== $run->project_id || app(BlogRevision::class)->hash($run->blog) !== ($run->input['blog_revision'] ?? ''))) {
            return true;
        }

        return false;
    }

    public function enqueue(Project $project, int $userId, string $action, array $input, string $key, ?Blog $blog = null, ?string $platform = null): AiRun
    {
        abort_unless(app(Claude::class)->ready(), 422, 'Claude is disabled or not configured. You can still edit project details manually.');
        $version = $this->approved($project);
        if ($action !== 'analyze' && ! $version) {
            throw ValidationException::withMessages(['profile' => 'Ask an owner or admin to approve the project profile first.']);
        }
        if ($action === 'draft' && ($input['outline_profile_version_id'] ?? null) !== $version?->id) {
            throw ValidationException::withMessages(['outline' => 'The profile changed. Review a new outline first.']);
        }
        if ($blog) {
            abort_unless($blog->project_id === $project->id, 404);
            $input['blog_revision'] = app(BlogRevision::class)->hash($blog);
            $input['article'] = ['title' => $blog->title, 'excerpt' => $blog->excerpt, 'body' => strip_tags($blog->content.' '.$blog->sections->pluck('text_content')->implode("\n"))];
        }
        if (strlen(json_encode($input)) > 45000) {
            throw ValidationException::withMessages(['context' => 'This article or brief is too large. Shorten the selected context.']);
        }
        $scope = $this->scope($project);
        $fingerprint = hash('sha256', json_encode([$project->id, $userId, $action, $input, $version?->id, $platform]));
        $run = DB::transaction(function () use ($scope, $project, $userId, $action, $input, $key, $blog, $version, $platform, $fingerprint) {
            DB::table('ai_settings')->insertOrIgnore(['scope' => $scope, 'enabled' => false, 'monthly_limit' => 20, 'created_at' => now(), 'updated_at' => now()]);
            $settings = DB::table('ai_settings')->where('scope', $scope)->lockForUpdate()->first();
            if (! $settings->enabled) {
                throw ValidationException::withMessages(['ai' => 'An owner or admin must enable AI usage for this workspace.']);
            }
            $existing = AiRun::where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->fingerprint === $fingerprint, 409, 'This request key was already used. Reload the form.');

                return $existing;
            }
            $duplicate = AiRun::where('fingerprint', $fingerprint)->whereIn('state', ['pending', 'running'])->first();
            if ($duplicate) {
                return $duplicate;
            }
            if (AiRun::where('scope', $scope)->whereIn('state', ['pending', 'running'])->exists()) {
                throw ValidationException::withMessages(['ai' => 'A workspace AI task is already in progress. Wait for it to finish.']);
            }
            $month = now()->format('Y-m');
            DB::table('ai_usage_months')->insertOrIgnore(['scope' => $scope, 'month' => $month, 'requests' => 0]);
            $usage = DB::table('ai_usage_months')->where('scope', $scope)->where('month', $month)->lockForUpdate()->first();
            if ($usage->requests >= min($settings->monthly_limit, config('ai.monthly_requests'))) {
                throw ValidationException::withMessages(['ai' => 'Monthly AI request allowance reached.']);
            }
            DB::table('ai_usage_months')->where('id', $usage->id)->increment('requests');
            $run = AiRun::create(['project_id' => $project->id, 'user_id' => $userId, 'scope' => $scope, 'action' => $action, 'platform' => $platform, 'request_key' => $key, 'fingerprint' => $fingerprint, 'input' => $input, 'profile_version_id' => $version?->id, 'blog_id' => $blog?->id, 'model' => config('ai.model')]);
            GenerateAiContent::dispatch($run->id)->afterCommit();

            return $run;
        });

        return $run;
    }
}
