<?php

namespace App\Services\RealEstate;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Property tax history (assessment + levy per year), NJ quarterly installments, and the deed reference from the tax list.
 * Paying an installment posts a property_tax expense, which is what the tax engine reads (Schedule A/E, NJ deduction).
 */
final class PropertyTaxService
{
    /** NJ statutory due dates (N.J.S.A. 54:4-66): Feb 1, May 1, Aug 1, Nov 1. */
    public const NJ_DUE = [1 => '02-01', 2 => '05-01', 3 => '08-01', 4 => '11-01'];

    public function __construct(private readonly AccessContext $ctx) {}

    /** Store what the MOD-IV tax list says. Manual or bill-sourced years are never overwritten. */
    public function recordFromParcel(object $property, array $parcel): array
    {
        $listYear = (int) now()->format('Y');
        $out = ['years' => [], 'installments' => 0, 'instruments' => 0];
        $this->upsertYear($property, $listYear, [
            'assessed_land' => $parcel['assessed_land'] ?? null, 'assessed_improvement' => $parcel['assessed_improvement'] ?? null,
            'assessed_total' => $parcel['assessed_total'] ?? null, 'property_class' => $parcel['property_class'] ?? null,
        ]) && $out['years'][] = $listYear;
        if (! empty($parcel['last_year_tax']) && Dec::cmp(Dec::norm($parcel['last_year_tax']), '0') > 0) {
            $this->upsertYear($property, $listYear - 1, ['tax_amount' => Dec::norm($parcel['last_year_tax'])]) && $out['years'][] = $listYear - 1;
            $out['installments'] = $this->projectInstallments($property, $listYear, Dec::norm($parcel['last_year_tax']));
        }
        if (! empty($parcel['deed_book']) && ! empty($parcel['deed_page'])) {
            $exists = DB::table('recorded_instruments')->where('property_id', $property->id)->where('book', $parcel['deed_book'])->where('page', $parcel['deed_page'])->whereNull('archived_at')->exists();
            if (! $exists) {
                DB::table('recorded_instruments')->insert([
                    'id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id, 'property_id' => $property->id,
                    'kind' => 'deed', 'recorded_on' => $parcel['deed_date'] ?? null, 'book' => mb_substr((string) $parcel['deed_book'], 0, 16), 'page' => mb_substr((string) $parcel['deed_page'], 0, 16),
                    'consideration' => $parcel['sale_price'] ?? null, 'county' => $parcel['county'] ?? null, 'source' => 'mod_iv',
                    'notes' => 'From the municipal tax list (deed date shown; the recording date may differ). Attach the recorded copy from the county clerk.',
                    'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
                $out['instruments'] = 1;
            }
        }

        return $out;
    }

    /**
     * Projected installments for a year from the prior year's levy (NJ preliminary bills are half the prior year,
     * so quarters start at prior/4). Existing billed or paid installments are left alone.
     */
    public function projectInstallments(object $property, int $year, string $priorYearTax): int
    {
        $quarter = Dec::round(Dec::div($priorYearTax, '4', 6), 2);
        $created = 0;
        foreach (self::NJ_DUE as $q => $md) {
            $amount = $q === 4 ? Dec::sub($priorYearTax, Dec::mul($quarter, '3')) : $quarter;
            $row = DB::table('property_tax_installments')->where('property_id', $property->id)->where('tax_year', $year)->where('quarter', $q)->first();
            if ($row) {
                if ($row->status === 'projected' && $row->source === 'estimate') {
                    DB::table('property_tax_installments')->where('id', $row->id)->update(['amount' => $amount, 'updated_at' => now()]);
                }

                continue;
            }
            DB::table('property_tax_installments')->insert([
                'id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id, 'property_id' => $property->id,
                'tax_year' => $year, 'quarter' => $q, 'due_on' => "{$year}-{$md}", 'amount' => $amount, 'status' => 'projected', 'source' => 'estimate',
                'notes' => 'Estimated from the prior year levy; replace with the amount on your tax bill.',
                'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
            ]);
            $created++;
        }

        return $created;
    }

    /** Record a payment and post it as a property_tax expense so returns and Schedule E pick it up. */
    public function pay(string $installmentId, string $paidOn, string $amount): array
    {
        $row = Str::isUlid($installmentId) ? $this->ctx->constrain(DB::table('property_tax_installments'), 'real_estate', 'write')->where('id', $installmentId)->whereNull('archived_at')->first() : null;
        if (! $row) {
            throw ApiError::notFound();
        }
        if ($row->status === 'paid') {
            throw ApiError::conflict('This installment is already recorded as paid.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || ! strtotime($paidOn) || $paidOn > now()->addDay()->toDateString()) {
            throw new ApiError('validation_failed', 'Paid date must be a real date, not in the future.', 422);
        }
        $amount = Dec::tryNorm($amount);
        if ($amount === null || Dec::cmp($amount, '0') <= 0) {
            throw new ApiError('validation_failed', 'Enter the amount paid.', 422);
        }

        return DB::transaction(function () use ($row, $paidOn, $amount) {
            $expenseId = (string) Str::ulid();
            DB::table('property_expenses')->insert([
                'id' => $expenseId, 'workspace_id' => $row->workspace_id, 'scope_id' => $row->scope_id, 'property_id' => $row->property_id,
                'incurred_on' => $paidOn, 'category' => 'property_tax', 'amount' => $amount, 'treatment' => 'expense',
                'notes' => "Property tax {$row->tax_year} Q{$row->quarter}", 'source' => 'tax_installment',
                'created_by' => $this->ctx->user->id, 'updated_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
            ]);
            $n = DB::table('property_tax_installments')->where('id', $row->id)->where('edit_version', $row->edit_version)->update([
                'status' => 'paid', 'paid_on' => $paidOn, 'paid_amount' => $amount, 'expense_id' => $expenseId,
                'edit_version' => $row->edit_version + 1, 'updated_by' => $this->ctx->user->id, 'updated_at' => now(),
            ]);
            if ($n !== 1) {
                throw ApiError::conflict('This installment changed. Reload and try again.');
            }
            $this->refreshYearTotal($row->property_id, (int) $row->tax_year);
            Audit::log('property_tax.paid', 'property_tax_installments', $row->id, ['amount' => $amount, 'expense_id' => $expenseId], scopeId: $row->scope_id);

            return ['installment_id' => $row->id, 'expense_id' => $expenseId, 'status' => 'paid'];
        });
    }

    /** Year levy = sum of billed/paid installments once all four are known. */
    private function refreshYearTotal(string $propertyId, int $year): void
    {
        $rows = DB::table('property_tax_installments')->where('property_id', $propertyId)->where('tax_year', $year)->whereNull('archived_at')->get();
        if ($rows->count() === 4 && $rows->every(fn ($r) => in_array($r->status, ['billed', 'paid'], true))) {
            $p = DB::table('properties')->find($propertyId);
            $this->upsertYear($p, $year, ['tax_amount' => Dec::add(...$rows->map(fn ($r) => Dec::norm($r->status === 'paid' ? $r->paid_amount : $r->amount))->all())], 'tax_bill');
        }
    }

    private function upsertYear(object $property, int $year, array $values, string $source = 'mod_iv'): bool
    {
        $values = array_filter($values, fn ($v) => $v !== null && $v !== '');
        if (! $values) {
            return false;
        }
        $row = DB::table('property_tax_years')->where('property_id', $property->id)->where('tax_year', $year)->first();
        if ($row) {
            // Manual entries win; MOD-IV only fills blanks on bill-sourced years.
            if ($row->source === 'manual' || ($row->source === 'tax_bill' && $source === 'mod_iv')) {
                $values = array_filter($values, fn ($v, $k) => $row->{$k} === null, ARRAY_FILTER_USE_BOTH);
            }
            if (! $values) {
                return false;
            }
            DB::table('property_tax_years')->where('id', $row->id)->update($values + ['source' => $row->source === 'manual' ? 'manual' : $source, 'as_of' => now()->toDateString(),
                'edit_version' => $row->edit_version + 1, 'updated_at' => now()]);

            return true;
        }
        DB::table('property_tax_years')->insert($values + [
            'id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id, 'property_id' => $property->id,
            'tax_year' => $year, 'source' => $source, 'as_of' => now()->toDateString(),
            'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);

        return true;
    }
}
