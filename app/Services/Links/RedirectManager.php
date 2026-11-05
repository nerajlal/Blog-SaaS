<?php

namespace App\Services\Links;

use App\Models\{Project, SeoRedirect, SeoChangeBatch};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedirectManager
{
    public function __construct(private UrlTools $urls, private LinkGraph $graph) {}

    public function origin(Project $project): string
    {
        abort_unless($project->seo_verified_at && !empty($project->seo_settings['public_url']), 409, 'Verify the public domain first.');
        return $this->urls->origin($project->seo_settings['public_url']);
    }

    public function normalize(array $data): array
    {
        try {
            $source = $this->urls->path($data['source_path']);
            $code = (int) $data['status_code'];
            if (!in_array($code, [301,302,307,308,410], true)) throw new \InvalidArgumentException('Unsupported status.');
            $target = $code === 410 ? null : $this->urls->path($data['target_path'] ?? '');
            if ($code === 410 && !empty($data['target_path'])) throw new \InvalidArgumentException('A 410 response must have no target.');
            if ($target === $source) throw new \InvalidArgumentException('Source and destination must differ.');
            if ($source === '/' || $source === '/robots.txt' || preg_match('~sitemap.*\.xml$~i', $source)) throw new \InvalidArgumentException('The home page, robots.txt and sitemaps are reserved.');
            return ['source_path' => $source, 'target_path' => $target, 'status_code' => $code, 'enabled' => true];
        } catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['redirects' => $e->getMessage()]); }
    }

    public function validateSet(Project $project, array $rows): void
    {
        if (count($rows) > 1000) throw ValidationException::withMessages(['redirects' => 'At most 1,000 redirects per origin.']);
        $origin = $this->origin($project); $sources = [];
        foreach ($rows as $row) if ($row['enabled']) $sources[$row['source_path']] = true;
        foreach ($rows as $row) {
            if (!$row['enabled']) continue;
            $live = $this->graph->target($project, $origin.$row['source_path']);
            if ($live && $project->blogs()->live()->whereKey($live->id)->exists()) throw ValidationException::withMessages(['redirects' => 'A redirect cannot replace a live article.']);
            if ($row['target_path'] && isset($sources[$row['target_path']])) throw ValidationException::withMessages(['redirects' => 'Redirect chains and cycles are not supported. Point directly to the final destination.']);
        }
    }

    public function rows(Project $project): array
    {
        $rows = SeoRedirect::where('project_id', $project->id)->where('origin_hash', hash('sha256', $this->origin($project)))->get()->map(fn ($r) => $r->only(['source_path','target_path','status_code','enabled']))->all();
        usort($rows, fn ($a, $b) => strcmp($a['source_path'], $b['source_path']));
        return $rows;
    }

    public function preview(Project $project, array $incoming, int $userId, string $reason): SeoChangeBatch
    {
        $before = $this->rows($project); $map = array_column($before, null, 'source_path');
        foreach ($incoming as $data) { $row = $this->normalize($data); $map[$row['source_path']] = $row; }
        ksort($map); $after = array_values($map); $this->validateSet($project, $after);
        return SeoChangeBatch::create(['project_id' => $project->id, 'user_id' => $userId, 'type' => 'redirects', 'context_hash' => hash('sha256', $this->origin($project)), 'changes' => ['before' => $before, 'after' => $after], 'reason' => $reason, 'expires_at' => now()->addHour()]);
    }

    public function previewRemoval(Project $project, string $source, int $userId, string $reason): SeoChangeBatch
    {
        try { $source = $this->urls->path($source); } catch (\InvalidArgumentException) { abort(422); }
        $before = $this->rows($project);
        $after = array_values(array_filter($before, fn ($row) => $row['source_path'] !== $source));
        abort_unless(count($after) < count($before), 404);
        return SeoChangeBatch::create(['project_id'=>$project->id,'user_id'=>$userId,'type'=>'redirects','context_hash'=>hash('sha256',$this->origin($project)),'changes'=>['before'=>$before,'after'=>$after],'reason'=>$reason,'expires_at'=>now()->addHour()]);
    }

    public function commit(Project $project, SeoChangeBatch $batch, bool $undo, int $userId): void
    {
        DB::transaction(function () use ($project, $batch, $undo, $userId) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $batch = SeoChangeBatch::whereKey($batch->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->type === 'redirects' && $batch->state === ($undo ? 'applied' : 'preview'), 409);
            abort_unless(hash_equals($batch->context_hash, hash('sha256', $this->origin($project))), 409, 'Public origin changed.');
            abort_if(!$undo && $batch->expires_at->isPast(), 409, 'Preview expired.');
            $expected = $batch->changes[$undo ? 'after' : 'before'];
            abort_unless(LinkState::same($expected, $this->rows($project)), 409, 'Redirects changed after this preview. Create a fresh preview.');
            $rows = $batch->changes[$undo ? 'before' : 'after']; $this->validateSet($project, $rows);
            SeoRedirect::where('project_id', $project->id)->where('origin_hash', $batch->context_hash)->delete();
            foreach ($rows as $row) SeoRedirect::create($row + ['project_id' => $project->id, 'origin' => $this->origin($project), 'origin_hash' => $batch->context_hash, 'source_key' => hash('sha256', $row['source_path'])]);
            $batch->update($undo ? ['state' => 'reverted', 'reverted_at' => now(), 'reverted_by' => $userId] : ['state' => 'applied', 'applied_at' => now()]);
        });
    }

    public function lookup(Project $project, string $path): ?array
    {
        if (!$project->seo_verified_at || empty($project->seo_settings['public_url'])) return null;
        $origin = $this->origin($project);
        $path = $this->urls->path($path, true);
        $live = $this->graph->target($project, $origin.$path);
        if ($live && $project->blogs()->live()->whereKey($live->id)->exists()) return null;
        $row = SeoRedirect::where('project_id', $project->id)->where('origin_hash', hash('sha256', $origin))->where('source_key', hash('sha256', $path))->where('enabled', true)->first();
        return $row ? ['status' => $row->status_code, 'location' => $row->target_path] : null;
    }
}
