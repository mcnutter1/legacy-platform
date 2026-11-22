<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

class DebtCompareHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['debt', 'read', null]];
    }

    public function runnerType(): ?string
    {
        return 'debt.compare';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $job->params;
        $loans = [];
        foreach ($ctx->constrain(DB::table('liabilities'), 'debt', 'read')->whereIn('id', (array) $p['liability_ids'])->whereNull('archived_at')->get() as $l) {
            if ($l->current_balance === null || $l->interest_rate === null) {
                throw new ApiError('validation_failed', "{$l->name} needs a balance and interest rate.");
            }
            $first = $l->first_payment_on && $l->first_payment_on > now()->toDateString() ? $l->first_payment_on : now()->addMonth()->startOfMonth()->toDateString();
            $loans[] = ['id' => $l->id, 'name' => $l->name, 'balance' => (string) $l->current_balance, 'rate' => (string) $l->interest_rate,
                'payment' => $l->payment_amount !== null ? (string) $l->payment_amount : null, 'term_months' => $l->term_months, 'first_due' => $first,
                'compounding' => $l->compounding, 'tax_deductible_rate' => in_array($l->deductible_interest_kind, ['qualified_residence', 'investment', 'business', 'rental'], true) ? '1' : '0'];
        }
        if (! $loans) {
            throw ApiError::notFound('No debts selected.');
        }

        return ['loans' => $loans, 'extra_monthly' => (string) ($p['extra_monthly'] ?? '0'), 'custom_order' => $p['custom_order'] ?? null,
            'investment_return' => (string) ($p['investment_return'] ?? '0.06'), 'marginal_tax_rate' => (string) ($p['marginal_tax_rate'] ?? '0'),
            'refinance' => $p['refinance'] ?? null];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        unset($result['_engine']);

        return $result;
    }
}
