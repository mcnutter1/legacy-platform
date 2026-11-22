<?php

namespace App\Console\Commands;

use App\Services\Notifications\AlertService;
use App\Services\Notifications\NotificationService;
use App\Services\OutboxProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MaintenanceCommand extends Command
{
    protected $signature = 'legacy:tick {task : outbox|notify|alerts|prune}';

    protected $description = 'Run one bounded maintenance task (outbox dispatch, email delivery, due-date alerts, pruning)';

    public function handle(OutboxProcessor $outbox, NotificationService $notify, AlertService $alerts): int
    {
        $res = match ($this->argument('task')) {
            'outbox' => $outbox->run(),
            'notify' => $notify->dispatch(),
            'alerts' => $alerts->run(),
            'prune' => $this->prune(),
            default => ['error' => 'unknown task'],
        };
        if (array_filter($res, fn ($v) => is_int($v) && $v > 0)) {
            $this->line(json_encode($res));
        }

        return self::SUCCESS;
    }

    private function prune(): array
    {
        $out = [
            'idempotency' => DB::table('idempotency_keys')->where('expires_at', '<', now())->delete(),
            'nonces' => DB::table('auth_callback_nonces')->where('expires_at', '<', now())->delete(),
            'download_tokens' => DB::table('download_tokens')->where('expires_at', '<', now()->subDay())->delete(),
            'oauth_codes' => DB::table('oauth_codes')->where('expires_at', '<', now()->subDay())->delete(),
            'upload_links' => DB::table('upload_links')->where('expires_at', '<', now()->subDays(7))->delete(),
            'jobs' => DB::table('compute_jobs')->whereIn('status', ['succeeded', 'cancelled'])->where('finished_at', '<', now()->subDays(30))->delete(),
            'sessions' => DB::table('sessions')->where('last_activity', '<', time() - 86400 * 2)->delete(),
        ];
        $dir = (string) config('legacy.vault.job_dir');
        $stale = 0;
        if (is_dir($dir)) {
            foreach (File::directories($dir) as $d) {
                if (filemtime($d) < time() - 3600) {
                    File::deleteDirectory($d);
                    $stale++;
                }
            }
        }
        $out['stale_job_dirs'] = $stale;

        return $out;
    }
}
