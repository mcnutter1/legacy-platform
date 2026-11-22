<?php

namespace App\Services;

use App\Jobs\JobService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Year close: income, cash flow, spending, taxes and a beginning→ending wealth bridge with explicit residual.
 * Closed snapshots are immutable; reopening creates a new version that supersedes the prior one.
 */
final class YearCloseService
{
    public function request(AccessContext $ctx, int $year, string $scopeId): array
    {
        if (! $ctx->can('ledger', 'write', $scopeId)) {
            throw ApiError::forbidden();
        }
        $job = app(JobService::class)->enqueue($ctx, 'yearclose.build', ['year' => $year, 'scope_id' => $scopeId], 'annual_snapshot', null, $scopeId);

        return ['job' => $job->present()];
    }

    public function build(AccessContext $ctx, int $year, string $scopeId): array
    {
        $start = ($year - 1).'-12-31';
        $end = "{$year}-12-31";
        $nw = app(NetWorthService::class);
        $begin = $nw->compute($ctx, $start);
        $finish = $nw->compute($ctx, $end);
        $tx = $ctx->constrain(DB::table('transactions'), 'ledger', 'read')->whereNull('archived_at')->whereBetween('posted_on', ["{$year}-01-01", $end]);
        $sumKind = fn (array $kinds, ?string $sign = null) => (string) ((clone $tx)->whereIn('kind', $kinds)->whereNull('transfer_group')
            ->when($sign === '+', fn ($q) => $q->where('amount', '>', 0))->when($sign === '-', fn ($q) => $q->where('amount', '<', 0))->sum('amount') ?: '0');

        $earned = $ctx->canAny('compensation') ? (string) ($ctx->constrain(DB::table('payroll_snapshots'), 'compensation', 'read')->where('status', 'approved')
            ->whereBetween('pay_date', ["{$year}-01-01", $end])->sum('gross') ?: '0') : null;
        $business = (string) ((clone $tx)->whereNotNull('entity_id')->where('amount', '>', 0)->sum('amount') ?: '0');
        $investment = $sumKind(['dividend', 'interest'], '+');
        $rental = $ctx->canAny('real_estate') ? (string) ($ctx->constrain(DB::table('property_stays'), 'real_estate', 'read')->whereNull('archived_at')->where('cancelled', false)
            ->whereBetween('check_in', ["{$year}-01-01", $end])->sum('gross_rent') ?: '0') : null;
        $spending = Dec::abs(Dec::norm($sumKind(['spend'], '-')));
        $contrib = Dec::norm($sumKind(['contribution']));
        $withdraw = Dec::abs(Dec::norm($sumKind(['withdrawal'])));
        $taxesPaid = $ctx->canAny('tax') ? (string) ($ctx->constrain(DB::table('tax_payments'), 'tax', 'read')->where('status', 'paid')->whereBetween('paid_on', ["{$year}-01-01", $end])->sum('amount') ?: '0') : null;
        $withheld = $ctx->canAny('compensation') ? array_reduce($ctx->constrain(DB::table('payroll_snapshots'), 'compensation', 'read')->where('status', 'approved')
            ->whereBetween('pay_date', ["{$year}-01-01", $end])->get(['current'])->all(), function ($c, $s) {
                $cur = json_decode((string) $s->current, true) ?: [];

                return Dec::add($c, Dec::norm($cur['federal_withholding'] ?? '0'), Dec::norm($cur['nj_withholding'] ?? '0'));
            }, '0') : null;
        $run = $ctx->canAny('tax') ? $ctx->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('tax_year', $year)->whereNotIn('status', ['pending', 'failed'])->orderByDesc('created_at')->first() : null;
        $runSummary = $run ? json_decode((string) $run->summary, true) : null;

        // Bridge: investment change = ending − beginning − net contributions; market = residual within invested accounts.
        $cat = fn (array $n, string $c) => collect($n['categories'])->where('category', $c)->sum(fn ($x) => (float) $x['value']);
        $investedBegin = $cat($begin, 'Investments') + $cat($begin, 'Retirement') + $cat($begin, 'Education & health');
        $investedEnd = $cat($finish, 'Investments') + $cat($finish, 'Retirement') + $cat($finish, 'Education & health');
        $market = $investedEnd - $investedBegin - ((float) $contrib - (float) $withdraw);
        $propBegin = $cat($begin, 'Real estate');
        $propEnd = $cat($finish, 'Real estate');
        $debtBegin = (float) $begin['total_liabilities'];
        $debtEnd = (float) $finish['total_liabilities'];
        $savings = ((float) ($earned ?? 0) + (float) $business + (float) $investment + (float) ($rental ?? 0)) - (float) $spending - (float) ($taxesPaid ?? 0) - (float) ($withheld ?? 0);
        $delta = (float) $finish['net_worth'] - (float) $begin['net_worth'];
        $explained = $savings + $market + ($propEnd - $propBegin) + ($debtBegin - $debtEnd);
        $bridge = [
            'beginning_net_worth' => $begin['net_worth'], 'net_savings_from_cash_flow' => Dec::norm(round($savings, 2)),
            'investment_market_change' => Dec::norm(round($market, 2)), 'property_value_change' => Dec::norm(round($propEnd - $propBegin, 2)),
            'debt_reduction' => Dec::norm(round($debtBegin - $debtEnd, 2)), 'fx_effect' => '0.0000',
            'unexplained_residual' => Dec::norm(round($delta - $explained, 2)), 'ending_net_worth' => $finish['net_worth'],
        ];
        $data = [
            'year' => $year, 'income' => array_filter(['earned' => $earned, 'business' => $business, 'investment' => $investment, 'rental' => $rental], fn ($v) => $v !== null),
            'spending' => $spending, 'contributions' => $contrib, 'withdrawals' => $withdraw, 'taxes_paid' => $taxesPaid, 'taxes_withheld' => $withheld,
            'tax_liability' => $runSummary['combined']['total_tax'] ?? null, 'tax_run_id' => $run?->id, 'tax_run_status' => $run?->status,
            'beginning' => ['net_worth' => $begin['net_worth'], 'assets' => $begin['total_assets'], 'liabilities' => $begin['total_liabilities']],
            'ending' => ['net_worth' => $finish['net_worth'], 'assets' => $finish['total_assets'], 'liabilities' => $finish['total_liabilities'], 'liquid' => $finish['liquid_assets']],
        ];
        $coverage = ['unvalued_begin' => $begin['unvalued'], 'unvalued_end' => $finish['unvalued'], 'issues' => array_merge($begin['issues'], $finish['issues'])];
        $existing = DB::table('annual_snapshots')->where('workspace_id', $ctx->workspace->id)->where('scope_id', $scopeId)->where('year', $year)->orderByDesc('version')->first();
        if ($existing && $existing->status === 'closed') {
            throw ApiError::conflict("{$year} is closed. Reopen it with a reason to create a new version.");
        }
        $id = $existing?->id ?? (string) Str::ulid();
        $row = ['data' => json_encode($data), 'bridge' => json_encode($bridge), 'coverage' => json_encode($coverage), 'updated_at' => now(), 'updated_by' => $ctx->user->id];
        if ($existing) {
            DB::table('annual_snapshots')->where('id', $id)->update($row + ['edit_version' => $existing->edit_version + 1]);
        } else {
            DB::table('annual_snapshots')->insert($row + ['id' => $id, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $scopeId, 'year' => $year, 'version' => 1,
                'status' => 'draft', 'view' => 'consolidated', 'basis' => 'calendar', 'created_by' => $ctx->user->id, 'created_at' => now(), 'edit_version' => 1]);
        }
        Audit::log('yearclose.built', 'annual_snapshot', $id, ['year' => $year], scopeId: $scopeId);

        return ['snapshot_id' => $id, 'residual' => $bridge['unexplained_residual']];
    }

    public function close(AccessContext $ctx, string $snapshotId): void
    {
        $s = $ctx->constrain(DB::table('annual_snapshots'), 'ledger', 'approve')->where('id', $snapshotId)->first();
        if (! $s || $s->status !== 'draft') {
            throw ApiError::conflict('Only draft snapshots can be closed.');
        }
        $hash = hash('sha256', $s->data.$s->bridge.$s->coverage);
        DB::table('annual_snapshots')->where('id', $s->id)->update(['status' => 'closed', 'closed_at' => now(), 'approved_by' => $ctx->user->id, 'content_hash' => $hash, 'edit_version' => $s->edit_version + 1]);
        Audit::log('yearclose.closed', 'annual_snapshot', $s->id, ['year' => $s->year, 'hash' => substr($hash, 0, 16)], scopeId: $s->scope_id);
    }

    public function reopen(AccessContext $ctx, string $snapshotId, string $reason): string
    {
        $s = $ctx->constrain(DB::table('annual_snapshots'), 'ledger', 'approve')->where('id', $snapshotId)->first();
        if (! $s || $s->status !== 'closed') {
            throw ApiError::conflict('Only closed snapshots can be reopened.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new ApiError('validation_failed', 'Explain why the year is being reopened (10+ characters).');
        }
        $id = (string) Str::ulid();
        DB::transaction(function () use ($s, $id, $ctx, $reason) {
            DB::table('annual_snapshots')->where('id', $s->id)->update(['status' => 'superseded']);
            DB::table('annual_snapshots')->insert(['id' => $id, 'workspace_id' => $s->workspace_id, 'scope_id' => $s->scope_id, 'year' => $s->year, 'version' => $s->version + 1,
                'status' => 'draft', 'view' => $s->view, 'basis' => $s->basis, 'data' => $s->data, 'bridge' => $s->bridge, 'coverage' => $s->coverage,
                'reopen_reason' => $reason, 'supersedes_id' => $s->id, 'approved_by' => $ctx->user->id, 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            Audit::log('yearclose.reopened', 'annual_snapshot', $id, ['year' => $s->year, 'supersedes' => $s->id], scopeId: $s->scope_id);
        });

        return $id;
    }
}
