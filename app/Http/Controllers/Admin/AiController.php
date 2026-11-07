<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProfile;
use App\Models\AiProfileVersion;
use App\Models\AiRun;
use App\Models\Blog;
use App\Models\Project;
use App\Services\Ai\Claude;
use App\Services\Ai\WorkspaceAi;
use App\Services\Links\UrlTools;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiController extends Controller
{
    public const PROFILE_FIELDS = [
        'name' => 'Business name', 'website' => 'Website URL', 'business_type' => 'Business type',
        'summary' => 'Business description', 'products' => 'Products, services, and features',
        'audience' => 'Audience and region', 'positioning' => 'Brand story and differentiators',
        'facts' => 'Approved facts and policies (include source URLs)', 'tone' => 'Brand voice',
        'language' => 'Language and spelling', 'goals' => 'Content goals', 'boundaries' => 'Claims and topics to avoid',
        'destinations' => 'Approved links and public blog URL pattern', 'social_profiles' => 'Official social profiles',
    ];

    private function privateView(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store');
    }

    public function profile(Project $project, WorkspaceAi $ai)
    {
        Gate::authorize('view', $project);
        $profile = AiProfile::firstWhere('project_id', $project->id);
        $approved = $ai->approved($project);
        $settings = DB::table('ai_settings')->where('scope', $ai->scope($project))->first();
        $requests = DB::table('ai_usage_months')->where('scope', $ai->scope($project))->where('month', now()->format('Y-m'))->value('requests') ?? 0;

        return $this->privateView('admin.ai.profile', compact('project', 'profile', 'approved', 'settings', 'requests') + ['fields' => self::PROFILE_FIELDS, 'ready' => app(Claude::class)->ready()]);
    }

    public function saveProfile(Request $request, Project $project)
    {
        Gate::authorize('manageSeo', $project);
        $rules = ['revision' => 'required|integer|min:0'];
        foreach (self::PROFILE_FIELDS as $field => $label) {
            $rules['data.'.$field] = in_array($field, ['name', 'summary', 'audience']) ? 'required|string|max:4000' : 'nullable|string|max:4000';
        }
        $rules['data.website'] = 'nullable|url:http,https|max:2048';
        $values = $request->validate($rules);
        if (strlen(json_encode($values['data'])) > 18000) {
            throw ValidationException::withMessages(['data' => 'Keep the business profile under 18 KB. Select concise, relevant facts.']);
        }
        DB::transaction(function () use ($values, $project) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $profile = AiProfile::firstWhere('project_id', $project->id);
            abort_unless(($profile?->revision ?? 0) === (int) $values['revision'], 409, 'Profile changed in another tab. Reload before saving.');
            AiProfile::updateOrCreate(['project_id' => $project->id], ['data' => $values['data'], 'revision' => ($profile?->revision ?? 0) + 1]);
        });

        return back()->with('success', 'Project details saved. Approve this version before using these changes for AI.');
    }

    public function approve(Request $request, Project $project)
    {
        Gate::authorize('manageSeo', $project);
        $values = $request->validate(['revision' => 'required|integer|min:1', 'confirm' => 'accepted']);
        DB::transaction(function () use ($project, $values, $request) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $profile = AiProfile::where('project_id', $project->id)->firstOrFail();
            Validator::make($profile->data, ['name' => 'required|string', 'summary' => 'required|string', 'audience' => 'required|string'])->validate();
            abort_unless($profile->revision === (int) $values['revision'], 409, 'Profile changed. Reload and review the current details.');
            AiProfileVersion::firstOrCreate(['project_id' => $project->id, 'revision' => $profile->revision], ['approved_by' => $request->user()->id, 'data' => $profile->data]);
        });

        return back()->with('success', 'Profile approved. Existing posts and drafts were not changed.');
    }

    public function settings(Request $request, Project $project, WorkspaceAi $ai)
    {
        Gate::authorize('manageSeo', $project);
        $data = $request->validate(['enabled' => 'required|boolean', 'monthly_limit' => 'required|integer|min:1|max:'.max(1, config('ai.monthly_requests'))]);
        DB::table('ai_settings')->updateOrInsert(['scope' => $ai->scope($project)], $data + ['updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Workspace AI allowance updated.');
    }

    public function studio(Request $request, Project $project, WorkspaceAi $ai)
    {
        Gate::authorize('view', $project);
        $blog = $request->filled('blog') ? $project->blogs()->findOrFail($request->input('blog')) : null;
        $runs = AiRun::where('project_id', $project->id)->when($blog, fn ($q) => $q->where('blog_id', $blog->id))->latest('id')->paginate(12)->withQueryString();
        $approved = $ai->approved($project);

        return $this->privateView('admin.ai.studio', compact('project', 'blog', 'runs', 'approved') + ['ready' => app(Claude::class)->ready()]);
    }

    public function generate(Request $request, Project $project, WorkspaceAi $ai)
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'action' => ['required', Rule::in(['analyze', 'outline', 'draft', 'social', 'titles', 'metadata', 'rewrite'])],
            'request_key' => 'required|uuid', 'blog_id' => 'nullable|integer', 'parent_id' => 'nullable|integer',
            'brief' => 'nullable|string|max:4000', 'selected_text' => 'nullable|string|max:8000',
            'platform' => ['nullable', Rule::in(['linkedin', 'reddit', 'facebook', 'instagram'])],
            'preferences' => 'nullable|string|max:2000', 'community_rules' => 'nullable|string|max:4000',
            'public_url' => 'nullable|url:http,https|max:2048', 'urls' => 'nullable|string|max:6200',
            'source_text' => 'nullable|string|max:12000', 'consent' => 'accepted',
        ]);
        $blog = ! empty($data['blog_id']) ? $project->blogs()->findOrFail($data['blog_id']) : null;
        $input = array_intersect_key($data, array_flip(['brief', 'selected_text', 'preferences', 'community_rules']));
        if ($data['action'] === 'analyze') {
            Gate::authorize('manageSeo', $project);
            $urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $data['urls'] ?? '')))));
            if (count($urls) > 3) {
                throw ValidationException::withMessages(['urls' => 'Select at most three pages.']);
            }
            foreach ($urls as &$url) {
                try {
                    $url = app(UrlTools::class)->url($url);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['urls' => 'Use public HTTP(S) website URLs on standard ports.']);
                }
            }
            unset($url);
            if ($urls && count(array_unique(array_map(fn ($u) => app(UrlTools::class)->origin($u), $urls))) !== 1) {
                throw ValidationException::withMessages(['urls' => 'All pages must use the same website origin.']);
            }
            if (! $urls && empty($data['source_text'])) {
                throw ValidationException::withMessages(['source_text' => 'Select website pages or paste source text.']);
            }
            $input['profile_revision'] = AiProfile::where('project_id', $project->id)->value('revision') ?? 0;
            $input['urls'] = $urls;
            $input['sources'] = empty($data['source_text']) ? [] : [['id' => 'manual-1', 'text' => $data['source_text'], 'provided_at' => now()->toIso8601String()]];
        } elseif ($data['action'] === 'draft') {
            $parent = AiRun::where('project_id', $project->id)->where('action', 'outline')->where('state', 'complete')->findOrFail($data['parent_id'] ?? 0);
            abort_if($ai->stale($parent) || ! $parent->reviewed_at, 409, 'Review a current outline first.');
            $input['approved_outline'] = $parent->edited ?? $parent->result;
            $input['outline_profile_version_id'] = $parent->profile_version_id;
            if ($parent->blog_id) {
                $blog = $project->blogs()->findOrFail($parent->blog_id);
            }
        } elseif ($data['action'] === 'outline' && empty($data['brief'])) {
            throw ValidationException::withMessages(['brief' => 'Enter a topic or writing brief.']);
        }
        if (in_array($data['action'], ['social', 'titles', 'metadata']) && ! $blog) {
            throw ValidationException::withMessages(['blog_id' => 'Choose a saved story first.']);
        }
        if ($data['action'] === 'rewrite' && empty($data['selected_text'])) {
            throw ValidationException::withMessages(['selected_text' => 'Paste the text to rewrite.']);
        }
        if ($data['action'] === 'social') {
            if (empty($data['platform'])) {
                throw ValidationException::withMessages(['platform' => 'Select a platform.']);
            }
            $input['public_url'] = '[BLOG_URL]';
            if (! empty($data['public_url'])) {
                abort_unless($blog->status === 'published' && (! $blog->published_at || $blog->published_at->isPast()), 422, 'Use a link placeholder until the article is published.');
                try {
                    $input['public_url'] = app(UrlTools::class)->url($data['public_url']);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['public_url' => 'Use a public article URL, not an admin or private URL.']);
                }
                if (preg_match('~/(admin|preview)(/|\?|$)~i', $input['public_url']) || parse_url($input['public_url'], PHP_URL_QUERY)) {
                    throw ValidationException::withMessages(['public_url' => 'Use the clean public article URL without admin paths, query strings, or preview tokens.']);
                }
            }
        }
        $run = $ai->enqueue($project, $request->user()->id, $data['action'], $input, $data['request_key'], $blog, $data['platform'] ?? null);

        return redirect()->route('admin.ai.run', [$project, $run]);
    }

    private function authorized(Project $project, AiRun $run, string $ability = 'view'): void
    {
        abort_unless($run->project_id === $project->id, 404);
        Gate::authorize($ability, $project);
    }

    public function run(Project $project, AiRun $run, WorkspaceAi $ai)
    {
        $this->authorized($project, $run);

        return $this->privateView('admin.ai.run', compact('project', 'run') + ['stale' => $ai->stale($run), 'fields' => Claude::fields($run->action)]);
    }

    public function status(Project $project, AiRun $run)
    {
        $this->authorized($project, $run);

        return response()->json(['state' => $run->state])->header('Cache-Control', 'private, no-store');
    }

    public function editResult(Request $request, Project $project, AiRun $run, WorkspaceAi $ai)
    {
        $this->authorized($project, $run, $run->action === 'analyze' ? 'manageSeo' : 'update');
        abort_unless(is_array($request->input('result')), 422);
        $request->merge(['result' => array_map(fn ($value) => $value ?? '', $request->input('result', []))]);
        $rules = array_combine(array_map(fn ($key) => 'result.'.$key, array_keys(Claude::fields($run->action))), array_values(Claude::fields($run->action)));
        $data = $request->validate($rules + ['revision' => 'required|integer|min:1', 'reviewed' => 'nullable|boolean']);
        DB::transaction(function () use ($data, $run, $ai) {
            $current = AiRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($current->state === 'complete' && $current->revision === (int) $data['revision'], 409, 'Result changed in another tab. Reload before saving.');
            abort_if(! empty($data['reviewed']) && $ai->stale($current), 409, 'Source changed. Generate and review a current version.');
            $result = $data['result'];
            if (isset($result['body_html'])) {
                $result['body_html'] = RichText::clean($result['body_html']);
            }
            $current->update(['edited' => $result, 'reviewed_at' => ! empty($data['reviewed']) ? now() : null, 'revision' => $current->revision + 1]);
        });

        return back()->with('success', 'Suggestion saved. No post was published.');
    }

    public function saveDraft(Request $request, Project $project, AiRun $run, WorkspaceAi $ai)
    {
        $this->authorized($project, $run, 'update');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'category_id' => ['required', Rule::exists('categories', 'id')->where('project_id', $project->id)], 'confirm' => 'accepted']);
        $blog = DB::transaction(function () use ($project, $run, $ai, $data, $request) {
            $current = AiRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($current->action === 'draft' && $current->state === 'complete', 422);
            if ($current->output_blog_id) {
                return Blog::findOrFail($current->output_blog_id);
            }
            abort_unless($current->revision === (int) $data['revision'] && $current->reviewed_at && ! $ai->stale($current), 409, 'Review and save the current draft before creating a story.');
            $result = $current->edited ?? $current->result;
            $slug = (Str::slug($result['title']) ?: 'story').'-'.Str::lower(Str::random(10));
            $blog = $project->blogs()->create(['user_id' => $request->user()->id, 'title' => $result['title'], 'slug' => $slug, 'excerpt' => $result['excerpt'], 'content' => '', 'category_id' => $data['category_id'], 'status' => 'draft', 'seo_settings' => ['description' => $result['seo_description']]]);
            $blog->sections()->create(['text_content' => RichText::clean($result['body_html']), 'order' => 0]);
            $current->update(['output_blog_id' => $blog->id]);

            return $blog;
        });

        return redirect()->route('admin.blogs.edit', $blog);
    }

    public function adopt(Request $request, Project $project, AiRun $run)
    {
        $this->authorized($project, $run, 'manageSeo');
        $data = $request->validate(['fields' => 'required|array|min:1', 'fields.*' => [Rule::in(['summary', 'products', 'audience', 'tone', 'facts'])], 'confirm' => 'accepted']);
        DB::transaction(function () use ($run, $project, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $current = AiRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($current->action === 'analyze' && $current->state === 'complete' && $current->reviewed_at, 422, 'Save and review the analysis first.');
            $profile = AiProfile::firstWhere('project_id', $project->id);
            abort_unless(($profile?->revision ?? 0) === ($current->input['profile_revision'] ?? 0), 409, 'Project details changed since analysis. Copy the suggestions manually into the current profile.');
            $values = $profile?->data ?? ['name' => $project->name, 'summary' => '', 'audience' => ''];
            $result = $current->edited ?? $current->result;
            foreach ($data['fields'] as $field) {
                if (mb_strlen($result[$field]) > 4000) {
                    throw ValidationException::withMessages(['result' => 'Shorten selected suggestions to 4,000 characters per field before adopting them.']);
                }
                $values[$field] = $result[$field];
            }
            if (strlen(json_encode($values)) > 18000) {
                throw ValidationException::withMessages(['result' => 'The combined profile exceeds 18 KB. Shorten its details first.']);
            }
            AiProfile::updateOrCreate(['project_id' => $project->id], ['data' => $values, 'revision' => ($profile?->revision ?? 0) + 1]);
        });

        return redirect()->route('admin.ai.profile', $project)->with('success', 'Selected suggestions copied into draft details. Review sources, fill any gaps, then save and approve.');
    }

    public function cancel(Project $project, AiRun $run)
    {
        $this->authorized($project, $run, 'update');
        if ($run->action === 'analyze') {
            Gate::authorize('manageSeo', $project);
        }
        AiRun::whereKey($run->id)->whereIn('state', ['pending', 'running'])->update(['state' => 'cancelled', 'detail' => 'Cancelled locally. A provider request already submitted may still incur charges.']);

        return back();
    }

    public function export(Project $project, AiRun $run)
    {
        $this->authorized($project, $run);
        abort_unless($run->state === 'complete', 422);
        $text = 'Project: '.$project->name."\nTask: ".$run->action.' '.($run->platform ?? '')."\nReview status: ".($run->reviewed_at ? 'Reviewed' : 'Needs review')."\n";
        if (app(WorkspaceAi::class)->stale($run)) {
            $text .= "STALE: source article or profile changed.\n";
        }
        foreach ($run->edited ?? $run->result as $key => $value) {
            $text .= "\n".Str::headline($key)."\n".strip_tags($value)."\n";
        }

        return response($text)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="ai-content-'.$run->id.'.txt"')->header('Cache-Control', 'private, no-store');
    }
}
