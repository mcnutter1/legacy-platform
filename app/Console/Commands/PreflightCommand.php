<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Application preflight: configuration, storage, keys, runner and integrations. Prints no secret values. */
class PreflightCommand extends Command
{
    protected $signature = 'legacy:preflight {--no-network}';

    protected $description = 'Verify configuration and dependencies';

    public function handle(): int
    {
        $fail = 0;
        $check = function (string $label, bool $ok, string $hint = '', bool $warnOnly = false) use (&$fail) {
            $this->line(sprintf('  %s %s%s', $ok ? '<info>✔</info>' : ($warnOnly ? '<comment>!</comment>' : '<error>✘</error>'), $label, $ok || ! $hint ? '' : " — {$hint}"));
            if (! $ok && ! $warnOnly) {
                $fail++;
            }
        };
        $prod = app()->environment('production');
        $this->info('Application');
        $check('APP_KEY set', strlen((string) config('app.key')) > 20);
        $check('APP_DEBUG off in production', ! $prod || ! config('app.debug'));
        $check('HTTPS APP_URL', ! $prod || str_starts_with((string) config('app.url'), 'https://'));
        $check('Host-only session cookie', ! $prod || str_starts_with((string) config('session.cookie'), '__Host-'), 'SESSION_COOKIE=__Host-mcnutt_legacy_session');
        $check('Secure cookies', ! $prod || (bool) config('session.secure'));
        $check('Database sessions', config('session.driver') === 'database');
        $check('Auth driver is McNutt Cloud in production', ! $prod || config('legacy.auth.driver') === 'mcnutt');
        $check('AUTH_APP_ID / AUTH_APP_SECRET', (bool) config('legacy.auth.app_id') && (bool) config('legacy.auth.app_secret'), 'register the app in McNutt Cloud', ! $prod);
        $check('Workspace creators configured', count(config('legacy.auth.workspace_creators')) > 0, 'set LEGACY_WORKSPACE_CREATORS to your McNutt Cloud uid', true);
        $this->info('Database');
        try {
            DB::select('select 1');
            $check('Connection', true);
            $check('Migrations applied', DB::getSchemaBuilder()->hasTable('compute_jobs'));
        } catch (\Throwable $e) {
            $check('Connection', false, class_basename($e));
        }
        $this->info('Vault');
        $root = (string) config('legacy.vault.root');
        $check('Private storage writable', is_dir($root) && is_writable($root), $root);
        $check('Private storage outside web root', ! str_starts_with(realpath($root) ?: $root, public_path()));
        $check('VAULT_ROOT_KEY is 32 bytes', strlen((string) base64_decode((string) config('legacy.vault.root_key'), true)) === 32, 'php -r "echo base64_encode(random_bytes(32));"');
        $check('Job directory writable', is_dir((string) config('legacy.vault.job_dir')) && is_writable((string) config('legacy.vault.job_dir')));
        $scanner = collect(['/usr/bin/clamdscan', '/usr/bin/clamscan'])->first(fn ($p) => is_executable($p));
        $check('Malware scanner available', (bool) $scanner, 'install clamav or set VAULT_REQUIRE_SCAN=false knowingly', true);
        $this->info('Runner');
        $py = (string) config('legacy.runner.python');
        $check('Python runner present', is_file($py) && is_executable($py), $py);
        $check('Tax rule packs registered', DB::getSchemaBuilder()->hasTable('tax_rule_packs') && DB::table('tax_rule_packs')->count() > 0, 'php artisan legacy:packs-sync', true);
        $this->info('Integrations');
        $check('Notifications token', config('legacy.notifications.driver') !== 'mcnutt' || (bool) config('legacy.notifications.api_token'), 'NOTIFICATIONS_API_TOKEN', true);
        $check('OpenAI extraction (optional)', ! config('legacy.openai.enabled') || (bool) config('legacy.openai.api_key'), 'OPENAI_API_KEY', true);
        if (! $this->option('no-network')) {
            try {
                $s = Http::timeout(5)->get((string) config('legacy.auth.validate_endpoint'))->status();
                $check('McNutt Cloud validate endpoint reachable', $s < 500, "HTTP {$s}", true);
            } catch (\Throwable) {
                $check('McNutt Cloud validate endpoint reachable', false, 'unreachable', true);
            }
        }
        $this->line($fail ? "<error>{$fail} problem(s)</error>" : '<info>Preflight passed</info>');

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
