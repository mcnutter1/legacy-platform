<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Positions, lots, allocation/concentration and performance (time- vs money-weighted reported separately). */
final class PortfolioService
{
    /** FIFO lot relief for a sale; returns realized gain components. */
    public function relieveLots(string $workspaceId, string $accountId, string $instrumentId, string $quantity, string $date): array
    {
        $remaining = Dec::abs($quantity);
        $basis = '0';
        $lots = DB::table('lots')->where('workspace_id', $workspaceId)->where('account_id', $accountId)->where('instrument_id', $instrumentId)
            ->whereNull('archived_at')->where('remaining_quantity', '>', 0)->orderBy('acquired_on')->orderBy('id')->lockForUpdate()->get();
        $short = '0';
        $long = '0';
        foreach ($lots as $lot) {
            if (Dec::cmp($remaining, '0') <= 0) {
                break;
            }
            $rq = Dec::norm($lot->remaining_quantity, 10);
            $take = Dec::cmp($rq, $remaining) <= 0 ? $rq : $remaining;
            $per = Dec::div(Dec::add(Dec::norm($lot->cost_basis), Dec::norm($lot->basis_adjustment ?? '0')), Dec::norm($lot->quantity, 10), 8);
            $b = Dec::mul($per, $take);
            $basis = Dec::add($basis, $b);
            if (strtotime($date) - strtotime($lot->acquired_on) > 365 * 86400) {
                $long = Dec::add($long, $b);
            } else {
                $short = Dec::add($short, $b);
            }
            $left = bcsub($rq, $take, 10);
            DB::table('lots')->where('id', $lot->id)->update(['remaining_quantity' => $left, 'closed_on' => bccomp($left, '0', 10) === 0 ? $date : null, 'updated_at' => now()]);
            $remaining = bcsub($remaining, $take, 10);
        }

        return ['basis_relieved' => $basis, 'short_term_basis' => $short, 'long_term_basis' => $long, 'unmatched_quantity' => $remaining];
    }

    public function summary(AccessContext $ctx, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $q = DB::table('holdings as h')->join('accounts as a', 'a.id', '=', 'h.account_id')->leftJoin('instruments as i', 'i.id', '=', 'h.instrument_id')
            ->whereNull('h.archived_at')->where('h.as_of', '<=', $asOf);
        $ctx->constrain($q, 'portfolio', 'read', null, 'h');
        $rows = $q->select('h.*', 'a.name as account_name', 'a.tax_bucket', 'a.kind as account_kind', 'i.symbol', 'i.name as instrument_name', 'i.asset_class',
            'i.sector', 'i.issuer', 'i.currency as instrument_currency', 'i.last_price', 'i.last_price_on')->orderByDesc('h.as_of')->get();
        $latest = [];
        foreach ($rows as $r) {
            $k = $r->account_id.'|'.$r->instrument_id;
            if (! isset($latest[$k])) {
                $latest[$k] = $r;
            }
        }
        $total = '0';
        $positions = [];
        $unvalued = [];
        $stale = [];
        $groups = ['asset_class' => [], 'sector' => [], 'issuer' => [], 'currency' => [], 'tax_bucket' => []];
        foreach ($latest as $r) {
            $value = $r->market_value !== null ? Dec::norm($r->market_value)
                : ($r->price !== null ? Dec::mul(Dec::norm($r->quantity, 10), Dec::norm($r->price, 8)) : null);
            $priceDate = $r->price_as_of ?? $r->as_of;
            if ($value === null) {
                $unvalued[] = ['account' => $r->account_name, 'symbol' => $r->symbol];
                continue;
            }
            if ((strtotime($asOf) - strtotime((string) $priceDate)) > 7 * 86400) {
                $stale[] = ['account' => $r->account_name, 'symbol' => $r->symbol, 'price_as_of' => $priceDate];
            }
            $total = Dec::add($total, $value);
            $positions[] = ['account_id' => $r->account_id, 'account' => $r->account_name, 'instrument_id' => $r->instrument_id, 'symbol' => $r->symbol,
                'name' => $r->instrument_name, 'quantity' => Dec::norm($r->quantity, 10), 'price' => $r->price !== null ? Dec::norm($r->price, 8) : null,
                'value' => $value, 'cost_basis' => $r->cost_basis !== null ? Dec::norm($r->cost_basis) : null,
                'unrealized' => $r->cost_basis !== null ? Dec::sub($value, Dec::norm($r->cost_basis)) : null,
                'as_of' => $r->as_of, 'price_as_of' => $priceDate, 'asset_class' => $r->asset_class ?: 'unclassified', 'tax_bucket' => $r->tax_bucket];
            foreach (['asset_class' => $r->asset_class ?: 'unclassified', 'sector' => $r->sector ?: 'unclassified', 'issuer' => $r->issuer ?: ($r->symbol ?: 'unknown'),
                'currency' => $r->currency ?: 'USD', 'tax_bucket' => $r->tax_bucket ?: 'taxable'] as $g => $key) {
                $groups[$g][$key] = Dec::add($groups[$g][$key] ?? '0', $value);
            }
        }
        $exposure = [];
        foreach ($groups as $g => $vals) {
            arsort($vals);
            $exposure[$g] = array_map(fn ($k, $v) => ['key' => $k, 'value' => $v, 'weight' => Dec::cmp($total, '0') > 0 ? Dec::div($v, $total, 6) : '0'], array_keys($vals), $vals);
        }
        $flags = [];
        foreach ($exposure['issuer'] as $e) {
            if (Dec::cmp($e['weight'], '0.10') > 0) {
                $flags[] = ['code' => 'concentration', 'message' => "{$e['key']} is ".round((float) $e['weight'] * 100, 1).'% of the portfolio.'];
            }
        }
        usort($positions, fn ($a, $b) => Dec::cmp($b['value'], $a['value']));

        return ['as_of' => $asOf, 'total_value' => $total, 'positions' => $positions, 'exposure' => $exposure, 'flags' => $flags,
            'coverage' => ['positions' => count($positions), 'unvalued' => $unvalued, 'stale_prices' => $stale],
            'methodology' => 'Latest holding snapshot per account and instrument on or before the as-of date. Book value; look-through exposures are reported separately when available.'];
    }

    /** Time-weighted (Modified Dietz chained monthly) and money-weighted (XIRR) returns for one account. */
    public function performance(AccessContext $ctx, string $accountId, string $from, string $to): array
    {
        $acct = DB::table('accounts')->where('workspace_id', $ctx->workspace->id)->where('id', $accountId)->first();
        if (! $acct || ! $ctx->can('portfolio', 'read', $acct->scope_id)) {
            throw \App\Support\ApiError::notFound();
        }
        $value = function (string $d) use ($accountId): ?float {
            $snap = DB::table('balance_snapshots')->where('account_id', $accountId)->where('as_of', '<=', $d)->whereNull('archived_at')->orderByDesc('as_of')->value('balance');
            if ($snap !== null) {
                return (float) $snap;
            }
            $h = DB::table('holdings')->where('account_id', $accountId)->where('as_of', '<=', $d)->whereNull('archived_at')->orderByDesc('as_of')->first();
            if (! $h) {
                return null;
            }

            return (float) DB::table('holdings')->where('account_id', $accountId)->where('as_of', $h->as_of)->sum('market_value');
        };
        $flows = DB::table('transactions')->where('account_id', $accountId)->whereNull('archived_at')->whereBetween('posted_on', [$from, $to])
            ->whereIn('kind', ['contribution', 'withdrawal', 'transfer'])->orderBy('posted_on')->get(['posted_on', 'amount']);
        $start = $value($from);
        $end = $value($to);
        if ($start === null || $end === null) {
            return ['status' => 'insufficient_data', 'message' => 'Balances are needed at both the start and end dates.'];
        }
        // Monthly Modified Dietz chain.
        $twr = 1.0;
        $cursor = $from;
        $periods = 0;
        while (strtotime($cursor) < strtotime($to)) {
            $next = min(date('Y-m-t', strtotime($cursor)), $to);
            if ($next === $cursor) {
                $next = date('Y-m-d', strtotime($cursor.' +1 day'));
            }
            $v0 = $value($cursor);
            $v1 = $value($next);
            if ($v0 === null || $v1 === null) {
                return ['status' => 'insufficient_data', 'message' => "Missing balance around {$cursor}."];
            }
            $days = max(1, (strtotime($next) - strtotime($cursor)) / 86400);
            $cf = 0.0;
            $wcf = 0.0;
            foreach ($flows as $f) {
                if ($f->posted_on > $cursor && $f->posted_on <= $next) {
                    $w = (strtotime($next) - strtotime($f->posted_on)) / 86400 / $days;
                    $cf += (float) $f->amount;
                    $wcf += (float) $f->amount * $w;
                }
            }
            $den = $v0 + $wcf;
            if (abs($den) > 1e-9) {
                $twr *= 1 + ($v1 - $v0 - $cf) / $den;
            }
            $periods++;
            $cursor = $next;
        }
        // XIRR from the investor perspective.
        $cfs = [[$from, -$start]];
        foreach ($flows as $f) {
            $cfs[] = [$f->posted_on, -(float) $f->amount];
        }
        $cfs[] = [$to, $end];
        $rate = 0.05;
        for ($i = 0; $i < 100; $i++) {
            $npv = 0.0;
            $d = 0.0;
            foreach ($cfs as [$dt, $amt]) {
                $t = (strtotime($dt) - strtotime($from)) / 86400 / 365.0;
                $npv += $amt / (1 + $rate) ** $t;
                $d -= $t * $amt / (1 + $rate) ** ($t + 1);
            }
            if (abs($d) < 1e-12) {
                break;
            }
            $newRate = $rate - $npv / $d;
            if (abs($newRate - $rate) < 1e-9) {
                $rate = $newRate;
                break;
            }
            $rate = max(-0.99, $newRate);
        }
        $years = max(1 / 365, (strtotime($to) - strtotime($from)) / 86400 / 365);

        return ['status' => 'ok', 'from' => $from, 'to' => $to, 'start_value' => round($start, 2), 'end_value' => round($end, 2),
            'net_flows' => round(array_sum(array_map(fn ($f) => (float) $f->amount, $flows->all())), 2),
            'time_weighted' => ['cumulative' => round($twr - 1, 6), 'annualized' => $years >= 1 ? round($twr ** (1 / $years) - 1, 6) : null, 'periods' => $periods],
            'money_weighted' => ['irr_annualized' => round($rate, 6)],
            'explanation' => 'Time-weighted return removes the effect of contribution timing (manager skill); money-weighted return reflects your actual dollar experience including timing.'];
    }
}
