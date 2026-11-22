<?php

namespace App\Services\Mcp;

use App\Models\Membership;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 authorization server facade for MCP clients: dynamic registration, authorization code + PKCE (S256),
 * exact redirect matching, resource (audience) binding, short-lived access tokens and rotating refresh tokens
 * with reuse detection. McNutt Cloud SSO only authenticates the consent session; SSO tokens are never forwarded.
 */
final class OAuthService
{
    public const SCOPES = [
        'wealth.read' => ['domains' => ['ledger', 'portfolio', 'debt', 'real_estate', 'assets', 'insurance'], 'actions' => ['read'], 'label' => 'Net worth, accounts and balances'],
        'investments.read' => ['domains' => ['portfolio', 'ledger'], 'actions' => ['read'], 'label' => 'Investment positions and allocation'],
        'plans.read' => ['domains' => ['planning'], 'actions' => ['read'], 'label' => 'Plans and scenario results'],
        'plans.run' => ['domains' => ['planning'], 'actions' => ['read'], 'label' => 'Run saved plan scenarios'],
        'tax.read' => ['domains' => ['tax'], 'actions' => ['read'], 'label' => 'Tax estimates and run status'],
        'docs.search' => ['domains' => ['vault'], 'actions' => ['read'], 'label' => 'Search document titles and reviewed fields'],
        'payroll.intake.write' => ['domains' => ['compensation', 'vault'], 'actions' => ['read', 'write'], 'label' => 'Submit paystubs for your review'],
        'records.propose' => ['domains' => ['household', 'ledger', 'portfolio', 'compensation', 'real_estate', 'assets', 'debt', 'tax', 'planning', 'insurance', 'governance'], 'actions' => ['read', 'write'], 'label' => 'Propose changes for your approval'],
        'alerts.read' => ['domains' => ['governance', 'tax', 'insurance'], 'actions' => ['read'], 'label' => 'Reminders and due dates'],
    ];

    public function resource(): string
    {
        return url('/mcp');
    }

    public function register(array $data): array
    {
        if (! config('legacy.mcp.dynamic_registration')) {
            throw new ApiError('registration_disabled', 'Dynamic client registration is disabled.', 403);
        }
        $uris = array_values(array_filter((array) ($data['redirect_uris'] ?? []), 'is_string'));
        if (! $uris || count($uris) > 5) {
            throw new ApiError('invalid_redirect_uri', 'Provide 1-5 redirect URIs.', 400);
        }
        foreach ($uris as $u) {
            $this->assertRedirectAllowed($u);
        }
        $method = $data['token_endpoint_auth_method'] ?? 'none';
        $clientId = 'mcp_'.Str::random(24);
        $secret = $method === 'client_secret_post' ? Str::random(48) : null;
        DB::table('oauth_clients')->insert(['id' => (string) Str::ulid(), 'client_id' => $clientId, 'name' => mb_substr((string) ($data['client_name'] ?? 'MCP client'), 0, 100),
            'redirect_uris' => json_encode($uris), 'client_secret_hash' => $secret ? hash('sha256', $secret) : null, 'registration' => 'dynamic',
            'created_at' => now(), 'updated_at' => now()]);
        Audit::log('oauth.client_registered', 'oauth_client', $clientId, ['name' => $data['client_name'] ?? null]);

        return array_filter(['client_id' => $clientId, 'client_secret' => $secret, 'client_id_issued_at' => time(), 'redirect_uris' => $uris,
            'token_endpoint_auth_method' => $method, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'client_name' => $data['client_name'] ?? null], fn ($v) => $v !== null);
    }

    public function assertRedirectAllowed(string $uri): void
    {
        $p = parse_url($uri);
        $host = strtolower($p['host'] ?? '');
        $local = app()->environment('local', 'testing') && in_array($host, ['localhost', '127.0.0.1'], true);
        if (! $local && (($p['scheme'] ?? '') !== 'https' || isset($p['fragment']))) {
            throw new ApiError('invalid_redirect_uri', 'Redirect URIs must use https and have no fragment.', 400);
        }
        $allowed = config('legacy.mcp.allowed_redirect_hosts', []);
        if (! $local && ! in_array($host, $allowed, true)) {
            throw new ApiError('invalid_redirect_uri', 'Redirect host is not allowed for this deployment.', 400);
        }
    }

    public function client(string $clientId): object
    {
        $c = DB::table('oauth_clients')->where('client_id', $clientId)->whereNull('disabled_at')->first();
        if (! $c) {
            throw new ApiError('invalid_client', 'Unknown client.', 401);
        }

        return $c;
    }

    /** @return list<string> */
    public function parseScopes(?string $scope): array
    {
        $req = array_values(array_unique(array_filter(explode(' ', (string) $scope))));
        $valid = array_values(array_intersect($req, array_keys(self::SCOPES)));

        return $valid ?: ['wealth.read', 'alerts.read'];
    }

    public function issueCode(object $client, Membership $m, array $scopes, string $redirectUri, string $challenge, ?string $resource): string
    {
        $code = Str::random(64);
        DB::table('oauth_codes')->insert(['code_hash' => hash('sha256', $code), 'client_id' => $client->client_id, 'user_id' => $m->user_id,
            'workspace_id' => $m->workspace_id, 'membership_id' => $m->id, 'scopes' => json_encode($scopes), 'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge, 'resource' => $resource, 'expires_at' => now()->addSeconds(60)]);
        Audit::log('oauth.consent_granted', 'oauth_client', $client->client_id, ['scopes' => $scopes], $m->workspace_id);

        return $code;
    }

    public function exchangeCode(array $d): array
    {
        $client = $this->authenticateClient($d);
        $row = DB::table('oauth_codes')->where('code_hash', hash('sha256', (string) ($d['code'] ?? '')))->first();
        if (! $row || $row->used_at || now()->greaterThan($row->expires_at) || $row->client_id !== $client->client_id) {
            throw new ApiError('invalid_grant', 'Authorization code is invalid or expired.', 400);
        }
        if (DB::table('oauth_codes')->where('code_hash', $row->code_hash)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            throw new ApiError('invalid_grant', 'Authorization code already used.', 400);
        }
        if (! hash_equals($row->redirect_uri, (string) ($d['redirect_uri'] ?? ''))) {
            throw new ApiError('invalid_grant', 'redirect_uri mismatch.', 400);
        }
        $verifier = (string) ($d['code_verifier'] ?? '');
        $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (strlen($verifier) < 43 || ! hash_equals($row->code_challenge, $calc)) {
            throw new ApiError('invalid_grant', 'PKCE verification failed.', 400);
        }
        $resource = $d['resource'] ?? $row->resource ?? $this->resource();
        if ($resource !== $this->resource()) {
            throw new ApiError('invalid_target', 'Unknown resource.', 400);
        }

        return $this->issueTokens($client->client_id, $row->user_id, $row->workspace_id, $row->membership_id, json_decode((string) $row->scopes, true), (string) Str::ulid());
    }

    public function refresh(array $d): array
    {
        $client = $this->authenticateClient($d);
        $row = DB::table('oauth_tokens')->where('token_hash', hash('sha256', (string) ($d['refresh_token'] ?? '')))->where('kind', 'refresh')->first();
        if (! $row || $row->client_id !== $client->client_id) {
            throw new ApiError('invalid_grant', 'Refresh token is invalid.', 400);
        }
        if ($row->revoked_at) {
            // Reuse of a rotated token: revoke the whole family.
            DB::table('oauth_tokens')->where('family_id', $row->family_id)->update(['revoked_at' => now()]);
            Audit::log('oauth.refresh_reuse_detected', 'oauth_client', $row->client_id, [], $row->workspace_id);
            throw new ApiError('invalid_grant', 'Refresh token is no longer valid.', 400);
        }
        if (now()->greaterThan($row->expires_at)) {
            throw new ApiError('invalid_grant', 'Refresh token expired.', 400);
        }
        $m = Membership::find($row->membership_id);
        if (! $m || ! $m->isActive()) {
            throw new ApiError('invalid_grant', 'Access was revoked.', 400);
        }
        DB::table('oauth_tokens')->where('id', $row->id)->update(['revoked_at' => now()]);
        $scopes = json_decode((string) $row->scopes, true);
        if (! empty($d['scope'])) {
            $scopes = array_values(array_intersect($scopes, explode(' ', (string) $d['scope'])));
        }

        return $this->issueTokens($row->client_id, $row->user_id, $row->workspace_id, $row->membership_id, $scopes, $row->family_id);
    }

    private function authenticateClient(array $d): object
    {
        $client = $this->client((string) ($d['client_id'] ?? ''));
        if ($client->client_secret_hash && ! hash_equals($client->client_secret_hash, hash('sha256', (string) ($d['client_secret'] ?? '')))) {
            throw new ApiError('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    private function issueTokens(string $clientId, string $userId, string $workspaceId, string $membershipId, array $scopes, string $family): array
    {
        $access = 'lgat_'.Str::random(48);
        $refresh = 'lgrt_'.Str::random(64);
        $base = ['client_id' => $clientId, 'user_id' => $userId, 'workspace_id' => $workspaceId, 'membership_id' => $membershipId,
            'scopes' => json_encode($scopes), 'resource' => $this->resource(), 'family_id' => $family, 'created_at' => now()];
        DB::table('oauth_tokens')->insert($base + ['id' => (string) Str::ulid(), 'kind' => 'access', 'token_hash' => hash('sha256', $access),
            'expires_at' => now()->addSeconds((int) config('legacy.mcp.access_token_ttl'))]);
        DB::table('oauth_tokens')->insert($base + ['id' => (string) Str::ulid(), 'kind' => 'refresh', 'token_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addSeconds((int) config('legacy.mcp.refresh_token_ttl'))]);
        DB::table('oauth_tokens')->where('expires_at', '<', now()->subDays(7))->delete();

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => (int) config('legacy.mcp.access_token_ttl'),
            'refresh_token' => $refresh, 'scope' => implode(' ', $scopes)];
    }

    public function revoke(string $token): void
    {
        $row = DB::table('oauth_tokens')->where('token_hash', hash('sha256', $token))->first();
        if ($row) {
            DB::table('oauth_tokens')->where('family_id', $row->family_id)->update(['revoked_at' => now()]);
        }
    }

    /** Resolve a bearer token to its principal, or null. */
    public function authenticate(string $bearer): ?object
    {
        $row = DB::table('oauth_tokens')->where('token_hash', hash('sha256', $bearer))->where('kind', 'access')->first();
        if (! $row || $row->revoked_at || now()->greaterThan($row->expires_at) || $row->resource !== $this->resource()) {
            return null;
        }
        DB::table('oauth_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);

        return $row;
    }

    public static function domainsFor(array $scopes): array
    {
        $d = [];
        $a = [];
        foreach ($scopes as $s) {
            $d = array_merge($d, self::SCOPES[$s]['domains'] ?? []);
            $a = array_merge($a, self::SCOPES[$s]['actions'] ?? []);
        }

        return [array_values(array_unique($d)), array_values(array_unique($a))];
    }
}
