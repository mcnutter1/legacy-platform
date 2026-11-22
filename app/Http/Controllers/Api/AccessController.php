<?php

namespace App\Http\Controllers\Api;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Models\Scope;
use App\Services\AccessService;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Members, invitations, scopes, grants, consents, audit history and access review. */
class AccessController extends ApiController
{
    public function __construct(private readonly AccessService $svc) {}

    public function overview(): JsonResponse
    {
        $ctx = $this->ctx();
        $isAdmin = $ctx->isAdministrator();
        $stewardOf = $ctx->stewardScopeIds();
        $members = Membership::with('user')->where('workspace_id', $ctx->workspace->id)->whereNotIn('status', ['removed'])->get();
        $scopes = Scope::where('workspace_id', $ctx->workspace->id)->whereNull('archived_at')->get();
        $grants = AccessGrant::where('workspace_id', $ctx->workspace->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereIn('scope_id', $stewardOf)->orWhere('membership_id', $ctx->membership->id))->get();

        return $this->ok([
            'me' => ['membership_id' => $ctx->membership->id, 'role' => $ctx->membership->role, 'is_administrator' => $isAdmin, 'steward_of' => $stewardOf],
            'members' => $members->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'status' => $m->status, 'label' => $m->label, 'person_id' => $m->person_id,
                'name' => $m->user?->name, 'email' => $isAdmin || $m->id === $ctx->membership->id ? ($m->user?->email ?? $m->invited_email) : null,
                'expires_at' => $m->expires_at?->toIso8601String(), 'is_recovery_admin' => $m->is_recovery_admin, 'sso_managed' => (bool) $m->sso_managed])->values(),
            'scopes' => $scopes->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'description' => $s->description,
                'stewards' => DB::table('scope_stewards')->where('scope_id', $s->id)->pluck('membership_id'),
                'my_access' => collect(\App\Support\Domains::ALL)->mapWithKeys(fn ($d) => [$d => array_values(array_filter(\App\Support\Domains::ACTIONS, fn ($a) => $ctx->can($d, $a, $s->id)))])->filter()->all()])->values(),
            'grants' => $grants->map(fn ($g) => ['id' => $g->id, 'membership_id' => $g->membership_id, 'scope_id' => $g->scope_id, 'domains' => $g->domains, 'actions' => $g->actions,
                'years' => $g->years, 'purpose' => $g->purpose, 'expires_at' => $g->expires_at?->toIso8601String(), 'pending_approval' => ! $g->isActive() && $g->revoked_at === null,
                'granted_by' => $g->granted_by])->values(),
        ]);
    }

    public function invite(Request $r): JsonResponse
    {
        $this->privileged();
        $res = $this->svc->invite($this->ctx(), $r->all());

        return $this->ok(['membership_id' => $res['membership']->id, 'invite_url' => $res['invite_url'],
            'note' => 'Share this single-use link with the invitee. It expires in 7 days and requires the invited email address.'], 201);
    }

    public function setStatus(Request $r, string $membership): JsonResponse
    {
        $this->privileged();
        $m = $this->svc->setMembershipStatus($this->ctx(), $membership, (string) $r->input('status'));

        return $this->ok(['id' => $m->id, 'status' => $m->status]);
    }

    public function setRole(Request $r, string $membership): JsonResponse
    {
        $this->privileged();
        $m = $this->svc->setRole($this->ctx(), $membership, (string) $r->input('role'));

        return $this->ok(['id' => $m->id, 'role' => $m->role]);
    }

    public function linkPerson(Request $r, string $membership): JsonResponse
    {
        $ctx = $this->ctx();
        if (! $ctx->isAdministrator()) {
            throw ApiError::forbidden();
        }
        $person = (string) $r->input('person_id');
        if (! DB::table('persons')->where('workspace_id', $ctx->workspace->id)->where('id', $person)->exists()) {
            throw ApiError::notFound('Person not found.');
        }
        Membership::where('workspace_id', $ctx->workspace->id)->where('id', $membership)->update(['person_id' => $person]);

        return $this->ok(['linked' => true]);
    }

    public function createScope(Request $r): JsonResponse
    {
        $s = $this->svc->newScope($this->ctx(), $r->all());

        return $this->ok(['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind], 201);
    }

    public function addSteward(Request $r, string $scope): JsonResponse
    {
        $this->privileged();
        $this->svc->addSteward($this->ctx(), $scope, (string) $r->input('membership_id'));

        return $this->ok(['added' => true]);
    }

    public function grant(Request $r): JsonResponse
    {
        $this->privileged();
        $g = $this->svc->grant($this->ctx(), $r->all());

        return $this->ok(['id' => $g->id, 'pending_approval' => ! $g->isActive()], 201);
    }

    public function approveGrant(string $grant): JsonResponse
    {
        $this->privileged();
        $this->svc->approveGrant($this->ctx(), $grant);

        return $this->ok(['approved' => true]);
    }

    public function revokeGrant(string $grant): JsonResponse
    {
        $this->privileged();
        $this->svc->revokeGrant($this->ctx(), $grant);

        return $this->ok(['revoked' => true]);
    }

    public function audit(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        $q = DB::table('audit_events')->where('workspace_id', $ctx->workspace->id)->orderByDesc('created_at')->orderByDesc('id');
        if (! $ctx->isAdministrator()) {
            // Non-administrators see their own activity and events on scopes they steward.
            $q->where(fn ($w) => $w->where('membership_id', $ctx->membership->id)->orWhereIn('scope_id', $ctx->stewardScopeIds()));
        }
        if ($r->filled('before')) {
            $q->where('created_at', '<', $r->query('before'));
        }
        $rows = $q->limit(100)->get(['id', 'actor_type', 'membership_id', 'action', 'subject_type', 'subject_id', 'scope_id', 'metadata', 'created_at', 'hash']);

        return response()->json(['data' => $rows->map(fn ($e) => ['metadata' => json_decode((string) $e->metadata, true)] + (array) $e),
            'next_before' => $rows->count() === 100 ? $rows->last()->created_at : null]);
    }

    public function consents(): JsonResponse
    {
        $ctx = $this->ctx();

        return $this->ok(DB::table('consents')->where('workspace_id', $ctx->workspace->id)->whereNull('revoked_at')->get(['id', 'membership_id', 'kind', 'subject_type', 'subject_id', 'granted_at']));
    }

    public function grantConsent(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        $kind = (string) $r->input('kind');
        if (! in_array($kind, ['ai_processing', 'combined_planning', 'emergency_release', 'notifications_email'], true)) {
            throw new ApiError('validation_failed', 'Unknown consent type.');
        }
        if ($kind === 'ai_processing' && ! $ctx->isAdministrator()) {
            throw ApiError::forbidden('Only administrators can enable AI document processing for the workspace.');
        }
        $id = (string) Str::ulid();
        DB::table('consents')->insert(['id' => $id, 'workspace_id' => $ctx->workspace->id, 'membership_id' => $ctx->membership->id, 'kind' => $kind,
            'subject_type' => $r->input('subject_type'), 'subject_id' => $r->input('subject_id'), 'details' => json_encode($r->input('details', [])),
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('consent.granted', 'consent', $id, ['kind' => $kind]);

        return $this->ok(['id' => $id], 201);
    }

    public function revokeConsent(string $consent): JsonResponse
    {
        $ctx = $this->ctx();
        $n = DB::table('consents')->where('workspace_id', $ctx->workspace->id)->where('id', $consent)
            ->when(! $ctx->isAdministrator(), fn ($q) => $q->where('membership_id', $ctx->membership->id))->update(['revoked_at' => now()]);
        if (! $n) {
            throw ApiError::notFound();
        }
        Audit::log('consent.revoked', 'consent', $consent);

        return $this->ok(['revoked' => true]);
    }

    public function sessions(): JsonResponse
    {
        $ctx = $this->ctx();
        $rows = DB::table('sessions')->where('user_id', $ctx->user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return $this->ok($rows->map(fn ($s) => ['id' => substr(hash('sha256', $s->id), 0, 16), 'current' => $s->id === session()->getId(),
            'user_agent' => mb_substr((string) $s->user_agent, 0, 120), 'last_activity' => date(DATE_ATOM, $s->last_activity)]));
    }

    public function revokeOtherSessions(): JsonResponse
    {
        $ctx = $this->ctx();
        DB::table('sessions')->where('user_id', $ctx->user->id)->where('id', '!=', session()->getId())->delete();
        Audit::log('auth.sessions_revoked', 'user', $ctx->user->id);

        return $this->ok(['revoked' => true]);
    }

    public function oauthClients(): JsonResponse
    {
        $ctx = $this->ctx();
        $tokens = DB::table('oauth_tokens')->where('membership_id', $ctx->membership->id)->whereNull('revoked_at')->where('kind', 'refresh')->where('expires_at', '>', now())->get();

        return $this->ok($tokens->groupBy('client_id')->map(function ($ts, $cid) {
            $c = DB::table('oauth_clients')->where('client_id', $cid)->first();

            return ['client_id' => $cid, 'name' => $c?->name, 'scopes' => json_decode((string) $ts->first()->scopes, true), 'connected_at' => $ts->min('created_at'), 'last_used_at' => $ts->max('last_used_at')];
        })->values());
    }

    public function revokeOauthClient(string $clientId): JsonResponse
    {
        $ctx = $this->ctx();
        DB::table('oauth_tokens')->where('membership_id', $ctx->membership->id)->where('client_id', $clientId)->update(['revoked_at' => now()]);
        Audit::log('oauth.client_revoked', 'oauth_client', $clientId);

        return $this->ok(['revoked' => true]);
    }
}
