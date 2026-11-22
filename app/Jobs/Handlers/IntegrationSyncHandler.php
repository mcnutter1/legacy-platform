<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Integrations\FinanceSyncService;
use App\Services\Integrations\IntegrationError;
use App\Services\Integrations\PmsSyncService;
use App\Services\Integrations\SyncService;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/** PHP-only: outbound MCP calls to an allow-listed provider host, then a transactional reconcile. */
class IntegrationSyncHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['ledger', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return null;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $c = $ctx->constrain(DB::table('source_connections'), 'ledger', 'write')->where('id', $job->params['connection_id'] ?? '')->whereNull('archived_at')->first();
        if (! $c) {
            throw new ApiError('not_found', 'Connection not found.', 404);
        }
        $svc = match ($c->provider) {
            'property_manager' => new PmsSyncService($ctx),
            'invoices' => new FinanceSyncService($ctx),
            default => new SyncService($ctx),
        };
        try {
            if (! empty($job->params['dry_run'])) {
                return ['_done' => $svc->preview($c)];
            }
            $out = $svc->sync($c);
            DB::table('source_connections')->where('id', $c->id)->update(['next_sync_at' => now()->addHours(max(1, (int) $c->sync_interval_hours))]);

            return ['_done' => $out];
        } catch (IntegrationError $e) {
            DB::table('source_connections')->where('id', $c->id)->update([
                'status' => $e->reason === 'unauthorized' ? 'needs_auth' : 'error', 'last_error' => $e->getMessage(), 'last_sync_at' => now(), 'updated_at' => now(),
            ] + (empty($job->params['dry_run']) ? ['next_sync_at' => now()->addHour()] : []));
            throw new ApiError('integration_'.$e->reason, $e->getMessage(), 502);
        }
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }
}
