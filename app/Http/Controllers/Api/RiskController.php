<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\GovernanceService;
use App\Services\InsuranceService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Estate scenarios, insurance analysis and family governance / emergency access. */
class RiskController extends ApiController
{
    public function estateRun(JobService $jobs, string $scenario): JsonResponse
    {
        $ctx = $this->ctx();
        $sc = $ctx->constrain(DB::table('estate_scenarios'), 'estate', 'read')->where('id', $scenario)->first();
        if (! $sc) {
            throw ApiError::notFound();
        }
        $runId = (string) Str::ulid();
        DB::table('estate_runs')->insert(['id' => $runId, 'workspace_id' => $sc->workspace_id, 'scope_id' => $sc->scope_id, 'scenario_id' => $sc->id, 'status' => 'pending',
            'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        $job = $jobs->enqueue($ctx, 'estate.distribute', ['scenario_id' => $sc->id, 'run_id' => $runId], 'estate_run', $runId, $sc->scope_id);
        DB::table('estate_runs')->where('id', $runId)->update(['job_id' => $job->id]);

        return response()->json(['data' => ['run_id' => $runId, 'job' => $job->present()]], 202);
    }

    public function estateRunShow(string $run): JsonResponse
    {
        $r = $this->ctx()->constrain(DB::table('estate_runs'), 'estate', 'read')->where('id', $run)->first();
        if (! $r) {
            throw ApiError::notFound();
        }

        return $this->ok(['id' => $r->id, 'scenario_id' => $r->scenario_id, 'status' => $r->status, 'summary' => json_decode((string) $r->summary, true),
            'deaths' => json_decode((string) $r->flows, true), 'unresolved' => json_decode((string) $r->unresolved, true), 'input_hash' => $r->input_hash,
            'engine_version' => $r->engine_version, 'created_at' => $r->created_at]);
    }

    public function insuranceAnalysis(InsuranceService $svc): JsonResponse
    {
        return $this->ok($svc->analysis($this->ctx()));
    }

    public function lossSummary(Request $r, InsuranceService $svc): JsonResponse
    {
        return $this->ok($svc->lossSummary($this->ctx(), $r->all()));
    }

    public function compareQuotes(InsuranceService $svc, string $case): JsonResponse
    {
        return $this->ok($svc->compareQuotes($this->ctx(), $case));
    }

    public function prompts(GovernanceService $svc): JsonResponse
    {
        return $this->ok($svc->prompts($this->ctx()));
    }

    public function breakGlassRequest(Request $r, GovernanceService $svc, string $binder): JsonResponse
    {
        $this->privileged();

        return $this->ok(['request_id' => $svc->request($this->ctx(), $binder, (string) $r->input('reason', ''))], 201);
    }

    public function breakGlassApprove(Request $r, GovernanceService $svc, string $request): JsonResponse
    {
        $this->privileged();

        return $this->ok($svc->approve($this->ctx(), $request, (string) $r->input('note', '')));
    }

    public function breakGlassDeny(GovernanceService $svc, string $request): JsonResponse
    {
        $this->privileged();
        $svc->deny($this->ctx(), $request);

        return $this->ok(['denied' => true]);
    }

    public function breakGlassList(): JsonResponse
    {
        $ctx = $this->ctx();
        $rows = DB::table('break_glass_requests')->where('workspace_id', $ctx->workspace->id)
            ->where(fn ($q) => $q->where('requester_membership_id', $ctx->membership->id)->orWhereIn('scope_id', $ctx->stewardScopeIds()))
            ->orderByDesc('created_at')->limit(50)->get();

        return $this->ok($rows->map(fn ($x) => ['id' => $x->id, 'binder_id' => $x->binder_id, 'status' => $x->status, 'reason' => $x->reason,
            'requester_membership_id' => $x->requester_membership_id, 'verifications' => count(json_decode((string) $x->verifications, true) ?: []),
            'expires_at' => $x->expires_at, 'created_at' => $x->created_at]));
    }
}
