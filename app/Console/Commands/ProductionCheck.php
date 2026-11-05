<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';
    protected $description = 'Read-only checks of required production settings and database connectivity';

    public function handle(): int
    {
        $checks = [
            'Environment is production' => app()->environment('production'),
            'Debug mode is disabled' => !config('app.debug'),
            'Application key is configured' => filled(config('app.key')),
            'Application URL uses HTTPS' => str_starts_with(config('app.url'), 'https://'),
            'Session cookies require HTTPS' => (bool) config('session.secure'),
            'Session payloads are encrypted' => (bool) config('session.encrypt'),
            'Mail transport can deliver reset emails' => !in_array(config('mail.default'), ['log', 'array'], true),
            'Production assets are built' => is_file(public_path('build/manifest.json')) && !is_file(public_path('hot')),
            'Upload execution protection exists' => is_file(public_path('storage/.htaccess')),
        ];
        try {
            DB::connection()->getPdo();
            $checks['Database connection succeeds'] = true;
            $demo = User::where('email', 'admin@gmail.com')->first();
            $checks['Demo password is absent'] = !$demo || !Hash::check('password', $demo->password);
            $files = app('migrator')->getMigrationFiles(database_path('migrations'));
            $pending = array_diff(array_keys($files), app('migration.repository')->getRan());
            $checks['Database migrations are current'] = count($pending) === 0;
        } catch (\Throwable $exception) {
            $checks['Database connection and migration state are healthy'] = false;
        }
        foreach ($checks as $label => $passed) {
            $passed ? $this->info('PASS  '.$label) : $this->error('FAIL  '.$label);
        }
        $this->line('Also verify the public/ document root, TLS, upload execution rules, SMTP delivery, backups, monitoring, and a restore on the actual host.');
        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
