<?php

namespace App\Http\Controllers\Api;

use App\Models\Membership;
use App\Services\AccessService;
use App\Services\Auth\StepUp;
use App\Support\Audit;
use App\Support\Domains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function me(Request $r): JsonResponse
    {
        $u = $r->user();
        $memberships = Membership::with('workspace')->where('user_id', $u->id)->whereIn('status', ['active', 'pending_approval'])->get();

        return $this->ok([
            'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'stepup_enrolled' => (bool) $u->totp_enabled_at, 'can_create_workspaces' => $u->canCreateWorkspaces()],
            'stepup' => ['required' => (bool) config('legacy.stepup.required'), 'fresh' => StepUp::isFresh()],
            'workspaces' => $memberships->map(fn ($m) => ['id' => $m->workspace_id, 'name' => $m->workspace->name, 'role' => $m->role, 'status' => $m->status,
                'membership_id' => $m->id, 'timezone' => $m->workspace->timezone, 'base_currency' => $m->workspace->base_currency])->values(),
            'domains' => Domains::LABELS,
            'release' => config('legacy.release'),
        ]);
    }

    public function createWorkspace(Request $r, AccessService $svc): JsonResponse
    {
        $d = $this->validated($r, ['name' => ['required', 'string', 'max:120'], 'timezone' => ['nullable', 'timezone']]);
        $ws = $svc->createWorkspace($r->user(), $d['name'], $d['timezone'] ?? null);

        return $this->ok(['id' => $ws->id, 'name' => $ws->name], 201);
    }

    public function meta(): JsonResponse
    {
        return $this->ok(['resources' => ResourceController::meta(), 'domains' => Domains::LABELS, 'actions' => Domains::ACTIONS]);
    }

    public function stepupEnroll(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u->totp_enabled_at && ! StepUp::isFresh()) {
            return response()->json(['error' => ['code' => 'step_up_required', 'message' => 'Verify your current authenticator before re-enrolling.']], 403);
        }
        $secret = StepUp::newSecret();
        $r->session()->put('stepup_pending_secret', encrypt($secret));

        return $this->ok(['secret' => $secret, 'otpauth_uri' => StepUp::otpauthUri($u, $secret)]);
    }

    public function stepupConfirm(Request $r): JsonResponse
    {
        $d = $this->validated($r, ['code' => ['required', 'digits:6']]);
        $u = $r->user();
        $pending = $r->session()->get('stepup_pending_secret');
        if ($pending) {
            $secret = decrypt($pending);
            if (! StepUp::verify($u, $d['code'], $secret)) {
                return response()->json(['error' => ['code' => 'invalid_code', 'message' => 'That code did not match. Check the time on your device.']], 422);
            }
            $u->forceFill(['totp_secret' => $secret, 'totp_enabled_at' => now()])->save();
            $r->session()->forget('stepup_pending_secret');
            Audit::log('auth.stepup.enrolled', 'user', $u->id);
            StepUp::markVerified();

            return $this->ok(['enrolled' => true]);
        }
        if (! $u->totp_enabled_at || ! StepUp::verify($u, $d['code'])) {
            return response()->json(['error' => ['code' => 'invalid_code', 'message' => 'That code did not match.']], 422);
        }
        $r->session()->migrate(true);
        StepUp::markVerified();

        return $this->ok(['verified' => true, 'valid_for_seconds' => (int) config('legacy.stepup.ttl_seconds')]);
    }
}
