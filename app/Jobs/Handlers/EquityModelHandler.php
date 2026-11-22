<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;

class EquityModelHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['compensation', 'read', null]];
    }

    public function runnerType(): ?string
    {
        return 'equity.model';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $job->params;
        $grants = [];
        $q = $ctx->constrain(DB::table('equity_grants'), 'compensation', 'read')->whereNull('archived_at');
        if (! empty($p['grant_ids'])) {
            $q->whereIn('id', (array) $p['grant_ids']);
        }
        foreach ($q->get() as $g) {
            $grants[] = ['id' => $g->id, 'company' => $g->company_name, 'grant_type' => $g->grant_type, 'units' => (string) $g->units,
                'strike' => (string) ($g->strike_price ?? '0'), 'expiration' => $g->expiration_date, 'participation_threshold' => (string) ($g->participation_threshold ?? '0'),
                'tranches' => DB::table('equity_tranches')->where('grant_id', $g->id)->whereNull('archived_at')->orderBy('vest_date')->get()
                    ->map(fn ($t) => ['vest_date' => $t->vest_date, 'units' => (string) $t->units, 'status' => $t->status, 'units_exercised' => (string) $t->units_exercised, 'condition' => $t->condition])->all()];
        }

        return ['as_of' => $p['as_of'] ?? now()->toDateString(), 'grants' => $grants, 'price_scenarios' => $p['price_scenarios'] ?? [],
            'exit_values' => $p['exit_values'] ?? [], 'waterfall' => $p['waterfall'] ?? null, 'ordinary_rate' => $p['ordinary_rate'] ?? '0.37',
            'ltcg_rate' => $p['ltcg_rate'] ?? '0.238', 'amt_rate' => $p['amt_rate'] ?? '0.28'];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        unset($result['_engine']);

        return $result;
    }
}
