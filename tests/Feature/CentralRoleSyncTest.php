<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Models\User;
use App\Services\Auth\CentralRoleSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** McNutt Cloud roles (admin, family, ice, guest, …) → workspace membership roles. */
class CentralRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    private function central(string $uid, array $roles): User
    {
        $u = $this->makeUser($uid, ucfirst($uid));
        $u->forceFill(['central_roles' => $roles])->save();

        return $u;
    }

    public function test_mapping_uses_the_strongest_role_and_ignores_unmapped_roles(): void
    {
        $sync = app(CentralRoleSync::class);
        $this->assertSame('administrator', $sync->mappedRole(['family', 'admin', 'vault']));
        $this->assertSame('member', $sync->mappedRole(['family', 'ice']));
        $this->assertSame('emergency_contact', $sync->mappedRole(['ice']));
        $this->assertNull($sync->mappedRole(['guest', 'vault']));
    }

    public function test_central_admin_can_create_a_workspace_and_others_cannot(): void
    {
        config(['legacy.auth.workspace_creators' => []]);
        $this->assertTrue($this->central('boss', ['admin', 'family'])->canCreateWorkspaces());
        $this->assertFalse($this->central('kid', ['family'])->canCreateWorkspaces());
    }

    public function test_family_and_ice_are_enrolled_with_no_shared_data_and_guests_are_not(): void
    {
        $owner = $this->central('owner', ['admin', 'family']);
        [$ws, , $joint] = $this->makeWorkspace($owner);
        $this->signIn($owner)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Joint Person'])->assertCreated();

        $kid = $this->central('kid', ['family']);
        $ice = $this->central('neighbor', ['ice']);
        $guest = $this->central('visitor', ['guest']);
        foreach ([$kid, $ice, $guest] as $u) {
            app(CentralRoleSync::class)->sync($u);
        }

        $km = Membership::where('workspace_id', $ws->id)->where('user_id', $kid->id)->firstOrFail();
        $this->assertSame(['member', 'active', true], [$km->role, $km->status, $km->sso_managed]);
        $this->assertSame(1, AccessGrant::where('membership_id', $km->id)->count(), 'only their own private scope');
        $this->assertSame('emergency_contact', Membership::where('user_id', $ice->id)->value('role'));
        $this->assertSame(0, AccessGrant::where('membership_id', Membership::where('user_id', $ice->id)->value('id'))->count());
        $this->assertFalse(Membership::where('user_id', $guest->id)->exists());

        // Enrollment grants membership, not the family's joint data.
        $this->signIn($kid)->getJson($this->wsUrl($ws, 'r/persons'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_role_changes_follow_central_roles_until_pinned_and_removal_suspends(): void
    {
        $owner = $this->central('owner', ['admin']);
        [$ws] = $this->makeWorkspace($owner);
        $u = $this->central('cousin', ['family']);
        $sync = app(CentralRoleSync::class);
        $sync->sync($u);

        $u->forceFill(['central_roles' => ['admin', 'family']])->save();
        $sync->sync($u);
        $this->assertSame('administrator', Membership::where('user_id', $u->id)->value('role'));

        $u->forceFill(['central_roles' => ['guest']])->save();
        $sync->sync($u);
        $m = Membership::where('user_id', $u->id)->first();
        $this->assertSame('suspended', $m->status);
        $this->signIn($u)->getJson($this->wsUrl($ws, 'r/persons'))->assertNotFound();

        $u->forceFill(['central_roles' => ['family']])->save();
        $sync->sync($u);
        $m->refresh();
        $this->assertSame(['member', 'active'], [$m->role, $m->status]);

        // An administrator's manual role pins it.
        $this->signIn($owner)->send('POST', $this->wsUrl($ws, "access/members/{$m->id}/role"), ['role' => 'principal'])->assertOk();
        $u->forceFill(['central_roles' => ['ice']])->save();
        $sync->sync($u);
        $this->assertSame('principal', $m->refresh()->role);
    }

    public function test_the_last_administrator_is_never_demoted_or_suspended_by_sync(): void
    {
        $owner = $this->central('owner', ['admin']);
        [$ws] = $this->makeWorkspace($owner);
        $owner->forceFill(['central_roles' => ['guest']])->save();
        app(CentralRoleSync::class)->sync($owner);
        $m = Membership::where('workspace_id', $ws->id)->where('user_id', $owner->id)->first();
        $this->assertSame(['administrator', 'active'], [$m->role, $m->status]);
    }
}
