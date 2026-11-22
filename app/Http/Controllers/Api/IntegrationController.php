<?php

namespace App\Http\Controllers\Api;

use App\Services\AccountCurationService;
use App\Services\Integrations\FinanceSyncService;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\SyncService;
use App\Support\ApiError;
use App\Support\Dec;
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
        $d = $this->validated($r, ['provider' => 'required|string', 'label' => 'nullable|string|max:120', 'scope_id' => 'required|string', 'server_url' => 'nullable|string|max:255',
            'property_id' => 'nullable|string|max:26', 'entity_id' => 'nullable|string|max:26']);

        return $this->ok($this->present($this->svc()->create($d), true), 201);
    }

    public function update(Request $r, string $connection): JsonResponse
    {
        $c = $this->svc()->connection($connection, 'write');
        if ($this->ifMatch($r) !== (int) $c->edit_version) {
            throw ApiError::conflict('This connection changed. Reload and try again.');
        }
        $d = $this->validated($r, ['label' => 'nullable|string|max:120', 'import_transactions' => 'nullable|boolean', 'auto_commit_transactions' => 'nullable|boolean',
            'transactions_days' => 'nullable|integer|min:1|max:730', 'sync_interval_hours' => 'nullable|integer|min:1|max:720',
            'property_id' => 'nullable|string|max:26', 'entity_id' => 'nullable|string|max:26']);

        return $this->ok($this->present($this->svc()->updateOptions($connection, $d), true));
    }

    public function accountMap(Request $r, string $connection): JsonResponse
    {
        $c = $this->svc()->connection($connection, 'write');
        if ($this->ifMatch($r) !== (int) $c->edit_version) {
            throw ApiError::conflict('This connection changed. Reload and try again.');
        }
        $d = $this->validated($r, ['map' => 'required|array|max:500', 'map.*' => 'string|max:32']);

        return $this->ok($this->present($this->svc()->setAccountMap($connection, $d['map']), true));
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
            'roles' => SyncService::effectiveRoles($a),
            'explicit_roles' => json_decode((string) $a->source_roles, true) ?: new \stdClass,
            'sources' => $links->map(fn ($l) => ['connection_id' => $l->connection_id, 'provider' => $l->provider, 'label' => $conns[$l->connection_id]->label ?? $l->provider,
                'status' => $conns[$l->connection_id]->status ?? null, 'external_name' => $l->external_name, 'match_method' => $l->match_method, 'last_seen_at' => $l->last_seen_at,
                'last_values' => json_decode((string) $l->last_values, true), 'authoritative' => $l->connection_id === $a->connection_id])->values(),
            'conflicts' => $ctx->constrain(DB::table('source_discrepancies'), 'ledger')->where('entity_type', 'account')->where('entity_id', $a->id)->whereIn('status', ['open', 'ignored'])
                ->orderByDesc('last_seen_at')->get()->map(fn ($d) => ['observed' => json_decode((string) $d->observed, true)] + (array) $d)->values(),
        ]);
    }

    public function sourceRole(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['role' => 'required|in:'.implode(',', SyncService::ROLES), 'connection_id' => 'nullable|string|max:26']);

        return $this->ok((new SyncService($this->ctx()))->setSourceRole($account, $d['role'], $d['connection_id'] ?? null));
    }

    public function merge(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['into_account_id' => 'required|string']);

        return $this->ok((new SyncService($this->ctx()))->merge($account, $d['into_account_id']));
    }

    public function visibility(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['visibility' => 'required|in:visible,hidden,ignored', 'reason' => 'nullable|in:'.implode(',', AccountCurationService::REASONS)]);

        return $this->ok((new AccountCurationService($this->ctx()))->setVisibility($account, $d['visibility'], $d['reason'] ?? null));
    }

    public function duplicates(Request $r): JsonResponse
    {
        $for = $r->query('account_id');

        return $this->ok((new AccountCurationService($this->ctx()))->duplicates(is_string($for) && $for !== '' ? $for : null));
    }

    public function notDuplicate(Request $r, string $account): JsonResponse
    {
        $d = $this->validated($r, ['other_account_id' => 'required|string|max:26']);

        return $this->ok((new AccountCurationService($this->ctx()))->notDuplicate($account, $d['other_account_id']));
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

    /** Earnings › Businesses & rentals: year-to-date view of connected books and rental activity. */
    public function businessAndRentals(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        $y = max(2000, min(2100, (int) ($r->query('year') ?: now()->year)));
        $conns = $ctx->constrain(DB::table('source_connections'), 'ledger')->whereNull('archived_at')->whereIn('provider', ['invoices', 'property_manager'])->get();
        $entityIds = $ctx->constrain(DB::table('business_ledger_lines'), 'ledger')->whereNull('archived_at')->whereBetween('posted_on', ["{$y}-01-01", "{$y}-12-31"])
            ->distinct()->pluck('entity_id')->merge($conns->where('provider', 'invoices')->map(fn ($c) => (json_decode((string) $c->config, true) ?: [])['entity_id'] ?? null)->filter())->unique();
        $entities = $ctx->constrain(DB::table('legal_entities'), 'governance')->whereIn('id', $entityIds)->whereNull('archived_at')->get()->keyBy('id');
        $businesses = [];
        foreach ($entityIds as $eid) {
            $totals = $ctx->constrain(DB::table('business_ledger_lines'), 'ledger')->where('entity_id', $eid)->whereNull('archived_at')
                ->whereBetween('posted_on', ["{$y}-01-01", "{$y}-12-31"])->selectRaw('connection_id, account_ref, account_type, SUM(amount) as total, COUNT(*) as n')
                ->groupBy('connection_id', 'account_ref', 'account_type')->get();
            $sc = FinanceSyncService::scheduleC($totals, FinanceSyncService::accountMaps($totals->pluck('connection_id')));
            $e = $entities[$eid] ?? null;
            $owners = DB::table('ownership_interests')->where('subject_type', 'entity')->where('subject_id', $eid)->whereNull('archived_at')->count();
            $conn = $conns->first(fn ($c) => ((json_decode((string) $c->config, true) ?: [])['entity_id'] ?? null) === $eid);
            $businesses[] = ['entity_id' => $eid, 'name' => $e->name ?? 'Business', 'kind' => $e->kind ?? null, 'tax_classification' => $e->tax_classification ?? null,
                'owners' => $owners, 'lines' => (int) $totals->sum('n'),
                'book' => array_map(fn ($v) => Dec::round($v, 2), $sc['book']), 'schedule_c' => ['gross' => Dec::round($sc['gross'], 2), 'returns' => Dec::round($sc['returns'], 2),
                    'cogs' => Dec::round($sc['cogs'], 2), 'expenses' => array_map(fn ($v) => Dec::round($v, 2), $sc['expenses']), 'net' => Dec::round($sc['net'], 2),
                    'meals_nondeductible' => Dec::round($sc['meals_nondeductible'], 2), 'excluded' => Dec::round($sc['excluded'], 2), 'unmapped' => $sc['unmapped']],
                'connection' => $conn ? ['id' => $conn->id, 'label' => $conn->label, 'status' => $conn->status, 'last_success_at' => $conn->last_success_at,
                    'tax_estimate' => (json_decode((string) $conn->last_sync_stats, true) ?: [])['tax_estimate'] ?? null] : null];
        }
        $rentals = [];
        $props = $ctx->constrain(DB::table('properties'), 'real_estate')->whereNull('archived_at')->whereIn('kind', ['rental_ltr', 'rental_str', 'mixed', 'second_home'])->get();
        foreach ($props as $p) {
            $stays = DB::table('property_stays')->where('property_id', $p->id)->whereNull('archived_at')->where('check_out', '>', "{$y}-01-01")->where('check_in', '<=', "{$y}-12-31")->get();
            $conn = $conns->first(fn ($c) => ((json_decode((string) $c->config, true) ?: [])['property_id'] ?? null) === $p->id);
            if ($stays->isEmpty() && ! $conn) {
                continue;
            }
            $rents = '0';
            $fees = '0';
            $days = ['rental' => 0, 'personal' => 0, 'owner_maintenance' => 0, 'blocked' => 0];
            foreach ($stays->where('cancelled', false) as $s) {
                $nights = max(0, (int) round((min(strtotime($s->check_out), strtotime(($y + 1).'-01-01')) - max(strtotime($s->check_in), strtotime("{$y}-01-01"))) / 86400));
                $bucket = match ($s->kind) { 'guest', 'family_fair_rent' => 'rental', 'personal', 'family_free', 'donated' => 'personal', 'owner_maintenance' => 'owner_maintenance', default => 'blocked' };
                $days[$bucket] += $nights;
                if ($bucket === 'rental') {
                    $rents = Dec::sub(Dec::add($rents, Dec::norm($s->gross_rent ?? '0'), Dec::norm($s->cleaning_fee ?? '0'), Dec::norm($s->damage_recovery ?? '0')), Dec::norm($s->refund ?? '0'));
                    $fees = Dec::add($fees, Dec::norm($s->platform_fee ?? '0'));
                }
            }
            $exp = DB::table('property_expenses')->where('property_id', $p->id)->whereNull('archived_at')->whereBetween('incurred_on', ["{$y}-01-01", "{$y}-12-31"])
                ->selectRaw('treatment, SUM(amount) as total, COUNT(*) as n')->groupBy('treatment')->get()->keyBy('treatment');
            $rentals[] = ['property_id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'rents' => Dec::round($rents, 2), 'platform_fees' => Dec::round($fees, 2),
                'expenses' => Dec::round(Dec::norm((string) ($exp['expense']->total ?? '0')), 2), 'improvements' => Dec::round(Dec::norm((string) ($exp['improvement']->total ?? '0')), 2),
                'undetermined' => (int) ($exp['undetermined']->n ?? 0), 'undetermined_amount' => Dec::round(Dec::norm((string) ($exp['undetermined']->total ?? '0')), 2),
                'days' => $days, 'stays' => $stays->count(),
                'connection' => $conn ? ['id' => $conn->id, 'label' => $conn->label, 'status' => $conn->status, 'last_success_at' => $conn->last_success_at,
                    'reconciliation' => collect((json_decode((string) $conn->last_sync_stats, true) ?: [])['reconciliation'] ?? [])->firstWhere('year', $y)] : null];
        }

        return $this->ok(['year' => $y, 'businesses' => $businesses, 'rentals' => $rentals,
            'connections' => $conns->map(fn ($c) => ['id' => $c->id, 'provider' => $c->provider, 'label' => $c->label, 'status' => $c->status])->values()]);
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
            'target' => array_intersect_key($cfg, array_flip(['property_id', 'entity_id'])) ?: null, 'business' => $cfg['business'] ?? null,
            'edit_version' => (int) $c->edit_version,
        ];
        if ($full) {
            $out['tool_catalog'] = json_decode((string) $c->tool_catalog, true);
            $out['last_preview'] = json_decode((string) $c->last_preview, true);
            if ($c->provider === 'invoices') {
                $out['accounts'] = array_values(array_map(fn ($a) => $a + ['line' => $cfg['account_map'][$a['id']] ?? 'other'],
                    array_filter((array) ($cfg['accounts'] ?? []), fn ($a) => in_array($a['type'] ?? '', ['income', 'expense'], true))));
            }
        }

        return $out;
    }
}
