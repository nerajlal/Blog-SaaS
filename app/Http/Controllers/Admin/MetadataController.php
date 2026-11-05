<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\{Project, SeoChangeBatch};
use App\Services\Links\MetadataBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
class MetadataController extends Controller
{
    public function index(Project $project)
    {
        Gate::authorize('manageSeo',$project);
        $batches=SeoChangeBatch::where('project_id',$project->id)->where('type','metadata')->latest('id')->limit(20)->get();
        return response()->view('admin.seo.metadata',compact('project','batches'))->header('Cache-Control','private, no-store');
    }
    public function preview(Request $request, Project $project, MetadataBatch $manager)
    {
        Gate::authorize('manageSeo',$project);
        $data=$request->validate(['csv'=>'required|file|max:128','reason'=>'required|string|min:5|max:500']);
        $handle=fopen($request->file('csv')->getRealPath(),'r'); $rows=[];
        try {
            $header=array_merge(['blog_id'],MetadataBatch::FIELDS);
            if(fgetcsv($handle,0,',','"','')!==$header) throw ValidationException::withMessages(['csv'=>'Use the exact header from an exported CSV.']);
            while(($row=fgetcsv($handle,0,',','"',''))!==false) {
                if(count($row)!==count($header)||count($rows)>=100) throw ValidationException::withMessages(['csv'=>'Use 1–100 rows with the exact number of columns.']);
                // Export escapes formula-like text using an apostrophe; remove only that escape on import.
                foreach($row as &$value) if(preg_match("/^'[=+@\\-\\t\\r]/",$value)) $value=substr($value,1);
                unset($value); $rows[]=array_combine($header,$row);
            }
        } finally {fclose($handle);}
        if(!$rows) throw ValidationException::withMessages(['csv'=>'CSV is empty.']);
        $manager->preview($project,$rows,$request->user()->id,$data['reason']);
        return back()->with('success','Metadata preview created. No story changed yet.');
    }
    public function apply(Request $request,Project $project,SeoChangeBatch $batch,MetadataBatch $manager)
    {
        Gate::authorize('manageSeo',$project);abort_unless($batch->project_id===$project->id,404);
        $manager->commit($project,$batch,false,$request->user()->id);return back()->with('success','Reviewed metadata applied.');
    }
    public function undo(Request $request,Project $project,SeoChangeBatch $batch,MetadataBatch $manager)
    {
        Gate::authorize('manageSeo',$project);abort_unless($batch->project_id===$project->id,404);
        $manager->commit($project,$batch,true,$request->user()->id);return back()->with('success','Metadata change reverted.');
    }
    public function export(Request $request,Project $project)
    {
        Gate::authorize('manageSeo',$project);$data=$request->validate(['page'=>'sometimes|integer|min:1']);
        $blogs=$project->blogs()->orderBy('id')->paginate(100);
        return response()->streamDownload(function() use($blogs){
            $out=fopen('php://output','w');fputcsv($out,array_merge(['blog_id'],MetadataBatch::FIELDS),',','"','');
            foreach($blogs as $blog){$row=[$blog->id];foreach(MetadataBatch::FIELDS as $field){$value=(string)($blog->seo_settings[$field]??'');$row[]=preg_match('/^[=+@\\-\\t\\r]/',$value)?"'".$value:$value;}fputcsv($out,$row,',','"','');}fclose($out);
        },'metadata-page-'.$blogs->currentPage().'.csv',['Content-Type'=>'text/csv','Cache-Control'=>'private, no-store','X-Export-Pages'=>(string)$blogs->lastPage()]);
    }
}
