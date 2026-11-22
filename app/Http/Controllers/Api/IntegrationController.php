<?php

namespace App\Http\Controllers\Api;

use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\SyncService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** MCP data-source integrations (Monarch, Snowball): credentials, mapping approval, sync, provenance and conflicts. */
class IntegrationController extends ApiController
{
    private function svc(): IntegrationService
    {
        return new IntegrationService($this->ctx());
    }

    public function index(): JsonResponse
    {
        $ctx = $this->ctx();
        $rows = $ctx->constrain(DB::table('source_connections'), 'ledger')->whereNull('archived_at')->orderBy('provider')->orderBy('label')->get();
        $conflicts = $ctx->constrain(DB::table('source_discrepancies'), 'ledger')->where('status', 'open')->count();

        return $this->ok(['providers' => $this->svc()->catalog(), 'connections' => $rows->map(fn ($c) => $this->present($c, false))->values(), 'open_conflicts' => $conflicts]);
    }

    public function show(string $connection): JsonResponse
    {
        return $this->ok($this->present($this->svc()->connection($connection), true));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->validated($r, ['provider' => 'required|string', 'label' => 'nullable|string|max:120', 'scope_id' => 'required|string', 'server_url' => 'nullable|string|max:255']);

        return $this->ok($this->present($this->svc()->create($d), true), 201);
    }

    public function update(Request $r, string $connection): JsonResponse
    {
        $c = $this->svc()->connection($connection, 'write');
        if ($this->ifMatch($r) !== (int) $c->edit_version) {
            throw ApiError::conflict('This connection changed. Reload and try again.');
        }
        $d = $this->validated($r, ['label' => 'nullable|string|max:120', 'import_transactions' => 'nullable|boolean', 'auto_commit_transactions' => 'nullable|boolean',
            'transactions_days' => 'nullable|integer|min:1|max:730', 'sync_interval_hours' => 'nullable|integer|min:1|max:720']);

        return $this->ok($this->present($this->svc()->updateOptions($connection, $d), true));
    }

    public function key(Request $r, string $connection): JsonResponse
    {
        $this->privileged();
        $d = $this->validated($r, ['key' => 'required|string|max:512', 'server_url' => 'nullable|string|max:255']);

        return $this->ok($this->present($this->svc()->setKey($connection, $d['key'], $d['server_url'] ?? null), true));
    }

    public function oauthStart(string $connection): JsonResponse
    {
        $this->privileged();

        return $this->ok($this->svc()->oauthStart($connection));
    }

    public function oauthComplete(Request $r, string $connection): JsonResponse
    {
        $d = $this->validated($r, ['code' => 'required|string|max:2048', 'state' => 'required|string|max:200']);

        return $this->ok($this->present($this->svc()->oauthComplete($connection, $d['code'], $d['state']), true));
    }

    public function test(string $connection): JsonResponse
    {
        $this->throttle('integration-test');

        return $this->ok($this->svc()->test($connection));
    }

    public function tools(Request $r, string $connection): JsonResponse
    {
        $d = $this->validated($r, ['accounts' => 'nullable|string|max:100', 'holdings' => 'nullable|string|max:100', 'transactions' => 'nullable|string|max:100']);

        return $this->ok($this->present($this->svc()->setToolMap($connection, $d), true));
    }

    public function preview(string $connection): JsonResponse
    {
        $this->throttle('integration-sync');

        return $this->accepted($this->svc()->enqueue($connection, true));
    }

    public function approve(string $connection): JsonResponse
    {
        $this->privileged();

        return $this->ok($this->present($this->svc()->approve($connection), true));
    }

    public function sync(string $connection): JsonResponse
    {
        $this->throttle('integration-sync');

        return $this->accepted($this->svc()->enqueue($connection, false));
    }

    public function disconnect(string $connection): JsonResponse
    {
        $this->privileged();

        return $this->ok($this->present($this->svc()->disconnect($connection), true));
    }

    public function accountSources(string $account): JsonResponse
    {
        $ctx = $this->ctx();
        $a = $ctx->constrain(DB::table('accounts'), 'ledger')->where('id', $account)->first();
        if (! $a) {
            throw ApiError::notFound();
        }
        $links = DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $a->id)->whereNull('archived_at')->get();
        $conns = DB::table('source_connections')->whereIn('id', $links->pluck('connection_id'))->get(['id', 'label', 'provider', 'status', 'last_success_at'])->keyBy('id');

        return $this->ok([
            'authoritative_connection_id' => $a->connection_id,
            'sources' => $links->map(fn ($l) => ['connection_id' => $l->connection_id, 'provider' => $l->provider, 'label' => $conns[$l->connection_id]->label ?? $l->provider,
                'status' => $conns[$l->connection_id]->status ?? null, 'external_name' => $l->external_name, 'match_method' => $l->match_method, 'last_seen_at' => $l->last_seen_at,
                'last_values' => json_decode((string) $l->last_values, true), 'authoritative' => $l->connection_id === $a->connection_id])->values(),
            'conflicts' => $ctx->constrain(DB::table('source_discrepancies'), 'ledger')->where('entity_type', 'account')->where('entity_id', $a->id)->whereIn('status', ['open', 'ignored'])
                ->orderByDesc('last_seen_at')->get()->map(fn ($d) => ['observed' => json_decode((string) $d->observed, true)] + (array) $d)->values(),
        ]);
    }

    public function merge(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['into_account_id' => 'required|string']);

        return $this->ok((new SyncService($this->ctx()))->merge($account, $d['into_account_id']));
    }

    public function authority(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['connection_id' => 'nullable|string']);

        return $this->ok((new SyncService($this->ctx()))->setAuthority($account, $d['connection_id'] ?? null));
    }

    public function resolveConflict(Request $r, string $conflict): JsonResponse
    {
        $d = $this->validated($r, ['action' => 'required|in:ignore,resolve,reopen', 'note' => 'nullable|string|max:1000', 'prefer_connection_id' => 'nullable|string']);

        return $this->ok((new SyncService($this->ctx()))->resolve($conflict, $d['action'], $d['note'] ?? null, $d['prefer_connection_id'] ?? null));
    }

    private function throttle(string $name): void
    {
        $key = $name.':'.$this->ctx()->user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ApiError('rate_limited', 'Too many requests. Wait a minute and try again.', 429);
        }
        RateLimiter::hit($key, 60);
    }

    private function present(object $c, bool $full): array
    {
        $cfg = json_decode((string) $c->config, true) ?: [];
        $out = [
            'id' => $c->id, 'scope_id' => $c->scope_id, 'provider' => $c->provider, 'label' => $c->label, 'mode' => $c->mode, 'status' => $c->status,
            'auth_kind' => $c->auth_kind, 'server_url' => $c->server_url, 'authorized' => (bool) $c->credential_encrypted, 'credential_hint' => $c->credential_hint,
            'credential_set_at' => $c->credential_set_at, 'token_expires_at' => $c->token_expires_at, 'approved_at' => $c->approved_at,
            'last_sync_at' => $c->last_sync_at, 'last_success_at' => $c->last_success_at, 'last_error' => $c->last_error, 'last_job_id' => $c->last_job_id,
            'sync_interval_hours' => (int) $c->sync_interval_hours, 'options' => array_intersect_key($cfg, array_flip(['import_transactions', 'auto_commit_transactions', 'transactions_days'])),
            'tool_map' => json_decode((string) $c->tool_map, true), 'last_sync_stats' => json_decode((string) $c->last_sync_stats, true),
            'linked_accounts' => DB::table('external_links')->where('connection_id', $c->id)->where('entity_type', 'account')->whereNull('archived_at')->count(),
            'edit_version' => (int) $c->edit_version,
        ];
        if ($full) {
            $out['tool_catalog'] = json_decode((string) $c->tool_catalog, true);
            $out['last_preview'] = json_decode((string) $c->last_preview, true);
        }

        return $out;
    }
}
