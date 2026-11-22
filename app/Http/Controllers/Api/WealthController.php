<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\EquityService;
use App\Services\InsightService;
use App\Services\LedgerService;
use App\Services\NetWorthService;
use App\Services\OwnershipService;
use App\Services\PortfolioService;
use App\Services\YearCloseService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WealthController extends ApiController
{
    private function date(Request $r, string $key = 'as_of'): ?string
    {
        $d = $r->query($key);
        if ($d !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
            throw new ApiError('validation_failed', 'Dates must be YYYY-MM-DD.');
        }

        return $d;
    }

    public function home(): JsonResponse
    {
        return $this->ok((new InsightService($this->ctx()))->home());
    }

    public function netWorth(Request $r, NetWorthService $svc): JsonResponse
    {
        return $this->ok($svc->compute($this->ctx(), $this->date($r), $r->query('person_id')));
    }

    public function portfolio(Request $r, PortfolioService $svc): JsonResponse
    {
        return $this->ok($svc->summary($this->ctx(), $this->date($r)));
    }

    public function performance(Request $r, PortfolioService $svc, string $account): JsonResponse
    {
        $from = $this->date($r, 'from') ?? now()->subYear()->toDateString();
        $to = $this->date($r, 'to') ?? now()->toDateString();

        return $this->ok($svc->performance($this->ctx(), $account, $from, $to));
    }

    public function ownershipValidation(Request $r): JsonResponse
    {
        if (! $this->ctx()->canAny('household')) {
            throw ApiError::forbidden();
        }

        return $this->ok(OwnershipService::validateAll($this->ctx()->workspace->id, $this->date($r) ?? now()->toDateString()));
    }

    public function search(Request $r): JsonResponse
    {
        return $this->ok((new InsightService($this->ctx()))->search((string) $r->query('q', '')));
    }

    public function links(Request $r): JsonResponse
    {
        return $this->ok((new InsightService($this->ctx()))->links((string) $r->query('type'), (string) $r->query('id')));
    }

    public function createLink(Request $r): JsonResponse
    {
        return $this->ok(['id' => (new InsightService($this->ctx()))->link($r->all())], 201);
    }

    public function tranches(EquityService $svc, string $grant): JsonResponse
    {
        return $this->ok($svc->generateTranches($this->ctx(), $grant));
    }

    public function equityEvent(Request $r, EquityService $svc, string $grant): JsonResponse
    {
        $this->privileged();

        return $this->ok(['event_id' => $svc->recordEvent($this->ctx(), $grant, $r->all())], 201);
    }

    public function equityReconcile(Request $r, EquityService $svc): JsonResponse
    {
        return $this->ok($svc->reconcile($this->ctx(), (int) $r->input('year', now()->year)));
    }

    public function equityModel(Request $r, JobService $jobs): JsonResponse
    {
        $d = $this->validated($r, ['grant_ids' => ['nullable', 'array'], 'price_scenarios' => ['nullable', 'array', 'max:12'], 'exit_values' => ['nullable', 'array', 'max:12'],
            'waterfall' => ['nullable', 'array'], 'ordinary_rate' => ['nullable', 'numeric'], 'ltcg_rate' => ['nullable', 'numeric'], 'amt_rate' => ['nullable', 'numeric']]);

        return $this->accepted($jobs->enqueue($this->ctx(), 'equity.model', $d)->present());
    }

    public function yearClose(Request $r, YearCloseService $svc): JsonResponse
    {
        $d = $this->validated($r, ['year' => ['required', 'integer', 'between:2000,2100'], 'scope_id' => ['required', 'string', 'size:26']]);

        return response()->json(['data' => $svc->request($this->ctx(), (int) $d['year'], $d['scope_id'])], 202);
    }

    public function closeYear(YearCloseService $svc, string $snapshot): JsonResponse
    {
        $this->privileged();
        $svc->close($this->ctx(), $snapshot);

        return $this->ok(['closed' => true]);
    }

    public function reopenYear(Request $r, YearCloseService $svc, string $snapshot): JsonResponse
    {
        $this->privileged();

        return $this->ok(['snapshot_id' => $svc->reopen($this->ctx(), $snapshot, (string) $r->input('reason', ''))], 201);
    }

    public function closePeriod(Request $r, LedgerService $svc): JsonResponse
    {
        $this->privileged();
        $d = $this->validated($r, ['book_id' => ['required', 'string', 'size:26'], 'period_end' => ['required', 'date_format:Y-m-d']]);
        $svc->closePeriod($this->ctx(), $d['book_id'], $d['period_end']);

        return $this->ok(['closed' => true]);
    }

    public function reopenPeriod(Request $r, LedgerService $svc, string $close): JsonResponse
    {
        $this->privileged();
        $svc->reopenPeriod($this->ctx(), $close, (string) $r->input('reason', ''));

        return $this->ok(['reopened' => true]);
    }
}
