<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Rules\PublicHttpsUrl;
use App\Services\Seo\DomainVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectSeoController extends Controller
{
    public function edit(Project $project)
    {
        Gate::authorize('manageSeo', $project);
        return view('admin.seo.project', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        Gate::authorize('manageSeo', $project);
        $settings = $request->validate([
            'public_url' => ['nullable', 'string', 'max:255', new PublicHttpsUrl(true)],
            'article_path' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (substr_count($value, '{slug}') !== 1 || !preg_match('~^/(?:[a-zA-Z0-9_-]+/)*\{slug\}/?$~', $value)) {
                    $fail('Use an article path containing exactly one {slug}, for example /blog/{slug}.');
                }
            }],
            'site_name' => 'required|string|max:120',
            'title_template' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (strip_tags($value) !== $value || !str_contains($value, '{title}') || preg_match('/[{}]/', str_replace(['{title}', '{site_name}'], '', $value))) {
                    $fail('Include {title}; the only other supported variable is {site_name}.');
                }
            }],
            'description' => 'nullable|string|max:500',
            'locale' => ['required', 'string', 'regex:/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', 'max:35'],
            'publisher_type' => ['required', Rule::in(['Organization', 'Person'])],
            'publisher_name' => 'nullable|string|max:120',
            'publisher_logo_url' => ['nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'social_image_url' => ['nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'media_url' => ['nullable', 'string', 'max:255', new PublicHttpsUrl(true)],
            'index' => 'required|boolean',
            'follow' => 'required|boolean',
        ]);
        if (!empty($settings['public_url'])) {
            $settings['public_url'] = strtolower(rtrim($settings['public_url'], '/'));
        }
        $settings['index'] = $request->boolean('index');
        $settings['follow'] = $request->boolean('follow');
        if (($project->seo_settings['public_url'] ?? null) !== ($settings['public_url'] ?? null) || !$project->seo_domain_token) {
            $project->seo_domain_token = Str::random(48);
            $project->seo_verified_at = null;
        }
        $project->seo_settings = $settings;
        $project->save();
        return back()->with('success', 'Project SEO settings saved. Verify a new public domain before publishing canonical URLs and sitemaps.');
    }

    public function verify(Project $project, DomainVerification $verification)
    {
        Gate::authorize('manageSeo', $project);
        if (!$verification->matches($project)) {
            return back()->withErrors(['domain' => 'The verification TXT record was not found. Check the exact name/value and allow time for DNS propagation.']);
        }
        $updated = Project::whereKey($project->id)->where('seo_domain_token', $project->seo_domain_token)->update(['seo_verified_at' => now()]);
        abort_unless($updated, 409, 'The domain settings changed. Retry verification.');
        return back()->with('success', 'Public domain verified. Canonical URLs, structured data, and sitemap output are enabled.');
    }
}
