<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildLinkGraph;
use App\Jobs\CheckPublicLink;
use App\Models\{Blog, Project, SeoLink, SeoLinkCheck, SeoLinkRun};
use App\Services\Links\LinkGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;

class LinkController extends Controller
{
    public function index(Request $request, Project $project, LinkGraph $graph)
    {
        Gate::authorize('view', $project);
        $run = $graph->ready($project);
        $latest = SeoLinkRun::where('project_id', $project->id)->latest('id')->first();
        $links = SeoLink::where('project_id', $project->id)->where('run_id', $run?->id ?? 0)->with('blog')->orderBy('id')->paginate(20);
        $checks = SeoLinkCheck::where('project_id', $project->id)->whereIn('url_hash', $links->pluck('url_hash'))->get()->keyBy('url_hash');
        // Incoming links count only other live articles in this snapshot, not navigation or drafts.
        $incoming = SeoLink::select('target_blog_id')->where('project_id', $project->id)->where('run_id', $run?->id ?? 0)
            ->whereNotNull('target_blog_id')->whereColumn('blog_id', '!=', 'target_blog_id')->whereHas('blog', fn ($q) => $q->live());
        $orphans = $run ? $project->blogs()->live()->whereNotIn('id', $incoming)->latest('id')->paginate(20, ['*'], 'orphans') : null;
        return response()->view('admin.seo.links', compact('project', 'run', 'latest', 'links', 'checks', 'orphans'))->header('Cache-Control', 'private, no-store');
    }

    public function rebuild(Project $project, LinkGraph $graph)
    {
        Gate::authorize('update', $project);
        $run = $graph->start($project);
        BuildLinkGraph::dispatch($run->id)->afterCommit();
        return back()->with('success', 'Link index queued. Refresh this page after the SEO worker finishes.');
    }

    public function suggestions(Blog $blog, LinkGraph $graph)
    {
        Gate::authorize('view', $blog->project);
        $project = $blog->project;
        $suggestions = $graph->suggestions($blog);
        $inputHash = app(\App\Services\Seo\ContentAnalyzer::class)->fingerprint($blog);
        $edits = \App\Models\SeoLinkEdit::where('project_id', $project->id)->where('blog_id', $blog->id)->latest('id')->limit(10)->get();
        return response()->view('admin.seo.link-suggestions', compact('project', 'blog', 'suggestions', 'inputHash', 'edits'))->header('Cache-Control', 'private, no-store');
    }

    public function insert(Request $request, Blog $blog, LinkGraph $graph, \App\Services\Seo\ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $blog->project);
        $data = $request->validate(['input_hash' => 'required|string|size:64', 'target_id' => 'required|integer']);
        DB::transaction(function () use ($request, $blog, $graph, $analyzer, $data) {
            // Shared project lock serializes approved inserts and graph/redirect writes.
            Project::whereKey($blog->project_id)->lockForUpdate()->firstOrFail();
            $fresh = Blog::whereKey($blog->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->project_id === $blog->project_id, 409);
            abort_unless(hash_equals($data['input_hash'], $analyzer->fingerprint($fresh)), 409, 'Story changed. Refresh suggestions first.');
            $item = collect($graph->suggestions($fresh)['items'])->first(fn ($item) => $item['blog']->id === (int) $data['target_id']);
            abort_unless($item, 409, 'This target is no longer eligible. Refresh suggestions.');
            $section = $fresh->sections()->create(['order' => ($fresh->sections()->max('order') ?? -1) + 1, 'text_content' => '<h2>Related reading</h2><p><a href="'.e($item['url']).'">'.e($item['blog']->title).'</a></p>']);
            \App\Models\SeoLinkEdit::create(['project_id' => $fresh->project_id, 'blog_id' => $fresh->id, 'section_id' => $section->id, 'user_id' => $request->user()->id, 'section_hash' => hash('sha256', $section->text_content), 'target_url' => $item['url']]);
            $fresh->touch(); $analyzer->capture($fresh);
        });
        return back()->with('success', 'Approved link appended in a Related reading section. You can undo it below.');
    }

    public function undo(Request $request, Blog $blog, \App\Models\SeoLinkEdit $edit, \App\Services\Seo\ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $blog->project);
        abort_unless($edit->project_id === $blog->project_id && $edit->blog_id === $blog->id, 404);
        DB::transaction(function () use ($blog, $edit, $analyzer) {
            Project::whereKey($blog->project_id)->lockForUpdate()->firstOrFail();
            $fresh = Blog::whereKey($blog->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->project_id === $edit->project_id, 409);
            $edit = \App\Models\SeoLinkEdit::whereKey($edit->id)->lockForUpdate()->firstOrFail();
            $section = $fresh->sections()->whereKey($edit->section_id)->first();
            abort_unless(!$edit->reverted_at && $section && hash_equals($edit->section_hash, hash('sha256', $section->text_content)) && !$section->image_path, 409, 'The inserted section changed or was removed. Review it in the editor.');
            $section->delete(); $edit->update(['reverted_at' => now()]); $fresh->touch(); $analyzer->capture($fresh);
        });
        return back()->with('success', 'Inserted link section removed.');
    }

    public function check(Request $request, Project $project, LinkGraph $graph)
    {
        Gate::authorize('update', $project);
        $data = $request->validate(['link_ids' => 'required|array|min:1|max:20', 'link_ids.*' => 'required|integer|distinct']);
        $run = $graph->ready($project);
        abort_unless($run, 409, 'Rebuild the link index first.');
        $ids = DB::transaction(function () use ($project, $run, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $links = SeoLink::where('project_id', $project->id)->where('run_id', $run->id)->whereIn('id', $data['link_ids'])->get();
            abort_unless($links->count() === count($data['link_ids']), 404);
            $remaining = 200 - SeoLinkCheck::where('project_id', $project->id)->where('updated_at', '>=', now()->startOfDay())->count();
            abort_if($remaining <= 0, 429, 'Daily check budget reached.');
            $jobs = [];
            foreach ($links->unique('url_hash') as $link) {
                $check = SeoLinkCheck::firstOrNew(['project_id' => $project->id, 'url_hash' => $link->url_hash]);
                if ($check->exists && $check->updated_at->gt(now()->subHour())) continue;
                if (count($jobs) >= $remaining) break;
                $check->fill(['url' => $link->url, 'state' => 'pending', 'token' => (string) Str::uuid(), 'checked_at' => null, 'http_status' => null, 'final_url' => null, 'detail' => 'Queued for a bounded HEAD request.'])->save();
                $jobs[] = [$check->id, $check->token];
            }
            return $jobs;
        });
        foreach ($ids as [$id, $token]) CheckPublicLink::dispatch($id, $token)->afterCommit();
        return back()->with('success', count($ids).' URL checks queued. URLs checked or queued in the last hour were skipped.');
    }
}
