<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Integrations\IntegrationError;
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
        $svc = new SyncService($ctx);
        try {
            return ['_done' => ! empty($job->params['dry_run']) ? $svc->preview($c) : $svc->sync($c)];
        } catch (IntegrationError $e) {
            DB::table('source_connections')->where('id', $c->id)->update([
                'status' => $e->reason === 'unauthorized' ? 'needs_auth' : 'error', 'last_error' => $e->getMessage(), 'last_sync_at' => now(), 'updated_at' => now(),
            ]);
            throw new ApiError('integration_'.$e->reason, $e->getMessage(), 502);
        }
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }
}
