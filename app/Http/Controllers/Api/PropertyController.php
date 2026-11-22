<?php

namespace App\Http\Controllers\Api;

use App\Resources\ResourceService;
use App\Services\NetWorthService;
use App\Services\RealEstate\PropertyLookupService;
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
