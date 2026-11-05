<?php
namespace App\Services\Links;
use App\Models\{Blog, Project, SeoChangeBatch};
use App\Services\Seo\{ContentAnalyzer, SeoInput};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

class MetadataBatch
{
    public const FIELDS = ['title','description','focus_keyphrase','robots_index','robots_follow'];
    public function __construct(private ContentAnalyzer $analyzer) {}
    public function preview(Project $project, array $rows, int $user, string $reason): SeoChangeBatch
    {
        $changes = []; $seen = [];
        foreach ($rows as $row) {
            $id = $row['blog_id'] ?? '';
            if (!ctype_digit((string) $id) || isset($seen[$id])) throw ValidationException::withMessages(['csv' => 'Each row must have a distinct project blog ID.']);
            $seen[$id] = true;
            $blog = $project->blogs()->findOrFail($id); $seo = [];
            foreach (self::FIELDS as $field) $seo[$field] = $row[$field] === '' ? null : $row[$field];
            Validator::make(['seo' => $seo], SeoInput::blogRules())->validate();
            $before = $blog->seo_settings ?? []; $after = array_merge($before, $seo);
            $changes[] = ['id' => $blog->id, 'title' => $blog->title, 'before' => $before, 'after' => $after];
        }
        return SeoChangeBatch::create(['project_id' => $project->id, 'user_id' => $user, 'type' => 'metadata', 'changes' => $changes, 'reason' => $reason, 'context_hash' => $this->analyzer->contextHash($project->fresh()), 'expires_at' => now()->addHour()]);
    }
    public function commit(Project $project, SeoChangeBatch $batch, bool $undo, int $user): void
    {
        DB::transaction(function () use ($project,$batch,$undo,$user) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $batch = SeoChangeBatch::whereKey($batch->id)->where('project_id',$project->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->type === 'metadata' && $batch->state === ($undo ? 'applied':'preview'),409);
            abort_if(!$undo && ($batch->expires_at->isPast() || !hash_equals($batch->context_hash,$this->analyzer->contextHash($project))),409,'Publication changed or preview expired. Create a fresh preview.');
            $blogs = [];
            foreach ($batch->changes as $change) {
                $blog = $project->blogs()->whereKey($change['id'])->lockForUpdate()->first();
                abort_unless($blog && LinkState::same($blog->seo_settings ?? [], $change[$undo ? 'after':'before']),409,'A story moved or its metadata changed. Undo would overwrite newer work.');
                $blogs[] = [$blog,$change[$undo ? 'before':'after']];
            }
            foreach ($blogs as [$blog,$settings]) { $blog->update(['seo_settings'=>$settings]); }
            // Capture only after all metadata changes so reports share the final project context.
            foreach ($blogs as [$blog]) $this->analyzer->capture($blog->fresh());
            $batch->update($undo ? ['state'=>'reverted','reverted_at'=>now(),'reverted_by'=>$user] : ['state'=>'applied','applied_at'=>now()]);
        });
    }
}
