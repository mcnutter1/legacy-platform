<?php

namespace App\Http\Controllers\Api;

use App\Resources\ResourceService;
use App\Services\NetWorthService;
use App\Services\RealEstate\PropertyLookupService;
use App\Services\RealEstate\PropertyTaxService;
use App\Services\TitleService;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Property public-record lookups, loans secured by property/vehicles/boats, and valuation review. */
class PropertyController extends ApiController
{
    private const COLLATERAL = ['property' => 'properties', 'asset' => 'assets'];

    public function lookup(Request $r): JsonResponse
    {
        $key = 'property-lookup:'.$this->ctx()->user->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw new ApiError('rate_limited', 'Too many lookups. Try again in a minute.', 429);
        }
        RateLimiter::hit($key, 60);

        return $this->ok((new PropertyLookupService($this->ctx()))->lookup($r->only(['address', 'mun_code', 'block', 'lot', 'qualifier'])));
    }

    public function applyPublicRecords(Request $r, string $property): JsonResponse
    {
        $row = (new ResourceService($this->ctx()))->find('properties', $property, 'write');
        $res = (new PropertyLookupService($this->ctx()))->apply($row, $r->only(['address', 'mun_code', 'block', 'lot', 'qualifier']), $r->input('pin'));

        return $this->ok($res);
    }

    /** Value, secured loans, equity and loan-to-value for a property or a titled asset (vehicle, boat, …). */
    public function financing(string $type, string $id): JsonResponse
    {
        $table = self::COLLATERAL[$type] ?? throw ApiError::notFound();
        $ctx = $this->ctx();
        $subject = (new ResourceService($ctx))->find($table, $id);
        $today = now()->toDateString();
        [$value, $method, $asOf] = app(NetWorthService::class)->valuation($type, $id, $today);
        if ($value === null && $type === 'property' && $subject->purchase_price) {
            [$value, $method, $asOf] = [Dec::norm($subject->purchase_price), 'purchase price', $subject->acquired_on];
        } elseif ($value === null && $type === 'asset' && $subject->cost) {
            [$value, $method, $asOf] = [Dec::norm($subject->cost), 'cost', $subject->acquired_on];
        }
        $loans = $ctx->canAny('debt')
            ? $ctx->constrain(DB::table('liabilities'), 'debt', 'read')->where('collateral_type', $type)->where('collateral_id', $id)->whereNull('archived_at')
                ->orderBy('name')->get(['id', 'name', 'kind', 'lender', 'current_balance', 'balance_as_of', 'interest_rate', 'payment_amount', 'maturity_on', 'status', 'edit_version'])
            : collect();
        $debt = '0';
        foreach ($loans as $l) {
            if ($l->status === 'active' && $l->current_balance !== null) {
                $debt = Dec::add($debt, Dec::norm($l->current_balance));
            }
        }
        $pending = DB::table('valuations')->where('workspace_id', $ctx->workspace->id)->where('subject_type', $type)->where('subject_id', $id)
            ->where('status', 'proposed')->whereNull('archived_at')->count();

        return $this->ok([
            'subject' => ['type' => $type, 'id' => $id, 'name' => $subject->name],
            'value' => $value, 'value_method' => $method, 'value_as_of' => $asOf, 'proposed_valuations' => $pending,
            'assessed_total' => $type === 'property' && $subject->assessed_total !== null ? Dec::norm($subject->assessed_total) : null,
            'loans' => $loans->map(fn ($l) => (array) $l + ['current_balance' => $l->current_balance !== null ? Dec::norm($l->current_balance) : null])->values(),
            'loans_visible' => $ctx->canAny('debt'),
            'total_debt' => $debt,
            'equity' => $value !== null ? Dec::sub($value, $debt) : null,
            'loan_to_value' => $value !== null && Dec::cmp($value, '0') > 0 ? Dec::div($debt, $value, 4) : null,
        ]);
    }

    public function payTaxInstallment(Request $r, string $installment): JsonResponse
    {
        $d = $this->validated($r, ['paid_on' => 'required|date_format:Y-m-d', 'amount' => 'required|string|max:24']);

        return $this->ok((new PropertyTaxService($this->ctx()))->pay($installment, $d['paid_on'], $d['amount']));
    }

    /** Project NJ quarterly installments for a year from the prior year's levy on file. */
    public function projectTaxes(Request $r, string $property): JsonResponse
    {
        $row = (new ResourceService($this->ctx()))->find('properties', $property, 'write');
        $year = (int) $r->input('tax_year', now()->format('Y'));
        if ($year < 2000 || $year > (int) now()->format('Y') + 1) {
            throw new ApiError('validation_failed', 'Choose a tax year from 2000 to next year.', 422);
        }
        $prior = DB::table('property_tax_years')->where('property_id', $row->id)->where('tax_year', $year - 1)->whereNull('archived_at')->value('tax_amount')
            ?? ($year - 1 === (int) now()->format('Y') - 1 ? $row->annual_property_tax : null);
        if ($prior === null || Dec::cmp(Dec::norm($prior), '0') <= 0) {
            throw ApiError::unsupported('Enter the '.($year - 1).' tax amount (Assessments & property tax) before projecting installments.');
        }
        $n = DB::transaction(fn () => (new PropertyTaxService($this->ctx()))->projectInstallments($row, $year, Dec::norm($prior)));

        return $this->ok(['created' => $n, 'tax_year' => $year]);
    }

    public function title(string $type, string $id): JsonResponse
    {
        return $this->ok((new TitleService($this->ctx()))->current($type, $id));
    }

    public function setTitle(Request $r, string $type, string $id): JsonResponse
    {
        return $this->ok((new TitleService($this->ctx()))->set($type, $id, $r->only(['title_form', 'effective_from', 'owners', 'evidence_document_id'])));
    }

    public function countyRecords(string $property): JsonResponse
    {
        $row = (new ResourceService($this->ctx()))->find('properties', $property, 'write');
        if (strtoupper((string) $row->county) !== 'MONMOUTH' || ! $row->block || ! $row->lot) {
            throw ApiError::unsupported('County tax cards and clerk records are integrated for Monmouth County parcels with a block and lot. Look up public records first.');
        }
        $job = app(\App\Jobs\JobService::class)->enqueue($this->ctx(), 'property.county_records', ['property_id' => $row->id], 'property', $row->id, $row->scope_id);

        return $this->accepted($job->present());
    }

    public function comps(string $property): JsonResponse
    {
        $row = (new ResourceService($this->ctx()))->find('properties', $property);

        return $this->ok((new PropertyLookupService($this->ctx()))->comps($row));
    }

    private const ESTIMATE_SOURCES = [
        'zillow' => ['Zillow Zestimate', 'model'], 'redfin' => ['Redfin Estimate', 'model'], 'realtor' => ['Realtor.com estimate', 'model'],
        'appraisal' => ['Appraisal', 'appraisal'], 'broker' => ['Broker price opinion', 'comps'], 'public_records_comps' => ['NJ tax-list comparable sales', 'comps'],
        'own' => ['Owner estimate', 'owner_override'], 'other' => ['Other source', 'model'],
    ];

    /** Record a value seen elsewhere (Zillow, Redfin, an appraisal…) with its source; optionally use it as the current value. */
    public function recordEstimate(Request $r, string $type, string $id): JsonResponse
    {
        $table = self::COLLATERAL[$type] ?? throw ApiError::notFound();
        $ctx = $this->ctx();
        $subject = (new ResourceService($ctx))->find($table, $id);
        if (! $ctx->can('assets', 'write', $subject->scope_id)) {
            throw ApiError::forbidden();
        }
        $d = $this->validated($r, ['source' => 'required|in:'.implode(',', array_keys(self::ESTIMATE_SOURCES)), 'value' => 'required|string|max:24', 'low' => 'nullable|string|max:24',
            'high' => 'nullable|string|max:24', 'as_of' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'url' => 'nullable|url:https|max:500', 'note' => 'nullable|string|max:1000',
            'accept' => 'nullable|boolean', 'source_label' => 'nullable|string|max:80']);
        $num = fn ($v) => $v === null || $v === '' ? null : Dec::tryNorm(str_replace([',', '$', ' '], '', (string) $v));
        [$value, $low, $high] = [$num($d['value']), $num($d['low'] ?? null), $num($d['high'] ?? null)];
        if ($value === null || Dec::cmp($value, '0') <= 0 || ($low && Dec::cmp($low, $value) > 0) || ($high && Dec::cmp($high, $value) < 0)) {
            throw new ApiError('validation_failed', 'Enter a positive value (and a range that brackets it).', 422);
        }
        [$label, $method] = self::ESTIMATE_SOURCES[$d['source']];
        $label = $d['source'] === 'other' && ! empty($d['source_label']) ? mb_substr($d['source_label'], 0, 80) : $label;
        $accept = (bool) ($d['accept'] ?? false);
        $vid = (string) \Illuminate\Support\Str::ulid();
        DB::table('valuations')->insert([
            'id' => $vid, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $subject->scope_id, 'subject_type' => $type, 'subject_id' => $subject->id,
            'purpose' => 'market', 'value' => $value, 'low' => $low, 'high' => $high, 'currency' => 'USD', 'as_of' => $d['as_of'] ?? now()->toDateString(),
            'method' => $method, 'source' => $label.' (entered by '.$ctx->user->name.')', 'status' => $accept ? 'accepted' : 'proposed',
            'accepted_by' => $accept ? $ctx->user->id : null, 'accepted_at' => $accept ? now() : null,
            'rationale' => trim(($d['note'] ?? '').(! empty($d['url']) ? "\nSource: ".$d['url'] : '')) ?: null,
            'created_by' => $ctx->user->id, 'updated_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        Audit::log('valuation.recorded', 'valuation', $vid, ['source' => $d['source'], 'accepted' => $accept], scopeId: $subject->scope_id);

        return $this->ok(['valuation_id' => $vid, 'status' => $accept ? 'accepted' : 'proposed'], 201);
    }

    public function decideValuation(Request $r, string $valuation): JsonResponse
    {
        $row = (new ResourceService($this->ctx()))->find('valuations', $valuation, 'write');
        $decision = $r->input('decision');
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw new ApiError('validation_failed', 'Decision must be accept or reject.', 422);
        }
        if ($row->status !== 'proposed') {
            throw ApiError::conflict('Only proposed valuations can be decided.');
        }
        $status = $decision === 'accept' ? 'accepted' : 'rejected';
        DB::table('valuations')->where('id', $row->id)->update(['status' => $status, 'accepted_by' => $status === 'accepted' ? $this->ctx()->user->id : null,
            'accepted_at' => $status === 'accepted' ? now() : null, 'edit_version' => $row->edit_version + 1, 'updated_by' => $this->ctx()->user->id, 'updated_at' => now()]);
        Audit::log('valuation.'.$status, 'valuation', $row->id, ['subject_type' => $row->subject_type], scopeId: $row->scope_id);

        return $this->ok(['status' => $status]);
    }
}
