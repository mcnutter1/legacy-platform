<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Planning\PlanInputBuilder;
use App\Support\AccessContext;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class PlanRunHandler implements JobHandler
{
    private string $type = 'plan.project';

    public function authorization(ComputeJob $job): array
    {
        $this->type = $job->type;

        return [['planning', 'read', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return $this->type;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $this->type = $job->type;
        $input = (new PlanInputBuilder($ctx))->build($job->params['scenario_id']);
        $issues = $input['_issues'] ?? [];
        unset($input['_issues']);
        if (isset($job->params['paths'])) {
            $input['mc']['paths'] = max(10, min(10000, (int) $job->params['paths']));
        }
        if (isset($job->params['seed'])) {
            $input['mc']['seed'] = (int) $job->params['seed'];
        }
        DB::table('plan_runs')->where('id', $job->params['run_id'])->update([
            'input_manifest' => json_encode($input), 'input_hash' => hash('sha256', json_encode($input)), 'issues' => json_encode($issues),
            'seed' => $input['mc']['seed'] ?? null, 'paths' => $job->type === 'plan.montecarlo' ? ($input['mc']['paths'] ?? null) : null, 'updated_at' => now(),
        ]);

        return $input;
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $run = DB::table('plan_runs')->find($job->params['run_id']);
        $issues = array_merge(json_decode((string) $run->issues, true) ?: [], $result['issues'] ?? []);
        DB::table('plan_runs')->where('id', $run->id)->update([
            'status' => 'completed', 'summary' => json_encode($result['summary'] ?? []), 'years' => json_encode($result['years'] ?? []),
            'issues' => json_encode($issues), 'engine_version' => $result['engine_version'] ?? null, 'updated_at' => now(),
        ]);
        Audit::log('plan.run_completed', 'plan_run', $run->id, ['kind' => $run->kind], $run->workspace_id, $run->scope_id);

        return ['run_id' => $run->id, 'kind' => $run->kind];
    }

    public function onFailure(ComputeJob $job): void
    {
        DB::table('plan_runs')->where('id', $job->params['run_id'] ?? '')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
