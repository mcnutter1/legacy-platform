<?php

namespace App\Services\Planning;

use App\Services\NetWorthService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Builds the projection input from a plan + scenario, current records and explicit scenario assumptions. */
final class PlanInputBuilder
{
    private const BUCKET = [
        'checking' => 'cash', 'savings' => 'cash', 'money_market' => 'cash', 'cd' => 'cash', 'treasury' => 'cash',
        'ira' => 'pretax', '401k' => 'pretax', '403b' => 'pretax', '457b' => 'pretax', 'sep_ira' => 'pretax', 'solo_401k' => 'pretax',
        'roth_ira' => 'roth', 'roth_401k' => 'roth', 'hsa' => 'hsa', '529' => 'education', 'utma' => 'taxable',
        'private_fund' => 'illiquid', 'business' => 'illiquid', 'brokerage' => 'taxable', 'crypto' => 'taxable',
    ];

    public function __construct(private readonly AccessContext $ctx) {}

    public function build(string $scenarioId): array
    {
        $sc = $this->ctx->constrain(DB::table('plan_scenarios'), 'planning', 'read')->where('id', $scenarioId)->whereNull('archived_at')->first();
        if (! $sc) {
            throw ApiError::notFound('Scenario not found.');
        }
        $plan = DB::table('plans')->find($sc->plan_id);
        $a = json_decode((string) $sc->assumptions, true) ?: [];
        $peopleOverrides = collect(json_decode((string) $sc->people, true) ?: [])->keyBy('id');
        $participants = json_decode((string) $plan->participant_person_ids, true) ?: [];
        $today = now()->toDateString();
        $issues = [];

        $people = [];
        foreach (DB::table('persons')->whereIn('id', $participants)->where('workspace_id', $this->ctx->workspace->id)->get() as $p) {
            $o = (array) ($peopleOverrides[$p->id] ?? []);
            $birthYear = $p->birth_date ? (int) substr($p->birth_date, 0, 4) : (int) ($o['birth_year'] ?? 0);
            if (! $birthYear) {
                throw new ApiError('validation_failed', "Add a birth date for {$p->display_name} before running the plan.");
            }
            $emp = $this->ctx->canAny('compensation')
                ? $this->ctx->constrain(DB::table('employments'), 'compensation', 'read')->where('person_id', $p->id)->whereNull('archived_at')
                    ->where(fn ($q) => $q->whereNull('end_on')->orWhere('end_on', '>=', $today))->get() : collect();
            $salary = (float) ($o['salary'] ?? $emp->sum(fn ($e) => (float) ($e->base_salary ?? 0)));
            $bonus = (float) ($o['bonus'] ?? $emp->sum(fn ($e) => (float) ($e->base_salary ?? 0) * (float) ($e->bonus_target_pct ?? 0)));
            $people[] = [
                'id' => $p->id, 'name' => $p->display_name, 'birth_year' => $birthYear,
                'retire_year' => (int) ($o['retire_year'] ?? ($birthYear + (int) ($o['retire_age'] ?? 65))),
                'death_age' => (int) ($o['death_age'] ?? $p->life_expectancy_age ?? 92),
                'salary' => $salary, 'salary_growth' => (float) ($o['salary_growth'] ?? ($a['salary_growth'] ?? 0.03)), 'bonus' => $bonus,
                'ss_pia' => (float) ($o['ss_pia'] ?? 0), 'ss_claim_age' => (float) ($o['ss_claim_age'] ?? 67),
                'pension' => $o['pension'] ?? null,
            ];
            if (empty($o['ss_pia'])) {
                $issues[] = ['code' => 'ss_missing', 'message' => "No Social Security estimate for {$p->display_name}; enter the PIA from the SSA statement (ssa.gov/myaccount)."];
            }
        }

        $nw = app(NetWorthService::class);
        $accounts = [];
        if ($this->ctx->canAny('ledger')) {
            $rows = $this->ctx->constrain(DB::table('accounts'), 'ledger', 'read')->whereNull('archived_at')->where('status', 'open')->where('visibility', 'visible')->where('is_liability', false)
                ->where(fn ($q) => $q->whereIn('owner_person_id', $participants)->orWhereNull('owner_person_id'))->get();
            foreach ($rows as $acc) {
                $bucket = self::BUCKET[$acc->kind] ?? ($acc->tax_bucket === 'pretax' ? 'pretax' : ($acc->tax_bucket === 'roth' ? 'roth' : 'taxable'));
                if (in_array($acc->kind, ['credit_card', 'loan', 'mortgage', 'heloc'], true)) {
                    continue;
                }
                [$val] = $nw->accountValue($acc->id, $today);
                if ($val === null) {
                    $issues[] = ['code' => 'account_unvalued', 'message' => "{$acc->name} has no balance; excluded from the projection."];
                    continue;
                }
                $basis = DB::table('lots')->where('account_id', $acc->id)->whereNull('archived_at')->sum(DB::raw('cost_basis * remaining_quantity / quantity'));
                $ov = (array) (($a['accounts'][$acc->id] ?? []) ?: []);
                $accounts[] = ['id' => $acc->id, 'name' => $acc->name, 'bucket' => $ov['bucket'] ?? $bucket, 'owner' => $acc->owner_person_id,
                    'balance' => (float) $val, 'basis' => $bucket === 'taxable' ? (float) ($ov['basis'] ?? ($basis ?: $val)) : null,
                    'allocation' => $ov['allocation'] ?? $this->allocation($acc->id, $bucket), 'annual_contribution' => (float) ($ov['annual_contribution'] ?? 0),
                    'employer_match' => (float) ($ov['employer_match'] ?? 0), 'contribution_end_year' => $ov['contribution_end_year'] ?? null,
                    'liquidity_year' => $ov['liquidity_year'] ?? null];
            }
        }
        $liabilities = [];
        if ($this->ctx->canAny('debt')) {
            foreach ($this->ctx->constrain(DB::table('liabilities'), 'debt', 'read')->whereNull('archived_at')->where('status', 'active')->where('contingent', false)->get() as $l) {
                if ($l->current_balance === null) {
                    continue;
                }
                $mult = ['monthly' => 12, 'biweekly' => 26, 'quarterly' => 4, 'annual' => 1][$l->payment_frequency] ?? 12;
                $liabilities[] = ['id' => $l->id, 'name' => $l->name, 'balance' => (float) $l->current_balance, 'rate' => (float) ($l->interest_rate ?? 0),
                    'payment' => (float) ($l->payment_amount ?? 0) * $mult, 'extra_payment' => (float) ($a['extra_debt_payments'][$l->id] ?? 0),
                    'interest_deductible' => $l->deductible_interest_kind === 'qualified_residence'];
            }
        }
        $properties = [];
        if ($this->ctx->canAny('real_estate')) {
            foreach ($this->ctx->constrain(DB::table('properties'), 'real_estate', 'read')->whereNull('archived_at')->where('status', 'owned')->get() as $p) {
                [$val] = $nw->valuation('property', $p->id, $today);
                $val ??= $p->purchase_price;
                if ($val === null) {
                    continue;
                }
                $ov = (array) ($a['properties'][$p->id] ?? []);
                $mortgage = DB::table('liabilities')->where('collateral_type', 'property')->where('collateral_id', $p->id)->whereNull('archived_at')->value('id');
                $properties[] = ['id' => $p->id, 'name' => $p->name, 'value' => (float) $val, 'appreciation' => (float) ($ov['appreciation'] ?? ($a['property_appreciation'] ?? 0.03)),
                    'sell_year' => $ov['sell_year'] ?? null, 'selling_cost_pct' => (float) ($ov['selling_cost_pct'] ?? 0.06),
                    'basis' => (float) (($p->purchase_price ?? 0) + ($p->closing_costs ?? 0)), 'primary_residence' => $p->kind === 'primary',
                    'annual_cost' => (float) ($ov['annual_cost'] ?? 0), 'net_rent' => (float) ($ov['net_rent'] ?? 0), 'mortgage_id' => $mortgage];
            }
        }
        $events = json_decode((string) $sc->events, true) ?: [];
        $strategy = json_decode((string) $sc->strategy, true) ?: [];

        return [
            'start_year' => (int) ($a['start_year'] ?? $plan->start_year), 'end_year' => (int) $plan->horizon_year,
            'inflation' => (float) ($a['inflation'] ?? 0.025), 'state' => ($a['state'] ?? 'NJ') === 'NONE' ? 'NONE' : 'NJ',
            'people' => $people, 'accounts' => $accounts, 'liabilities' => $liabilities, 'properties' => $properties,
            'streams' => $a['streams'] ?? [], 'events' => $events,
            'expenses' => ['essential' => (float) ($a['essential_spending'] ?? 0), 'discretionary' => (float) ($a['discretionary_spending'] ?? 0),
                'healthcare_pre65' => (float) ($a['healthcare_pre65'] ?? 0), 'healthcare_65' => (float) ($a['healthcare_65'] ?? 0),
                'healthcare_growth' => (float) ($a['healthcare_growth'] ?? 0.05), 'phases' => $a['spending_phases'] ?? [],
                'survivor_multiplier' => (float) ($a['survivor_multiplier'] ?? 0.75)],
            'returns' => $a['returns'] ?? new \stdClass,
            'strategy' => $strategy ?: new \stdClass,
            'mc' => ['paths' => (int) ($a['mc_paths'] ?? 1000), 'seed' => (int) ($a['mc_seed'] ?? crc32($scenarioId)), 'method' => $a['mc_method'] ?? 'normal'],
            '_issues' => $issues,
        ];
    }

    private function allocation(string $accountId, string $bucket): array
    {
        $rows = DB::table('holdings as h')->leftJoin('instruments as i', 'i.id', '=', 'h.instrument_id')->where('h.account_id', $accountId)->whereNull('h.archived_at')
            ->where('h.as_of', DB::table('holdings')->where('account_id', $accountId)->max('as_of'))->get(['i.asset_class', 'i.kind', 'h.market_value']);
        $tot = 0.0;
        $w = [];
        foreach ($rows as $r) {
            $cls = match (true) {
                in_array($r->asset_class, ['us_equity', 'intl_equity', 'bonds', 'cash', 'real_estate', 'alternatives'], true) => $r->asset_class,
                in_array($r->asset_class, ['em_equity'], true) => 'intl_equity',
                in_array($r->asset_class, ['tips', 'munis'], true) => 'bonds',
                in_array($r->asset_class, ['private_equity', 'private_credit', 'commodities', 'crypto'], true) => 'alternatives',
                in_array($r->kind, ['money_market', 'cash', 'treasury', 'cd'], true) => 'cash',
                in_array($r->kind, ['bond'], true) => 'bonds',
                default => 'us_equity',
            };
            $v = (float) ($r->market_value ?? 0);
            $w[$cls] = ($w[$cls] ?? 0) + $v;
            $tot += $v;
        }
        if ($tot <= 0) {
            return $bucket === 'cash' ? ['cash' => 1.0] : ['us_equity' => 0.6, 'bonds' => 0.4];
        }

        return array_map(fn ($v) => round($v / $tot, 6), $w);
    }

    /** Spending baseline from complete months, with disclosed exclusions (transfers, one-offs, income). */
    public function spendingBaseline(int $months = 12): array
    {
        $end = now()->startOfMonth()->subDay()->toDateString();
        $start = now()->startOfMonth()->subMonths($months)->toDateString();
        $q = $this->ctx->constrain(DB::table('transactions'), 'ledger', 'read')->whereNull('archived_at')->whereBetween('posted_on', [$start, $end]);
        $rows = (clone $q)->where('kind', 'spend')->whereNull('transfer_group')->get(['posted_on', 'amount', 'category', 'tags', 'id']);
        $byCat = [];
        $byMonth = [];
        $oneOffs = [];
        foreach ($rows as $r) {
            $amt = abs((float) $r->amount);
            $tags = json_decode((string) $r->tags, true) ?: [];
            if (in_array('one-off', array_map('strtolower', $tags), true) || $amt >= 10000) {
                $oneOffs[] = ['id' => $r->id, 'posted_on' => $r->posted_on, 'category' => $r->category, 'amount' => round($amt, 2)];
                continue;
            }
            $c = $r->category ?: 'Uncategorized';
            $byCat[$c] = ($byCat[$c] ?? 0) + $amt;
            $m = substr($r->posted_on, 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + $amt;
        }
        arsort($byCat);
        $monthsWithData = max(1, count($byMonth));

        return ['from' => $start, 'to' => $end, 'complete_months' => $monthsWithData,
            'monthly_average' => round(array_sum($byMonth) / $monthsWithData, 2), 'annualized' => round(array_sum($byMonth) / $monthsWithData * 12, 2),
            'by_category' => array_map(fn ($k, $v) => ['category' => $k, 'monthly' => round($v / $monthsWithData, 2)], array_keys($byCat), $byCat),
            'by_month' => $byMonth, 'excluded_one_offs' => $oneOffs,
            'exclusions' => 'Transfers, income, investment trades and items tagged one-off or ≥ $10,000 are excluded. Partial current month excluded.'];
    }
}
