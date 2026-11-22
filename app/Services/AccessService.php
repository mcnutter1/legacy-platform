<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Models\Scope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Domains;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Workspaces, memberships, scopes and grants. Every material access change revokes live sessions/tokens. */
final class AccessService
{
    public function createWorkspace(User $user, string $name, ?string $timezone = null): Workspace
    {
        if (! $user->canCreateWorkspaces()) {
            throw ApiError::forbidden('Workspace creation is limited to designated family administrators.');
        }

        return DB::transaction(function () use ($user, $name, $timezone) {
            $ws = Workspace::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.strtolower(Str::random(6)),
                'timezone' => $timezone ?: 'America/New_York',
                'created_by' => $user->id,
                'settings' => ['dual_control_high_risk' => true],
            ]);
            $m = Membership::create([
                'workspace_id' => $ws->id, 'user_id' => $user->id, 'role' => 'administrator',
                'status' => 'active', 'label' => $user->name, 'is_recovery_admin' => true, 'created_by' => $user->id,
            ]);
            $joint = $this->createScope($ws, 'Family (joint)', 'joint', $m, $user);
            $private = $this->createScope($ws, ($user->name ?: 'My').' (private)', 'private', $m, $user);
            foreach ([$joint, $private] as $s) {
                AccessGrant::create([
                    'workspace_id' => $ws->id, 'membership_id' => $m->id, 'scope_id' => $s->id,
                    'domains' => Domains::ALL, 'actions' => Domains::ACTIONS, 'purpose' => 'Founding steward', 'granted_by' => $m->id,
                ]);
            }
            Audit::log('workspace.created', 'workspace', $ws->id, workspaceId: $ws->id);

            return $ws;
        });
    }

    public function createScope(Workspace $ws, string $name, string $kind, Membership $steward, ?User $by = null): Scope
    {
        $scope = Scope::create([
            'workspace_id' => $ws->id, 'name' => $name, 'kind' => $kind,
            'owner_membership_id' => $kind === 'private' ? $steward->id : null, 'created_by' => $by?->id,
        ]);
        DB::table('scope_stewards')->insert([
            'workspace_id' => $ws->id, 'scope_id' => $scope->id, 'membership_id' => $steward->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $scope;
    }

    /** Stewards may create scopes; the creator becomes steward and receives full access. */
    public function newScope(AccessContext $ctx, array $data): Scope
    {
        if (! in_array($data['kind'] ?? '', ['joint', 'private', 'branch', 'entity', 'shared', 'advisor'], true)) {
            throw new ApiError('validation_failed', 'Invalid scope kind.');
        }
        if (! $ctx->isAdministrator() && ! in_array($ctx->membership->role, ['principal', 'administrator'], true)) {
            throw ApiError::forbidden();
        }

        return DB::transaction(function () use ($ctx, $data) {
            $scope = $this->createScope($ctx->workspace, mb_substr((string) $data['name'], 0, 120), $data['kind'], $ctx->membership, $ctx->user);
            AccessGrant::create([
                'workspace_id' => $ctx->workspace->id, 'membership_id' => $ctx->membership->id, 'scope_id' => $scope->id,
                'domains' => Domains::ALL, 'actions' => Domains::ACTIONS, 'purpose' => 'Scope steward', 'granted_by' => $ctx->membership->id,
            ]);
            Audit::log('scope.created', 'scope', $scope->id, ['kind' => $scope->kind]);

            return $scope;
        });
    }

    public function addSteward(AccessContext $ctx, string $scopeId, string $membershipId): void
    {
        if (! $ctx->isSteward($scopeId)) {
            throw ApiError::forbidden('Only a steward of this scope can add stewards.');
        }
        $m = $this->membership($ctx, $membershipId);
        DB::table('scope_stewards')->insertOrIgnore([
            'workspace_id' => $ctx->workspace->id, 'scope_id' => $scopeId, 'membership_id' => $m->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log('scope.steward_added', 'scope', $scopeId, ['membership_id' => $m->id], scopeId: $scopeId);
    }

    public function invite(AccessContext $ctx, array $data): array
    {
        if (! $ctx->isAdministrator()) {
            throw ApiError::forbidden('Only administrators can invite members.');
        }
        if (! in_array($data['role'] ?? '', Membership::ROLES, true)) {
            throw new ApiError('validation_failed', 'Invalid role.');
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('validation_failed', 'A valid email is required.');
        }
        if ($data['role'] === 'advisor' && empty($data['expires_at'])) {
            throw new ApiError('validation_failed', 'Advisor access must have an expiry date.');
        }
        $token = Str::random(48);
        $m = Membership::create([
            'workspace_id' => $ctx->workspace->id, 'role' => $data['role'], 'status' => 'invited',
            'label' => mb_substr((string) ($data['label'] ?? $email), 0, 120), 'invited_email' => $email,
            'invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => now()->addDays(7),
            'expires_at' => $data['expires_at'] ?? null, 'person_id' => $data['person_id'] ?? null, 'created_by' => $ctx->user->id,
        ]);
        Audit::log('membership.invited', 'membership', $m->id, ['role' => $m->role]);
        Outbox::publish('membership.invited', ['membership_id' => $m->id], 'membership', $m->id);

        return ['membership' => $m, 'invite_url' => url('/invite/'.$token)];
    }

    public function acceptInvite(User $user, string $token): Membership
    {
        $m = Membership::where('invite_token_hash', hash('sha256', $token))->first();
        if (! $m || $m->status !== 'invited' || ! $m->invite_expires_at || $m->invite_expires_at->isPast()) {
            throw new ApiError('invite_invalid', 'This invitation is invalid or has expired.', 410);
        }
        if (! $user->email || strtolower($user->email) !== strtolower((string) $m->invited_email)) {
            throw ApiError::forbidden('Sign in with the invited email address to accept.');
        }
        if (Membership::where('workspace_id', $m->workspace_id)->where('user_id', $user->id)->exists()) {
            throw ApiError::conflict('You are already a member of this workspace.');
        }
        $m->forceFill([
            'user_id' => $user->id, 'invite_token_hash' => null,
            // Identity payload has no verified-email claim: an administrator confirms the binding.
            'status' => config('legacy.auth.email_is_verified') ? 'active' : 'pending_approval',
        ])->save();
        Audit::log('membership.accepted', 'membership', $m->id, workspaceId: $m->workspace_id);

        return $m;
    }

    public function setMembershipStatus(AccessContext $ctx, string $membershipId, string $status): Membership
    {
        if (! $ctx->isAdministrator()) {
            throw ApiError::forbidden();
        }
        if (! in_array($status, ['active', 'suspended', 'removed'], true)) {
            throw new ApiError('validation_failed', 'Invalid status.');
        }
        $m = $this->membership($ctx, $membershipId);
        if ($m->id === $ctx->membership->id && $status !== 'active') {
            $others = Membership::where('workspace_id', $ctx->workspace->id)->where('role', 'administrator')->where('status', 'active')->where('id', '!=', $m->id)->count();
            if ($others === 0) {
                throw ApiError::conflict('Assign another administrator before removing yourself.');
            }
        }
        DB::transaction(function () use ($m, $status) {
            $m->forceFill(['status' => $status, 'access_version' => $m->access_version + 1])->save();
            if ($status !== 'active') {
                $this->revokeLiveAccess($m);
            }
            Audit::log('membership.status_changed', 'membership', $m->id, ['status' => $status]);
            Outbox::publish('access.changed', ['membership_id' => $m->id], 'membership', $m->id);
        });

        return $m;
    }

    public function setRole(AccessContext $ctx, string $membershipId, string $role): Membership
    {
        if (! $ctx->isAdministrator() || ! in_array($role, Membership::ROLES, true)) {
            throw ApiError::forbidden();
        }
        $m = $this->membership($ctx, $membershipId);
        DB::transaction(function () use ($m, $role) {
            $m->forceFill(['role' => $role, 'access_version' => $m->access_version + 1])->save();
            $this->revokeLiveAccess($m);
            Audit::log('membership.role_changed', 'membership', $m->id, ['role' => $role]);
        });

        return $m;
    }

    /** Stewards grant access to their scopes. High-risk grants need a second steward when dual control is on. */
    public function grant(AccessContext $ctx, array $data): AccessGrant
    {
        $scopeId = (string) ($data['scope_id'] ?? '');
        if (! $ctx->isSteward($scopeId)) {
            throw ApiError::forbidden('Only a steward of this scope can grant access to it.');
        }
        $m = $this->membership($ctx, (string) ($data['membership_id'] ?? ''));
        $domains = array_values(array_intersect((array) ($data['domains'] ?? []), Domains::ALL));
        $actions = array_values(array_intersect((array) ($data['actions'] ?? []), Domains::ACTIONS));
        if (! $domains || ! $actions) {
            throw new ApiError('validation_failed', 'Choose at least one domain and action.');
        }
        if (in_array($m->role, ['advisor'], true) && empty($data['expires_at'])) {
            throw new ApiError('validation_failed', 'Advisor grants must expire.');
        }
        $years = isset($data['years']) ? array_values(array_map('intval', (array) $data['years'])) : null;
        $highRisk = (bool) array_intersect($actions, ['export', 'approve']) && (bool) array_intersect($domains, ['estate', 'tax', 'identity'])
            || $m->role === 'advisor' && in_array('export', $actions, true);
        $needsApproval = $highRisk && $ctx->workspace->setting('dual_control_high_risk', true)
            && count(DB::table('scope_stewards')->where('scope_id', $scopeId)->pluck('membership_id')) > 1;

        $g = DB::transaction(function () use ($ctx, $m, $scopeId, $domains, $actions, $years, $data, $needsApproval) {
            $g = AccessGrant::create([
                'workspace_id' => $ctx->workspace->id, 'membership_id' => $m->id, 'scope_id' => $scopeId,
                'domains' => $domains, 'actions' => $actions, 'years' => $years,
                'conditions' => $needsApproval ? ['requires_approval' => true] : null,
                'purpose' => mb_substr((string) ($data['purpose'] ?? ''), 0, 255) ?: null,
                'granted_by' => $ctx->membership->id, 'expires_at' => $data['expires_at'] ?? null, 'created_by' => $ctx->user->id,
            ]);
            $m->increment('access_version');
            Audit::log('grant.created', 'access_grant', $g->id, ['membership_id' => $m->id, 'domains' => $domains, 'actions' => $actions, 'pending' => $needsApproval], scopeId: $scopeId);

            return $g;
        });

        return $g;
    }

    public function approveGrant(AccessContext $ctx, string $grantId): AccessGrant
    {
        $g = AccessGrant::where('workspace_id', $ctx->workspace->id)->findOrFail($grantId);
        if (! $ctx->isSteward($g->scope_id) || $g->granted_by === $ctx->membership->id) {
            throw ApiError::forbidden('A different steward must approve this grant.');
        }
        $g->forceFill(['approved_by' => $ctx->membership->id])->save();
        Audit::log('grant.approved', 'access_grant', $g->id, scopeId: $g->scope_id);

        return $g;
    }

    public function revokeGrant(AccessContext $ctx, string $grantId): void
    {
        $g = AccessGrant::where('workspace_id', $ctx->workspace->id)->findOrFail($grantId);
        if (! $ctx->isSteward($g->scope_id) && ! ($ctx->isAdministrator() && $g->membership_id !== $ctx->membership->id)) {
            throw ApiError::forbidden();
        }
        DB::transaction(function () use ($g, $ctx) {
            $g->forceFill(['revoked_at' => now(), 'revoked_by' => $ctx->membership->id])->save();
            $m = Membership::find($g->membership_id);
            if ($m) {
                $m->increment('access_version');
                $this->revokeLiveAccess($m);
            }
            Audit::log('grant.revoked', 'access_grant', $g->id, scopeId: $g->scope_id);
            Outbox::publish('access.changed', ['membership_id' => $g->membership_id], 'access_grant', $g->id);
        });
    }

    /** Kill browser sessions and delegated OAuth tokens so revoked access cannot linger. */
    public function revokeLiveAccess(Membership $m): void
    {
        if ($m->user_id) {
            DB::table('sessions')->where('user_id', $m->user_id)->delete();
        }
        DB::table('oauth_tokens')->where('membership_id', $m->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function membership(AccessContext $ctx, string $id): Membership
    {
        $m = Membership::where('workspace_id', $ctx->workspace->id)->find($id);
        if (! $m) {
            throw ApiError::notFound('Member not found.');
        }

        return $m;
    }
}
