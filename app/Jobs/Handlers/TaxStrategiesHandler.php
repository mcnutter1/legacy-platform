<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\NetWorthService;
use App\Services\Tax\TaxInputBuilder;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxStrategiesHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['tax', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'tax.strategies';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $job->params;
        $input = (new TaxInputBuilder($ctx))->build($p['tax_unit_id']);
        $pretax = '0';
        if ($ctx->canAny('ledger')) {
            foreach ($ctx->constrain(DB::table('accounts'), 'ledger', 'read')->whereIn('kind', ['ira', 'sep_ira'])->whereNull('archived_at')->where('visibility', 'visible')->get() as $a) {
                [$v] = app(NetWorthService::class)->accountValue($a->id, now()->toDateString());
                $pretax = bcadd($pretax, $v ?? '0', 4);
            }
        }
        $losses = '0';
        if ($ctx->canAny('portfolio')) {
            foreach (app(\App\Services\PortfolioService::class)->summary($ctx)['positions'] as $pos) {
                if ($pos['unrealized'] !== null && bccomp($pos['unrealized'], '0', 4) < 0 && $pos['tax_bucket'] === 'taxable') {
                    $losses = bcadd($losses, ltrim($pos['unrealized'], '-'), 4);
                }
            }
        }

        return ['tax_input' => $input, 'goals' => ['rank_by' => $p['rank_by'] ?? 'net_current_year_cash'],
            'context' => ['pretax_ira_balance' => $pretax, 'harvestable_losses' => $losses, 'hdhp_covered' => (bool) ($p['hdhp_covered'] ?? false),
                'planned_business_purchase' => (string) ($p['planned_business_purchase'] ?? '0')]];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $unit = DB::table('tax_units')->find($job->params['tax_unit_id']);
        $n = 0;
        foreach ($result['candidates'] ?? [] as $c) {
            DB::table('tax_strategies')->insert([
                'id' => (string) Str::ulid(), 'workspace_id' => $unit->workspace_id, 'scope_id' => $unit->scope_id, 'tax_unit_id' => $unit->id,
                'tax_year' => $c['tax_year'], 'kind' => $c['kind'], 'title' => mb_substr($c['title'], 0, 255), 'assumptions' => json_encode($c['assumptions'] ?? []),
                'evidence_required' => json_encode($c['evidence_required'] ?? []), 'authorities' => json_encode($c['authorities'] ?? []), 'deadline' => $c['deadline'] ?? null,
                'impact' => json_encode($c['impact'] + ['eligibility_constraints' => $c['eligibility_constraints'] ?? [], 'engine_status' => $c['status'] ?? null, 'job_id' => $job->id]),
                'status' => 'proposed', 'requires_professional' => (bool) ($c['requires_professional'] ?? true),
                'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
            ]);
            $n++;
        }

        return ['strategies' => $n, 'baseline_total_tax' => $result['baseline']['total_tax'] ?? null];
    }
}
