<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Services\RealEstate\PropertyLookupService;
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

    public function test_tax_records_installments_deed_reference_and_payment_posts_an_expense(): void
    {
        $this->fakeSources();
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Shore house', 'kind' => 'second_home'])->json('data.id');
        $res = $this->signIn($u)->send('POST', $this->wsUrl($ws, "properties/{$id}/public-records"), ['address' => '12 Ocean Avenue, Example Beach NJ', 'pin' => '1599_7_2'])
            ->assertOk()->json('data.taxes');
        $y = (int) now()->format('Y');
        $this->assertSame([[$y, $y - 1], 4, 1], [$res['years'], $res['installments'], $res['instruments']]);
        $years = DB::table('property_tax_years')->where('property_id', $id)->orderBy('tax_year')->get();
        $this->assertEquals([9876.54, 750000], [(float) $years[0]->tax_amount, (float) $years[1]->assessed_total]);
        $inst = DB::table('property_tax_installments')->where('property_id', $id)->orderBy('quarter')->get();
        $this->assertSame(["{$y}-02-01", "{$y}-11-01"], [$inst[0]->due_on, $inst[3]->due_on]);
        $this->assertSame('9876.5400', number_format(array_sum(array_map(fn ($r) => (float) $r->amount, $inst->all())), 4, '.', ''));
        $deed = DB::table('recorded_instruments')->where('property_id', $id)->first();
        $this->assertSame(['deed', '12345', '678', 'mod_iv'], [$deed->kind, $deed->book, $deed->page, $deed->source]);

        // Re-applying does not duplicate anything.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "properties/{$id}/public-records"), ['address' => '12 Ocean Avenue, Example Beach NJ', 'pin' => '1599_7_2'])->assertOk();
        $this->assertSame([2, 4, 1], [DB::table('property_tax_years')->count(), DB::table('property_tax_installments')->count(), DB::table('recorded_instruments')->count()]);

        $this->signIn($u)->send('POST', $this->wsUrl($ws, "property-tax-installments/{$inst[0]->id}/pay"), ['paid_on' => "{$y}-01-28", 'amount' => '2469.14'])->assertOk();
        $exp = DB::table('property_expenses')->where('property_id', $id)->first();
        $this->assertSame(['property_tax', 'expense', 'tax_installment'], [$exp->category, $exp->treatment, $exp->source]);
        $this->assertEquals(2469.14, (float) $exp->amount);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "property-tax-installments/{$inst[0]->id}/pay"), ['paid_on' => "{$y}-01-28", 'amount' => '1'])->assertStatus(409);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "property-tax-installments/{$inst[1]->id}/pay"), ['paid_on' => '2999-01-01', 'amount' => '1'])->assertStatus(422);
    }

    public function test_monmouth_record_card_key(): void
    {
        $this->assertSame('131600040____00016_________M', PropertyLookupService::monmouthCardKey('1316', '40', '16', null));
        $this->assertSame('131600040____00007__01_____M', PropertyLookupService::monmouthCardKey('1316', '40', '7.01', ''));
        $this->assertSame('131600049____00013__02C0001M', PropertyLookupService::monmouthCardKey('1316', '49', '13.02', 'c0001'));
        $this->assertNull(PropertyLookupService::monmouthCardKey('1316', "40' OR 1", '1', null));
    }

    public function test_public_record_comps_and_recorded_estimates(): void
    {
        $recent = fn (int $months) => now()->subMonths($months)->format('ymd');
        $sale = fn (string $pin, int $price, int $assessed, string $date, string $code = '') => ['attributes' => ['PAMS_PIN' => $pin, 'PCL_MUN' => '1351', 'PCLBLOCK' => '1',
            'PCLLOT' => substr($pin, -1), 'PROP_CLASS' => '2', 'COUNTY' => 'MONMOUTH', 'PROP_LOC' => "{$pin} EXAMPLE CIRCLE", 'NET_VALUE' => $assessed, 'SALE_PRICE' => $price,
            'DEED_DATE' => $date, 'SALES_CODE' => $code], 'centroid' => ['x' => -74.5, 'y' => 40.1]];
        Http::fake(['services2.arcgis.com/*' => Http::response(['features' => [
            $sale('1351_1_1', 1100000, 1000000, $recent(4)), $sale('1351_1_2', 1200000, 1000000, $recent(10)), $sale('1351_1_3', 1300000, 1000000, $recent(20)),
            $sale('1351_1_4', 900000, 1000000, $recent(80)), $sale('1351_1_5', 10, 1000000, $recent(2)), $sale('1351_1_6', 2500000, 1000000, $recent(3), '26'),
            $sale('1351_1_9', 1500000, 1500000, $recent(1)),
        ]])]);
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary'])->json('data.id');
        $this->getJson($this->wsUrl($ws, "properties/{$id}/comps"))->assertStatus(422);
        DB::table('properties')->where('id', $id)->update(['mun_code' => '1351', 'latitude' => '40.1', 'longitude' => '-74.5', 'assessed_total' => '1700000', 'parcel_pin' => '1351_1_9', 'property_class' => '2']);

        $c = $this->getJson($this->wsUrl($ws, "properties/{$id}/comps"))->assertOk()->json('data');
        // Old (80 months), nominal ($10), NU-coded and the subject itself are excluded; ratios 1.1, 1.2, 1.3.
        $this->assertSame(['ok', 3, 1.2], [$c['status'], count($c['comps']), $c['median_ratio']]);
        $this->assertSame(['2040000.0000', '1870000.0000', '2210000.0000'], [$c['value'], $c['low'], $c['high']]);

        $this->send('POST', $this->wsUrl($ws, "estimates/property/{$id}"), ['source' => 'zillow', 'value' => '2,200,000', 'url' => 'http://example.test/x'])->assertStatus(422);
        $this->send('POST', $this->wsUrl($ws, "estimates/property/{$id}"), ['source' => 'zillow', 'value' => '$2,200,000', 'url' => 'https://www.zillow.com/homedetails/example'])
            ->assertCreated()->assertJsonPath('data.status', 'proposed');
        $this->send('POST', $this->wsUrl($ws, "estimates/property/{$id}"), ['source' => 'own', 'value' => '1950000', 'low' => '1800000', 'high' => '2100000', 'accept' => true])
            ->assertCreated()->assertJsonPath('data.status', 'accepted');
        $f = $this->getJson($this->wsUrl($ws, "collateral/property/{$id}"))->json('data');
        $this->assertEquals([1950000, 1], [(float) $f['value'], $f['proposed_valuations']]);
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
