<?php

namespace Tests\Feature;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use App\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Monarch (OAuth) and Snowball (key) MCP sources: authorize, test, preview, approve, sync, de-dupe, conflicts, provenance. */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private array $snowball = [];

    private array $monarch = [];

    private bool $monarchWebApproved = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legacy.features.connector_snowball_api' => true, 'legacy.features.connector_monarch_api' => true, 'app.url' => 'https://legacy.example.test']);
        $this->snowball = [
            'portfolios' => ['portfolios' => [
                ['id' => 'p-1', 'name' => 'Example Brokerage', 'broker' => 'Example Securities', 'currency' => 'USD', 'value' => 100050],
                ['id' => 'p-all', 'name' => 'Combined', 'is_combined' => true, 'value' => 100050],
            ]],
            'holdings' => ['p-1' => ['holdings' => [
                ['symbol' => 'EXMPL', 'name' => 'Example Corp', 'quantity' => 12, 'price' => 50, 'marketValue' => 600, 'costBasis' => 400],
                ['symbol' => 'VTI', 'name' => 'Total Market ETF', 'type' => 'ETF', 'quantity' => 100, 'price' => 300, 'marketValue' => 30000],
            ]]],
        ];
        $this->monarch = [
            'accounts' => ['accounts' => [
                ['id' => 'm-1', 'displayName' => 'Example Brokerage', 'institution' => ['name' => 'Example Securities'], 'mask' => '1234',
                    'type' => ['name' => 'brokerage'], 'subtype' => ['name' => 'brokerage'], 'currentBalance' => 100000.0, 'isAsset' => true],
                ['id' => 'm-2', 'displayName' => 'Everyday Checking', 'institution' => ['name' => 'Example Bank'], 'mask' => '9876',
                    'type' => ['name' => 'depository'], 'subtype' => ['name' => 'checking'], 'currentBalance' => '2500.10', 'isAsset' => true],
            ]],
            'holdings' => ['holdings' => [
                ['account' => ['id' => 'm-1'], 'security' => ['ticker' => 'EXMPL', 'name' => 'Example Corp', 'type' => 'equity'], 'quantity' => 10, 'totalValue' => 500, 'basis' => 400],
                ['account' => ['id' => 'm-1'], 'security' => ['ticker' => 'VTI', 'name' => 'Total Market ETF', 'type' => 'etf'], 'quantity' => 100, 'totalValue' => 30000],
            ]],
            'transactions' => ['transactions' => [
                ['id' => 't-1', 'date' => now()->subDays(2)->toDateString(), 'amount' => -42.5, 'merchant' => ['name' => 'Example Grocer'], 'category' => ['name' => 'Groceries'], 'account' => ['id' => 'm-2']],
                ['id' => 't-2', 'date' => now()->subDays(1)->toDateString(), 'amount' => 3100, 'merchant' => ['name' => 'Example Employer'], 'category' => ['name' => 'Paychecks'], 'account' => ['id' => 'm-2']],
                ['id' => 't-3', 'date' => now()->toDateString(), 'amount' => -9.99, 'pending' => true, 'account' => ['id' => 'm-2']],
            ]],
        ];
        Http::fake(fn (Request $r) => $this->fakeServer($r));
    }

    private function fakeServer(Request $r)
    {
        $url = $r->url();
        if (str_starts_with($url, 'https://api.monarch.com/.well-known/oauth-protected-resource')) {
            return Http::response(['resource' => 'https://api.monarch.com/mcp', 'authorization_servers' => ['https://api.monarch.com']]);
        }
        if ($url === 'https://api.monarch.com/.well-known/oauth-authorization-server') {
            return Http::response(['issuer' => 'https://api.monarch.com', 'authorization_endpoint' => 'https://api.monarch.com/oauth/authorize/',
                'token_endpoint' => 'https://api.monarch.com/oauth/token/', 'registration_endpoint' => 'https://api.monarch.com/oauth/register',
                'revocation_endpoint' => 'https://api.monarch.com/oauth/revoke/', 'code_challenge_methods_supported' => ['S256']]);
        }
        if ($url === 'https://api.monarch.com/oauth/register') {
            if (! $this->monarchWebApproved && ! str_starts_with($r->data()['redirect_uris'][0] ?? '', 'http://127.0.0.1')) {
                return Http::response(['error' => 'invalid_redirect_uri', 'error_description' => "Redirect URI is not supported. This redirect URL isn't on Monarch's approved list yet."], 400);
            }

            return Http::response(['client_id' => 'client-123'], 201);
        }
        if ($url === 'https://api.monarch.com/oauth/token/') {
            $d = $r->data();
            if (($d['grant_type'] ?? '') === 'authorization_code' && ($d['code'] ?? '') === 'good-code' && strlen($d['code_verifier'] ?? '') === 64) {
                return Http::response(['access_token' => 'mon-access', 'refresh_token' => 'mon-refresh', 'expires_in' => 3600, 'scope' => 'mcp:read', 'token_type' => 'Bearer']);
            }

            return Http::response(['error' => 'invalid_grant'], 400);
        }
        if ($url === 'https://api.monarch.com/oauth/revoke/') {
            return Http::response([], 200);
        }
        if ($url === 'https://api.monarch.com/mcp') {
            if (($r->header('Authorization')[0] ?? '') !== 'Bearer mon-access') {
                return Http::response(['error' => 'invalid_token'], 401);
            }

            return $this->rpc($r, [
                ['name' => 'get_accounts', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'get_account_holdings', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass]],
                ['name' => 'get_transactions', 'inputSchema' => ['type' => 'object', 'properties' => ['start_date' => ['type' => 'string'], 'end_date' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'maximum' => 100]]]],
                ['name' => 'get_budgets', 'inputSchema' => ['type' => 'object']],
            ], fn ($tool, $args) => match ($tool) {
                'get_accounts' => $this->monarch['accounts'], 'get_account_holdings' => $this->monarch['holdings'], 'get_transactions' => $this->monarch['transactions'], default => [],
            });
        }
        if ($url === 'https://snowball-analytics.com/mcp-server') {
            if (($r->header('X-API-Key')[0] ?? '') !== 'u_example_key_123') {
                return Http::response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'A valid Snowball MCP API key is required.']], 401);
            }

            return $this->rpc($r, [
                ['name' => 'list_portfolios', 'inputSchema' => ['type' => 'object']],
                ['name' => 'get_holdings', 'inputSchema' => ['type' => 'object', 'properties' => ['portfolio_id' => ['type' => 'string']], 'required' => ['portfolio_id']]],
                ['name' => 'get_company_profile', 'inputSchema' => ['type' => 'object', 'properties' => ['symbol' => ['type' => 'string']], 'required' => ['symbol']]],
            ], fn ($tool, $args) => match ($tool) {
                'list_portfolios' => $this->snowball['portfolios'], 'get_holdings' => $this->snowball['holdings'][$args['portfolio_id']] ?? ['holdings' => []], default => [],
            }, sse: true);
        }

        return Http::response('unexpected '.$url, 500);
    }

    private function rpc(Request $r, array $tools, callable $call, bool $sse = false)
    {
        $m = $r->data();
        if (! isset($m['id'])) {
            return Http::response('', 202);
        }
        $result = match ($m['method']) {
            'initialize' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'fake', 'version' => '1'], 'capabilities' => ['tools' => new \stdClass]],
            'tools/list' => ['tools' => $tools],
            // Text content carrying JSON, as most servers return it.
            'tools/call' => ['content' => [['type' => 'text', 'text' => json_encode($call($m['params']['name'], $m['params']['arguments'] ?? []))]]],
        };
        $msg = json_encode(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $result]);

        return $sse ? Http::response("event: message\ndata: {$msg}\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'sess-1'])
            : Http::response($msg, 200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'sess-2']);
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

    private function snowballConnection($ws, $u, string $scopeId): string
    {
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'label' => 'Snowball (Example)', 'scope_id' => $scopeId])
            ->assertCreated()->json('data.id');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/key"), ['key' => 'u_example_key_123'])->assertOk();
        $t = $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/test"))->assertOk()->json('data');
        $this->assertSame(['accounts' => 'list_portfolios', 'holdings' => 'get_holdings', 'transactions' => null], $t['tool_map']);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/preview"))->assertStatus(202);
        $this->work();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/approve"))->assertOk();

        return $id;
    }

    private function monarchConnection($ws, $u, string $scopeId): string
    {
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'monarch', 'scope_id' => $scopeId])->assertCreated()->json('data.id');
        $url = $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/oauth/start"))->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['client-123', 'mcp:read', 'S256', 'https://legacy.example.test/integrations/oauth/callback', 'https://api.monarch.com/mcp'],
            [$q['client_id'], $q['scope'], $q['code_challenge_method'], $q['redirect_uri'], $q['resource']]);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/oauth/complete"), ['code' => 'good-code', 'state' => $q['state']])->assertOk()
            ->assertJsonPath('data.authorized', true);
        // The state is single use.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/oauth/complete"), ['code' => 'good-code', 'state' => $q['state']])->assertStatus(400);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/test"))->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/preview"))->assertStatus(202);
        $this->work();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/approve"))->assertOk();

        return $id;
    }

    public function test_snowball_key_preview_approve_sync_and_provenance(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'scope_id' => $joint->id])->json('data.id');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(409);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/key"), ['key' => 'u_wrong_key_000'])->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/test"))->assertStatus(502)->assertJsonPath('error.code', 'integration_unauthorized');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/approve"))->assertStatus(409);

        $id = $this->snowballConnection($ws, $u, $joint->id);
        $c = $this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$id}"))->assertOk()->json('data');
        $this->assertSame(1, $c['last_preview']['totals']['accounts'], 'combined portfolio skipped');
        $this->assertSame('u_exampl…', $c['credential_hint']);
        $this->assertStringNotContainsString('u_example_key_123', json_encode($c));
        $this->assertSame(0, DB::table('accounts')->count(), 'preview writes nothing');

        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $acct = DB::table('accounts')->first();
        $this->assertSame(['Example Brokerage', 'brokerage', $id], [$acct->name, $acct->kind, $acct->connection_id]);
        $this->assertEquals(100050, (float) DB::table('balance_snapshots')->where('account_id', $acct->id)->value('balance'));
        $this->assertSame(2, DB::table('holdings')->where('account_id', $acct->id)->count());
        $this->assertSame('etf', DB::table('instruments')->where('symbol', 'VTI')->value('kind'));

        $list = $this->signIn($u)->getJson($this->wsUrl($ws, 'r/accounts'))->json('data');
        $this->assertSame([['snowball', 'Snowball (Example)', 'mcp', true]], array_map(fn ($s) => [$s['provider'], $s['label'], $s['mode'], $s['authoritative']], $list[0]['_sources']));
        $h = $this->signIn($u)->getJson($this->wsUrl($ws, 'r/holdings'))->json('data');
        $this->assertSame('snowball', $h[0]['_sources'][0]['provider']);

        // Re-sync is a no-op for structure.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(1, DB::table('accounts')->count());
        $this->assertSame(2, DB::table('holdings')->count());
        $this->assertSame(1, DB::table('balance_snapshots')->count());
    }

    public function test_monarch_oauth_transactions_and_overlap_with_snowball_is_deduped_and_conflicts_flagged(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $mon = $this->monarchConnection($ws, $u, $joint->id);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$mon}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(2, DB::table('accounts')->count());
        $batch = DB::table('import_batches')->where('connection_id', $mon)->first();
        $this->assertSame(['previewed', 2], [$batch->status, (int) $batch->row_count], 'pending transaction skipped, rows await review');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch->id}/commit"))->assertOk();
        $this->assertSame('monarch', DB::table('transactions')->value('source'));
        $this->assertSame('monarch:t-1', DB::table('transactions')->orderBy('posted_on')->value('external_id'));

        // The same brokerage account through Snowball links to the Monarch account instead of duplicating it.
        $snow = $this->snowballConnection($ws, $u, $joint->id);
        $preview = $this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$snow}"))->json('data.last_preview.accounts.0');
        $this->assertSame('link', $preview['action']);
        $this->assertFalse($preview['authoritative']);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$snow}/sync"))->assertStatus(202);
        $this->work();
        $brokerage = DB::table('accounts')->where('mask', '1234')->first();
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame($mon, $brokerage->connection_id, 'first source stays authoritative');
        $this->assertEquals(10, (float) DB::table('holdings')->where('account_id', $brokerage->id)->where('instrument_id', DB::table('instruments')->where('symbol', 'EXMPL')->value('id'))->value('quantity'));

        $src = $this->signIn($u)->getJson($this->wsUrl($ws, "accounts/{$brokerage->id}/sources"))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['monarch', 'snowball'], array_column($src['sources'], 'provider'));
        $conflicts = collect($src['conflicts']);
        $this->assertSame(['holding_quantity', 'EXMPL'], [$conflicts[0]['kind'], $conflicts[0]['field']]);
        $this->assertFalse($conflicts->contains('kind', 'balance'), '$50 on $100k is within tolerance');
        $badges = $this->signIn($u)->getJson($this->wsUrl($ws, "r/accounts/{$brokerage->id}"))->json('data._sources');
        $this->assertSame([['monarch', true], ['snowball', false]], collect($badges)->map(fn ($b) => [$b['provider'], $b['authoritative']])->sortBy(0)->values()->all());

        // A real disagreement on balance is flagged; preferring Snowball makes it authoritative.
        $this->snowball['portfolios']['portfolios'][0]['value'] = 120000;
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$snow}/sync"))->assertStatus(202);
        $this->work();
        $bal = DB::table('source_discrepancies')->where('entity_id', $brokerage->id)->where('kind', 'balance')->first();
        $this->assertSame('open', $bal->status);
        $this->assertEquals(20000, (float) $bal->difference);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "source-conflicts/{$bal->id}/resolve"), ['action' => 'resolve', 'prefer_connection_id' => $snow])->assertOk();
        $this->assertSame($snow, DB::table('accounts')->where('id', $brokerage->id)->value('connection_id'));
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$snow}/sync"))->assertStatus(202);
        $this->work();
        $this->assertEquals(12, (float) DB::table('holdings')->where('account_id', $brokerage->id)->where('instrument_id', DB::table('instruments')->where('symbol', 'EXMPL')->value('id'))->value('quantity'));
    }

    public function test_ambiguous_match_is_flagged_and_can_be_merged(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $manual = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/accounts'), ['scope_id' => $joint->id, 'name' => 'Household account', 'institution' => 'Example Bank',
            'kind' => 'checking'])->assertCreated()->json('data.id');
        $mon = $this->monarchConnection($ws, $u, $joint->id);
        $plan = collect($this->signIn($u)->getJson($this->wsUrl($ws, "integrations/{$mon}"))->json('data.last_preview.accounts'))->keyBy('external_id');
        $this->assertSame(['possible_duplicate', $manual], [$plan['m-2']['action'], $plan['m-2']['candidate']['id']]);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$mon}/sync"))->assertStatus(202);
        $this->work();
        $dup = DB::table('source_discrepancies')->where('kind', 'possible_duplicate')->first();
        $this->assertSame($manual, $dup->candidate_id);

        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$dup->entity_id}/merge"), ['into_account_id' => $manual])->assertOk();
        $this->assertNotNull(DB::table('accounts')->where('id', $dup->entity_id)->value('archived_at'));
        $this->assertSame($manual, DB::table('external_links')->where('external_id', 'm-2')->value('entity_id'));
        $this->assertSame($mon, DB::table('accounts')->where('id', $manual)->value('connection_id'));
        $this->assertSame('resolved', DB::table('source_discrepancies')->where('id', $dup->id)->value('status'));
        $this->assertSame(1, DB::table('balance_snapshots')->where('account_id', $manual)->count());

        // Next sync updates the merged account rather than recreating the duplicate.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$mon}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(2, DB::table('accounts')->whereNull('archived_at')->count());
    }

    public function test_monarch_falls_back_to_loopback_redirect_when_web_callback_is_not_approved(): void
    {
        $this->monarchWebApproved = false;
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'monarch', 'scope_id' => $joint->id])->json('data.id');
        $start = $this->send('POST', $this->wsUrl($ws, "integrations/{$id}/oauth/start"))->assertOk()->json('data');
        $this->assertSame(['loopback', 'http://127.0.0.1:33418/callback'], [$start['mode'], $start['redirect_uri']]);
        $this->assertStringContainsString('approved list', (string) $start['notice']);
        parse_str((string) parse_url($start['authorize_url'], PHP_URL_QUERY), $q);
        $this->assertSame('http://127.0.0.1:33418/callback', $q['redirect_uri']);
        $this->send('POST', $this->wsUrl($ws, "integrations/{$id}/oauth/complete"), ['code' => 'good-code', 'state' => $q['state']])->assertOk()->assertJsonPath('data.authorized', true);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.monarch.com/oauth/token/' && ($r->data()['redirect_uri'] ?? null) === 'http://127.0.0.1:33418/callback');
    }

    public function test_guards_hosts_step_up_and_access(): void
    {
        $u = $this->makeUser('owner');
        $kid = $this->makeUser('kid');
        [$ws, $om, $joint] = $this->makeWorkspace($u);
        $km = $this->addMember($ws, $kid);
        $id = $this->signIn($u, stepUp: false)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'scope_id' => $joint->id])->assertCreated()->json('data.id');
        $this->send('POST', $this->wsUrl($ws, "integrations/{$id}/key"), ['key' => 'u_example_key_123'])->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');
        $this->assertNull(DB::table('source_connections')->where('id', $id)->value('credential_encrypted'));
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'scope_id' => $joint->id, 'server_url' => 'https://evil.example/mcp'])->assertStatus(422);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'scope_id' => $joint->id, 'server_url' => 'https://snowball-analytics.com/mcp-server/k/secret'])->assertStatus(422);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'other', 'scope_id' => $joint->id])->assertStatus(422);

        // Members without ledger access cannot see or use connections.
        $this->signIn($kid)->getJson($this->wsUrl($ws, "integrations/{$id}"))->assertNotFound();
        $this->assertSame([], $this->signIn($kid)->getJson($this->wsUrl($ws, 'integrations'))->json('data.connections'));
        AccessGrant::create(['workspace_id' => $ws->id, 'membership_id' => $km->id, 'scope_id' => $joint->id, 'domains' => ['ledger'], 'actions' => ['read'], 'granted_by' => $om->id]);
        $this->signIn($kid)->getJson($this->wsUrl($ws, "integrations/{$id}"))->assertOk();
        $this->signIn($kid)->send('POST', $this->wsUrl($ws, "integrations/{$id}/test"))->assertNotFound();

        config(['legacy.features.connector_snowball_api' => false]);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'integrations'), ['provider' => 'snowball', 'scope_id' => $joint->id])->assertStatus(503);
    }
}
