<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Joint title on real estate, assets, accounts and debts. */
class TitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_joint_title_forms_and_history(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u);
        $person = fn (string $n) => $this->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => $n])->assertCreated()->json('data.id');
        [$alex, $jordan, $sam] = [$person('Alex Example'), $person('Jordan Example'), $person('Sam Example')];
        $home = $this->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary'])->json('data.id');
        $boat = $this->send('POST', $this->wsUrl($ws, 'r/assets'), ['scope_id' => $joint->id, 'name' => 'Boat', 'kind' => 'boat'])->json('data.id');
        $p = fn (string $id) => ['owner_type' => 'person', 'owner_id' => $id];

        // Tenancy by the entirety needs a recorded spousal relationship.
        $tbe = ['title_form' => 'tenancy_by_entirety', 'effective_from' => '2020-06-01', 'owners' => [$p($alex), $p($jordan)]];
        $this->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), $tbe)->assertStatus(422);
        $this->send('POST', $this->wsUrl($ws, 'r/relationships'), ['scope_id' => $joint->id, 'person_id' => $alex, 'related_person_id' => $jordan, 'kind' => 'spouse'])->assertCreated();
        $t = $this->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), $tbe)->assertOk()->json('data');
        $this->assertTrue($t['complete']);
        $this->assertSame(['0.500000000000', '0.500000000000'], array_column($t['owners'], 'fraction'));
        $this->send('PUT', $this->wsUrl($ws, "title/asset/{$boat}"), $tbe)->assertStatus(422);

        // Joint tenants share equally whatever was typed; three-way splits still total 100%.
        $t = $this->send('PUT', $this->wsUrl($ws, "title/asset/{$boat}"), ['title_form' => 'jtwros', 'owners' => [$p($alex) + ['fraction' => '0.9'], $p($jordan), $p($sam)]])->assertOk()->json('data');
        $this->assertTrue($t['complete']);
        $this->assertEqualsCanonicalizing(['0.333333333333', '0.333333333333', '0.333333333334'], array_column($t['owners'], 'fraction'));

        // Tenants in common keep their stated shares, which must total 100%.
        $tic = ['title_form' => 'tenants_in_common', 'effective_from' => '2024-01-01', 'owners' => [$p($alex) + ['fraction' => '0.6'], $p($sam) + ['fraction' => '0.3']]];
        $this->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), $tic)->assertStatus(422);
        $tic['owners'][1]['fraction'] = '0.4';
        $this->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), $tic)->assertOk();
        $this->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), ['title_form' => 'sole', 'owners' => [$p($alex), $p($sam)]])->assertStatus(422);

        $hist = DB::table('ownership_interests')->where('subject_id', $home)->whereNull('archived_at')->orderBy('effective_from')->get();
        $this->assertSame(['2023-12-31', '2023-12-31', null, null], $hist->pluck('effective_to')->all(), 'prior title is end-dated, not deleted');
        $this->assertSame(['tenants_in_common', 'tenants_in_common'], $this->getJson($this->wsUrl($ws, "title/property/{$home}"))->json('data.owners.*.title_form'));
    }

    public function test_changing_title_needs_household_write(): void
    {
        $u = $this->makeUser('owner');
        $kid = $this->makeUser('kid');
        [$ws, $om, $joint] = $this->makeWorkspace($u);
        $km = $this->addMember($ws, $kid);
        $alex = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Alex Example'])->json('data.id');
        $home = $this->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary'])->json('data.id');
        AccessGrant::create(['workspace_id' => $ws->id, 'membership_id' => $km->id, 'scope_id' => $joint->id, 'domains' => ['real_estate'], 'actions' => ['read', 'write'], 'granted_by' => $om->id]);
        $this->signIn($kid)->getJson($this->wsUrl($ws, "title/property/{$home}"))->assertOk()->assertJsonPath('data.owners', []);
        $this->signIn($kid)->send('PUT', $this->wsUrl($ws, "title/property/{$home}"), ['title_form' => 'sole', 'owners' => [['owner_type' => 'person', 'owner_id' => $alex]]])->assertStatus(403);
    }
}
