<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Deny-by-default, cross-workspace isolation, scope/domain grants and field redaction. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_are_rejected_and_the_shell_redirects(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->get('/w/anything/home')->assertRedirect('/auth/login');
    }

    public function test_health_is_masked(): void
    {
        $res = $this->getJson('/health');
        $this->assertContains($res->status(), [200, 503]);
        $res->assertJsonStructure(['status', 'checks' => ['database', 'storage', 'vault_key', 'runner', 'worker'], 'release']);
        $this->assertStringNotContainsString('PHP', (string) $res->getContent());
    }

    public function test_workspaces_are_isolated_and_invisible_rows_look_missing(): void
    {
        $alice = $this->makeUser('alice', 'Alice');
        $bob = $this->makeUser('bob', 'Bob');
        [$wsA, , $jointA] = $this->makeWorkspace($alice, 'A family');
        [$wsB] = $this->makeWorkspace($bob, 'B family');

        $person = $this->signIn($alice)->send('POST', $this->wsUrl($wsA, 'r/persons'), ['scope_id' => $jointA->id, 'display_name' => 'Secret Person'])
            ->assertCreated()->json('data.id');

        // Bob cannot address Alice's workspace at all, and cannot reach her record through his own.
        $this->signIn($bob)->getJson($this->wsUrl($wsA, 'r/persons'))->assertNotFound();
        $this->signIn($bob)->getJson($this->wsUrl($wsB, "r/persons/{$person}"))->assertNotFound();
        $this->signIn($bob)->getJson($this->wsUrl($wsB, 'r/persons'))->assertOk()->assertJsonCount(0, 'data');
        // Bob cannot write into Alice's scope via his workspace either.
        $this->signIn($bob)->send('POST', $this->wsUrl($wsB, 'r/persons'), ['scope_id' => $jointA->id, 'display_name' => 'X'])->assertStatus(403);
    }

    public function test_administrators_get_no_implicit_data_access(): void
    {
        $owner = $this->makeUser('owner');
        $admin2 = $this->makeUser('admin2');
        [$ws, , $joint] = $this->makeWorkspace($owner);
        $this->addMember($ws, $admin2, 'administrator');
        $this->signIn($owner)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Kid'])->assertCreated();

        $this->signIn($admin2)->getJson($this->wsUrl($ws, 'r/persons'))->assertOk()->assertJsonCount(0, 'data');
        $nw = $this->signIn($admin2)->getJson($this->wsUrl($ws, 'net-worth'))->assertOk()->json('data.net_worth');
        $this->assertEquals(0, (float) $nw);
    }

    public function test_grants_are_limited_by_scope_domain_and_redact_sensitive_fields(): void
    {
        $owner = $this->makeUser('owner');
        $cpa = $this->makeUser('cpa', 'CPA');
        [$ws, $ownerM, $joint, $private] = $this->makeWorkspace($owner);
        $cpaM = $this->addMember($ws, $cpa, 'advisor');
        $this->signIn($owner);
        $this->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Joint Person', 'ssn_last4' => '1234', 'email' => 'j@example.test'])->assertCreated();
        $this->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $private->id, 'display_name' => 'Private Person'])->assertCreated();

        AccessGrant::create(['workspace_id' => $ws->id, 'membership_id' => $cpaM->id, 'scope_id' => $joint->id, 'domains' => ['household'], 'actions' => ['read'],
            'granted_by' => $ownerM->id, 'expires_at' => now()->addDays(30)]);

        $rows = $this->signIn($cpa)->getJson($this->wsUrl($ws, 'r/persons'))->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Joint Person', $rows[0]['display_name']);
        $this->assertNull($rows[0]['email']);
        $one = $this->signIn($cpa)->getJson($this->wsUrl($ws, 'r/persons/'.$rows[0]['id']))->assertOk()->json('data');
        $this->assertNull($one['ssn_last4']);
        $this->assertContains('ssn_last4', $one['_redacted']);
        // Read-only grant: no writes, no other domains.
        $this->signIn($cpa)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Nope'])->assertStatus(403);
        $this->signIn($cpa)->getJson($this->wsUrl($ws, 'r/accounts'))->assertOk()->assertJsonCount(0, 'data');

        // The owner sees the identity field.
        $own = $this->signIn($owner)->getJson($this->wsUrl($ws, 'r/persons/'.$rows[0]['id']))->assertOk()->json('data');
        $this->assertSame('1234', $own['ssn_last4']);
    }

    public function test_expired_grants_stop_working(): void
    {
        $owner = $this->makeUser('owner');
        $adv = $this->makeUser('adv');
        [$ws, $ownerM, $joint] = $this->makeWorkspace($owner);
        $m = $this->addMember($ws, $adv, 'advisor');
        $this->signIn($owner)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'P'])->assertCreated();
        AccessGrant::create(['workspace_id' => $ws->id, 'membership_id' => $m->id, 'scope_id' => $joint->id, 'domains' => ['household'], 'actions' => ['read'],
            'granted_by' => $ownerM->id, 'expires_at' => now()->subMinute()]);

        $this->signIn($adv)->getJson($this->wsUrl($ws, 'r/persons'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_privileged_actions_require_step_up(): void
    {
        $owner = $this->makeUser('owner');
        [$ws] = $this->makeWorkspace($owner);
        $this->signIn($owner, stepUp: false)->send('POST', $this->wsUrl($ws, 'access/invitations'), ['email' => 'x@example.test', 'role' => 'member'])
            ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');
        $this->signIn($owner)->send('POST', $this->wsUrl($ws, 'access/invitations'), ['email' => 'x@example.test', 'role' => 'member'])
            ->assertCreated()->assertJsonStructure(['data' => ['invite_url']]);
    }
}
