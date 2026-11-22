<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Confirms reviewed paycheck extractions into payroll snapshots with lineage and YTD progression checks. */
final class PayrollService
{
    public const AMOUNT_FIELDS = ['gross', 'net', 'federal_taxable_wages', 'state_wages', 'ss_wages', 'medicare_wages', 'federal_withholding', 'social_security',
        'medicare', 'additional_medicare', 'nj_withholding', 'nj_sdi', 'nj_fli', 'nj_ui', 'pretax_401k', 'roth_401k', 'hsa', 'fsa', 'medical_premium', 'dental', 'vision',
        'espp', 'rsu_income', 'bonus', 'deferred_comp', 'post_tax_deductions', 'net_adjustments'];

    public function approveExtraction(AccessContext $ctx, string $extractionId, array $data): array
    {
        $ex = DB::table('document_extractions')->where('workspace_id', $ctx->workspace->id)->where('id', $extractionId)->first();
        if (! $ex || ! $ctx->can('vault', 'read', $ex->scope_id)) {
            throw ApiError::notFound();
        }
        if ($ex->status !== 'needs_review') {
            throw ApiError::conflict('This extraction was already '.$ex->status.'.');
        }
        $emp = DB::table('employments')->where('workspace_id', $ctx->workspace->id)->where('id', $data['employment_id'] ?? '')->whereNull('archived_at')->first();
        if (! $emp || ! $ctx->can('compensation', 'write', $emp->scope_id)) {
            throw ApiError::forbidden('Choose an employment record you can update.');
        }
        $fields = json_decode((string) $ex->fields, true) ?: [];
        $overrides = (array) ($data['fields'] ?? []);
        $current = [];
        $ytd = [];
        foreach (self::AMOUNT_FIELDS as $k) {
            $c = $overrides[$k]['current'] ?? ($fields[$k]['current'] ?? null);
            $y = $overrides[$k]['ytd'] ?? ($fields[$k]['ytd'] ?? null);
            if ($c !== null && $c !== '') {
                $current[$k] = Dec::norm($c);
            }
            if ($y !== null && $y !== '') {
                $ytd[$k] = Dec::norm($y);
            }
        }
        $payDate = $overrides['pay_date'] ?? ($fields['pay_date']['value'] ?? null);
        if (! $payDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $payDate) || ! isset($current['gross'])) {
            throw new ApiError('validation_failed', 'Pay date and gross pay are required.');
        }
        $checks = $this->checks($emp->id, $payDate, $current, $ytd, $data['run_kind'] ?? 'regular');
        $blocking = array_filter($checks, fn ($c) => ($c['blocking'] ?? false));
        if ($blocking && empty($data['acknowledge_checks'])) {
            throw new ApiError('checks_failed', 'Some checks failed. Correct the values or acknowledge them explicitly.', 422, ['checks' => array_values($checks)]);
        }
        $edited = array_keys(array_filter($overrides, fn ($v) => $v !== null));

        return DB::transaction(function () use ($ctx, $ex, $emp, $payDate, $current, $ytd, $checks, $data, $overrides, $edited) {
            $supersedes = null;
            if (($data['run_kind'] ?? 'regular') === 'correction') {
                $supersedes = DB::table('payroll_snapshots')->where('employment_id', $emp->id)->where('pay_date', $payDate)->where('status', 'approved')->value('id');
                if ($supersedes) {
                    DB::table('payroll_snapshots')->where('id', $supersedes)->update(['status' => 'superseded', 'updated_at' => now()]);
                }
            }
            $id = (string) Str::ulid();
            DB::table('payroll_snapshots')->insert([
                'id' => $id, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $emp->scope_id, 'employment_id' => $emp->id, 'person_id' => $emp->person_id,
                'pay_date' => $payDate, 'period_start' => $overrides['period_start'] ?? (json_decode((string) $ex->fields, true)['period_start']['value'] ?? null),
                'period_end' => $overrides['period_end'] ?? (json_decode((string) $ex->fields, true)['period_end']['value'] ?? null),
                'run_kind' => $data['run_kind'] ?? 'regular', 'status' => 'approved', 'gross' => $current['gross'], 'net' => $current['net'] ?? null,
                'current' => json_encode($current), 'ytd' => json_encode($ytd), 'checks' => json_encode($checks), 'document_id' => $ex->document_id,
                'extraction_id' => $ex->id, 'supersedes_id' => $supersedes, 'approved_by' => $ctx->user->id, 'approved_at' => now(),
                'source' => 'extraction', 'source_ref' => $ex->id, 'value_state' => 'actual', 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
            ]);
            DB::table('document_extractions')->where('id', $ex->id)->update(['status' => 'approved', 'reviewed_by' => $ctx->user->id, 'approved_at' => now(),
                'overrides' => json_encode($overrides), 'target_type' => 'payroll_snapshot', 'target_id' => $id, 'updated_at' => now()]);
            DB::table('review_items')->where('workspace_id', $ctx->workspace->id)->where('dedup_key', 'extraction:'.$ex->id)
                ->update(['status' => 'resolved', 'resolved_by' => $ctx->user->id, 'resolved_at' => now(), 'resolution' => json_encode(['payroll_snapshot_id' => $id])]);
            Audit::log('payroll.approved', 'payroll_snapshot', $id, ['extraction_id' => $ex->id, 'edited_fields' => $edited], scopeId: $emp->scope_id);
            Outbox::publish('payroll.approved', ['payroll_snapshot_id' => $id, 'person_id' => $emp->person_id, 'year' => (int) substr($payDate, 0, 4),
                'initiator_membership_id' => $ctx->membership->id], 'payroll_snapshot', $id);

            return ['payroll_snapshot_id' => $id, 'checks' => $checks];
        });
    }

    public function checks(string $employmentId, string $payDate, array $current, array $ytd, string $runKind): array
    {
        $out = [];
        $prev = DB::table('payroll_snapshots')->where('employment_id', $employmentId)->where('status', 'approved')->where('pay_date', '<', $payDate)
            ->whereRaw('substr(pay_date, 1, 4) = ?', [substr($payDate, 0, 4)])->orderByDesc('pay_date')->first();
        if ($prev && isset($ytd['gross'])) {
            $prevYtd = json_decode((string) $prev->ytd, true) ?: [];
            if (isset($prevYtd['gross'])) {
                $expected = Dec::add(Dec::norm($prevYtd['gross']), $current['gross']);
                $diff = Dec::sub($ytd['gross'], $expected);
                $ok = Dec::cmp(Dec::abs($diff), '1.00') <= 0;
                $out[] = ['check' => 'ytd_progression', 'passed' => $ok, 'blocking' => ! $ok && $runKind === 'regular',
                    'message' => $ok ? 'YTD gross equals the prior YTD plus this paycheck.' : "YTD gross differs from prior YTD + current by {$diff}; an off-cycle or correction run may be missing."];
            }
        }
        if (isset($current['net'])) {
            $ded = '0';
            foreach (self::AMOUNT_FIELDS as $k) {
                if (! in_array($k, ['gross', 'net', 'federal_taxable_wages', 'state_wages', 'ss_wages', 'medicare_wages', 'bonus', 'rsu_income', 'espp', 'net_adjustments'], true) && isset($current[$k])) {
                    $ded = Dec::add($ded, $current[$k]);
                }
            }
            // Net adjustments are after-tax additions (e.g. expense reimbursements) that raise net pay.
            $diff = Dec::sub(Dec::add(Dec::sub($current['gross'], $ded), $current['net_adjustments'] ?? '0'), $current['net']);
            $out[] = ['check' => 'net_components', 'passed' => Dec::cmp(Dec::abs($diff), '1.00') <= 0, 'blocking' => false,
                'message' => Dec::cmp(Dec::abs($diff), '1.00') <= 0 ? 'Gross minus deductions equals net.' : "Unexplained difference of {$diff} between gross, deductions and net (imputed income or unlisted deductions)."];
            if (Dec::cmp($current['net'], $current['gross']) > 0) {
                $out[] = ['check' => 'net_le_gross', 'passed' => false, 'blocking' => true, 'message' => 'Net pay exceeds gross pay.'];
            }
        }
        foreach ($ytd as $k => $v) {
            // Net adjustments (reimbursements, reversals) are not a running total on many stubs.
            if ($k !== 'net_adjustments' && isset($current[$k]) && Dec::cmp($v, $current[$k]) < 0) {
                $out[] = ['check' => "{$k}_ytd_lt_current", 'passed' => false, 'blocking' => true, 'message' => "YTD {$k} is less than the current amount."];
            }
        }
        if (DB::table('payroll_snapshots')->where('employment_id', $employmentId)->where('pay_date', $payDate)->where('status', 'approved')->exists() && $runKind !== 'correction') {
            $out[] = ['check' => 'duplicate_pay_date', 'passed' => false, 'blocking' => true, 'message' => 'A paycheck for this date is already approved; use run type "correction".'];
        }

        return $out;
    }
}
