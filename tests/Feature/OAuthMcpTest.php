<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** OAuth 2.1 (DCR + PKCE S256 + rotating refresh) and the MCP endpoint with scope-trimmed access. */
class OAuthMcpTest extends TestCase
{
    use RefreshDatabase;

    private string $redirect = 'http://localhost/callback';

    private function pkce(): array
    {
        $verifier = Str::random(64);

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** @return array{0: array, 1: string, 2: \App\Models\Workspace, 3: \App\Models\Scope} token response, client id, workspace, joint scope */
    private function connect(array $scopes): array
    {
        $u = $this->makeUser('owner', 'Owner');
        [$ws, $m, $joint] = $this->makeWorkspace($u);
        $client = $this->postJson('/oauth/register', ['client_name' => 'Test client', 'redirect_uris' => [$this->redirect]])->assertCreated()->json('client_id');
        [$verifier, $challenge] = $this->pkce();
        $params = ['client_id' => $client, 'redirect_uri' => $this->redirect, 'response_type' => 'code', 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256', 'state' => 'xyz', 'scope' => implode(' ', $scopes), 'resource' => url('/mcp')];
        $this->signIn($u)->get('/oauth/authorize?'.http_build_query($params))->assertOk()->assertSee('Test client');
        $res = $this->signIn($u)->post('/oauth/authorize', $params + ['decision' => 'allow', 'membership_id' => $m->id, 'scopes' => $scopes]);
        $res->assertRedirect();
        parse_str((string) parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('xyz', $q['state']);
        $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client,
            'redirect_uri' => $this->redirect, 'code_verifier' => $verifier])->assertOk()->json();
        // Codes are single use.
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client,
            'redirect_uri' => $this->redirect, 'code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        return [$tokens, $client, $ws, $joint];
    }

    private function rpc(string $token, string $method, array $params = [], int $id = 1)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    public function test_discovery_metadata(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('resource', url('/mcp'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256']);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertStatus(401)->assertHeader('WWW-Authenticate');
    }

    public function test_pkce_is_enforced(): void
    {
        $u = $this->makeUser('owner');
        [, $m] = $this->makeWorkspace($u);
        $client = $this->postJson('/oauth/register', ['redirect_uris' => [$this->redirect]])->json('client_id');
        [, $challenge] = $this->pkce();
        $params = ['client_id' => $client, 'redirect_uri' => $this->redirect, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'wealth.read'];
        $res = $this->signIn($u)->post('/oauth/authorize', $params + ['decision' => 'allow', 'membership_id' => $m->id, 'scopes' => ['wealth.read']]);
        parse_str((string) parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => $this->redirect,
            'code_verifier' => Str::random(64)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // Plain PKCE is refused at authorization.
        $this->signIn($u)->get('/oauth/authorize?'.http_build_query(['code_challenge_method' => 'plain'] + $params))->assertStatus(400);
    }

    public function test_redirects_must_be_https_on_allowed_hosts_outside_local(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/cb']])->assertStatus(400);
    }

    public function test_consent_errors_rerender_instead_of_redirecting_away(): void
    {
        $u = $this->makeUser('owner');
        [, $m] = $this->makeWorkspace($u);
        $u->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_enabled_at' => now()])->save();
        $client = $this->postJson('/oauth/register', ['redirect_uris' => [$this->redirect]])->json('client_id');
        [, $challenge] = $this->pkce();
        $params = ['client_id' => $client, 'redirect_uri' => $this->redirect, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'wealth.read'];
        $this->signIn($u, stepUp: false)->post('/oauth/authorize', $params + ['decision' => 'allow', 'membership_id' => $m->id, 'scopes' => ['wealth.read'], 'totp' => '000000'])
            ->assertStatus(422)->assertSee('did not match')->assertSee('Allow');
    }

    public function test_claude_registration_with_basic_client_auth(): void
    {
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $this->postJson('/oauth/register', ['redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'private_key_jwt'])->assertStatus(400)
            ->assertJsonPath('error', 'invalid_client_metadata');
        $reg = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'client_secret_basic',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']])->assertCreated()->json();
        $this->assertSame('client_secret_basic', $reg['token_endpoint_auth_method']);
        $u = $this->makeUser('owner');
        [, $m] = $this->makeWorkspace($u);
        [$verifier, $challenge] = $this->pkce();
        $params = ['client_id' => $reg['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            'scope' => 'wealth.read', 'resource' => url('/mcp')];
        $page = $this->signIn($u)->get('/oauth/authorize?'.http_build_query($params))->assertOk();
        // Without the client origin in form-action, browsers silently block the redirect after "Allow".
        $this->assertStringContainsString("form-action 'self'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://claude.ai', (string) $page->headers->get('Content-Security-Policy'));
        $res = $this->signIn($u)->post('/oauth/authorize', $params + ['decision' => 'allow', 'membership_id' => $m->id, 'scopes' => ['wealth.read']]);
        parse_str((string) parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $body = ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => $redirect, 'code_verifier' => $verifier];
        $basic = fn (string $secret) => ['Authorization' => 'Basic '.base64_encode(urlencode($reg['client_id']).':'.urlencode($secret))];
        $this->withHeaders($basic('wrong'))->postJson('/oauth/token', $body)->assertStatus(401);
        $this->withHeaders($basic($reg['client_secret']))->postJson('/oauth/token', $body)->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_mcp_tools_are_trimmed_to_granted_scopes_and_return_data(): void
    {
        [$tokens, , $ws, $joint] = $this->connect(['wealth.read']);
        DB::table('accounts')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws->id, 'scope_id' => $joint->id, 'name' => 'Checking', 'kind' => 'checking',
            'tax_bucket' => 'taxable', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);

        $this->rpc($tokens['access_token'], 'initialize', ['protocolVersion' => config('legacy.mcp.protocol_versions')[0]])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'mcnutt-legacy');
        $names = collect($this->rpc($tokens['access_token'], 'tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('get_net_worth', $names);
        $this->assertNotContains('get_tax_estimate', $names);
        $this->assertNotContains('propose_record_change', $names);

        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'list_accounts', 'arguments' => []])->assertOk()->assertJsonPath('result.isError', false);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'get_tax_estimate', 'arguments' => ['tax_year' => 2025]])->assertJsonPath('error.code', -32602);
    }

    public function test_every_tool_schema_is_a_json_object_schema(): void
    {
        [$tokens] = $this->connect(array_keys(\App\Services\Mcp\OAuthService::SCOPES));
        $raw = $this->rpc($tokens['access_token'], 'tools/list')->assertOk()->getContent();
        // Strict clients (Claude) discard the whole list if any inputSchema.properties is an array.
        foreach (json_decode($raw)->result->tools as $t) {
            $this->assertSame('object', $t->inputSchema->type, $t->name);
            $this->assertIsObject($t->inputSchema->properties, $t->name);
        }
        $this->assertStringNotContainsString('"properties":[]', $raw);
    }

    public function test_refresh_tokens_rotate_and_reuse_revokes_the_family(): void
    {
        [$tokens, $client] = $this->connect(['wealth.read']);
        $next = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);

        // Replaying the old refresh token is treated as theft: the whole family is revoked.
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertStatus(400);
        $this->rpc($next['access_token'], 'tools/list')->assertStatus(401);
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $next['refresh_token'], 'client_id' => $client])->assertStatus(400);
    }

    public function test_suspending_the_member_cuts_off_mcp_access(): void
    {
        [$tokens, , $ws] = $this->connect(['wealth.read']);
        DB::table('memberships')->where('workspace_id', $ws->id)->update(['status' => 'suspended']);
        $this->rpc($tokens['access_token'], 'tools/list')->assertStatus(401);
    }

    public function test_cross_origin_browser_requests_are_refused(): void
    {
        [$tokens] = $this->connect(['wealth.read']);
        $this->withHeaders(['Authorization' => 'Bearer '.$tokens['access_token'], 'Origin' => 'https://evil.example'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(403);
    }
}
