<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\{Project, SeoNotFound};
use App\Services\Links\UrlTools;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
class TelemetryController extends Controller
{
    public function index(Project $project, UrlTools $urls)
    {
        Gate::authorize('manageSeo', $project);
        $hash = $project->seo_verified_at ? hash('sha256', $urls->origin($project->seo_settings['public_url'])) : '';
        $events = SeoNotFound::where('project_id', $project->id)->where('origin_hash', $hash)->where('day', '>=', now()->subDays(90)->toDateString())->latest('last_seen')->paginate(30);
        return response()->view('admin.seo.not-found', compact('project', 'events'))->header('Cache-Control', 'private, no-store');
    }
    public function rotate(Project $project)
    {
        Gate::authorize('manageSeo', $project);
        $key = 'st_'.Str::random(64); $project->forceFill(['telemetry_key_hash' => hash('sha256', $key)])->save();
        return back()->with('telemetry_key', $key)->with('success', 'Server-only telemetry key rotated. Copy it now; it will not be shown again.');
    }
}
