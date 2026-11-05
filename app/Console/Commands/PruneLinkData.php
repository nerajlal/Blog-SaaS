<?php
namespace App\Console\Commands;
use App\Models\{SeoLinkRun, SeoNotFound, SeoChangeBatch};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class PruneLinkData extends Command
{
    protected $signature = 'seo:prune-links';
    protected $description = 'Prune expired 404 events, deduplication receipts and obsolete link snapshots';
    public function handle(): int
    {
        SeoNotFound::where('day', '<', now()->subDays(90)->toDateString())->delete();
        DB::table('seo_404_receipts')->where('created_at', '<', now()->subDays(7))->delete();
        SeoChangeBatch::where('state', 'preview')->where('expires_at', '<', now())->update(['state' => 'expired']);
        // Keep the newest snapshot per project, including the current report.
        $latest = DB::query()->fromSub(DB::table('seo_link_runs')->selectRaw('MAX(id) AS keep_id')->groupBy('project_id'), 'kept')->select('keep_id');
        SeoLinkRun::whereNotIn('id', $latest)->where('updated_at', '<', now()->subDays(7))->where('status', '!=', 'building')->delete();
        SeoLinkRun::where('status', 'building')->where('updated_at', '<', now()->subDay())->update(['status' => 'failed']);
        $this->info('Expired link diagnostics pruned.'); return self::SUCCESS;
    }
}
