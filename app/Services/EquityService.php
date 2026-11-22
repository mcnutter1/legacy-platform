<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Equity compensation lifecycle: tranche generation, exercise/vest/settle events and share conservation. */
final class EquityService
{
    /** Generate time-based tranches from vesting terms {cliff_months, total_months, frequency_months}. Replaces scheduled tranches only. */
    public function generateTranches(AccessContext $ctx, string $grantId): array
    {
        $g = $ctx->constrain(DB::table('equity_grants'), 'compensation', 'write')->where('id', $grantId)->first();
        if (! $g) {
            throw ApiError::notFound();
        }
        $v = json_decode((string) $g->vesting, true) ?: [];
        $total = (int) ($v['total_months'] ?? 48);
        $cliff = (int) ($v['cliff_months'] ?? 12);
        $freq = max(1, (int) ($v['frequency_months'] ?? 1));
        $condition = $v['condition'] ?? 'time';
        $start = $g->vest_start_date ?: $g->grant_date;
        if ($total <= 0 || $cliff > $total) {
            throw new ApiError('validation_failed', 'Vesting terms are invalid.');
        }
        $units = Dec::norm($g->units, 10);
        $dates = [];
        for ($m = $cliff ?: $freq; $m <= $total; $m += $freq) {
            $dates[] = $m;
        }
        if (! in_array($total, $dates, true)) {
            $dates[] = $total;
        }
        $rows = [];
        $allocated = '0';
        foreach ($dates as $i => $m) {
            $cumulative = bcdiv(bcmul($units, (string) $m, 10), (string) $total, 0);
            $u = $i === array_key_last($dates) ? bcsub($units, $allocated, 10) : bcsub($cumulative, $allocated, 10);
            $allocated = bcadd($allocated, $u, 10);
            $rows[] = ['vest_date' => date('Y-m-d', strtotime($start." +{$m} months")), 'units' => $u];
        }

        return DB::transaction(function () use ($ctx, $g, $rows, $condition) {
            DB::table('equity_tranches')->where('grant_id', $g->id)->where('status', 'scheduled')->where('units_exercised', 0)->delete();
            foreach ($rows as $r) {
                DB::table('equity_tranches')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $g->workspace_id, 'scope_id' => $g->scope_id, 'grant_id' => $g->id,
                    'vest_date' => $r['vest_date'], 'units' => $r['units'], 'condition' => $condition, 'status' => 'scheduled',
                    'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            }
            Audit::log('equity.tranches_generated', 'equity_grant', $g->id, ['count' => count($rows)], scopeId: $g->scope_id);

            return ['tranches' => count($rows)];
        });
    }

    /** Record exercise/settlement while conserving units: exercised options and issued shares never both count. */
    public function recordEvent(AccessContext $ctx, string $grantId, array $data): string
    {
        $g = $ctx->constrain(DB::table('equity_grants'), 'compensation', 'write')->where('id', $grantId)->first();
        if (! $g) {
            throw ApiError::notFound();
        }
        $kind = (string) ($data['kind'] ?? '');
        $units = Dec::norm($data['units'] ?? '0', 10);
        if (! in_array($kind, ['vest', 'exercise', 'settle', 'sell', 'sell_to_cover', 'forfeit', 'expire', 'purchase'], true) || bccomp($units, '0', 10) <= 0) {
            throw new ApiError('validation_failed', 'Valid event kind and positive units are required.');
        }
        $date = (string) ($data['event_date'] ?? '');
        $tranches = DB::table('equity_tranches')->where('grant_id', $g->id)->whereNull('archived_at')->orderBy('vest_date')->get();
        $vested = $tranches->where('vest_date', '<=', $date)->where('status', '!=', 'forfeited')->sum(fn ($t) => (float) $t->units);
        $exercised = $tranches->sum(fn ($t) => (float) $t->units_exercised);
        if (in_array($kind, ['exercise'], true)) {
            if ($g->expiration_date && $date > $g->expiration_date) {
                throw ApiError::conflict('The grant expired on '.$g->expiration_date.'.');
            }
            if ((float) $units > $vested - $exercised + 1e-9) {
                throw ApiError::conflict('Exercise exceeds vested, unexercised units ('.round($vested - $exercised, 4).').');
            }
        }
        $fmv = isset($data['fmv']) ? Dec::norm($data['fmv'], 8) : null;
        $strike = Dec::norm($g->strike_price ?? '0', 8);
        $ordinary = null;
        if ($fmv !== null) {
            $ordinary = match (true) {
                $kind === 'exercise' && $g->grant_type === 'nso' => Dec::mul($units, Dec::sub($fmv, $strike)),
                in_array($kind, ['vest', 'settle'], true) && in_array($g->grant_type, ['rsu', 'psu', 'rsa'], true) => Dec::mul($units, $fmv),
                default => null,
            };
        }

        return DB::transaction(function () use ($ctx, $g, $kind, $units, $date, $data, $fmv, $ordinary, $tranches) {
            $id = (string) Str::ulid();
            $lotId = null;
            if (in_array($kind, ['exercise', 'settle', 'purchase'], true) && ! empty($data['account_id'])) {
                $acct = $ctx->constrain(DB::table('accounts'), 'ledger', 'write')->where('id', $data['account_id'])->first();
                if (! $acct) {
                    throw ApiError::notFound('Brokerage account not found.');
                }
                $instr = DB::table('instruments')->where('workspace_id', $g->workspace_id)->where('symbol', $g->symbol ?: Str::upper(Str::slug($g->company_name, '')))->value('id');
                if (! $instr) {
                    $instr = (string) Str::ulid();
                    DB::table('instruments')->insert(['id' => $instr, 'workspace_id' => $g->workspace_id, 'symbol' => $g->symbol ?: Str::upper(substr(Str::slug($g->company_name, ''), 0, 12)),
                        'name' => $g->company_name, 'kind' => $g->symbol ? 'equity' : 'private', 'created_at' => now(), 'updated_at' => now()]);
                }
                $withheld = Dec::norm($data['units_withheld'] ?? '0', 10);
                $netUnits = bcsub($units, $withheld, 10);
                // Basis: FMV at vest/exercise for RSU and NSO (income already in W-2); strike for ISO.
                $basisPerShare = $g->grant_type === 'iso' ? Dec::norm($g->strike_price ?? '0', 8) : ($fmv ?? Dec::norm($g->strike_price ?? '0', 8));
                $lotId = (string) Str::ulid();
                DB::table('lots')->insert(['id' => $lotId, 'workspace_id' => $g->workspace_id, 'scope_id' => $acct->scope_id, 'account_id' => $acct->id, 'instrument_id' => $instr,
                    'acquired_on' => $date, 'quantity' => $netUnits, 'remaining_quantity' => $netUnits, 'cost_basis' => Dec::mul($netUnits, $basisPerShare),
                    'origin' => $kind === 'exercise' ? 'option_exercise' : ($kind === 'purchase' ? 'espp' : 'rsu_vest'), 'source' => 'equity_event', 'source_ref' => $id,
                    'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            }
            DB::table('equity_events')->insert(['id' => $id, 'workspace_id' => $g->workspace_id, 'scope_id' => $g->scope_id, 'grant_id' => $g->id, 'kind' => $kind,
                'event_date' => $date, 'units' => $units, 'fmv' => $fmv, 'price' => isset($data['price']) ? Dec::norm($data['price'], 8) : null,
                'units_withheld' => isset($data['units_withheld']) ? Dec::norm($data['units_withheld'], 10) : null, 'tax_withheld' => isset($data['tax_withheld']) ? Dec::norm($data['tax_withheld']) : null,
                'proceeds' => isset($data['proceeds']) ? Dec::norm($data['proceeds']) : null, 'ordinary_income' => $ordinary, 'exercise_method' => $data['exercise_method'] ?? null,
                'lot_id' => $lotId, 'reconciliation' => 'pending', 'source' => 'manual', 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            if (in_array($kind, ['exercise', 'settle'], true)) {
                $left = $units;
                foreach ($tranches->where('vest_date', '<=', $date) as $t) {
                    $room = bcsub(Dec::norm($t->units, 10), Dec::norm($t->units_exercised, 10), 10);
                    if (bccomp($room, '0', 10) <= 0 || bccomp($left, '0', 10) <= 0) {
                        continue;
                    }
                    $take = bccomp($room, $left, 10) <= 0 ? $room : $left;
                    DB::table('equity_tranches')->where('id', $t->id)->update(['units_exercised' => bcadd(Dec::norm($t->units_exercised, 10), $take, 10), 'status' => 'vested']);
                    $left = bcsub($left, $take, 10);
                }
            } elseif ($kind === 'forfeit') {
                DB::table('equity_tranches')->where('grant_id', $g->id)->where('vest_date', '>', $date)->where('status', 'scheduled')->update(['status' => 'forfeited']);
            }
            Audit::log('equity.event_recorded', 'equity_grant', $g->id, ['kind' => $kind], scopeId: $g->scope_id);

            return $id;
        });
    }

    /** Match vest/exercise income to payroll before it counts as realized. */
    public function reconcile(AccessContext $ctx, int $year): array
    {
        $events = $ctx->constrain(DB::table('equity_events'), 'compensation', 'read')->whereNull('archived_at')->whereIn('kind', ['vest', 'settle', 'exercise'])
            ->whereBetween('event_date', ["{$year}-01-01", "{$year}-12-31"])->get();
        $out = [];
        foreach ($events as $e) {
            $grant = DB::table('equity_grants')->find($e->grant_id);
            $payroll = DB::table('payroll_snapshots')->where('person_id', $grant->person_id)->where('status', 'approved')
                ->whereBetween('pay_date', [date('Y-m-d', strtotime($e->event_date.' -10 days')), date('Y-m-d', strtotime($e->event_date.' +35 days'))])->get();
            $match = $payroll->first(function ($p) use ($e) {
                $cur = json_decode((string) $p->current, true) ?: [];
                $rsu = (float) ($cur['rsu_income'] ?? 0);

                return $e->ordinary_income !== null && abs($rsu - (float) $e->ordinary_income) <= max(1.0, 0.01 * (float) $e->ordinary_income);
            });
            $status = $match ? 'matched_payroll' : 'pending';
            DB::table('equity_events')->where('id', $e->id)->update(['reconciliation' => $status, 'payroll_snapshot_id' => $match?->id]);
            $out[] = ['event_id' => $e->id, 'kind' => $e->kind, 'date' => $e->event_date, 'ordinary_income' => $e->ordinary_income, 'status' => $status];
        }

        return ['year' => $year, 'events' => $out, 'unmatched' => count(array_filter($out, fn ($o) => $o['status'] === 'pending'))];
    }
}
