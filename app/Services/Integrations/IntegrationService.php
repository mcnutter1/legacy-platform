<?php

namespace App\Services\Integrations;

use App\Jobs\JobService;
use App\Services\Integrations\Providers\FinanceProvider;
use App\Services\Integrations\Providers\MonarchProvider;
use App\Services\Integrations\Providers\PropertyManagerProvider;
use App\Services\Integrations\Providers\Provider;
use App\Services\Integrations\Providers\SnowballProvider;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Connection lifecycle for MCP data sources: create → authorize (key or OAuth) → test → preview → approve → sync → disconnect. */
final class IntegrationService
{
    public const PROVIDERS = ['monarch' => MonarchProvider::class, 'snowball' => SnowballProvider::class,
        'property_manager' => PropertyManagerProvider::class, 'invoices' => FinanceProvider::class];

    public const LOOPBACK_REDIRECT = 'http://127.0.0.1:33418/callback';

    public function __construct(private readonly AccessContext $ctx) {}

    public static function provider(string $key): Provider
    {
        $cls = self::PROVIDERS[$key] ?? null;
        if (! $cls) {
            throw new ApiError('unsupported', 'This provider has no live integration.', 422);
        }

        return new $cls;
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/integrations/oauth/callback';
    }

    public function catalog(): array
    {
        return array_values(array_map(function (string $cls) {
            $p = new $cls;

            return ['provider' => $p->key(), 'label' => $p->label(), 'auth_kind' => $p->authKind(), 'enabled' => $p->enabled(), 'transport' => $p->transport(), 'target' => $p->target(),
                'default_url' => $p->defaultUrl(), 'instructions' => $p->instructions(), 'redirect_uri' => $p->authKind() === 'oauth' ? self::redirectUri() : null];
        }, self::PROVIDERS));
    }

    public function create(array $data): object
    {
        $p = self::provider((string) ($data['provider'] ?? ''));
        if (! $p->enabled()) {
            throw new ApiError('feature_disabled', "{$p->label()} sync is turned off on this server.", 503);
        }
        $scopeId = (string) ($data['scope_id'] ?? '');
        if (! $this->ctx->can('ledger', 'write', $scopeId)) {
            throw ApiError::forbidden();
        }
        $url = $this->serverUrl($p, $data['server_url'] ?? null);
        $config = match ($p->target()) {
            'property' => ['property_id' => PmsSyncService::assertTarget($this->ctx, $data['property_id'] ?? null)],
            'entity' => ['entity_id' => FinanceSyncService::assertTarget($this->ctx, $data['entity_id'] ?? null)],
            default => ['transactions_days' => 90, 'import_transactions' => true, 'auto_commit_transactions' => false],
        };
        $id = (string) Str::ulid();
        DB::table('source_connections')->insert([
            'id' => $id, 'workspace_id' => $this->ctx->workspace->id, 'scope_id' => $scopeId, 'provider' => $p->key(),
            'label' => mb_substr(trim((string) ($data['label'] ?? '')) ?: $p->label(), 0, 120), 'mode' => $p->transport() === 'rest' ? 'api' : 'mcp', 'status' => 'needs_auth',
            'auth_kind' => $p->authKind(), 'server_url' => $url, 'freshness_hours' => 48, 'sync_interval_hours' => 24,
            'config' => json_encode($config),
            'owner_membership_id' => $this->ctx->membership->id, 'created_by' => $this->ctx->user->id, 'updated_by' => $this->ctx->user->id,
            'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        Audit::log('integration.created', 'source_connection', $id, ['provider' => $p->key()], scopeId: $scopeId);

        return $this->connection($id);
    }

    public function connection(string $id, string $action = 'read'): object
    {
        $c = Str::isUlid($id) ? $this->ctx->constrain(DB::table('source_connections'), 'ledger', $action)->where('id', $id)->whereNull('archived_at')->first() : null;
        if (! $c) {
            throw ApiError::notFound();
        }

        return $c;
    }

    public function setKey(string $id, string $key, ?string $serverUrl): object
    {
        $c = $this->connection($id, 'write');
        $p = self::provider($c->provider);
        if ($p->authKind() !== 'api_key') {
            throw new ApiError('validation_failed', "{$p->label()} is authorized with OAuth, not a key.", 422);
        }
        $key = trim($key);
        if (! preg_match('/^[\x21-\x7E]{8,512}$/', $key)) {
            throw new ApiError('validation_failed', 'That does not look like an API key.', 422, ['key' => ['Paste the full key the provider showed once when you created it.']]);
        }
        $this->update($c, [
            'credential_encrypted' => Crypt::encryptString(json_encode(['api_key' => $key])), 'credential_hint' => mb_substr($key, 0, 8).'…',
            'credential_set_at' => now(), 'server_url' => $this->serverUrl($p, $serverUrl ?: $c->server_url), 'status' => 'needs_test', 'last_error' => null,
        ]);
        Audit::log('integration.credential_set', 'source_connection', $c->id, ['provider' => $c->provider], scopeId: $c->scope_id);

        return $this->connection($c->id);
    }

    public function oauthStart(string $id): array
    {
        $c = $this->connection($id, 'write');
        $p = self::provider($c->provider);
        if ($p->authKind() !== 'oauth') {
            throw new ApiError('validation_failed', "{$p->label()} uses a key, not OAuth.", 422);
        }
        $meta = $this->discover($p, (string) $c->server_url);
        $client = json_decode((string) $c->oauth_client, true) ?: [];
        $reusable = ! empty($client['client_id']) && ($client['registration_endpoint'] ?? null) === $meta['registration_endpoint']
            && in_array($client['redirect_uri'] ?? null, [self::redirectUri(), self::LOOPBACK_REDIRECT], true);
        // A loopback client is re-checked daily in case the provider has since approved our web callback.
        if ($reusable && $client['redirect_uri'] === self::LOOPBACK_REDIRECT && (int) ($client['web_checked_at'] ?? 0) < time() - 86400) {
            $reusable = false;
        }
        if (! $reusable) {
            try {
                $client = $this->register($p, $meta, self::redirectUri());
            } catch (ApiError $e) {
                if ($e->errorCode !== 'invalid_redirect_uri') {
                    throw $e;
                }
                // RFC 8252 loopback redirect: allowed by providers that gate web callbacks behind an approval list.
                $client = $this->register($p, $meta, self::LOOPBACK_REDIRECT) + ['web_checked_at' => time(), 'web_error' => $e->getMessage()];
            }
        }
        $client = $meta + $client;
        $verifier = Str::random(64);
        $state = $this->ctx->workspace->id.'.'.$c->id.'.'.Str::random(40);
        Cache::put('integration-oauth:'.hash('sha256', $state), [
            'user_id' => $this->ctx->user->id, 'membership_id' => $this->ctx->membership->id, 'connection_id' => $c->id, 'verifier' => Crypt::encryptString($verifier),
        ], now()->addMinutes(10));
        $this->update($c, ['oauth_client' => json_encode($client)]);
        $url = $meta['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uri'], 'scope' => 'mcp:read', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'resource' => $c->server_url,
        ], '', '&', PHP_QUERY_RFC3986);
        $loopback = $client['redirect_uri'] === self::LOOPBACK_REDIRECT;

        return ['authorize_url' => $url, 'mode' => $loopback ? 'loopback' : 'web', 'redirect_uri' => $client['redirect_uri'],
            'web_redirect' => self::redirectUri(), 'notice' => $loopback ? ($client['web_error'] ?? null) : null];
    }

    public function oauthComplete(string $id, string $code, string $state): object
    {
        $c = $this->connection($id, 'write');
        $pending = Cache::pull('integration-oauth:'.hash('sha256', $state));
        if (! $pending || $pending['connection_id'] !== $c->id || $pending['user_id'] !== $this->ctx->user->id || $pending['membership_id'] !== $this->ctx->membership->id) {
            throw new ApiError('invalid_state', 'This authorization link expired or belongs to another session. Start again.', 400);
        }
        $client = json_decode((string) $c->oauth_client, true) ?: [];
        $tokens = self::tokenRequest(self::provider($c->provider), $client, [
            'grant_type' => 'authorization_code', 'code' => mb_substr($code, 0, 2048), 'redirect_uri' => $client['redirect_uri'] ?? self::redirectUri(),
            'client_id' => $client['client_id'] ?? '', 'code_verifier' => Crypt::decryptString($pending['verifier']), 'resource' => $c->server_url,
        ]);
        $this->storeTokens($c, $tokens);
        Audit::log('integration.authorized', 'source_connection', $c->id, ['provider' => $c->provider, 'scope' => $tokens['scope'] ?? null], scopeId: $c->scope_id);

        return $this->connection($c->id);
    }

    /** Headers for an MCP call, refreshing OAuth tokens when needed. Usable from jobs (no AccessContext checks). */
    public static function headers(object $c): array
    {
        if (! $c->credential_encrypted) {
            throw new IntegrationError('unauthorized', 'This connection has not been authorized yet.');
        }
        $cred = json_decode(Crypt::decryptString($c->credential_encrypted), true) ?: [];
        if ($c->auth_kind === 'api_key') {
            return ['X-API-Key' => (string) ($cred['api_key'] ?? '')];
        }
        if (($cred['expires_at'] ?? PHP_INT_MAX) < time() + 60) {
            $cred = Cache::lock('integration-refresh:'.$c->id, 30)->block(20, function () use ($c) {
                $fresh = DB::table('source_connections')->find($c->id);
                $cred = json_decode(Crypt::decryptString($fresh->credential_encrypted), true) ?: [];
                if (($cred['expires_at'] ?? PHP_INT_MAX) >= time() + 60) {
                    return $cred;
                }
                if (empty($cred['refresh_token'])) {
                    DB::table('source_connections')->where('id', $c->id)->update(['status' => 'needs_auth', 'updated_at' => now()]);
                    throw new IntegrationError('unauthorized', 'Authorization expired. Reconnect to continue syncing.');
                }
                $client = json_decode((string) $fresh->oauth_client, true) ?: [];
                try {
                    $tokens = self::tokenRequest(self::provider($fresh->provider), $client, [
                        'grant_type' => 'refresh_token', 'refresh_token' => $cred['refresh_token'], 'client_id' => $client['client_id'] ?? '', 'resource' => $fresh->server_url,
                    ]);
                } catch (ApiError $e) {
                    DB::table('source_connections')->where('id', $c->id)->update(['status' => 'needs_auth', 'last_error' => $e->getMessage(), 'updated_at' => now()]);
                    throw new IntegrationError('unauthorized', 'Authorization expired. Reconnect to continue syncing.');
                }
                $tokens['refresh_token'] ??= $cred['refresh_token'];

                return self::persistTokens($fresh, $tokens);
            });
        }

        return ['Authorization' => 'Bearer '.($cred['access_token'] ?? '')];
    }

    public static function client(object $c): McpClient
    {
        $p = self::provider($c->provider);
        if (! $p->hostAllowed((string) $c->server_url)) {
            throw new IntegrationError('invalid_url', 'The server address is not an allowed host for this provider.');
        }

        return new McpClient((string) $c->server_url, self::headers($c));
    }

    public function test(string $id): array
    {
        $c = $this->connection($id, 'write');
        $p = self::provider($c->provider);
        if ($p->transport() === 'rest') {
            try {
                $out = $c->provider === 'invoices' ? (new FinanceSyncService($this->ctx))->test($c) : (new PmsSyncService($this->ctx))->test($c);
            } catch (IntegrationError $e) {
                $this->update($c, ['status' => $e->reason === 'unauthorized' ? 'needs_auth' : 'error', 'last_error' => $e->getMessage(), 'last_sync_at' => now()]);
                throw new ApiError('integration_'.$e->reason, $e->getMessage(), 502);
            }
            $this->update($this->connection($c->id, 'write'), ['last_error' => null, 'status' => $c->approved_at ? 'active' : 'needs_review']);
            Audit::log('integration.tested', 'source_connection', $c->id, ['provider' => $c->provider], scopeId: $c->scope_id);

            return $out;
        }
        try {
            $client = self::client($c);
            $server = $client->initialize();
            $tools = $client->tools();
        } catch (IntegrationError $e) {
            $this->update($c, ['status' => $e->reason === 'unauthorized' ? 'needs_auth' : 'error', 'last_error' => $e->getMessage(), 'last_sync_at' => now()]);
            throw new ApiError('integration_'.$e->reason, $e->getMessage(), 502);
        }
        $current = json_decode((string) $c->tool_map, true) ?: [];
        $names = array_column($tools, 'name');
        $stale = array_filter($current, fn ($t) => $t !== null && ! in_array($t, $names, true));
        $map = ($current && ! $stale) ? $current : $p->suggestTools($tools);
        $changed = $map !== $current;
        $this->update($c, ['tool_catalog' => json_encode($tools), 'tool_map' => json_encode($map), 'last_error' => null,
            'status' => $c->approved_at && ! $changed ? 'active' : 'needs_review'] + ($changed ? ['approved_at' => null, 'approved_by' => null] : []));
        Audit::log('integration.tested', 'source_connection', $c->id, ['tools' => count($tools)], scopeId: $c->scope_id);

        return ['server' => ['name' => mb_substr((string) ($server['serverInfo']['name'] ?? ''), 0, 80) ?: null, 'version' => mb_substr((string) ($server['serverInfo']['version'] ?? ''), 0, 40) ?: null,
            'protocol' => $server['protocolVersion'] ?? null], 'tools' => $tools, 'tool_map' => $map, 'missing' => array_keys(array_filter($map, fn ($v) => $v === null))];
    }

    public function setToolMap(string $id, array $map): object
    {
        $c = $this->connection($id, 'write');
        $names = array_column(json_decode((string) $c->tool_catalog, true) ?: [], 'name');
        $clean = [];
        foreach (['accounts', 'holdings', 'transactions'] as $role) {
            $v = $map[$role] ?? null;
            if ($v !== null && $v !== '' && ! in_array($v, $names, true)) {
                throw new ApiError('validation_failed', "Unknown tool for {$role}. Run Test to refresh the tool list.", 422);
            }
            $clean[$role] = $v ?: null;
        }
        $this->update($c, ['tool_map' => json_encode($clean), 'approved_at' => null, 'approved_by' => null, 'status' => 'needs_review', 'last_preview' => null]);

        return $this->connection($c->id);
    }

    public function updateOptions(string $id, array $data): object
    {
        $c = $this->connection($id, 'write');
        $cfg = json_decode((string) $c->config, true) ?: [];
        foreach (['import_transactions' => 'bool', 'auto_commit_transactions' => 'bool', 'transactions_days' => 'int'] as $k => $t) {
            if (array_key_exists($k, $data)) {
                $cfg[$k] = $t === 'bool' ? (bool) $data[$k] : max(1, min(730, (int) $data[$k]));
            }
        }
        $set = ['config' => json_encode($cfg)];
        $target = self::provider($c->provider)->target();
        if ($target === 'property' && ! empty($data['property_id']) && $data['property_id'] !== ($cfg['property_id'] ?? null)) {
            $cfg['property_id'] = PmsSyncService::assertTarget($this->ctx, $data['property_id']);
            $set = ['config' => json_encode($cfg), 'approved_at' => null, 'approved_by' => null, 'last_preview' => null, 'status' => 'needs_review'];
        }
        if ($target === 'entity' && ! empty($data['entity_id']) && $data['entity_id'] !== ($cfg['entity_id'] ?? null)) {
            $cfg['entity_id'] = FinanceSyncService::assertTarget($this->ctx, $data['entity_id']);
            $set = ['config' => json_encode($cfg), 'approved_at' => null, 'approved_by' => null, 'last_preview' => null, 'status' => 'needs_review'];
        }
        if (isset($data['sync_interval_hours'])) {
            $set['sync_interval_hours'] = max(1, min(720, (int) $data['sync_interval_hours']));
        }
        if (isset($data['label']) && trim((string) $data['label']) !== '') {
            $set['label'] = mb_substr(trim((string) $data['label']), 0, 120);
        }
        $this->update($c, $set);

        return $this->connection($c->id);
    }

    /** Finance: which Schedule C line each account feeds ({account_id: line}). */
    public function setAccountMap(string $id, array $map): object
    {
        $c = $this->connection($id, 'write');
        if ($c->provider !== 'invoices') {
            throw new ApiError('validation_failed', 'Only bookkeeping connections have an account mapping.', 422);
        }
        $cfg = json_decode((string) $c->config, true) ?: [];
        foreach ($map as $acct => $line) {
            if (! isset($cfg['accounts'][$acct]) || ! in_array($line, FinanceSyncService::LINES, true)) {
                throw new ApiError('validation_failed', 'Unknown account or Schedule C line. Run Preview to refresh the accounts.', 422);
            }
            $cfg['account_map'][$acct] = $line;
        }
        $this->update($c, ['config' => json_encode($cfg)]);
        Audit::log('integration.account_map_updated', 'source_connection', $c->id, ['accounts' => count($map)], scopeId: $c->scope_id);

        return $this->connection($c->id);
    }

    public function approve(string $id): object
    {
        $c = $this->connection($id, 'write');
        $preview = json_decode((string) $c->last_preview, true);
        if (self::provider($c->provider)->transport() === 'rest') {
            if (empty($preview['ok'])) {
                throw ApiError::conflict('Run a preview before approving.');
            }
            if ($c->provider === 'invoices') {
                (new FinanceSyncService($this->ctx))->ensureEntity($c);
                $c = $this->connection($c->id, 'write');
            }
            $this->update($c, ['approved_at' => now(), 'approved_by' => $this->ctx->user->id, 'status' => 'active', 'owner_membership_id' => $this->ctx->membership->id]);
            Audit::log('integration.approved', 'source_connection', $c->id, ['provider' => $c->provider], scopeId: $c->scope_id);

            return $this->connection($c->id);
        }
        if (! $preview || ($preview['tool_map'] ?? null) !== json_decode((string) $c->tool_map, true)) {
            throw ApiError::conflict('Run a preview with the current tool mapping before approving.');
        }
        if (empty($preview['accounts'])) {
            throw ApiError::conflict('The preview found no accounts to sync.');
        }
        $this->update($c, ['approved_at' => now(), 'approved_by' => $this->ctx->user->id, 'status' => 'active', 'owner_membership_id' => $this->ctx->membership->id]);
        Audit::log('integration.approved', 'source_connection', $c->id, ['tool_map' => $preview['tool_map']], scopeId: $c->scope_id);

        return $this->connection($c->id);
    }

    public function enqueue(string $id, bool $dryRun): array
    {
        $c = $this->connection($id, 'write');
        if (! $c->credential_encrypted) {
            throw ApiError::conflict('Authorize this connection first.');
        }
        if (! $dryRun && ! $c->approved_at) {
            throw ApiError::conflict('Preview and approve the mapping before syncing.');
        }
        $job = app(JobService::class)->enqueue($this->ctx, 'integration.sync', ['connection_id' => $c->id, 'dry_run' => $dryRun], 'source_connection', $c->id, $c->scope_id);
        $this->update($c, ['last_job_id' => $job->id]);

        return $job->present();
    }

    public function disconnect(string $id): object
    {
        $c = $this->connection($id, 'write');
        if ($c->auth_kind === 'oauth' && $c->credential_encrypted) {
            $cred = json_decode(Crypt::decryptString($c->credential_encrypted), true) ?: [];
            $client = json_decode((string) $c->oauth_client, true) ?: [];
            $p = self::provider($c->provider);
            if (! empty($client['revocation_endpoint']) && $p->hostAllowed($client['revocation_endpoint'])) {
                foreach (['refresh_token', 'access_token'] as $kind) {
                    if (! empty($cred[$kind])) {
                        try {
                            Http::asForm()->timeout(10)->post($client['revocation_endpoint'], ['token' => $cred[$kind], 'token_type_hint' => $kind, 'client_id' => $client['client_id'] ?? '']);
                        } catch (ConnectionException) {
                        }
                    }
                }
            }
        }
        $this->update($c, ['credential_encrypted' => null, 'credential_hint' => null, 'token_expires_at' => null, 'approved_at' => null, 'approved_by' => null, 'status' => 'disconnected']);
        Audit::log('integration.disconnected', 'source_connection', $c->id, ['provider' => $c->provider], scopeId: $c->scope_id);

        return $this->connection($c->id);
    }

    private function serverUrl(Provider $p, ?string $url): string
    {
        $url = trim((string) $url) ?: $p->defaultUrl();
        if (! $p->hostAllowed($url) || str_contains($url, '/k/')) {
            throw new ApiError('validation_failed', "Use the {$p->label()} server address (https, on ".implode(', ', $p->allowedHosts()).'). Keys belong in the key field, not the URL.', 422);
        }

        return $url;
    }

    private function discover(Provider $p, string $resource): array
    {
        $u = parse_url($resource);
        $origin = 'https://'.$u['host'];
        $prm = $this->getJson($p, $origin.'/.well-known/oauth-protected-resource'.rtrim($u['path'] ?? '', '/'))
            ?? $this->getJson($p, $origin.'/.well-known/oauth-protected-resource');
        $as = rtrim((string) ($prm['authorization_servers'][0] ?? $origin), '/');
        if (! $p->hostAllowed($as.'/')) {
            throw new ApiError('integration_unavailable', 'The provider advertised an unexpected authorization server.', 502);
        }
        $meta = $this->getJson($p, $as.'/.well-known/oauth-authorization-server');
        $out = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint'] as $k) {
            $v = $meta[$k] ?? null;
            if ($v !== null && ! $p->hostAllowed((string) $v)) {
                throw new ApiError('integration_unavailable', 'The provider advertised an unexpected OAuth endpoint.', 502);
            }
            $out[$k] = $v;
        }
        if (! $out['authorization_endpoint'] || ! $out['token_endpoint'] || ! $out['registration_endpoint']) {
            throw new ApiError('integration_unavailable', 'The provider does not support dynamic client registration.', 502);
        }
        if (! in_array('S256', (array) ($meta['code_challenge_methods_supported'] ?? []), true)) {
            throw new ApiError('integration_unavailable', 'The provider does not support PKCE (S256).', 502);
        }

        return $out;
    }

    private function register(Provider $p, array $meta, string $redirect): array
    {
        try {
            $res = Http::timeout(15)->withOptions(['allow_redirects' => false])->asJson()->post($meta['registration_endpoint'], [
                'client_name' => 'McNutt Legacy', 'redirect_uris' => [$redirect], 'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'], 'token_endpoint_auth_method' => 'none', 'scope' => 'mcp:read',
                'client_uri' => rtrim((string) config('app.url'), '/'),
            ]);
        } catch (ConnectionException) {
            throw new ApiError('integration_unavailable', "{$p->label()} did not respond.", 502);
        }
        if (! $res->successful() || ! is_string($res->json('client_id'))) {
            $why = mb_substr((string) ($res->json('error_description') ?? $res->json('error') ?? 'HTTP '.$res->status()), 0, 300);

            throw new ApiError($res->json('error') === 'invalid_redirect_uri' ? 'invalid_redirect_uri' : 'integration_unavailable', "{$p->label()} refused client registration: {$why}", 502);
        }

        return ['client_id' => $res->json('client_id'), 'redirect_uri' => $redirect, 'registration_endpoint' => $meta['registration_endpoint']];
    }

    private static function tokenRequest(Provider $p, array $client, array $form): array
    {
        $endpoint = (string) ($client['token_endpoint'] ?? '');
        if (! $p->hostAllowed($endpoint)) {
            throw new ApiError('integration_unavailable', 'Unknown token endpoint. Reconnect.', 502);
        }
        try {
            $res = Http::asForm()->timeout(15)->withOptions(['allow_redirects' => false])->post($endpoint, $form);
        } catch (ConnectionException) {
            throw new ApiError('integration_unavailable', "{$p->label()} did not respond.", 502);
        }
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            throw new ApiError('integration_unauthorized', "{$p->label()} did not issue a token (".mb_substr((string) ($res->json('error') ?? 'HTTP '.$res->status()), 0, 60).').', 400);
        }

        return $res->json();
    }

    private function storeTokens(object $c, array $tokens): void
    {
        self::persistTokens($c, $tokens);
        DB::table('source_connections')->where('id', $c->id)->update(['status' => 'needs_test', 'last_error' => null, 'credential_set_at' => now()]);
    }

    private static function persistTokens(object $c, array $tokens): array
    {
        $cred = ['access_token' => (string) $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => isset($tokens['expires_in']) ? time() + (int) $tokens['expires_in'] : null, 'scope' => $tokens['scope'] ?? null];
        $scope = (string) ($cred['scope'] ?? 'mcp:read');
        DB::table('source_connections')->where('id', $c->id)->update([
            'credential_encrypted' => Crypt::encryptString(json_encode($cred)), 'credential_hint' => 'OAuth · '.mb_substr($scope, 0, 22),
            'token_expires_at' => $cred['expires_at'] ? date('Y-m-d H:i:s', $cred['expires_at']) : null, 'updated_at' => now(),
        ]);

        return $cred;
    }

    private function getJson(Provider $p, string $url): ?array
    {
        if (! $p->hostAllowed($url)) {
            return null;
        }
        try {
            $res = Http::timeout(10)->withOptions(['allow_redirects' => false])->acceptJson()->get($url);
        } catch (ConnectionException) {
            throw new ApiError('integration_unavailable', "{$p->label()} did not respond.", 502);
        }

        return $res->successful() && is_array($res->json()) ? $res->json() : null;
    }

    private function update(object $c, array $set): void
    {
        DB::table('source_connections')->where('id', $c->id)->update($set + ['updated_by' => $this->ctx->user->id, 'updated_at' => now(), 'edit_version' => DB::raw('edit_version + 1')]);
    }
}
