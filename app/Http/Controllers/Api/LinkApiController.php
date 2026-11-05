<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Project, SeoNotFound};
use App\Services\Links\{RedirectManager, UrlTools};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LinkApiController extends Controller
{
    public function resolve(Request $request, RedirectManager $manager)
    {
        $key = $request->header('X-API-KEY');
        abort_unless(is_string($key) && $key !== '', 401);
        $project = Project::where('api_key', $key)->first(); abort_unless($project, 401);
        $data = $request->validate(['path' => 'required|string|max:2000']);
        try { $redirect = $manager->lookup($project, $data['path']); }
        catch (\InvalidArgumentException) { abort(422, 'Invalid path.'); }
        return response()->json(['redirect' => $redirect])->header('Cache-Control', 'no-store');
    }

    public function notFound(Request $request, UrlTools $urls)
    {
        $key = $request->header('X-Telemetry-Key');
        abort_unless(is_string($key) && strlen($key) >= 32 && strlen($key) <= 128, 401);
        $project = Project::where('telemetry_key_hash', hash('sha256', $key))->first(); abort_unless($project, 401);
        abort_unless($project->seo_verified_at && !empty($project->seo_settings['public_url']), 409);
        $data = $request->validate(['origin' => 'required|string|max:255', 'path' => 'required|string|max:2000', 'event_id' => 'required|uuid', 'referrer' => 'nullable|string|max:2048']);
        try {
            $origin = $urls->origin($project->seo_settings['public_url']);
            abort_unless($data['origin'] === $origin, 422, 'Origin does not match the verified project.');
            $path = $urls->path($data['path'], true); $referrer = null;
            if (!empty($data['referrer'])) {
                // Keep only origin and path: no queries, fragments, credentials, IPs or user agents.
                try { $ref = $urls->url($data['referrer']); $referrer = $urls->origin($ref).$urls->path(parse_url($ref, PHP_URL_PATH) ?: '/'); }
                catch (\InvalidArgumentException) { $referrer = null; }
            }
        } catch (\InvalidArgumentException) { abort(422, 'Invalid path or origin.'); }
        DB::transaction(function () use ($project, $data, $origin, $path, $referrer) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seo_verified_at && $locked->telemetry_key_hash === $project->telemetry_key_hash && ($locked->seo_settings['public_url'] ?? '') === ($project->seo_settings['public_url'] ?? ''), 409);
            if (DB::table('seo_404_receipts')->where('project_id', $project->id)->where('event_id', $data['event_id'])->exists()) return;
            abort_if(DB::table('seo_404_receipts')->where('project_id', $project->id)->where('created_at', '>=', now()->startOfDay())->count() >= 10000, 429);
            $event = SeoNotFound::firstOrNew(['project_id' => $project->id, 'origin_hash' => hash('sha256', $origin), 'path_hash' => hash('sha256', $path), 'day' => now()->toDateString()]);
            abort_if(!$event->exists && SeoNotFound::where('project_id', $project->id)->where('day', now()->toDateString())->count() >= 1000, 429);
            DB::table('seo_404_receipts')->insert(['project_id' => $project->id, 'event_id' => $data['event_id'], 'created_at' => now()]);
            $event->fill(['path' => $path, 'hits' => ($event->hits ?? 0) + 1, 'referrer' => $referrer, 'last_seen' => now()])->save();
        });
        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
