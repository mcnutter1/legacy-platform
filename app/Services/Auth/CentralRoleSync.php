<?php

namespace App\Services\Auth;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccessService;
use App\Support\Audit;
use App\Support\Domains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maps verified McNutt Cloud roles (from the signed payload) to workspace membership roles.
 * Roles decide who belongs and in what capacity; data visibility still comes only from scope grants.
 */
final class CentralRoleSync
{
    /** Highest first: a user holding several central roles gets the strongest mapped role. */
    private const PRIORITY = ['administrator', 'principal', 'member', 'emergency_contact', 'dependent'];

    public function __construct(private readonly AccessService $access) {}

    /** @param list<string> $centralRoles */
    public function mappedRole(array $centralRoles): ?string
    {
        $map = (array) config('legacy.auth.role_map', []);
        $roles = array_values(array_filter(array_map(fn ($r) => $map[$r] ?? null, $centralRoles)));
        foreach (self::PRIORITY as $r) {
            if (in_array($r, $roles, true)) {
                return $r;
            }
        }

        return null;
    }

    public function sync(User $user): void
    {
        if (! config('legacy.auth.role_sync')) {
            return;
        }
        $target = $this->mappedRole((array) ($user->central_roles ?? []));
        foreach (Workspace::all() as $ws) {
            if (! $ws->setting('sso_role_sync', false)) {
                continue;
            }
            DB::transaction(fn () => $this->syncWorkspace($ws, $user, $target));
        }
    }

    private function syncWorkspace(Workspace $ws, User $user, ?string $target): void
    {
        $m = Membership::where('workspace_id', $ws->id)->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $m) {
            if ($target) {
                $this->enroll($ws, $user, $target);
            }

            return;
        }
        if (! $m->sso_managed) {
            return;
        }
        if (! $target) {
            if ($m->status === 'active' && ! $this->isLastAdministrator($m)) {
                $m->forceFill(['status' => 'suspended', 'sso_suspended_at' => now(), 'access_version' => $m->access_version + 1])->save();
                $this->revokeTokens($m);
                Audit::log('membership.sso_suspended', 'membership', $m->id, [], $ws->id);
            }

            return;
        }
        $changes = [];
        if ($m->status === 'suspended' && $m->sso_suspended_at) {
            $changes += ['status' => 'active', 'sso_suspended_at' => null];
        }
        if ($m->role !== $target) {
            if ($m->role === 'administrator' && $this->isLastAdministrator($m)) {
                Log::warning('sso.role_sync.last_admin_kept', ['membership' => $m->id]);
            } else {
                $changes['role'] = $target;
            }
        }
        if ($changes) {
            $m->forceFill($changes + ['access_version' => $m->access_version + 1])->save();
            $this->revokeTokens($m);
            Audit::log('membership.sso_synced', 'membership', $m->id, ['role' => $m->role, 'status' => $m->status], $ws->id);
        }
    }

    private function enroll(Workspace $ws, User $user, string $role): void
    {
        $m = Membership::create([
            'workspace_id' => $ws->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
            'label' => $user->name ?: $user->username ?: $user->email, 'sso_managed' => true,
        ]);
        // Family members get their own private scope; emergency contacts only reach released binders.
        if (in_array($role, ['administrator', 'principal', 'member'], true)) {
            $scope = $this->access->createScope($ws, ($user->name ?: 'Member').' (private)', 'private', $m, $user);
            AccessGrant::create([
                'workspace_id' => $ws->id, 'membership_id' => $m->id, 'scope_id' => $scope->id,
                'domains' => Domains::ALL, 'actions' => Domains::ACTIONS, 'purpose' => 'Own private scope', 'granted_by' => $m->id,
            ]);
        }
        Audit::log('membership.sso_enrolled', 'membership', $m->id, ['role' => $role], $ws->id);
    }

    private function isLastAdministrator(Membership $m): bool
    {
        return $m->role === 'administrator' && ! Membership::where('workspace_id', $m->workspace_id)->where('id', '!=', $m->id)
            ->where('role', 'administrator')->where('status', 'active')->exists();
    }

    private function revokeTokens(Membership $m): void
    {
        DB::table('oauth_tokens')->where('membership_id', $m->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
