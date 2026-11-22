<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\Planning\PlanInputBuilder;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanningController extends ApiController
{
    public function run(Request $r, JobService $jobs, string $scenario): JsonResponse
    {
        $ctx = $this->ctx();
        $sc = $ctx->constrain(DB::table('plan_scenarios'), 'planning', 'read')->where('id', $scenario)->whereNull('archived_at')->first();
        if (! $sc) {
            throw ApiError::notFound();
        }
        $plan = DB::table('plans')->find($sc->plan_id);
        $participants = json_decode((string) $plan->participant_person_ids, true) ?: [];
        if (count($participants) > 1 && $plan->kind !== 'individual') {
            // Combined planning requires each participant's recorded consent when they have their own login.
            $linked = DB::table('memberships')->where('workspace_id', $ctx->workspace->id)->whereIn('person_id', $participants)->where('status', 'active')->pluck('id');
            foreach ($linked as $mid) {
                if ($mid !== $ctx->membership->id && ! DB::table('consents')->where('membership_id', $mid)->where('kind', 'combined_planning')->whereNull('revoked_at')->exists()) {
                    throw new ApiError('consent_required', 'Every participant with a login must consent to combined planning (Settings → Consents).', 403);
                }
            }
        }
        $kind = match ($r->input('kind', 'deterministic')) { 'montecarlo' => 'montecarlo', 'stress' => 'stress', default => 'deterministic' };
        $runId = (string) Str::ulid();
        DB::table('plan_runs')->insert(['id' => $runId, 'workspace_id' => $sc->workspace_id, 'scope_id' => $sc->scope_id, 'scenario_id' => $sc->id, 'kind' => $kind,
            'status' => 'pending', 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        $type = ['deterministic' => 'plan.project', 'montecarlo' => 'plan.montecarlo', 'stress' => 'plan.stress'][$kind];
        $job = $jobs->enqueue($ctx, $type, array_filter(['scenario_id' => $sc->id, 'run_id' => $runId, 'paths' => $r->input('paths'), 'seed' => $r->input('seed')], fn ($v) => $v !== null),
            'plan_run', $runId, $sc->scope_id);
        DB::table('plan_runs')->where('id', $runId)->update(['job_id' => $job->id]);

        return response()->json(['data' => ['run_id' => $runId, 'job' => $job->present()]], 202);
    }

    public function show(string $run): JsonResponse
    {
        $r = $this->ctx()->constrain(DB::table('plan_runs'), 'planning', 'read')->where('id', $run)->first();
        if (! $r) {
            throw ApiError::notFound();
        }

        return $this->ok(['id' => $r->id, 'scenario_id' => $r->scenario_id, 'kind' => $r->kind, 'status' => $r->status, 'seed' => $r->seed, 'paths' => $r->paths,
            'engine_version' => $r->engine_version, 'input_hash' => $r->input_hash, 'summary' => json_decode((string) $r->summary, true),
            'years' => json_decode((string) $r->years, true), 'issues' => json_decode((string) $r->issues, true), 'created_at' => $r->created_at]);
    }

    /** Branch a scenario: copies assumptions so comparisons differ only by explicit edits. */
    public function branch(Request $r, string $scenario): JsonResponse
    {
        $ctx = $this->ctx();
        $sc = $ctx->constrain(DB::table('plan_scenarios'), 'planning', 'write')->where('id', $scenario)->first();
        if (! $sc) {
            throw ApiError::notFound();
        }
        $id = (string) Str::ulid();
        DB::table('plan_scenarios')->insert(['id' => $id, 'workspace_id' => $sc->workspace_id, 'scope_id' => $sc->scope_id, 'plan_id' => $sc->plan_id, 'parent_id' => $sc->id,
            'name' => mb_substr((string) $r->input('name', $sc->name.' (alternative)'), 0, 255), 'is_baseline' => false, 'assumptions' => $sc->assumptions,
            'events' => $sc->events, 'strategy' => $sc->strategy, 'people' => $sc->people, 'status' => 'draft', 'created_by' => $ctx->user->id,
            'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        Audit::log('plan.scenario_branched', 'plan_scenario', $id, ['parent' => $sc->id], scopeId: $sc->scope_id);

        return $this->ok(['id' => $id], 201);
    }

    /** Compare scenarios and list exactly which assumptions differ from the baseline. */
    public function compare(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        $ids = array_slice((array) $r->query('scenarios', []), 0, 4);
        $scs = $ctx->constrain(DB::table('plan_scenarios'), 'planning', 'read')->whereIn('id', $ids)->get();
        if ($scs->count() < 2) {
            throw new ApiError('validation_failed', 'Choose at least two scenarios.');
        }
        $base = $scs->firstWhere('is_baseline', true) ?? $scs->first();
        $flat = fn ($s) => \Illuminate\Support\Arr::dot(['assumptions' => json_decode((string) $s->assumptions, true) ?: [], 'events' => json_decode((string) $s->events, true) ?: [],
            'strategy' => json_decode((string) $s->strategy, true) ?: [], 'people' => json_decode((string) $s->people, true) ?: []]);
        $bf = $flat($base);
        $out = [];
        foreach ($scs as $s) {
            $f = $flat($s);
            $diffs = [];
            foreach (array_unique(array_merge(array_keys($bf), array_keys($f))) as $k) {
                if (($bf[$k] ?? null) !== ($f[$k] ?? null)) {
                    $diffs[] = ['path' => $k, 'baseline' => $bf[$k] ?? null, 'scenario' => $f[$k] ?? null];
                }
            }
            $runs = DB::table('plan_runs')->where('scenario_id', $s->id)->where('status', 'completed')->orderByDesc('created_at')->get()->unique('kind')
                ->mapWithKeys(fn ($x) => [$x->kind => ['id' => $x->id, 'summary' => json_decode((string) $x->summary, true), 'input_hash' => $x->input_hash]]);
            $out[] = ['scenario_id' => $s->id, 'name' => $s->name, 'is_baseline' => $s->id === $base->id, 'differences' => array_slice($diffs, 0, 100), 'runs' => $runs];
        }

        return $this->ok(['baseline_id' => $base->id, 'scenarios' => $out]);
    }

    public function spendingBaseline(Request $r): JsonResponse
    {
        if (! $this->ctx()->canAny('ledger')) {
            throw ApiError::forbidden();
        }

        return $this->ok((new PlanInputBuilder($this->ctx()))->spendingBaseline(max(3, min(24, (int) $r->query('months', 12)))));
    }

    public function debtCompare(Request $r, JobService $jobs): JsonResponse
    {
        $d = $this->validated($r, ['liability_ids' => ['required', 'array', 'min:1', 'max:20'], 'extra_monthly' => ['nullable', 'numeric', 'min:0'],
            'custom_order' => ['nullable', 'array'], 'investment_return' => ['nullable', 'numeric'], 'marginal_tax_rate' => ['nullable', 'numeric'], 'refinance' => ['nullable', 'array']]);

        return $this->accepted($jobs->enqueue($this->ctx(), 'debt.compare', $d, 'liability', null, null)->present());
    }

    public function amortize(Request $r, JobService $jobs, string $liability): JsonResponse
    {
        $l = $this->ctx()->constrain(DB::table('liabilities'), 'debt', 'read')->where('id', $liability)->first();
        if (! $l || $l->current_balance === null || $l->interest_rate === null) {
            throw new ApiError('validation_failed', 'The debt needs a balance and rate.');
        }

        return $this->accepted($jobs->enqueue($this->ctx(), 'debt.amortize', ['liability_id' => $l->id, 'extra_monthly' => (string) $r->input('extra_monthly', '0'),
            'statement' => $r->input('statement')], 'liability', $l->id, $l->scope_id)->present());
    }
}
