<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Address validation, NJ parcel/MOD-IV records, licensed AVMs, secured loans and valuation review. */
class PropertyRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSources(bool $rentcast = false): void
    {
        config(['legacy.property_lookup.rentcast_api_key' => $rentcast ? 'rc-test' : null, 'legacy.property_lookup.bridge_token' => null]);
        Http::fake([
            'geocoding.geo.census.gov/*' => Http::response(['result' => ['addressMatches' => [[
                'matchedAddress' => '12 OCEAN AVE, EXAMPLE BEACH, NJ, 08700',
                'coordinates' => ['x' => -74.05, 'y' => 40.0],
                'addressComponents' => ['preDirection' => '', 'streetName' => 'OCEAN', 'suffixType' => 'AVE', 'city' => 'EXAMPLE BEACH', 'state' => 'NJ', 'zip' => '08700'],
                'geographies' => ['Counties' => [['BASENAME' => 'Ocean', 'STATE' => '34', 'COUNTY' => '029']], 'County Subdivisions' => [['NAME' => 'Example Beach borough']]],
            ]]]]),
            'services2.arcgis.com/*' => Http::response(['features' => [
                ['attributes' => ['PAMS_PIN' => '1599_7_3', 'PCL_MUN' => '1599', 'PCLBLOCK' => '7', 'PCLLOT' => '3', 'PCLQCODE' => '', 'PROP_CLASS' => '2', 'COUNTY' => 'OCEAN',
                    'MUN_NAME' => 'EXAMPLE BEACH BORO', 'PROP_LOC' => '14 OCEAN AVENUE', 'NET_VALUE' => 500000, 'LAND_VAL' => 300000, 'IMPRVT_VAL' => 200000],
                    'centroid' => ['x' => -74.0501, 'y' => 40.0001]],
                ['attributes' => ['PAMS_PIN' => '1599_7_2', 'PCL_MUN' => '1599', 'PCLBLOCK' => '7', 'PCLLOT' => '2', 'PCLQCODE' => '', 'PROP_CLASS' => '2', 'COUNTY' => 'OCEAN',
                    'MUN_NAME' => 'EXAMPLE BEACH BORO', 'PROP_LOC' => '12 OCEAN AVENUE', 'OWNER_NAME' => '', 'LAND_VAL' => 400000, 'IMPRVT_VAL' => 350000,
                    'NET_VALUE' => 750000, 'LAST_YR_TX' => 9876.54, 'DEED_BOOK' => '12345', 'DEED_PAGE' => '678', 'DEED_DATE' => '190614', 'SALE_PRICE' => 690000,
                    'YR_CONSTR' => 1962, 'CALC_ACRE' => 0.1148],
                    'centroid' => ['x' => -74.0500, 'y' => 40.0000]],
            ]]),
            'api.rentcast.io/*' => Http::response(['price' => 1150000, 'priceRangeLow' => 1000000, 'priceRangeHigh' => 1300000,
                'subjectProperty' => ['squareFootage' => 1850, 'bedrooms' => 3], 'comparables' => [[], []]]),
        ]);
    }

    public function test_lookup_validates_the_address_and_ranks_the_exact_parcel_first(): void
    {
        $this->fakeSources();
        $u = $this->makeUser('owner');
        [$ws] = $this->makeWorkspace($u);
        $d = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'real-estate/lookup'), ['address' => '12 Ocean Avenue, Example Beach NJ'])->assertOk()->json('data');

        $this->assertSame('12 OCEAN AVE, EXAMPLE BEACH, NJ, 08700', $d['address']['matched']);
        $this->assertSame('Ocean', $d['address']['county']);
        $p = $d['parcels'][0];
        $this->assertSame(['1599_7_2', '7', '2', 'exact'], [$p['pin'], $p['block'], $p['lot'], $p['match']]);
        $this->assertSame(['2019-06-14', 1962, 'Residential (1–4 family)'], [$p['deed_date'], $p['year_built'], $p['property_class_label']]);
        $this->assertEquals(9876.54, (float) $p['last_year_tax']);
        $this->assertSame([], $d['valuations']);
        $this->assertContains('not_configured', array_column($d['sources'], 'status'));
        $labels = array_column($d['links'], 'label');
        $this->assertContains('Zillow', $labels);
        $this->assertContains('Ocean County Clerk public records search (deeds & mortgages)', $labels);
    }

    public function test_applying_records_fills_block_lot_and_adds_assessment_and_proposed_avm_valuations(): void
    {
        $this->fakeSources(rentcast: true);
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Shore house', 'kind' => 'second_home'])
            ->assertCreated()->json('data.id');

        $res = $this->signIn($u)->send('POST', $this->wsUrl($ws, "properties/{$id}/public-records"), ['address' => '12 Ocean Avenue, Example Beach NJ', 'pin' => '1599_7_2'])
            ->assertOk()->json('data');
        $this->assertCount(2, $res['valuations']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.rentcast.io') && $r->hasHeader('X-Api-Key', 'rc-test'));

        $p = $this->signIn($u)->getJson($this->wsUrl($ws, "r/properties/{$id}"))->json('data');
        $this->assertSame(['7', '2', '1599', 'Ocean', '12345', '678', 1850], [$p['block'], $p['lot'], $p['mun_code'], $p['county'], $p['deed_book'], $p['deed_page'], $p['living_area_sqft']]);
        $this->assertSame('12 OCEAN AVE', $p['address_line']);
        $this->assertNotNull($p['address_verified_at']);
        $this->assertEquals(750000, (float) $p['assessed_total']);

        $vals = DB::table('valuations')->where('subject_id', $id)->get()->keyBy('purpose');
        $this->assertSame(['accepted', 'statement'], [$vals['tax']->status, $vals['tax']->method]);
        $this->assertSame(['proposed', 'licensed_feed'], [$vals['market']->status, $vals['market']->method]);
        $this->assertSame(1, DB::table('property_lookups')->where('property_id', $id)->count());

        // A forged parcel id is refused.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "properties/{$id}/public-records"), ['address' => '12 Ocean Avenue, Example Beach NJ', 'pin' => '9999_1_1'])
            ->assertStatus(422);
    }

    public function test_block_and_lot_search_and_input_is_required(): void
    {
        $this->fakeSources();
        $u = $this->makeUser('owner');
        [$ws] = $this->makeWorkspace($u);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'real-estate/lookup'), [])->assertStatus(422);
        $d = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'real-estate/lookup'), ['mun_code' => '1599', 'block' => '7', 'lot' => "2' OR 1=1"])->assertOk()->json('data');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'arcgis') && str_contains(urldecode($r->url()), "PCLLOT='2'' OR 1=1'"));
        $this->assertNull($d['address']);
    }

    public function test_loans_link_to_homes_and_vehicles_and_financing_shows_equity(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u);
        $home = $this->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary', 'purchase_price' => '800000'])->json('data.id');
        $boat = $this->send('POST', $this->wsUrl($ws, 'r/assets'), ['scope_id' => $joint->id, 'name' => 'Boat', 'kind' => 'boat', 'make' => 'Example', 'model' => '28 CC',
            'model_year' => 2021, 'cost' => '120000'])->assertCreated()->json('data.id');
        $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'Mortgage', 'kind' => 'mortgage', 'current_balance' => '500000',
            'collateral_type' => 'property', 'collateral_id' => $home])->assertCreated();
        $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'HELOC', 'kind' => 'heloc', 'current_balance' => '50000',
            'collateral_type' => 'property', 'collateral_id' => $home])->assertCreated();
        $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'Boat loan', 'kind' => 'boat', 'current_balance' => '60000',
            'collateral_type' => 'asset', 'collateral_id' => $boat])->assertCreated();
        $val = $this->send('POST', $this->wsUrl($ws, 'r/valuations'), ['scope_id' => $joint->id, 'subject_type' => 'property', 'subject_id' => $home, 'purpose' => 'market',
            'value' => '1000000', 'as_of' => now()->toDateString(), 'method' => 'appraisal'])->assertCreated()->json('data.id');

        $f = $this->getJson($this->wsUrl($ws, "collateral/property/{$home}"))->assertOk()->json('data');
        $this->assertSame(2, count($f['loans']));
        $this->assertSame('purchase price', $f['value_method'], 'proposed valuations do not count until accepted');
        $this->assertSame(1, $f['proposed_valuations']);

        $this->send('POST', $this->wsUrl($ws, "valuations/{$val}/decision"), ['decision' => 'accept'])->assertOk();
        $f = $this->getJson($this->wsUrl($ws, "collateral/property/{$home}"))->json('data');
        $this->assertEquals([1000000, 550000, 450000, 0.55], [(float) $f['value'], (float) $f['total_debt'], (float) $f['equity'], (float) $f['loan_to_value']]);
        $this->send('POST', $this->wsUrl($ws, "valuations/{$val}/decision"), ['decision' => 'reject'])->assertStatus(409);

        $b = $this->getJson($this->wsUrl($ws, "collateral/asset/{$boat}"))->json('data');
        $this->assertEquals([120000, 60000, 60000], [(float) $b['value'], (float) $b['total_debt'], (float) $b['equity']]);
        // A loan cannot be secured by another workspace's collateral.
        $other = $this->makeUser('other');
        [$ws2, , $joint2] = $this->makeWorkspace($other);
        $this->signIn($other)->send('POST', $this->wsUrl($ws2, 'r/liabilities'), ['scope_id' => $joint2->id, 'name' => 'X', 'kind' => 'auto', 'current_balance' => '1',
            'collateral_type' => 'asset', 'collateral_id' => $boat])->assertStatus(422);
    }

    public function test_financing_hides_loans_without_debt_access(): void
    {
        $owner = $this->makeUser('owner');
        $kid = $this->makeUser('kid');
        [$ws, $om, $joint] = $this->makeWorkspace($owner);
        $km = $this->addMember($ws, $kid);
        $this->signIn($owner);
        $home = $this->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary'])->json('data.id');
        $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'Mortgage', 'kind' => 'mortgage', 'current_balance' => '1',
            'collateral_type' => 'property', 'collateral_id' => $home])->assertCreated();
        AccessGrant::create(['workspace_id' => $ws->id, 'membership_id' => $km->id, 'scope_id' => $joint->id, 'domains' => ['real_estate'], 'actions' => ['read'], 'granted_by' => $om->id]);

        $f = $this->signIn($kid)->getJson($this->wsUrl($ws, "collateral/property/{$home}"))->assertOk()->json('data');
        $this->assertSame([[], false], [$f['loans'], $f['loans_visible']]);
        $this->signIn($kid)->send('POST', $this->wsUrl($ws, 'real-estate/lookup'), ['address' => '1 Main St'])->assertStatus(403);
    }
}
