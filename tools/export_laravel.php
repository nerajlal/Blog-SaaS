<?php
// Run manually from the repository root. This reads Laravel data without changing it.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($argc !== 2 || file_exists($argv[1])) { fwrite(STDERR, "Provide a new private output path. Existing files are never overwritten.\n"); exit(1); }
$tables = ['users','workspaces','workspace_user','projects','categories','blogs','blog_sections','blog_autosaves','seo_analyses','seo_reviews','seo_links','seo_link_edits','seo_redirects','seo_change_batches','seo_404_events','search_connections','search_reports','search_inspections','ai_profiles','ai_profile_versions','ai_settings','ai_usage_months','ai_runs'];
$encrypted = ['blog_autosaves'=>['snapshot'], 'search_connections'=>['access_token','refresh_token'], 'ai_profiles'=>['data'], 'ai_profile_versions'=>['data'], 'ai_runs'=>['input','result','edited']];
$payload = ['format'=>'blogsaas-laravel-export-v1','tables'=>[]];
foreach ($tables as $table) {
    if (!Illuminate\Support\Facades\Schema::hasTable($table)) continue;
    $rows=Illuminate\Support\Facades\DB::table($table)->get()->map(fn($row)=>(array)$row)->all();
    foreach ($rows as &$row) foreach ($encrypted[$table] ?? [] as $field) {
        if (!empty($row[$field])) {
            $plain=Illuminate\Support\Facades\Crypt::decryptString($row[$field]);
            $row[$field]=json_decode($plain,true) ?? $plain;
        }
    }
    unset($row);
    $payload['tables'][$table]=$rows;
}
$handle=fopen($argv[1],'x'); chmod($argv[1],0600);
fwrite($handle,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); fclose($handle);
echo "Private export written. It contains password hashes, unpublished content, and possibly API credentials. Keep it out of Git.\n";
