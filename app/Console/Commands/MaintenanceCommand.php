<?php

namespace App\Console\Commands;

use App\Jobs\JobService;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\AlertService;
use App\Services\Notifications\NotificationService;
use App\Services\OutboxProcessor;
use App\Support\AccessContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MaintenanceCommand extends Command
{
    protected $signature = 'legacy:tick {task : outbox|notify|alerts|prune|integrations}';

    protected $description = 'Run one bounded maintenance task (outbox dispatch, email delivery, due-date alerts, pruning, scheduled integration syncs)';

    public function handle(OutboxProcessor $outbox, NotificationService $notify, AlertService $alerts): int
    {
        $res = match ($this->argument('task')) {
            'outbox' => $outbox->run(),
            'notify' => $notify->dispatch(),
            'alerts' => $alerts->run(),
            'prune' => $this->prune(),
            'integrations' => $this->integrations(),
            default => ['error' => 'unknown task'],
        };
        if (array_filter($res, fn ($v) => is_int($v) && $v > 0)) {
            $this->line(json_encode($res));
        }

        return self::SUCCESS;
    }

    private function integrations(): array
    {
        $queued = 0;
        $skipped = 0;
        $due = DB::table('source_connections')->whereIn('mode', ['mcp', 'api'])->whereIn('status', ['active', 'error'])->whereNotNull('approved_at')->whereNotNull('credential_encrypted')
            ->whereNull('archived_at')->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))->get();
        foreach ($due as $c) {
            $m = Membership::where('workspace_id', $c->workspace_id)->find($c->owner_membership_id);
            $u = $m ? User::find($m->user_id) : null;
            $pending = DB::table('compute_jobs')->where('type', 'integration.sync')->where('subject_id', $c->id)->whereIn('status', ['queued', 'leased', 'retryable'])->exists();
            if (! $m || ! $u || ! $m->isActive() || $u->disabled_at || $pending) {
                $skipped++;

                continue;
            }
            // Runs as the member who approved the connection; their grants are re-checked when the job executes.
            $ctx = new AccessContext($u, Workspace::findOrFail($c->workspace_id), $m);
            app()->instance(AccessContext::class, $ctx);
            try {
                app(JobService::class)->enqueue($ctx, 'integration.sync', ['connection_id' => $c->id, 'dry_run' => false, 'scheduled' => true], 'source_connection', $c->id, $c->scope_id, 150);
            } finally {
                app()->forgetInstance(AccessContext::class);
            }
            DB::table('source_connections')->where('id', $c->id)->update(['next_sync_at' => now()->addHours(max(1, (int) $c->sync_interval_hours))]);
            $queued++;
        }

        return ['integrations_queued' => $queued, 'integrations_skipped' => $skipped];
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
