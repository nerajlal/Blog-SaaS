<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\BlogSection;
use App\Models\Project;
use App\Services\Seo\ContentAnalyzer;
use App\Services\Seo\SeoInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SeoAnalysisController extends Controller
{
    public function preview(Request $request, Project $project, ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $project);
        $data = $request->validate(array_merge(SeoInput::blogRules(), [
            'blog_id' => 'nullable|integer', 'title' => 'nullable|string|max:255',
            'cover_image_present' => 'required|boolean', 'cover_image_replaced' => 'sometimes|boolean', 'is_cornerstone' => 'sometimes|boolean',
            'sections' => 'required|array|min:1|max:100', 'sections.*' => 'array',
            'sections.*.text' => 'nullable|string|max:100000', 'sections.*.existing_image' => 'nullable|string|max:2048', 'sections.*.image_replaced' => 'sometimes|boolean', 'sections.*.image_present' => 'required|boolean',
        ]));
        $blog = !empty($data['blog_id']) ? $project->blogs()->with(['sections', 'latestSeoAnalysis'])->findOrFail($data['blog_id']) : new Blog(['project_id' => $project->id, 'content' => '', 'status' => 'draft']);
        $savedSnapshot = $blog->exists ? $blog->latestSeoAnalysis : null;
        $savedImages = $blog->exists ? $blog->sections->pluck('image_path')->filter()->all() : [];
        $blog->title = $data['title'] ?? '';
        $blog->seo_settings = $data['seo'] ?? [];
        $blog->is_cornerstone = $request->boolean('is_cornerstone');
        $blog->cover_image = $request->boolean('cover_image_present') ? (!$request->boolean('cover_image_replaced') && $blog->cover_image ? $blog->cover_image : '/storage/analysis-preview.jpg') : null;
        $blog->cover_image_alt = $data['cover_image_alt'] ?? null;
        $blog->cover_image_decorative = $request->boolean('cover_image_decorative');
        $blog->setRelation('project', $project);
        $blog->setRelation('sections', collect(array_values($data['sections']))->map(fn ($section) => new BlogSection([
            'text_content' => $section['text'] ?? '', 'image_path' => filter_var($section['image_present'], FILTER_VALIDATE_BOOLEAN) ? (!filter_var($section['image_replaced'] ?? false, FILTER_VALIDATE_BOOLEAN) && in_array($section['existing_image'] ?? '', $savedImages, true) ? $section['existing_image'] : '/storage/analysis-preview.jpg') : null,
            'image_alt' => $section['image_alt'] ?? null, 'image_decorative' => filter_var($section['image_decorative'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ])));
        $result = $analyzer->analyze($blog);
        // A placeholder for a selected upload cannot be mistaken for a saved revision.
        $matchesSaved = $savedSnapshot && hash_equals($savedSnapshot->input_hash, $result['input_hash']);
        if ($blog->exists && $matchesSaved) $result = $analyzer->withReviews($blog, $result);
        return response()->json(['analysis' => $result, 'saved_revision' => (bool) $matchesSaved])->header('Cache-Control', 'no-store');
    }

    public function refresh(Blog $blog, ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $blog->project);
        $analyzer->capture($blog);
        return back()->with('success', 'Saved content analyzed. No article text was changed.');
    }

    public function refreshPage(Request $request, Project $project, ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $project);
        $data = $request->validate(['blog_ids' => 'required|array|min:1|max:20', 'blog_ids.*' => 'required|integer|distinct']);
        $blogs = $project->blogs()->whereIn('id', $data['blog_ids'])->get();
        abort_unless($blogs->count() === count($data['blog_ids']), 404);
        foreach ($blogs as $blog) $analyzer->capture($blog);
        return back()->with('success', 'Checks refreshed for '.$blogs->count().' saved stories.');
    }

    public function health(Request $request, Project $project, ContentAnalyzer $analyzer)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['filter' => ['nullable', Rule::in(['all', 'needs_analysis', 'missing_metadata', 'missing_alt', 'duplicate_titles', 'duplicate_keyphrases', 'stale', 'cornerstone'])]]);
        $filter = $data['filter'] ?? 'all';
        $context = $analyzer->contextHash($project);
        $current = fn ($q) => $q->where('project_id', $project->id)->where('context_hash', $context)->where('rules_version', ContentAnalyzer::VERSION)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
        $query = $project->blogs();
        if ($filter === 'needs_analysis') $query->whereDoesntHave('latestSeoAnalysis', $current);
        if ($filter === 'cornerstone') $query->where('is_cornerstone', true);
        if ($filter === 'stale') $query->whereRaw('COALESCE(content_reviewed_at, updated_at, created_at) < ?', [now()->subDays(90)]);
        if ($filter === 'missing_alt') {
            $query->where(function ($q) {
                $q->where(fn ($cover) => $cover->whereNotNull('cover_image')->where('cover_image_decorative', false)->where(fn ($alt) => $alt->whereNull('cover_image_alt')->orWhere('cover_image_alt', '')))
                    ->orWhereHas('sections', fn ($section) => $section->whereNotNull('image_path')->where('image_decorative', false)->where(fn ($alt) => $alt->whereNull('image_alt')->orWhere('image_alt', '')));
            });
        }
        if (in_array($filter, ['duplicate_titles', 'duplicate_keyphrases'], true)) {
            $key = $filter === 'duplicate_titles' ? 'seo_title_key' : 'seo_focus_key';
            $query->whereIn($key, Blog::select($key)->where('project_id', $project->id)->whereNotNull($key)->groupBy($key)->havingRaw('COUNT(*) > 1'));
        }
        if ($filter === 'missing_metadata') {
            $query->whereHas('latestSeoAnalysis', function ($q) use ($current) {
                $current($q);
                $q->where('missing_metadata', true);
            });
        }
        $blogs = $query->with(['sections', 'latestSeoAnalysis'])->latest('id')->paginate(20)->withQueryString();
        foreach ($blogs as $blog) $blog->setRelation('project', $project);
        $stats = ['total' => $project->blogs()->count(), 'analyzed' => $project->blogs()->whereHas('latestSeoAnalysis', $current)->count(), 'cornerstone' => $project->blogs()->where('is_cornerstone', true)->count()];
        return response()->view('admin.seo.health', compact('project', 'blogs', 'filter', 'stats', 'analyzer'))->header('Cache-Control', 'private, no-store');
    }

    public function report(Blog $blog, ContentAnalyzer $analyzer)
    {
        Gate::authorize('view', $blog->project);
        $project = $blog->project;
        $snapshot = $blog->latestSeoAnalysis;
        if ($snapshot && $snapshot->project_id !== $project->id) $snapshot = null;
        $current = $analyzer->isCurrent($blog, $snapshot);
        $result = $snapshot ? $analyzer->withReviews($blog, $snapshot->result) : null;
        $history = $blog->seoReviews()->where('project_id', $project->id)->latest('id')->limit(20)->get();
        return response()->view('admin.seo.report', compact('blog', 'project', 'snapshot', 'current', 'result', 'history'))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, Blog $blog, ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $blog->project);
        $data = $request->validate(['rule_id' => 'required|string|max:60', 'input_hash' => 'required|string|size:64', 'action' => ['required', Rule::in(['dismiss', 'restore'])], 'reason' => 'required|string|min:5|max:500']);
        $snapshot = $blog->latestSeoAnalysis;
        abort_unless($analyzer->isCurrent($blog, $snapshot) && hash_equals($snapshot->input_hash, $data['input_hash']), 409, 'This report is out of date. Refresh the saved analysis before reviewing warnings.');
        $findings = array_merge($snapshot->result['seo']['findings'], $snapshot->result['readability']['findings']);
        abort_unless(collect($findings)->contains(fn ($finding) => $finding['id'] === $data['rule_id'] && $finding['status'] === 'warning'), 422, 'Only a current warning can be reviewed.');
        $blog->seoReviews()->create(array_merge($data, ['project_id' => $blog->project_id, 'user_id' => $request->user()->id, 'rules_version' => ContentAnalyzer::VERSION, 'created_at' => now()]));
        return back()->with('success', 'Editorial decision recorded. Raw scores remain unchanged; the decision applies only to this analysis revision.');
    }

    public function markReviewed(Blog $blog, ContentAnalyzer $analyzer)
    {
        Gate::authorize('update', $blog->project);
        // An editorial review does not change the public article modification date.
        Blog::withoutTimestamps(fn () => Blog::whereKey($blog->id)->update(['content_reviewed_at' => now()]));
        $blog->refresh();
        $analyzer->capture($blog);
        return back()->with('success', 'Content marked as reviewed. Article text and public modification date are unchanged.');
    }
}
