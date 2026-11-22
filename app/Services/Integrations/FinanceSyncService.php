<?php

namespace App\Services\Integrations;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * McNutt Finance Data API (docs/INVOICES_DATA_API.md) → business_ledger_lines of one legal entity (cash basis).
 * Each account carries a Schedule C line (auto-suggested, editable) that TaxInputBuilder uses.
 */
final class FinanceSyncService
{
    public const LINES = ['gross', 'returns', 'cogs', 'advertising', 'car_truck', 'commissions', 'contract_labor', 'depreciation', 'insurance', 'interest',
        'legal_professional', 'office', 'rent', 'repairs', 'supplies', 'taxes_licenses', 'travel', 'meals', 'utilities', 'wages', 'other', 'exclude'];

    private const KINDS = ['sole_proprietor' => ['sole_prop', null], 'llc' => ['llc', null], 's_corp' => ['s_corp', 's_corp'], 'c_corp' => ['c_corp', 'c_corp'],
        'partnership' => ['partnership', 'partnership']];

    public function __construct(private readonly AccessContext $ctx) {}

    public static function client(object $c): RestClient
    {
        $p = IntegrationService::provider($c->provider);
        if (! $p->hostAllowed((string) $c->server_url)) {
            throw new IntegrationError('invalid_url', 'The server address is not an allowed host for this provider.');
        }

        return new RestClient((string) $c->server_url, IntegrationService::headers($c));
    }

    public function test(object $c): array
    {
        $d = self::client($c)->get('')['data'] ?? [];
        $biz = self::business($d);
        $datasets = array_values(array_map(fn ($x) => ['path' => mb_substr((string) ($x['path'] ?? ''), 0, 80), 'scope' => mb_substr((string) ($x['scope'] ?? ''), 0, 40), 'available' => (bool) ($x['available'] ?? false)],
            array_filter((array) ($d['datasets'] ?? []), 'is_array')));
        $scopes = array_values(array_filter((array) ($d['key']['scopes'] ?? []), 'is_string'));
        $missing = in_array('ledger.read', $scopes, true) ? [] : ['ledger.read'];
        $cfg = json_decode((string) $c->config, true) ?: [];
        DB::table('source_connections')->where('id', $c->id)->update(['config' => json_encode(['business' => $biz, 'scopes' => $scopes] + $cfg), 'updated_at' => now()]);

        return ['server' => ['name' => $biz['name'], 'version' => 'Data API v1', 'protocol' => 'rest'], 'business' => $biz, 'scopes' => $scopes, 'datasets' => $datasets,
            'tools' => [], 'tool_map' => null, 'missing' => $missing];
    }

    public function preview(object $c): array
    {
        $api = self::client($c);
        $d = $api->get('')['data'] ?? [];
        $biz = self::business($d);
        $scopes = array_values(array_filter((array) ($d['key']['scopes'] ?? []), 'is_string'));
        if (! in_array('ledger.read', $scopes, true)) {
            throw new IntegrationError('forbidden', 'This key is missing the ledger.read scope. Create a key with ledger.read in Finance → Settings → Data API access.');
        }
        [$accounts, $map] = $this->accounts($c, $api);
        $y = (int) now()->format('Y');
        $pnl = in_array('reports.read', $scopes, true) ? $this->pnl($api, $y) : null;
        $entity = $this->targetEntity($c);
        $issues = [];
        if (! $entity && ! isset(self::KINDS[$biz['entity_type']])) {
            $issues[] = "Finance reports the business type as \"{$biz['entity_type']}\"; Legacy will create it as \"other\". Set its kind and tax classification after approval.";
        }
        if ($biz['entity_type'] === 'llc' && ! $entity) {
            $issues[] = 'Legacy cannot tell a single-member LLC from a multi-member one; set the tax classification (disregarded for a single-member LLC) after approval.';
        }
        $preview = ['ok' => true, 'kind' => 'invoices', 'tool_map' => null, 'fetched_at' => now()->toIso8601String(), 'business' => $biz,
            'entity' => $entity ? ['id' => $entity->id, 'name' => $entity->name, 'kind' => $entity->kind, 'tax_classification' => $entity->tax_classification] : null,
            'accounts_map' => array_values(array_map(fn ($a) => $a + ['line' => $map[$a['id']] ?? 'other'], array_filter($accounts, fn ($a) => in_array($a['type'], ['income', 'expense'], true)))),
            'pnl' => $pnl, 'year' => $y, 'issues' => $issues, 'totals' => ['accounts' => count($accounts), 'holdings' => 0, 'transactions' => 0]];
        DB::table('source_connections')->where('id', $c->id)->update(['last_preview' => json_encode($preview), 'last_sync_at' => now(), 'last_error' => null,
            'status' => $c->approved_at ? $c->status : 'needs_review', 'updated_at' => now()]);

        return ['preview' => true, 'accounts' => count($accounts)];
    }

    public function sync(object $c): array
    {
        if (! $c->approved_at) {
            throw new IntegrationError('not_approved', 'Approve the connection before syncing.');
        }
        $api = self::client($c);
        $entity = $this->targetEntity($c);
        if (! $entity) {
            throw new IntegrationError('unsupported', 'Choose or create the business entity for these books.');
        }
        if (! $this->ctx->can('ledger', 'write', $entity->scope_id)) {
            throw new IntegrationError('forbidden', 'You no longer have write access to this business.');
        }
        [$accounts, $map] = $this->accounts($c, $api);
        $from = (now()->year - 1).'-01-01';
        $stats = ['lines_created' => 0, 'lines_unchanged' => 0, 'unknown_accounts' => 0, 'reconciliation' => [], 'tax_estimate' => null];
        $existing = DB::table('business_ledger_lines')->where('connection_id', $c->id)->pluck('id', 'external_id');
        $cursor = null;
        $pages = 0;
        // Cash events are append-only and small; re-reading the open years keeps the import exact without a fragile resume point.
        do {
            $page = $api->get('cash-events', ['from' => $from, 'limit' => 500, 'cursor' => $cursor]);
            $rows = [];
            foreach ((array) ($page['data'] ?? []) as $e) {
                $ext = is_scalar($e['id'] ?? null) ? mb_substr((string) $e['id'], 0, 64) : null;
                $acct = is_scalar($e['account_id'] ?? null) ? (string) $e['account_id'] : null;
                $date = is_string($e['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['date']) ? $e['date'] : null;
                $amount = is_string($e['amount'] ?? null) && preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $e['amount']) ? Dec::norm($e['amount']) : null;
                if (! $ext || ! $acct || ! $date || $amount === null) {
                    continue;
                }
                if (isset($existing[$ext])) {
                    $stats['lines_unchanged']++;

                    continue;
                }
                $a = $accounts[$acct] ?? null;
                if (! $a) {
                    $stats['unknown_accounts']++;
                }
                $rows[] = ['id' => (string) Str::ulid(), 'workspace_id' => $c->workspace_id, 'scope_id' => $entity->scope_id, 'entity_id' => $entity->id, 'connection_id' => $c->id,
                    'external_id' => $ext, 'posted_on' => $date, 'account_ref' => mb_substr($acct, 0, 64), 'account_code' => mb_substr((string) ($a['code'] ?? $e['account_code'] ?? ''), 0, 32) ?: null,
                    'account_name' => $a['name'] ?? null, 'account_type' => self::accountType($a), 'amount' => $amount,
                    'description' => mb_substr((string) ($e['description'] ?? ''), 0, 255) ?: null, 'source_key' => mb_substr((string) ($e['source_key'] ?? ''), 0, 128) ?: null,
                    'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1];
                $existing[$ext] = true;
            }
            DB::transaction(fn () => collect($rows)->chunk(200)->each(fn ($ch) => DB::table('business_ledger_lines')->insert($ch->all())));
            $stats['lines_created'] += count($rows);
            $cursor = $page['meta']['next_cursor'] ?? null;
        } while (($page['meta']['has_more'] ?? false) && $cursor && ++$pages < 200);

        $scopes = (json_decode((string) $c->config, true) ?: [])['scopes'] ?? [];
        if (in_array('reports.read', $scopes, true)) {
            foreach ([now()->year - 1, now()->year] as $y) {
                $stats['reconciliation'][] = $this->reconcile($c, $api, $y);
            }
            try {
                $t = $api->get('reports/tax-estimate', ['year' => now()->year])['data'] ?? [];
                $stats['tax_estimate'] = ['provisional' => true, 'year' => (int) ($t['year'] ?? now()->year), 'entity' => mb_substr((string) ($t['entity'] ?? ''), 0, 24),
                    'net_business_income' => self::money($t['net_business_income'] ?? null), 'business_tax' => self::money($t['summary']['business_tax'] ?? null),
                    'business_federal' => self::money($t['summary']['business_federal'] ?? null), 'business_state' => self::money($t['summary']['business_state'] ?? null)];
            } catch (IntegrationError) {
                // Optional: the owner may not have configured the Finance tax estimate.
            }
        }
        DB::table('source_connections')->where('id', $c->id)->update(['last_sync_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'status' => 'active',
            'last_sync_stats' => json_encode($stats), 'updated_at' => now()]);
        Audit::log('integration.synced', 'source_connection', $c->id, ['lines_created' => $stats['lines_created']], scopeId: $c->scope_id);

        return $stats;
    }

    /** All accounts (paged) → config.accounts; new accounts get a suggested Schedule C line, existing choices are kept. */
    private function accounts(object $c, RestClient $api): array
    {
        $accounts = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $api->get('accounts', ['limit' => 500, 'cursor' => $cursor]);
            foreach ((array) ($page['data'] ?? []) as $a) {
                if (! is_scalar($a['id'] ?? null)) {
                    continue;
                }
                $id = mb_substr((string) $a['id'], 0, 64);
                $accounts[$id] = ['id' => $id, 'code' => mb_substr((string) ($a['code'] ?? ''), 0, 32), 'name' => mb_substr((string) ($a['name'] ?? ''), 0, 120),
                    'type' => in_array($a['type'] ?? '', ['asset', 'liability', 'equity', 'income', 'expense'], true) ? $a['type'] : 'other',
                    'subtype' => is_string($a['subtype'] ?? null) ? mb_substr($a['subtype'], 0, 32) : null,
                    'system_key' => is_string($a['system_key'] ?? null) ? mb_substr($a['system_key'], 0, 40) : null];
            }
            $cursor = $page['meta']['next_cursor'] ?? null;
        } while (($page['meta']['has_more'] ?? false) && $cursor && ++$pages < 50);
        $cfg = json_decode((string) DB::table('source_connections')->where('id', $c->id)->value('config'), true) ?: [];
        $map = (array) ($cfg['account_map'] ?? []);
        foreach ($accounts as $id => $a) {
            $map[$id] ??= self::suggestLine($a);
        }
        $cfg['accounts'] = $accounts;
        $cfg['account_map'] = $map;
        DB::table('source_connections')->where('id', $c->id)->update(['config' => json_encode($cfg), 'updated_at' => now()]);
        DB::table('business_ledger_lines')->where('connection_id', $c->id)->whereNull('account_name')->get(['id', 'account_ref'])
            ->each(fn ($l) => isset($accounts[$l->account_ref]) && DB::table('business_ledger_lines')->where('id', $l->id)
                ->update(['account_name' => $accounts[$l->account_ref]['name'], 'account_code' => $accounts[$l->account_ref]['code'] ?: null, 'account_type' => self::accountType($accounts[$l->account_ref])]));

        return [$accounts, $map];
    }

    public static function suggestLine(array $a): string
    {
        $type = $a['type'] ?? '';
        if ($type === 'income') {
            return preg_match('/refund|return|discount|allowance/i', (string) $a['name']) ? 'returns' : 'gross';
        }
        if ($type !== 'expense') {
            return 'exclude';
        }
        if (($a['subtype'] ?? null) === 'cogs') {
            return 'cogs';
        }
        $n = strtolower(($a['system_key'] ?? '').' '.($a['name'] ?? ''));
        foreach ([
            'exclude' => '/draw|distribution|owner|income tax|estimated tax|federal tax|state tax/',
            'meals' => '/meal|dining|restaurant|entertain/',
            'travel' => '/travel|airfare|hotel|lodging/',
            'car_truck' => '/vehicle|\bcar\b|\bauto\b|mileage|fuel|gas\b|parking|tolls/',
            'advertising' => '/advertis|marketing|promotion/',
            'commissions' => '/commission|merchant|processing fee|bank.*fee|stripe/',
            'contract_labor' => '/contract|subcontract|freelanc|1099/',
            'depreciation' => '/depreciat|amortiz/',
            'insurance' => '/insurance/',
            'interest' => '/interest/',
            'legal_professional' => '/legal|accounting|bookkeep|professional|attorney|cpa/',
            'wages' => '/wage|salar|payroll/',
            'rent' => '/\brent\b|lease/',
            'repairs' => '/repair|maintenance/',
            'supplies' => '/suppl/',
            'taxes_licenses' => '/tax|licen|permit|registration/',
            'utilities' => '/utilit|phone|internet|electric|telecom/',
            'office' => '/office|postage|software|subscription|hosting|computer/',
        ] as $line => $re) {
            if (preg_match($re, $n)) {
                return $line;
            }
        }

        return 'other';
    }

    /**
     * Schedule C amounts from per-account totals ({connection_id, account_ref, account_type, total}) and accountMaps().
     * Meals are 50% deductible (IRC 274(n)); 'exclude' accounts (owner draws, income tax) never reach the return.
     */
    public static function scheduleC(iterable $totals, array $map): array
    {
        $out = ['gross' => '0', 'returns' => '0', 'cogs' => '0', 'expenses' => [], 'meals_nondeductible' => '0', 'excluded' => '0', 'unmapped' => 0,
            'book' => ['income' => '0', 'cogs' => '0', 'expense' => '0']];
        foreach ($totals as $t) {
            $amt = Dec::norm((string) $t->total);
            $type = $t->account_type;
            if (isset($out['book'][$type])) {
                $out['book'][$type] = Dec::add($out['book'][$type], $amt);
            }
            $line = $map[$t->connection_id.'|'.$t->account_ref] ?? null;
            if ($line === null) {
                $out['unmapped']++;
                $line = match ($type) { 'income' => 'gross', 'cogs' => 'cogs', 'expense' => 'other', default => 'exclude' };
            }
            switch ($line) {
                case 'exclude':
                    $out['excluded'] = Dec::add($out['excluded'], $amt);
                    break;
                case 'gross':
                    $out['gross'] = Dec::add($out['gross'], $amt);
                    break;
                case 'returns':
                    // Contra-income accounts carry refunds as negative income; Schedule C line 2 is positive.
                    $out['returns'] = Dec::add($out['returns'], $type === 'income' ? Dec::neg($amt) : $amt);
                    break;
                case 'cogs':
                    $out['cogs'] = Dec::add($out['cogs'], $amt);
                    break;
                case 'meals':
                    $half = Dec::div($amt, '2');
                    $out['meals_nondeductible'] = Dec::add($out['meals_nondeductible'], $half);
                    $out['expenses']['meals'] = Dec::add($out['expenses']['meals'] ?? '0', $half);
                    break;
                default:
                    $out['expenses'][$line] = Dec::add($out['expenses'][$line] ?? '0', $amt);
            }
        }
        $out['book']['net'] = Dec::sub(Dec::sub($out['book']['income'], $out['book']['cogs']), $out['book']['expense']);
        $out['net'] = Dec::sub(Dec::sub(Dec::sub($out['gross'], $out['returns']), $out['cogs']), Dec::add('0', ...array_values($out['expenses'])));

        return $out;
    }

    public static function accountTotals(string $entityId, int $year): \Illuminate\Support\Collection
    {
        return DB::table('business_ledger_lines')->where('entity_id', $entityId)->whereNull('archived_at')->whereBetween('posted_on', ["{$year}-01-01", "{$year}-12-31"])
            ->selectRaw('connection_id, account_ref, account_type, SUM(amount) as total, COUNT(*) as n')->groupBy('connection_id', 'account_ref', 'account_type')->get();
    }

    /** Account maps of every connection feeding the entity, keyed "connection_id|account_id". */
    public static function accountMaps(iterable $connectionIds): array
    {
        $map = [];
        foreach (DB::table('source_connections')->whereIn('id', collect($connectionIds)->unique()->values())->get(['id', 'config']) as $c) {
            foreach ((array) ((json_decode((string) $c->config, true) ?: [])['account_map'] ?? []) as $acct => $line) {
                $map[$c->id.'|'.$acct] = $line;
            }
        }

        return $map;
    }

    private static function accountType(?array $a): string
    {
        return match (true) {
            ! $a => 'other',
            $a['type'] === 'income' => 'income',
            $a['type'] === 'expense' && ($a['subtype'] ?? null) === 'cogs' => 'cogs',
            $a['type'] === 'expense' => 'expense',
            default => 'other',
        };
    }

    private function pnl(RestClient $api, int $y): ?array
    {
        $r = $api->get('reports/profit-and-loss', ['from' => "{$y}-01-01", 'to' => "{$y}-12-31", 'basis' => 'cash'])['data'] ?? [];
        $t = is_array($r['totals'] ?? null) ? (array) end($r['totals']) : [];

        return $t ? ['income' => self::money($t['income'] ?? null), 'cogs' => self::money($t['cogs'] ?? null), 'expense' => self::money($t['expense'] ?? null), 'net' => self::money($t['net'] ?? null)] : null;
    }

    private function reconcile(object $c, RestClient $api, int $y): array
    {
        $pnl = $this->pnl($api, $y);
        $sum = fn (array $types) => Dec::norm((string) (DB::table('business_ledger_lines')->where('connection_id', $c->id)->whereNull('archived_at')
            ->whereIn('account_type', $types)->whereBetween('posted_on', ["{$y}-01-01", "{$y}-12-31"])->sum('amount') ?: '0'), 2);
        $legacy = ['income' => $sum(['income']), 'cogs' => $sum(['cogs']), 'expense' => $sum(['expense'])];
        $legacy['net'] = Dec::norm(Dec::sub(Dec::sub($legacy['income'], $legacy['cogs']), $legacy['expense']), 2);

        return ['year' => $y, 'finance' => $pnl, 'legacy' => $legacy, 'net_difference' => $pnl && $pnl['net'] !== null ? Dec::norm(Dec::sub($legacy['net'], $pnl['net']), 2) : null];
    }

    /** The linked entity; on first approval Legacy may create it from the Finance business profile. */
    public function targetEntity(object $c): ?object
    {
        $id = (json_decode((string) $c->config, true) ?: [])['entity_id'] ?? null;

        return $id ? DB::table('legal_entities')->where('workspace_id', $c->workspace_id)->where('id', $id)->whereNull('archived_at')->first() : null;
    }

    /** Called from approve(): create the entity in the connection's scope when none was chosen. */
    public function ensureEntity(object $c): string
    {
        if ($e = $this->targetEntity($c)) {
            return $e->id;
        }
        $cfg = json_decode((string) $c->config, true) ?: [];
        $biz = $cfg['business'] ?? (json_decode((string) $c->last_preview, true) ?: [])['business'] ?? null;
        if (! $biz) {
            throw ApiError::conflict('Run Test first so Legacy can read the business profile.');
        }
        if (! $this->ctx->can('governance', 'write', $c->scope_id)) {
            throw new ApiError('forbidden', 'Creating the business entity needs governance write access. Choose an existing entity instead.', 403);
        }
        [$kind, $class] = self::KINDS[$biz['entity_type']] ?? ['other', null];
        $id = (string) Str::ulid();
        DB::table('legal_entities')->insert(['id' => $id, 'workspace_id' => $c->workspace_id, 'scope_id' => $c->scope_id, 'name' => mb_substr($biz['legal_name'] ?: $biz['name'], 0, 255),
            'kind' => $kind, 'tax_classification' => $class, 'accounting_basis' => $biz['reporting_basis'] === 'accrual' ? 'accrual' : 'cash', 'status' => 'active',
            'notes' => 'Created from McNutt Finance. Record the owners so its profit reaches the right tax return.', 'created_by' => $this->ctx->user->id,
            'updated_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        Audit::log('entity.created_from_integration', 'legal_entity', $id, ['provider' => 'invoices'], scopeId: $c->scope_id);
        DB::table('source_connections')->where('id', $c->id)->update(['config' => json_encode(['entity_id' => $id] + $cfg), 'updated_at' => now()]);

        return $id;
    }

    public static function assertTarget(AccessContext $ctx, ?string $entityId): ?string
    {
        if ($entityId === null || $entityId === '') {
            return null;
        }
        $e = Str::isUlid($entityId) ? $ctx->constrain(DB::table('legal_entities'), 'governance', 'read')->where('id', $entityId)->whereNull('archived_at')->first() : null;
        if (! $e) {
            throw new ApiError('validation_failed', 'Choose a business entity you can see.', 422);
        }

        return $e->id;
    }

    private static function business(array $d): array
    {
        $b = (array) ($d['business'] ?? []);
        if (! isset($b['name']) || ! is_string($b['name'])) {
            throw new IntegrationError('unparseable', 'The Finance discovery response had no business profile.');
        }
        $type = is_string($b['entity_type'] ?? null) ? strtolower(trim($b['entity_type'])) : 'other';

        return ['id' => mb_substr((string) ($b['id'] ?? ''), 0, 40), 'name' => mb_substr($b['name'], 0, 120), 'legal_name' => mb_substr((string) ($b['legal_name'] ?? ''), 0, 160),
            'entity_type' => mb_substr($type, 0, 40), 'reporting_basis' => ($b['reporting_basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash',
            'books_closed_through' => is_string($b['books_closed_through'] ?? null) ? mb_substr($b['books_closed_through'], 0, 10) : null];
    }

    private static function money(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $v) ? Dec::norm($v, 2) : null;
    }
}
