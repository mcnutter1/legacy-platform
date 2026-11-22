<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Estate\EstateInputBuilder;
use App\Support\AccessContext;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EstateRunHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['estate', 'read', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'estate.distribute';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $input = (new EstateInputBuilder($ctx))->build($job->params['scenario_id']);
        DB::table('estate_runs')->where('id', $job->params['run_id'])->update(['input_manifest' => json_encode($input), 'input_hash' => hash('sha256', json_encode($input)), 'updated_at' => now()]);

        return $input;
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $run = DB::table('estate_runs')->find($job->params['run_id']);
        DB::table('estate_runs')->where('id', $run->id)->update([
            'status' => 'completed', 'summary' => json_encode(($result['summary'] ?? []) + ['beneficiaries' => $result['beneficiaries'] ?? [], 'trusts' => $result['trusts'] ?? []]),
            'flows' => json_encode($result['deaths'] ?? []), 'unresolved' => json_encode($result['unresolved'] ?? []),
            'engine_version' => $result['engine_version'] ?? null, 'updated_at' => now(),
        ]);
        foreach (($result['unresolved'] ?? []) as $u) {
            if (! in_array($u['code'] ?? '', ['unfunded_trust', 'minor_beneficiary', 'title_vs_will', 'liquidity_gap', 'no_will', 'lapsed_beneficiary', 'beneficiary_shares'], true)) {
                continue;
            }
            DB::table('review_items')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'workspace_id' => $run->workspace_id, 'scope_id' => $run->scope_id, 'domain' => 'estate', 'kind' => 'professional_review',
                'subject_type' => 'estate_run', 'subject_id' => $run->id, 'title' => mb_substr($u['message'], 0, 250),
                'reason' => 'Raised by the estate model; review with counsel.', 'evidence' => json_encode($u), 'severity' => $u['code'] === 'liquidity_gap' ? 'high' : 'normal',
                'status' => 'open', 'dedup_key' => 'estate:'.substr(hash('sha256', $u['code'].($u['asset'] ?? $u['trust'] ?? $u['person'] ?? '')), 0, 40),
                'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
            ]);
        }
        Audit::log('estate.run_completed', 'estate_run', $run->id, [], $run->workspace_id, $run->scope_id);

        return ['run_id' => $run->id, 'unresolved' => count($result['unresolved'] ?? [])];
    }

    public function onFailure(ComputeJob $job): void
    {
        DB::table('estate_runs')->where('id', $job->params['run_id'] ?? '')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
