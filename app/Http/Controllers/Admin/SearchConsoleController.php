<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FetchSearchReport;
use App\Jobs\InspectSearchUrl;
use App\Models\Project;
use App\Models\SearchConnection;
use App\Models\SearchInspection;
use App\Models\SearchReport;
use App\Services\SearchConsole\GoogleSearch;
use App\Services\Seo\SeoResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SearchConsoleController extends Controller
{
    public function index(Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $configured = $google->configured();
        $connection = SearchConnection::where('project_id', $project->id)->first();
        $current = $connection && $project->seo_verified_at && $connection->origin === ($project->seo_settings['public_url'] ?? '') && $connection->state === 'connected';
        $reports = $current ? SearchReport::where('project_id', $project->id)->where('connection_id', $connection->id)->where('origin', $connection->origin)->where('property', $connection->property)->latest('id')->limit(12)->get() : collect();
        $report = $reports->firstWhere('state', 'complete');
        $rows = collect($report?->rows ?? [])->sortBy('date');
        $impressions = $rows->sum('impressions');
        $totals = ['clicks' => $rows->sum('clicks'), 'impressions' => $impressions, 'ctr' => $impressions ? $rows->sum('clicks') / $impressions : null, 'position' => $impressions ? $rows->sum(fn ($r) => $r['position'] * $r['impressions']) / $impressions : null];
        $stories = $current ? $project->blogs()->live()->latest('id')->limit(100)->get(['id', 'title']) : collect();
        $inspections = $current ? SearchInspection::where('project_id', $project->id)->where('connection_id', $connection->id)->where('property', $connection->property)->whereHas('blog', fn ($q) => $q->where('project_id', $project->id))->latest('id')->limit(20)->get() : collect();

        return response()->view('admin.seo.search-console', compact('project', 'configured', 'connection', 'current', 'reports', 'report', 'rows', 'totals', 'stories', 'inspections'))->header('Cache-Control', 'private, no-store');
    }

    public function connect(Request $request, Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        abort_unless($google->configured(), 409, 'Configure the Google OAuth client first.');
        $origin = $google->origin($project);
        $state = Str::random(64);
        $request->session()->put('search_oauth', ['state' => hash('sha256', $state), 'project' => $project->id, 'user' => $request->user()->id, 'origin' => $origin, 'expires' => now()->addMinutes(10)->timestamp]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => config('search_console.client_id'), 'redirect_uri' => config('search_console.redirect_uri'), 'response_type' => 'code', 'scope' => GoogleSearch::SCOPE, 'state' => $state, 'access_type' => 'offline', 'prompt' => 'consent']));
    }

    public function callback(Request $request, GoogleSearch $google)
    {
        $state = $request->session()->pull('search_oauth');
        $provided = $request->query('state');
        abort_unless(is_array($state) && is_string($provided) && strlen($provided) <= 128 && hash_equals($state['state'], hash('sha256', $provided)) && $state['expires'] >= now()->timestamp && $state['user'] === $request->user()->id, 403, 'OAuth session expired or invalid. Start again.');
        $project = Project::findOrFail($state['project']);
        Gate::authorize('manageSeo', $project);
        abort_unless($google->origin($project) === $state['origin'], 409, 'The verified origin changed. Start again.');
        if ($request->has('error')) {
            return redirect()->route('admin.search.index', $project)->withErrors(['google' => 'Google authorization was declined. No connection changed.']);
        }
        $data = $request->validate(['code' => 'required|string|max:4096']);
        try {
            $tokens = $google->exchange($data['code']);
        } catch (ValidationException $e) {
            return redirect()->route('admin.search.index', $project)->withErrors($e->errors());
        }
        abort_unless(! empty($tokens['refresh_token']), 422, 'Google did not issue offline access. Revoke the prior app grant in Google and reconnect.');
        DB::transaction(function () use ($project, $state, $tokens, $request) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seo_verified_at && ($locked->seo_settings['public_url'] ?? '') === $state['origin'], 409);
            $existing = SearchConnection::where('project_id', $project->id)->first();
            if ($existing) {
                $existing->delete();
            }
            SearchConnection::create(['project_id' => $project->id, 'user_id' => $request->user()->id, 'origin' => $state['origin'], 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'expires_at' => now()->addSeconds(max(60, min(86400, (int) ($tokens['expires_in'] ?? 3600)))), 'state' => 'connected', 'health' => 'Connected. Select a property to begin.']);
        });

        return redirect()->route('admin.search.index', $project)->with('success', 'Google connected. Load the accessible matching properties next.');
    }

    public function properties(Request $request, Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $connection = $google->active($project);
        $properties = $google->properties($connection);
        $request->session()->flash('search_properties', ['project' => $project->id, 'connection' => $connection->id, 'items' => $properties]);
        $connection->update(['checked_at' => now(), 'health' => count($properties) ? 'Google access checked; matching properties found.' : 'Google returned no accessible matching root/domain properties.']);

        return back();
    }

    public function select(Request $request, Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $data = $request->validate(['property' => 'required|string|max:512']);
        $connection = $google->active($project);
        $allowed = $google->properties($connection);
        abort_unless(collect($allowed)->contains(fn ($site) => $site['siteUrl'] === $data['property']), 422, 'Choose an accessible property covering this origin.');
        $connection->update(['property' => $data['property'], 'checked_at' => now(), 'health' => 'Property selected. Request a performance report.']);

        return back()->with('success', 'Search Console property selected.');
    }

    public function fetch(Request $request, Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $connection = $google->active($project);
        abort_unless($connection->property, 409, 'Select a property first.');
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date']);
        $start = CarbonImmutable::parse($data['start_date'], 'America/Los_Angeles');
        $end = CarbonImmutable::parse($data['end_date'], 'America/Los_Angeles');
        abort_unless($start->diffInDays($end) < 90 && $start->gte(now('America/Los_Angeles')->subMonths(16)->startOfDay()) && $end->lte(now('America/Los_Angeles')->subDays(3)->startOfDay()), 422, 'Choose up to 90 days within the last 16 months, ending at least three days ago.');
        $report = DB::transaction(function () use ($project, $connection, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_if(SearchReport::where('project_id', $project->id)->where('created_at', '>', now()->subHour())->exists(), 429, 'One performance fetch per project per hour.');

            return SearchReport::create(['project_id' => $project->id, 'connection_id' => $connection->id, 'property' => $connection->property, 'origin' => $connection->origin] + $data);
        });
        FetchSearchReport::dispatch($report->id)->afterCommit();

        return back()->with('success', 'Google performance fetch queued. Reload after the SEO worker finishes.');
    }

    public function inspect(Request $request, Project $project, GoogleSearch $google, SeoResolver $resolver)
    {
        Gate::authorize('manageSeo', $project);
        $connection = $google->active($project);
        abort_unless($connection->property, 409, 'Select a property first.');
        $data = $request->validate(['blog_id' => 'required|integer']);
        $blog = $project->blogs()->live()->findOrFail($data['blog_id']);
        $blog->setRelation('project', $project);
        $url = $resolver->articleUrl($blog);
        abort_unless($url, 409);
        $item = DB::transaction(function () use ($project, $connection, $blog, $url) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $query = SearchInspection::where('project_id', $project->id)->where('created_at', '>=', now()->subDay());
            abort_if((clone $query)->count() >= 100 || (clone $query)->where('blog_id', $blog->id)->exists(), 429, 'One inspection per story per 24 hours, maximum 100 per project per 24 hours.');

            return SearchInspection::create(['project_id' => $project->id, 'connection_id' => $connection->id, 'blog_id' => $blog->id, 'url' => $url, 'property' => $connection->property]);
        });
        InspectSearchUrl::dispatch($item->id)->afterCommit();

        return back()->with('success', 'Indexed-version inspection queued. This does not submit a URL for indexing.');
    }

    public function disconnect(Request $request, Project $project, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $connection = DB::transaction(function () use ($project) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $connection = SearchConnection::where('project_id', $project->id)->first();
            if ($connection) {
                $connection->delete();
            }

return $connection;
        });
        $request->session()->forget(['search_oauth', 'search_properties']);
        $revoked = ! $connection || $google->revoke($connection);

        return back()->with('success', $revoked ? 'Disconnected. Stored tokens and reports deleted; Google revocation requested.' : 'Disconnected locally; stored tokens and reports deleted. Google revocation failed. Remove the app grant from your Google account.');
    }

    public function export(Project $project, SearchReport $report, GoogleSearch $google)
    {
        Gate::authorize('manageSeo', $project);
        $connection = $google->active($project);
        abort_unless($report->project_id === $project->id && $report->connection_id === $connection->id && $report->property === $connection->property && $report->origin === $connection->origin && $report->state === 'complete', 404);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['source', 'property', 'origin', 'start_date', 'end_date', 'fetched_at', 'date', 'clicks', 'impressions', 'ctr', 'position'], ',', '"', '');
            foreach ($report->rows ?? [] as $row) {
                fputcsv($out,array_merge(['Google Search Console', $report->property, $report->origin, $report->start_date, $report->end_date, $report->fetched_at?->toIso8601String()],array_map(fn ($field) => $row[$field] ?? '', ['date', 'clicks', 'impressions', 'ctr', 'position'])),',','"','');
            }fclose($out);
        }, 'search-report-'.$report->id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }
}
