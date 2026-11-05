<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Project, SeoChangeBatch};
use App\Services\Links\RedirectManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RedirectController extends Controller
{
    public function index(Project $project, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project);
        $rows = $project->seo_verified_at ? $manager->rows($project) : [];
        $batches = SeoChangeBatch::where('project_id', $project->id)->where('type', 'redirects')->latest('id')->limit(20)->get();
        return response()->view('admin.seo.redirects', compact('project', 'rows', 'batches'))->header('Cache-Control', 'private, no-store');
    }
    public function preview(Request $request, Project $project, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500', 'source_path' => 'required_without:csv|nullable|string|max:1000', 'target_path' => 'nullable|string|max:1000', 'status_code' => 'required_without:csv|nullable|integer', 'csv' => 'nullable|file|max:128']);
        $rows = [];
        if ($request->hasFile('csv')) {
            $handle = fopen($request->file('csv')->getRealPath(), 'r');
            try {
                if (fgetcsv($handle, 0, ',', '"', '') !== ['source_path','target_path','status_code']) throw ValidationException::withMessages(['csv' => 'Required CSV header: source_path,target_path,status_code']);
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (count($row) !== 3 || count($rows) >= 100) throw ValidationException::withMessages(['csv' => 'CSV must contain 1–100 rows, each with exactly three columns.']);
                    $rows[] = array_combine(['source_path','target_path','status_code'], $row);
                }
            } finally { fclose($handle); }
            if (!$rows) throw ValidationException::withMessages(['csv' => 'CSV is empty.']);
        } else $rows[] = $data;
        $manager->preview($project, $rows, $request->user()->id, $data['reason']);
        return back()->with('success', 'Preview created. Review the resulting rules below before applying.');
    }
    public function remove(Request $request, Project $project, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project);
        $data=$request->validate(['source_path'=>'required|string|max:1000','reason'=>'required|string|min:5|max:500']);
        $manager->previewRemoval($project,$data['source_path'],$request->user()->id,$data['reason']);
        return back()->with('success','Removal preview created. Review it before applying.');
    }
    public function apply(Request $request, Project $project, SeoChangeBatch $batch, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project); abort_unless($batch->project_id === $project->id, 404);
        $manager->commit($project, $batch, false, $request->user()->id);
        return back()->with('success', 'Redirect rules applied. Your consumer must use the redirect lookup API.');
    }
    public function undo(Request $request, Project $project, SeoChangeBatch $batch, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project); abort_unless($batch->project_id === $project->id, 404);
        $manager->commit($project, $batch, true, $request->user()->id);
        return back()->with('success', 'Redirect change reverted.');
    }
    public function export(Project $project, RedirectManager $manager)
    {
        Gate::authorize('manageSeo', $project); $rows = $manager->rows($project);
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w'); fputcsv($out, ['source_path','target_path','status_code'], ',', '"', '');
            foreach ($rows as $row) fputcsv($out, [$row['source_path'], $row['target_path'], $row['status_code']], ',', '"', '');
            fclose($out);
        }, 'redirects.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }
}
