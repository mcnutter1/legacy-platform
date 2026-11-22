<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

class DebtAmortizeHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['debt', 'read', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'debt.amortize';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $l = $ctx->constrain(DB::table('liabilities'), 'debt', 'read')->where('id', $job->params['liability_id'])->first();
        if (! $l) {
            throw ApiError::notFound();
        }

        return ['loan' => ['id' => $l->id, 'name' => $l->name, 'balance' => (string) $l->current_balance, 'rate' => (string) $l->interest_rate,
            'payment' => $l->payment_amount !== null ? (string) $l->payment_amount : null, 'term_months' => $l->term_months,
            'first_due' => $l->first_payment_on && $l->first_payment_on > now()->toDateString() ? $l->first_payment_on : now()->addMonth()->startOfMonth()->toDateString(),
            'compounding' => $l->compounding, 'escrow' => (string) ($l->escrow_amount ?? '0'), 'extra_monthly' => (string) ($job->params['extra_monthly'] ?? '0'),
            'balloon_date' => $l->balloon_amount ? $l->maturity_on : null], 'statement' => $job->params['statement'] ?? null];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        unset($result['_engine']);

        return $result;
    }
}
