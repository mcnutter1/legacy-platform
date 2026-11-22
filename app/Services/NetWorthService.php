<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Net worth and liquidity with source precedence that counts each fact once: a residence contributes its
 * accepted market valuation once; its mortgage once (liability record wins over a mirrored liability account);
 * whole life counts net surrender value. Missing values are listed as unvalued — never treated as zero.
 */
final class NetWorthService
{
    public function compute(AccessContext $ctx, ?string $asOf = null, ?string $personId = null): array
    {
        $asOf ??= now()->toDateString();
        $own = $personId ? new OwnershipService($ctx->workspace->id, $asOf) : null;
        $issues = [];
        $items = [];
        $unvalued = [];
        $add = function (string $category, string $label, string $type, string $id, ?string $value, string $method, ?string $asOfValue, bool $liquid, string $section = 'assets', array $extra = []) use (&$items, &$unvalued, $own, $personId, &$issues) {
            if ($value === null) {
                $unvalued[] = ['category' => $category, 'label' => $label, 'type' => $type, 'id' => $id];

                return;
            }
            $share = '1';
            if ($own) {
                $f = $own->fraction($personId, $type, $id, [], $issues);
                if ($f === null) {
                    $issues[] = ['code' => 'ownership_unresolved', 'message' => "Ownership of {$label} is not recorded; excluded from the personal view."];

                    return;
                }
                $share = $f;
            }
            if (bccomp($share, '0', 12) === 0) {
                return;
            }
            $items[] = ['section' => $section, 'category' => $category, 'label' => $label, 'type' => $type, 'id' => $id,
                'value' => Dec::round(bcmul($value, $share, 6), 2), 'share' => $share, 'method' => $method, 'as_of' => $asOfValue, 'liquid' => $liquid] + $extra;
        };

        // Accounts (assets and mirrored liabilities)
        if ($ctx->canAny('ledger') || $ctx->canAny('portfolio')) {
            $accounts = $ctx->constrain(DB::table('accounts')->whereNull('archived_at')->where('status', '!=', 'closed')->where('visibility', 'visible'), 'ledger', 'read');
            $linkedLiabilityAccounts = DB::table('liabilities')->where('workspace_id', $ctx->workspace->id)->whereNotNull('account_id')->whereNull('archived_at')->pluck('account_id')->all();
            foreach ($accounts->get() as $a) {
                [$val, $method, $vdate] = $this->accountValue($a->id, $asOf);
                if ($a->is_liability || in_array($a->kind, ['credit_card', 'loan', 'mortgage', 'heloc'], true)) {
                    if (in_array($a->id, $linkedLiabilityAccounts, true)) {
                        continue;
                    }
                    $add('Credit & loans', $a->name, 'account', $a->id, $val === null ? null : Dec::abs($val), $method, $vdate, false, 'liabilities');
                    continue;
                }
                $cat = match (true) {
                    in_array($a->kind, ['checking', 'savings', 'money_market', 'cd', 'treasury'], true) => 'Cash & equivalents',
                    in_array($a->tax_bucket, ['pretax', 'roth'], true) || in_array($a->kind, ['ira', 'roth_ira', '401k', 'roth_401k', '403b', '457b', 'sep_ira', 'solo_401k'], true) => 'Retirement',
                    in_array($a->kind, ['hsa', '529', 'utma'], true) => 'Education & health',
                    in_array($a->kind, ['private_fund', 'business'], true) => 'Private & business',
                    default => 'Investments',
                };
                $liquid = in_array($a->liquidity, ['daily', 't_plus'], true) && $cat !== 'Private & business';
                $add($cat, $a->name, 'account', $a->id, $val, $method, $vdate, $liquid, 'assets', ['tax_bucket' => $a->tax_bucket]);
            }
        }
        // Real estate
        if ($ctx->canAny('real_estate')) {
            foreach ($ctx->constrain(DB::table('properties')->whereNull('archived_at')->where('status', 'owned'), 'real_estate', 'read')->get() as $p) {
                [$val, $method, $vdate] = $this->valuation('property', $p->id, $asOf);
                if ($val === null && $p->purchase_price !== null) {
                    [$val, $method, $vdate] = [Dec::norm($p->purchase_price), 'cost (no current valuation)', $p->acquired_on];
                    $issues[] = ['code' => 'stale_valuation', 'message' => "{$p->name} has no accepted market valuation; shown at cost."];
                }
                $add('Real estate', $p->name, 'property', $p->id, $val, $method, $vdate, false);
            }
        }
        // Personal & business assets
        if ($ctx->canAny('assets')) {
            foreach ($ctx->constrain(DB::table('assets')->whereNull('archived_at')->where('status', 'owned'), 'assets', 'read')->get() as $x) {
                [$val, $method, $vdate] = $this->valuation('asset', $x->id, $asOf);
                if ($val === null && $x->cost !== null) {
                    [$val, $method, $vdate] = [Dec::norm($x->cost), 'cost (no current valuation)', $x->acquired_on];
                }
                $add(in_array($x->kind, ['business_interest', 'private_fund'], true) ? 'Private & business' : 'Personal property', $x->name, 'asset', $x->id, $val, $method, $vdate, false);
            }
        }
        // Insurance cash value (net surrender), never alongside death benefit.
        if ($ctx->canAny('insurance')) {
            foreach ($ctx->constrain(DB::table('insurance_policies')->whereNull('archived_at')->where('status', 'active')->whereIn('kind', ['whole_life', 'universal_life', 'variable_life']), 'insurance', 'read')->get() as $pol) {
                if ($pol->cash_value === null) {
                    $add('Insurance cash value', $pol->name, 'policy', $pol->id, null, 'missing', null, false);
                    continue;
                }
                $net = Dec::sub(Dec::sub(Dec::norm($pol->cash_value), Dec::norm($pol->loan_balance ?? '0')), Dec::norm($pol->surrender_charge ?? '0'));
                $add('Insurance cash value', $pol->name, 'policy', $pol->id, Dec::cmp($net, '0') > 0 ? $net : '0', 'net surrender value', $pol->cash_value_as_of, false);
            }
        }
        // Liabilities
        $contingent = [];
        if ($ctx->canAny('debt')) {
            foreach ($ctx->constrain(DB::table('liabilities')->whereNull('archived_at')->where('status', 'active'), 'debt', 'read')->get() as $l) {
                if ($l->contingent || $l->kind === 'guarantee') {
                    $contingent[] = ['label' => $l->name, 'amount' => $l->current_balance !== null ? Dec::norm($l->current_balance) : null, 'kind' => $l->kind];
                    continue;
                }
                $cat = in_array($l->kind, ['mortgage', 'heloc'], true) ? 'Mortgages' : 'Loans & lines';
                $add($cat, $l->name, 'liability', $l->id, $l->current_balance !== null ? Dec::norm($l->current_balance) : null, 'statement balance', $l->balance_as_of, false, 'liabilities');
            }
        }
        // Equity compensation (vested, at latest company valuation) is shown separately and excluded from totals.
        $equity = [];
        if ($ctx->canAny('compensation')) {
            foreach ($ctx->constrain(DB::table('equity_grants')->whereNull('archived_at')->whereIn('status', ['active', 'fully_vested']), 'compensation', 'read')->get() as $g) {
                $vested = DB::table('equity_tranches')->where('grant_id', $g->id)->where('vest_date', '<=', $asOf)->where('status', '!=', 'forfeited')->whereNull('archived_at')->sum('units');
                $px = DB::table('company_valuations')->where('workspace_id', $ctx->workspace->id)->where('company_name', $g->company_name)->where('as_of', '<=', $asOf)->orderByDesc('as_of')->first();
                $spread = $px ? max(0, (float) $px->price_per_share - (float) ($g->strike_price ?? 0)) : null;
                $equity[] = ['grant_id' => $g->id, 'company' => $g->company_name, 'type' => $g->grant_type, 'vested_units' => Dec::norm($vested, 10),
                    'price' => $px ? Dec::norm($px->price_per_share, 8) : null, 'price_as_of' => $px?->as_of,
                    'intrinsic_value' => $spread === null ? null : Dec::round(bcmul((string) $spread, Dec::norm($vested, 10), 6), 2)];
            }
        }

        $sum = fn (array $rows, ?callable $f = null) => array_reduce(array_filter($rows, $f ?? fn () => true), fn ($c, $r) => Dec::add($c, $r['value']), '0');
        $assets = array_values(array_filter($items, fn ($r) => $r['section'] === 'assets'));
        $liabs = array_values(array_filter($items, fn ($r) => $r['section'] === 'liabilities'));
        $totalAssets = $sum($assets);
        $totalLiabs = $sum($liabs);
        $liquid = $sum($assets, fn ($r) => $r['liquid']);
        $byCat = [];
        foreach ($items as $r) {
            $k = $r['section'].'|'.$r['category'];
            $byCat[$k] ??= ['section' => $r['section'], 'category' => $r['category'], 'value' => '0', 'items' => []];
            $byCat[$k]['value'] = Dec::add($byCat[$k]['value'], $r['value']);
            $byCat[$k]['items'][] = $r;
        }

        return [
            'as_of' => $asOf, 'view' => $personId ? 'personal' : 'consolidated', 'person_id' => $personId, 'currency' => $ctx->workspace->base_currency,
            'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabs, 'net_worth' => Dec::sub($totalAssets, $totalLiabs),
            'liquid_assets' => $liquid, 'categories' => array_values($byCat), 'unvalued' => $unvalued, 'contingent_liabilities' => $contingent,
            'equity_compensation' => $equity, 'issues' => $issues,
            'coverage' => ['valued_items' => count($items), 'unvalued_items' => count($unvalued)],
            'methodology' => 'Latest statement, holding or accepted valuation on or before the as-of date. Each asset and liability is counted once; '
                .'unvested equity, contingent liabilities and death benefits are excluded; whole life counts net surrender value. '
                .($personId ? 'Personal view applies recorded legal-title ownership fractions, looking through entities.' : 'Consolidated view includes 100% of records you can access.'),
        ];
    }

    public function accountValue(string $accountId, string $asOf): array
    {
        $h = DB::table('holdings')->where('account_id', $accountId)->where('as_of', '<=', $asOf)->whereNull('archived_at')->max('as_of');
        $b = DB::table('balance_snapshots')->where('account_id', $accountId)->where('as_of', '<=', $asOf)->whereNull('archived_at')->orderByDesc('as_of')->first();
        // Same-day positions from a different source than the balance may be partial (e.g. no cash line); trust the reported balance then.
        $sameDayOtherSource = $h && $b && $h === $b->as_of && $b->source_ref !== null
            && ! DB::table('holdings')->where('account_id', $accountId)->where('as_of', $h)->whereNull('archived_at')->where('source_ref', $b->source_ref)->exists();
        if ($h && (! $b || $h >= $b->as_of) && ! $sameDayOtherSource) {
            $rows = DB::table('holdings')->where('account_id', $accountId)->where('as_of', $h)->whereNull('archived_at')->get();
            if ($rows->contains(fn ($r) => $r->market_value === null && $r->price === null)) {
                return [null, 'holdings (unpriced)', $h];
            }
            $v = $rows->reduce(fn ($c, $r) => Dec::add($c, $r->market_value !== null ? Dec::norm($r->market_value) : Dec::mul(Dec::norm($r->quantity, 10), Dec::norm($r->price, 8))), '0');

            return [$v, 'holdings', $h];
        }
        if ($b) {
            return [Dec::norm($b->balance), 'balance snapshot', $b->as_of];
        }

        return [null, 'missing', null];
    }

    public function valuation(string $subjectType, string $id, string $asOf, string $purpose = 'market'): array
    {
        $v = DB::table('valuations')->where('subject_type', $subjectType)->where('subject_id', $id)->where('purpose', $purpose)
            ->where('status', 'accepted')->where('as_of', '<=', $asOf)->whereNull('archived_at')->orderByDesc('as_of')->first();

        return $v ? [Dec::norm($v->value), $v->method, $v->as_of] : [null, 'missing', null];
    }
}
