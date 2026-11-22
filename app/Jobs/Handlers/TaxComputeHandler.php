<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Tax\TaxInputBuilder;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;

class TaxComputeHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['tax', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'tax.compute';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $job->params;
        $input = (new TaxInputBuilder($ctx))->build($p['tax_unit_id'], (array) ($p['overrides'] ?? []));
        $hash = hash('sha256', json_encode($input));
        DB::table('tax_runs')->where('id', $p['run_id'])->update(['input_hash' => $hash, 'input_manifest' => json_encode($input), 'updated_at' => now()]);

        return $input;
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $runId = $job->params['run_id'];
        $run = DB::table('tax_runs')->where('id', $runId)->first();
        if (! $run || $run->immutable) {
            throw new ApiError('conflict', 'Run is immutable or missing.', 409);
        }
        $status = in_array($result['status'] ?? '', ['estimate', 'calculated', 'unsupported'], true) ? $result['status'] : 'failed';
        $issues = array_merge($result['issues'] ?? [], array_map(fn ($u) => $u + ['severity' => 'blocking'], $result['unsupported'] ?? []));
        DB::table('tax_runs')->where('id', $runId)->update([
            'status' => $status, 'summary' => json_encode(($result['summary'] ?? []) + ['status_reasons' => $result['status_reasons'] ?? [], 'rentals' => $result['rentals'] ?? []]),
            'lines' => json_encode($result['lines'] ?? []), 'trace' => json_encode($result['trace'] ?? []), 'issues' => json_encode($issues),
            'rule_packs' => json_encode($result['packs'] ?? []), 'engine_version' => $result['engine_version'] ?? null, 'updated_at' => now(),
        ]);
        foreach (($result['summary']['federal']['carryforwards'] ?? []) as $k => $v) {
            // Carryforwards are proposed for review, never auto-posted.
            if ((float) $v > 0 && in_array($run->kind, ['return'], true)) {
                DB::table('review_items')->insertOrIgnore([
                    'id' => (string) \Illuminate\Support\Str::ulid(), 'workspace_id' => $run->workspace_id, 'scope_id' => $run->scope_id, 'domain' => 'tax',
                    'kind' => 'carryforward_proposal', 'subject_type' => 'tax_run', 'subject_id' => $run->id,
                    'title' => 'Confirm '.str_replace('_', ' ', $k).' carryforward of $'.number_format((float) $v).' to '.($run->tax_year + 1),
                    'reason' => 'Computed by the tax engine from this run; confirm before it is used next year.', 'evidence' => json_encode(['run_id' => $run->id, 'kind' => $k, 'amount' => $v]),
                    'severity' => 'normal', 'status' => 'open', 'dedup_key' => "carry:{$run->tax_unit_id}:{$run->tax_year}:{$k}", 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
            }
        }
        Audit::log('tax.run_completed', 'tax_run', $runId, ['status' => $status], $run->workspace_id, $run->scope_id);
        Outbox::publish('tax.run_completed', ['run_id' => $runId, 'status' => $status], 'tax_run', $runId, $run->workspace_id);

        return ['run_id' => $runId, 'status' => $status, 'total_tax' => $result['summary']['combined']['total_tax'] ?? null];
    }

    public function onFailure(ComputeJob $job): void
    {
        DB::table('tax_runs')->where('id', $job->params['run_id'] ?? '')->where('immutable', false)->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
