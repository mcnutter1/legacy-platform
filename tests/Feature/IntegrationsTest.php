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

    /** Mimic Monarch's live server: PascalCase tools, a required "intent" enum, {"result": "<json>"} payloads, offset paging. */
    private bool $monarchLive = false;

    private array $monarchCalls = [];

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
            if ($this->monarchLive) {
                return $this->monarchLiveServer($r);
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

    private function rpc(Request $r, array $tools, callable $call, bool $sse = false, bool $wrapped = false)
    {
        $m = $r->data();
        if (! isset($m['id'])) {
            return Http::response('', 202);
        }
        $result = match ($m['method']) {
            'initialize' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'fake', 'version' => '1'], 'capabilities' => ['tools' => new \stdClass]],
            'tools/list' => ['tools' => $tools],
            // Text content carrying JSON, as most servers return it; FastMCP also wraps it as structuredContent.result.
            'tools/call' => (function () use ($call, $m, $wrapped) {
                $json = json_encode($call($m['params']['name'], $m['params']['arguments'] ?? []));

                return ['content' => [['type' => 'text', 'text' => $json]]] + ($wrapped ? ['structuredContent' => ['result' => $json]] : []);
            })(),
        };
        $msg = json_encode(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $result]);

        return $sse ? Http::response("event: message\ndata: {$msg}\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'sess-1'])
            : Http::response($msg, 200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'sess-2']);
    }

    private function monarchLiveServer(Request $r)
    {
        $intent = ['type' => 'string', 'enum' => ['budgeting', 'spending_analysis', 'investment_tracking', 'net_worth_tracking', 'organizing_finances', 'other']];
        $tools = [
            ['name' => 'GetAccounts', 'inputSchema' => ['type' => 'object', 'required' => ['intent'], 'properties' => ['intent' => $intent, 'accounts' => ['anyOf' => [['type' => 'array'], ['type' => 'null']]]]]],
            ['name' => 'GetInvestments', 'inputSchema' => ['type' => 'object', 'required' => ['intent'], 'properties' => ['intent' => $intent, 'accounts' => ['anyOf' => [['type' => 'array'], ['type' => 'null']]],
                'start_date' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]]]],
            ['name' => 'GetTransactions', 'inputSchema' => ['type' => 'object', 'required' => ['intent', 'start_date', 'end_date'], 'properties' => ['intent' => $intent,
                'start_date' => ['type' => 'string'], 'end_date' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'description' => 'maximum transactions to return (1-100, default 50).'],
                'offset' => ['type' => 'integer']]]],
            ['name' => 'CreateTransaction', 'inputSchema' => ['type' => 'object', 'required' => ['intent'], 'properties' => ['intent' => $intent]]],
        ];
        $exmpl = ['ticker' => 'EXMPL', 'quantity' => 15, 'value' => 750.0, 'price' => 50.0, 'security_type' => 'equity',
            'account_holdings' => [['account_id' => 'a1', 'account_name' => 'Example Brokerage', 'quantity' => 10, 'value' => 500.0], ['account_id' => 'a5', 'account_name' => 'Example 401k', 'quantity' => 5, 'value' => 250.0]]];

        return $this->rpc($r, $tools, function ($tool, $args) use ($exmpl) {
            $this->monarchCalls[] = [$tool, $args];
            if (! in_array($args['intent'] ?? null, ['net_worth_tracking', 'investment_tracking', 'organizing_finances'], true)) {
                return ['error' => 'intent required'];
            }

            return match ($tool) {
                'GetAccounts' => ['accounts' => [
                    ['id' => 'a1', 'name' => 'Example Brokerage', 'type' => 'Brokerage (Taxable)', 'balance' => 30500.0, 'institution' => 'Example Securities'],
                    ['id' => 'a2', 'name' => 'Everyday Checking', 'type' => 'Checking', 'balance' => 2500.1, 'institution' => 'Example Bank'],
                    ['id' => 'a3', 'name' => 'Example Card', 'type' => 'Credit Card', 'balance' => -420.5, 'institution' => 'Example Bank'],
                    ['id' => 'a4', 'name' => 'Example Home', 'type' => 'Primary Home', 'balance' => 650000.0],
                    ['id' => 'a5', 'name' => 'Example 401k', 'type' => '401k', 'balance' => 12000.0],
                ], 'total_balance' => 694580.1, 'finicity_excluded' => ['hidden_account_count' => 1, 'institutions' => ['Example Fidelity']]],
                'GetInvestments' => ['investments' => match ($args['accounts'][0] ?? null) {
                    'Example Brokerage' => [['ticker' => 'VTI', 'quantity' => 100, 'value' => 30000.0, 'price' => 300.0, 'security_type' => 'etf', 'account_holdings' => []], $exmpl],
                    'Example 401k' => [$exmpl, ['ticker' => null, 'quantity' => 11750, 'value' => 11750.0, 'security_type' => 'cash', 'account_holdings' => []]],
                    default => [],
                }],
                'GetTransactions' => ($args['limit'] ?? 0) > 100 ? ['error' => 'limit too large'] : match ((int) ($args['offset'] ?? 0)) {
                    0 => ['transactions' => [['id' => 't-1', 'date' => now()->subDays(3)->toDateString(), 'pending' => false, 'merchant' => 'Example Grocer', 'amount' => -42.5, 'account' => 'Everyday Checking', 'account_id' => 'a2']],
                        'truncated' => true, 'next_offset' => 1],
                    default => ['transactions' => [['id' => 't-2', 'date' => now()->subDays(1)->toDateString(), 'pending' => false, 'merchant' => 'Example Employer', 'amount' => 3100.0, 'account_id' => 'a2']], 'truncated' => false],
                },
                default => [],
            };
        }, wrapped: true);
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

    public function test_hidden_and_ignored_accounts_are_left_out_and_duplicates_can_be_found_dismissed_and_merged(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->snowballConnection($ws, $u, $joint->id);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $synced = DB::table('accounts')->where('connection_id', $id)->value('id');
        $nw = fn () => (float) $this->signIn($u)->getJson($this->wsUrl($ws, 'net-worth'))->assertOk()->json('data.net_worth');
        $this->assertGreaterThan(0, $nw());

        // Hide: still synced, not counted, not listed by default.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$synced}/visibility"), ['visibility' => 'hidden', 'reason' => 'aggregate'])->assertOk();
        $this->assertEquals(0, $nw());
        $this->assertCount(0, $this->signIn($u)->getJson($this->wsUrl($ws, 'r/accounts?filter[visibility]=visible'))->json('data'));
        $this->snowball['portfolios']['portfolios'][0]['value'] = 101000;
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(1, DB::table('balance_snapshots')->where('account_id', $synced)->where('balance', 101000)->count(), 'hidden accounts keep syncing');

        // Ignore: sync no longer writes to it.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$synced}/visibility"), ['visibility' => 'ignored', 'reason' => 'not_ours'])->assertOk();
        $this->snowball['portfolios']['portfolios'][0]['value'] = 999;
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$id}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(0, DB::table('balance_snapshots')->where('account_id', $synced)->where('balance', 999)->count());
        $this->assertSame(1, DB::table('accounts')->count(), 'an ignored account is not re-created');
        $this->assertSame(1, json_decode(DB::table('source_connections')->where('id', $id)->value('last_sync_stats'), true)['ignored']);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$synced}/visibility"), ['visibility' => 'visible'])->assertOk();
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$synced}/visibility"), ['visibility' => 'bogus'])->assertStatus(422);

        // A manually entered copy is suggested as a duplicate; dismiss, then merge.
        $manual = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/accounts'), ['scope_id' => $joint->id, 'name' => 'Example Brokerage', 'institution' => 'Example Securities', 'kind' => 'brokerage'])
            ->assertCreated()->json('data.id');
        $pairs = $this->signIn($u)->getJson($this->wsUrl($ws, 'account-duplicates'))->assertOk()->json('data');
        $this->assertCount(1, $pairs);
        $this->assertSame([false, ['institution', 'name', 'type']], [$pairs[0]['same_source'], $pairs[0]['reasons']]);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$manual}/not-duplicate"), ['other_account_id' => $synced])->assertOk();
        $this->assertCount(0, $this->signIn($u)->getJson($this->wsUrl($ws, 'account-duplicates'))->json('data'));
        $this->assertCount(0, $this->signIn($u)->getJson($this->wsUrl($ws, "account-duplicates?account_id={$manual}"))->json('data'));
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$manual}/merge"), ['into_account_id' => $synced])->assertOk();
        $this->assertNotNull(DB::table('accounts')->where('id', $manual)->value('archived_at'));
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

    public function test_monarch_live_tool_shapes_intent_wrapped_results_per_account_holdings_and_paging(): void
    {
        $this->monarchLive = true;
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $mon = $this->monarchConnection($ws, $u, $joint->id);
        $this->assertSame(['accounts' => 'GetAccounts', 'holdings' => 'GetInvestments', 'transactions' => 'GetTransactions'],
            json_decode(DB::table('source_connections')->where('id', $mon)->value('tool_map'), true));
        $this->work();
        $byName = DB::table('accounts')->get()->keyBy('name');
        $this->assertEqualsCanonicalizing(['Example Brokerage', 'Everyday Checking', 'Example Card', 'Example 401k'], $byName->keys()->all(), 'home skipped');
        $this->assertSame(['credit_card', 1], [$byName['Example Card']->kind, (int) $byName['Example Card']->is_liability]);
        $this->assertSame('401k', $byName['Example 401k']->kind);
        $qty = fn ($acct, $sym) => (float) DB::table('holdings')->where('account_id', $byName[$acct]->id)
            ->where('instrument_id', DB::table('instruments')->where('symbol', $sym)->value('id'))->value('quantity');
        $this->assertEquals([100, 10, 5, 11750], [$qty('Example Brokerage', 'VTI'), $qty('Example Brokerage', 'EXMPL'), $qty('Example 401k', 'EXMPL'), $qty('Example 401k', 'CASH')]);
        $this->assertSame(2, (int) DB::table('import_batches')->where('connection_id', $mon)->value('row_count'), 'both transaction pages staged');
        foreach ($this->monarchCalls as [$tool, $args]) {
            $this->assertArrayHasKey('intent', $args, $tool);
        }
        $this->assertSame(['investment_tracking'], array_values(array_unique(array_map(fn ($c) => $c[1]['intent'], array_filter($this->monarchCalls, fn ($c) => $c[0] === 'GetInvestments')))));
        $this->assertSame([100], array_values(array_unique(array_map(fn ($c) => $c[1]['limit'], array_filter($this->monarchCalls, fn ($c) => $c[0] === 'GetTransactions')))));
        $this->assertNotContains('CreateTransaction', array_column($this->monarchCalls, 0), 'write tools are never called');
        $issues = implode(' ', json_decode(DB::table('source_connections')->where('id', $mon)->value('last_preview'), true)['issues']);
        $this->assertStringContainsString('withholds 1 account(s)', $issues);
        $this->assertStringContainsString('homes and vehicles', $issues);
    }

    public function test_linked_sources_each_supply_the_detail_the_other_lacks_and_roles_can_be_chosen(): void
    {
        $this->monarch['holdings']['holdings'] = [];
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $mon = $this->monarchConnection($ws, $u, $joint->id);
        $this->work();
        $snow = $this->snowballConnection($ws, $u, $joint->id);
        $this->work();
        $brokerage = DB::table('accounts')->where('mask', '1234')->first();
        $this->assertSame(2, DB::table('accounts')->count(), 'Snowball linked to the Monarch account');
        $this->assertSame($mon, $brokerage->connection_id);

        $src = $this->signIn($u)->getJson($this->wsUrl($ws, "accounts/{$brokerage->id}/sources"))->assertOk()->json('data');
        $this->assertSame(['balance' => $mon, 'holdings' => $snow, 'transactions' => $mon], $src['roles'], 'Snowball fills in the positions Monarch lacks');
        $this->assertSame(2, DB::table('holdings')->where('account_id', $brokerage->id)->whereNull('archived_at')->where('source_ref', $snow)->count());
        $this->assertSame(0, DB::table('balance_snapshots')->where('account_id', $brokerage->id)->where('source_ref', $snow)->count(), 'balance stays with the primary');
        [$value, $method] = app(\App\Services\NetWorthService::class)->accountValue($brokerage->id, now()->toDateString());
        $this->assertSame(['100000', 'balance snapshot'], [rtrim(rtrim($value, '0'), '.'), $method], 'same-day positions from another source do not override the reported balance');

        // Choosing Monarch for holdings stops Snowball writing them; returning to automatic restores it.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$brokerage->id}/source-role"), ['role' => 'holdings', 'connection_id' => $mon])->assertOk()
            ->assertJsonPath('data.roles.holdings', $mon);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$brokerage->id}/source-role"), ['role' => 'holdings', 'connection_id' => (string) \Illuminate\Support\Str::ulid()])->assertStatus(422);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "integrations/{$snow}/sync"))->assertStatus(202);
        $this->work();
        $this->assertSame(0, json_decode(DB::table('source_connections')->where('id', $snow)->value('last_sync_stats'), true)['holdings']);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "accounts/{$brokerage->id}/source-role"), ['role' => 'holdings', 'connection_id' => null])->assertOk()
            ->assertJsonPath('data.roles.holdings', $snow);
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
