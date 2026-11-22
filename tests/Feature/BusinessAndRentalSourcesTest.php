<?php

namespace Tests\Feature;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use App\Services\Tax\TaxInputBuilder;
use App\Support\AccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Beach House PMS (rental) and McNutt Finance (side business) REST sources → property stays/expenses, business books, tax inputs. */
class BusinessAndRentalSourcesTest extends TestCase
{
    use RefreshDatabase;

    private int $y;

    private array $stays = [];

    private array $expenses = [];

    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['legacy.features.connector_property_api' => true, 'legacy.features.connector_invoices_api' => true]);
        $this->y = (int) now()->format('Y');
        $y = $this->y;
        $this->stays = [
            ['external_id' => 'booking:1', 'kind' => 'guest', 'check_in' => "{$y}-03-01", 'check_out' => "{$y}-03-08", 'nights' => 7, 'revenue_recognized' => true, 'cancelled' => false,
                'channel' => 'direct', 'gross_rent' => '1400.00', 'cleaning_fee' => '150.00', 'taxes_collected' => '180.00', 'platform_fee' => '50.00', 'deposit_kept' => '200.00',
                'refund' => '0.00', 'payout' => '1500.00'],
            ['external_id' => 'booking:2', 'kind' => 'guest', 'check_in' => "{$y}-06-01", 'check_out' => "{$y}-06-03", 'revenue_recognized' => false, 'cancelled' => false,
                'gross_rent' => '500.00', 'cleaning_fee' => '0.00'],
            ['external_id' => 'block:3', 'kind' => 'personal', 'check_in' => "{$y}-04-01", 'check_out' => "{$y}-04-05", 'revenue_recognized' => false, 'cancelled' => false],
            ['external_id' => 'booking:4', 'kind' => 'guest', 'check_in' => "{$y}-05-01", 'check_out' => "{$y}-05-04", 'revenue_recognized' => true, 'cancelled' => false,
                'gross_rent' => '600.00', 'cleaning_fee' => '100.00', 'deposit_kept' => '0.00'],
        ];
        $this->expenses = [
            ['external_id' => 'expense:10', 'incurred_on' => "{$y}-03-09", 'amount' => '120.00', 'category' => 'Cleaning', 'suggested_category' => 'cleaning', 'treatment' => 'expense', 'vendor' => 'Example Cleaners'],
            ['external_id' => 'expense:11', 'incurred_on' => "{$y}-04-10", 'amount' => '900.00', 'category' => 'Home Improvements', 'suggested_category' => 'repairs', 'treatment' => 'undetermined'],
            ['external_id' => 'expense:12', 'incurred_on' => "{$y}-05-10", 'amount' => '80.00', 'category' => 'Utilities', 'suggested_category' => 'utilities', 'treatment' => 'expense',
                'import_source' => 'monarch', 'import_reference' => 'tx-99'],
        ];
        $this->events = [
            ['id' => '77', 'date' => "{$y}-02-15", 'account_id' => '12', 'account_code' => '4000', 'amount' => '5000.00', 'source_key' => 'payment:9:alloc:15', 'description' => 'Payment · INV-1'],
            ['id' => '78', 'date' => "{$y}-02-20", 'account_id' => '20', 'account_code' => '6400', 'amount' => '200.00', 'source_key' => 'expense:1', 'description' => 'Client lunch'],
            ['id' => '79', 'date' => "{$y}-03-01", 'account_id' => '21', 'account_code' => '6500', 'amount' => '300.00', 'source_key' => 'expense:2', 'description' => 'Hosting'],
            ['id' => '80', 'date' => "{$y}-04-15", 'account_id' => '22', 'account_code' => '6900', 'amount' => '1000.00', 'source_key' => 'expense:3', 'description' => 'Estimated tax'],
            ['id' => '81', 'date' => "{$y}-05-01", 'account_id' => '12', 'account_code' => '4000', 'amount' => '-500.00', 'source_key' => 'credit:5', 'description' => 'Refund'],
        ];
        Http::fake(fn (Request $r) => $this->fake($r));
    }

    private function fake(Request $r)
    {
        $u = parse_url($r->url());
        parse_str($u['query'] ?? '', $q);
        if ($u['host'] === 'beachdaysalways.com') {
            if (($r->header('X-API-Key')[0] ?? '') !== 'fo_example_key_123') {
                return Http::response(['error' => 'invalid_api_key'], 401);
            }
            $inYear = fn (array $rows, string $k) => array_values(array_filter($rows, fn ($x) => str_starts_with($x[$k], (string) ($q['year'] ?? ''))));

            return Http::response(['data' => match ($q['resource'] ?? '') {
                'ping' => ['ok' => true],
                'property' => ['external_id' => 'property:1', 'name' => 'Example Beach House', 'kind' => 'rental_str', 'city' => 'Example Shore', 'state' => 'NJ', 'api_version' => 1],
                'stays' => $inYear($this->stays, 'check_in'),
                'expenses' => $inYear($this->expenses, 'incurred_on'),
                'summary' => ['income' => ['gross_revenue' => (int) $q['year'] === $this->y ? '2450.00' : '0.00', 'booking_count' => 2], 'expenses' => ['total' => '1100.00'],
                    'days' => ['rental' => 10, 'personal' => 4]],
                default => [],
            }, 'meta' => []]);
        }
        if ($u['host'] === 'invoices.mcnutt.cloud') {
            if (($r->header('X-API-Key')[0] ?? '') !== 'fin_example_key_123') {
                return Http::response(['error' => ['code' => 'unauthorized', 'message' => 'Invalid key']], 401);
            }
            $path = substr($u['path'], strlen('/api/v1/data'));

            return match ($path) {
                '' => Http::response(['data' => ['business' => ['id' => 'b1', 'name' => 'Example Consulting', 'legal_name' => '', 'entity_type' => 'sole_proprietor', 'reporting_basis' => 'cash'],
                    'key' => ['scopes' => ['ledger.read', 'reports.read']], 'datasets' => [['path' => '/api/v1/data/accounts', 'scope' => 'ledger.read', 'available' => true]]]]),
                '/accounts' => ($q['cursor'] ?? null) === 'c2'
                    ? Http::response(['data' => [['id' => '21', 'code' => '6500', 'name' => 'Software & Hosting', 'type' => 'expense'], ['id' => '22', 'code' => '6900', 'name' => 'Income Tax Payments', 'type' => 'expense'],
                        ['id' => '30', 'code' => '3100', 'name' => 'Owner Draw', 'type' => 'equity']], 'meta' => ['has_more' => false, 'next_cursor' => null]])
                    : Http::response(['data' => [['id' => '12', 'code' => '4000', 'name' => 'Service Income', 'type' => 'income', 'system_key' => 'service_income'],
                        ['id' => '20', 'code' => '6400', 'name' => 'Meals', 'type' => 'expense']], 'meta' => ['has_more' => true, 'next_cursor' => 'c2']]),
                '/cash-events' => Http::response(['data' => $this->events, 'meta' => ['has_more' => false, 'next_cursor' => null]]),
                '/reports/profit-and-loss' => Http::response(['data' => ['basis' => 'cash', 'totals' => [['income' => '4500.00', 'cogs' => '0.00', 'expense' => '1500.00', 'net' => '3000.00']]]]),
                '/reports/tax-estimate' => Http::response(['data' => ['year' => $this->y, 'entity' => 'smllc', 'net_business_income' => '3000.00', 'summary' => ['business_tax' => '900.00']]]),
                default => Http::response(['error' => ['message' => 'not found']], 404),
            };
        }

        return Http::response('unexpected '.$r->url(), 500);
    }

    private function work(): void
    {
        $jobs = app(JobService::class);
        foreach (['interactive', 'bulk'] as $q) {
            while ($job = $jobs->claim($q)) {
                $jobs->execute($job, app(RunnerClient::class));
            }
        }
    }

    private function connect($ws, $u, array $create, string $key): string
    {
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), $create)->assertCreated()->json('data.id');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/key"), ['key' => $key])->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/approve"))->assertStatus(409);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/test"))->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/preview"))->assertStatus(202);
        $this->work();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/approve"))->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();

        return $id;
    }

    private function taxInput($ws, $m, $u, string $unit): array
    {
        return (new TaxInputBuilder(new AccessContext($u, $ws, $m)))->build($unit);
    }

    public function test_pms_sync_upserts_stays_and_expenses_keeps_user_edits_and_archives_deletions(): void
    {
        $u = $this->makeUser('owner');
        [$ws, $m, $joint] = $this->makeWorkspace($u);
        $this->signIn($u);
        $prop = $this->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Beach house', 'kind' => 'rental_str'])->assertCreated()->json('data.id');
        $this->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'property_manager', 'scope_id' => $joint->id])->assertStatus(422);

        $bad = $this->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'property_manager', 'scope_id' => $joint->id, 'property_id' => $prop])->json('data.id');
        $this->send('POST', $this->wsUrl($ws, "integrations/{$bad}/key"), ['key' => 'fo_wrong_key_000'])->assertOk();
        $this->send('POST', $this->wsUrl($ws, "integrations/{$bad}/test"))->assertStatus(502)->assertJsonPath('error.code', 'integration_unauthorized');

        $id = $this->connect($ws, $u, ['provider' => 'property_manager', 'scope_id' => $joint->id, 'property_id' => $prop], 'fo_example_key_123');
        $c = $this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$id}"))->assertOk()->json('data');
        $this->assertSame('Example Beach House', $c['last_preview']['remote_property']['name']);
        $this->assertSame(['property_id' => $prop], $c['target']);

        $stays = DB::table('property_stays')->where('property_id', $prop)->orderBy('check_in')->get()->keyBy('external_id');
        $this->assertSame(['booking:1', 'block:3', 'booking:4'], $stays->keys()->all(), 'unpaid booking is not revenue yet');
        $this->assertEquals(200, (float) $stays['booking:1']->damage_recovery);
        $this->assertSame(['beach_house_pms', $id], [$stays['booking:1']->source, $stays['booking:1']->source_ref]);
        $this->assertSame(3, DB::table('property_expenses')->where('property_id', $prop)->count());
        $this->assertSame('undetermined', DB::table('property_expenses')->where('external_id', 'expense:11')->value('treatment'));
        $stats = json_decode((string) DB::table('source_connections')->where('id', $id)->value('last_sync_stats'), true);
        $rec = collect($stats['reconciliation'])->firstWhere('year', $this->y);
        $this->assertSame(['2450.00', '0.00'], [$rec['legacy_gross_revenue'], $rec['revenue_difference']]);

        // The owner reclassifies the 3-night stay as family (free) use; the PMS then edits a booking and deletes an expense.
        $this->signIn($u)->send('PATCH', $this->wsUrl($ws, 'r/property_stays/'.$stays['booking:4']->id), ['kind' => 'family_free'], ['If-Match' => '"'.$stays['booking:4']->edit_version.'"'])->assertOk();
        $this->stays[0]['gross_rent'] = '1500.00';
        array_shift($this->expenses);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(3, DB::table('property_stays')->where('property_id', $prop)->count());
        $this->assertSame('family_free', DB::table('property_stays')->where('external_id', 'booking:4')->value('kind'));
        $this->assertEquals(1500, (float) DB::table('property_stays')->where('external_id', 'booking:1')->value('gross_rent'));
        $this->assertNotNull(DB::table('property_expenses')->where('external_id', 'expense:10')->value('archived_at'));

        // Schedule E input: rents include kept damage deposits; family use counts as personal days; undetermined expense flagged.
        $person = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Example Owner'])->json('data.id');
        $unit = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/tax_units'), ['scope_id' => $joint->id, 'name' => 'Example', 'tax_year' => $this->y, 'filing_status' => 'single',
            'primary_person_id' => $person])->assertCreated()->json('data.id');
        $in = $this->taxInput($ws, $m, $u, $unit);
        $rental = $in['income']['rentals'][0];
        $this->assertSame('1850', rtrim(rtrim($rental['rents'], '0'), '.'));
        $this->assertSame([7, 7], [$rental['fair_rental_days'], $rental['personal_days']]);
        $this->assertArrayNotHasKey('cleaning', $rental['expenses']);
        $this->assertStringContainsString('not yet classified as repair or improvement', implode(' ', $in['missing_inputs']));

        $view = $this->signIn($u)->getJson($this->wsUrl($ws, 'earnings/business-and-rentals'))->assertOk()->json('data.rentals.0');
        $this->assertSame(['1850.00', 1, 7, 7], [$view['rents'], $view['undetermined'], $view['days']['rental'], $view['days']['personal']]);
    }

    public function test_approval_starts_syncing_scheduler_reschedules_and_schedules_page_shows_full_run_output(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $prop = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Beach house', 'kind' => 'rental_str'])->json('data.id');
        $id = $this->connect($ws, $u, ['provider' => 'property_manager', 'scope_id' => $joint->id, 'property_id' => $prop], 'fo_example_key_123');
        $syncs = fn () => DB::table('compute_jobs')->where('type', 'integration.sync')->where('subject_id', $id)->get()->filter(fn ($j) => empty(json_decode($j->params, true)['dry_run']));
        $this->assertCount(2, $syncs(), 'approval queues the first sync itself');
        $this->assertGreaterThan(now()->addHours(23), now()->parse(DB::table('source_connections')->where('id', $id)->value('next_sync_at')));

        $this->artisan('legacy:tick integrations')->assertSuccessful();
        $this->assertCount(2, $syncs(), 'not due yet');
        DB::table('source_connections')->where('id', $id)->update(['next_sync_at' => now()->subMinute()]);
        $this->artisan('legacy:tick integrations')->assertSuccessful();
        $this->artisan('legacy:tick integrations')->assertSuccessful();
        $this->assertCount(3, $syncs(), 'due REST connection queued once');
        $this->work();

        $d = $this->signIn($u)->getJson($this->wsUrl($ws, 'schedules'))->assertOk()->json('data');
        $src = $d['sources'][0];
        $this->assertSame([$id, true, 'scheduled', 'succeeded'], [$src['id'], $src['scheduled'], $src['runs'][0]['trigger'], $src['runs'][0]['status']]);
        $this->assertCount(count(\App\Support\Heartbeat::TASKS), $d['system']);
        $run = $this->signIn($u)->getJson($this->wsUrl($ws, 'schedules/runs/'.$src['runs'][0]['id']))->assertOk()->json('data');
        $this->assertArrayHasKey('stays_updated', $run['result']);
        $this->assertSame(['connection_id' => $id, 'dry_run' => false, 'scheduled' => true], $run['params']);

        $other = $this->makeUser('member-without-grants');
        $this->addMember($ws, $other);
        $this->signIn($other)->getJson($this->wsUrl($ws, 'schedules/runs/'.$src['runs'][0]['id']))->assertNotFound();
        $this->assertSame([], $this->signIn($other)->getJson($this->wsUrl($ws, 'schedules'))->assertOk()->json('data.sources'));
    }

    public function test_finance_books_become_schedule_c_with_account_mapping_and_no_double_count(): void
    {
        $u = $this->makeUser('owner');
        [$ws, $m, $joint] = $this->makeWorkspace($u);
        $id = $this->connect($ws, $u, ['provider' => 'invoices', 'scope_id' => $joint->id], 'fin_example_key_123');

        $entity = DB::table('legal_entities')->where('workspace_id', $ws->id)->first();
        $this->assertSame(['Example Consulting', 'sole_prop'], [$entity->name, $entity->kind], 'entity created from the Finance profile on approval');
        $c = $this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$id}"))->assertOk()->json('data');
        $lines = array_column($c['accounts'], 'line', 'name');
        $this->assertSame(['Service Income' => 'gross', 'Meals' => 'meals', 'Software & Hosting' => 'office', 'Income Tax Payments' => 'exclude'], $lines, 'equity accounts hidden');
        $this->assertSame(5, DB::table('business_ledger_lines')->where('entity_id', $entity->id)->count());
        $stats = json_decode((string) DB::table('source_connections')->where('id', $id)->value('last_sync_stats'), true);
        $this->assertSame('0.00', collect($stats['reconciliation'])->firstWhere('year', $this->y)['net_difference']);
        $this->assertTrue($stats['tax_estimate']['provisional']);

        // Re-sync is idempotent.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(5, DB::table('business_ledger_lines')->where('entity_id', $entity->id)->count());

        // Mapping edits are validated and versioned.
        $v = '"'.$this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$id}"))->json('data.edit_version').'"';
        $this->signIn($u)->send('PUT', $this->wsUrl($ws, "integrations/{$id}/account-map"), ['map' => ['21' => 'bogus']], ['If-Match' => $v])->assertStatus(422);
        $this->signIn($u)->send('PUT', $this->wsUrl($ws, "integrations/{$id}/account-map"), ['map' => ['21' => 'other']], ['If-Match' => $v])->assertOk();

        $person = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'Example Owner'])->json('data.id');
        $unit = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/tax_units'), ['scope_id' => $joint->id, 'name' => 'Example', 'tax_year' => $this->y, 'filing_status' => 'single',
            'primary_person_id' => $person])->json('data.id');
        $in = $this->taxInput($ws, $m, $u, $unit);
        $this->assertSame([], $in['income']['schedule_c']);
        $this->assertStringContainsString('no owner in this tax unit', implode(' ', $in['missing_inputs']));

        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/ownership_interests'), ['scope_id' => $joint->id, 'owner_type' => 'person', 'owner_id' => $person,
            'subject_type' => 'entity', 'subject_id' => $entity->id, 'fraction' => '1'])->assertCreated();
        $in = $this->taxInput($ws, $m, $u, $unit);
        $sc = $in['income']['schedule_c'][0];
        $this->assertSame(['4500.00', '0.00', ['meals' => '100.00', 'other' => '300.00']], [$sc['gross'], $sc['cogs'], $sc['expenses']]);
        $this->assertStringContainsString('meals limited to 50%', implode(' ', $in['assumptions']));
        $this->assertStringStartsWith('business_ledger_line:', $sc['facts'][0]);

        $view = $this->signIn($u)->getJson($this->wsUrl($ws, 'earnings/business-and-rentals'))->assertOk()->json('data.businesses.0');
        $this->assertSame(['3000.00', '4100.00', '1000.00'], [$view['book']['net'], $view['schedule_c']['net'], $view['schedule_c']['excluded']]);
    }
}
