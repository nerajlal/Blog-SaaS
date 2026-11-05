<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Links\LinkGraph;
use Illuminate\Console\Command;

class IndexLinks extends Command
{
    protected $signature = 'seo:links {--project= : Verified project ID}';
    protected $description = 'Build a project link graph in bounded batches without making external HTTP requests';
    public function handle(LinkGraph $graph): int
    {
        $id = $this->option('project');
        if (!ctype_digit((string) $id) || !($project = Project::find($id)) || !$project->seo_verified_at) { $this->error('Provide a verified project ID.'); return self::FAILURE; }
        $run = $graph->start($project);
        do { $run = $graph->step($run); } while ($run->status === 'building');
        $this->info('Graph '.$run->status.': '.$run->processed.' stories; '.$run->omitted.' omitted/unsupported entries.');
        return in_array($run->status, ['complete', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }
}
