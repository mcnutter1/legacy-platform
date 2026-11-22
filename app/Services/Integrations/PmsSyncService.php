<?php

namespace App\Services\Integrations;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Beach House PMS Family Office API (docs/BEACH_HOUSE_PMS_API.md) → property_stays / property_expenses of one property.
 * Upserts on external_id; rows a person has edited (updated_by set) keep their classification on re-sync;
 * rows missing from a full-window pull were deleted in the PMS and are archived.
 */
final class PmsSyncService
{
    public const SOURCE = 'beach_house_pms';

    private const EXPENSE_CATEGORIES = ['mortgage_interest', 'property_tax', 'insurance', 'repairs', 'maintenance', 'utilities', 'cleaning', 'management', 'supplies',
        'hoa', 'advertising', 'travel', 'legal_professional', 'platform_fees', 'other'];

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
        $api = self::client($c);
        $api->get('', ['resource' => 'ping']);
        $prop = $api->get('', ['resource' => 'property'])['data'] ?? [];

        return ['server' => ['name' => 'Beach House PMS', 'version' => 'API '.($prop['api_version'] ?? '1'), 'protocol' => 'rest'],
            'property' => array_intersect_key((array) $prop, array_flip(['external_id', 'name', 'kind', 'city', 'state', 'status'])),
            'tools' => [], 'tool_map' => null, 'missing' => []];
    }

    public function preview(object $c): array
    {
        $api = self::client($c);
        $property = $this->property($c);
        $remote = $api->get('', ['resource' => 'property'])['data'] ?? [];
        $years = [];
        foreach ($this->years() as $y) {
            $sum = $api->get('', ['resource' => 'summary', 'year' => $y])['data'] ?? [];
            $stays = $api->get('', ['resource' => 'stays', 'year' => $y])['data'] ?? [];
            $exp = $api->get('', ['resource' => 'expenses', 'year' => $y])['data'] ?? [];
            $known = DB::table('property_stays')->where('property_id', $property->id)->whereNotNull('external_id')->pluck('external_id')->flip();
            $years[] = ['year' => $y, 'summary' => $this->summaryView($sum), 'stays' => count($stays),
                'new_stays' => count(array_filter($stays, fn ($s) => ! isset($known[$s['external_id'] ?? '']))),
                'pending_skipped' => count(array_filter($stays, fn ($s) => ($s['kind'] ?? '') === 'guest' && empty($s['revenue_recognized']) && empty($s['cancelled']))),
                'expenses' => count($exp), 'undetermined' => count(array_filter($exp, fn ($e) => ($e['treatment'] ?? '') === 'undetermined'))];
        }
        $preview = ['ok' => true, 'kind' => 'property_manager', 'tool_map' => null, 'fetched_at' => now()->toIso8601String(),
            'remote_property' => array_intersect_key((array) $remote, array_flip(['external_id', 'name', 'kind', 'city', 'state', 'status', 'living_area_sqft'])),
            'property' => ['id' => $property->id, 'name' => $property->name], 'years' => $years, 'issues' => [],
            'totals' => ['accounts' => 0, 'holdings' => 0, 'transactions' => array_sum(array_column($years, 'stays')) + array_sum(array_column($years, 'expenses'))]];
        DB::table('source_connections')->where('id', $c->id)->update(['last_preview' => json_encode($preview), 'last_sync_at' => now(), 'last_error' => null,
            'status' => $c->approved_at ? $c->status : 'needs_review', 'updated_at' => now()]);

        return ['preview' => true, 'years' => count($years)];
    }

    public function sync(object $c): array
    {
        if (! $c->approved_at) {
            throw new IntegrationError('not_approved', 'Approve the connection before syncing.');
        }
        $api = self::client($c);
        $property = $this->property($c);
        if (! $this->ctx->can('real_estate', 'write', $property->scope_id)) {
            throw new IntegrationError('forbidden', 'You no longer have write access to this property.');
        }
        $stats = ['stays_created' => 0, 'stays_updated' => 0, 'stays_archived' => 0, 'pending_skipped' => 0, 'expenses_created' => 0, 'expenses_updated' => 0,
            'expenses_archived' => 0, 'undetermined' => 0, 'linked_to_monarch' => 0, 'reconciliation' => []];
        foreach ($this->years() as $y) {
            $stays = $api->get('', ['resource' => 'stays', 'year' => $y])['data'] ?? [];
            $expenses = $api->get('', ['resource' => 'expenses', 'year' => $y])['data'] ?? [];
            $summary = $api->get('', ['resource' => 'summary', 'year' => $y])['data'] ?? [];
            DB::transaction(function () use ($c, $property, $y, $stays, $expenses, &$stats) {
                $this->upsertStays($c, $property, $y, $stays, $stats);
                $this->upsertExpenses($c, $property, $y, $expenses, $stats);
            });
            $stats['reconciliation'][] = $this->reconcile($property, $y, $summary);
        }
        DB::table('source_connections')->where('id', $c->id)->update(['last_sync_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'status' => 'active',
            'last_sync_stats' => json_encode($stats), 'updated_at' => now()]);
        Audit::log('integration.synced', 'source_connection', $c->id, array_diff_key($stats, ['reconciliation' => 1]), scopeId: $c->scope_id);

        return $stats;
    }

    private function upsertStays(object $c, object $property, int $year, array $rows, array &$stats): void
    {
        $seen = [];
        $existing = DB::table('property_stays')->where('property_id', $property->id)->whereNotNull('external_id')->get()->keyBy('external_id');
        foreach ($rows as $r) {
            $ext = self::externalId($r['external_id'] ?? null, ['booking', 'block']);
            $kind = (string) ($r['kind'] ?? '');
            $in = self::date($r['check_in'] ?? null);
            $out = self::date($r['check_out'] ?? null);
            if (! $ext || ! $in || ! $out || $out <= $in || ! in_array($kind, ['guest', 'personal', 'owner_maintenance', 'blocked'], true)) {
                continue;
            }
            $cancelled = (bool) ($r['cancelled'] ?? false);
            // Approved-but-unpaid bookings are not revenue yet (matches the PMS P&L).
            if ($kind === 'guest' && ! $cancelled && empty($r['revenue_recognized'])) {
                $stats['pending_skipped']++;

                continue;
            }
            $seen[] = $ext;
            $vals = ['check_in' => $in, 'check_out' => $out, 'cancelled' => $cancelled, 'channel' => mb_substr((string) ($r['channel'] ?? ''), 0, 32) ?: null,
                'gross_rent' => self::money($r['gross_rent'] ?? null), 'cleaning_fee' => self::money($r['cleaning_fee'] ?? null),
                'platform_fee' => self::money($r['platform_fee'] ?? null), 'taxes_collected' => self::money($r['taxes_collected'] ?? null),
                'payout' => self::money($r['payout'] ?? null), 'refund' => ($m = self::money($r['refund'] ?? null)) !== null ? Dec::abs($m) : null,
                'damage_recovery' => self::money($r['deposit_kept'] ?? null), 'source' => self::SOURCE, 'source_ref' => $c->id, 'archived_at' => null, 'updated_at' => now()];
            $row = $existing[$ext] ?? null;
            if ($row) {
                // A person reclassified this stay (family, donated…): keep their kind.
                if ($row->updated_by === null) {
                    $vals['kind'] = $kind;
                }
                DB::table('property_stays')->where('id', $row->id)->update($vals + ['edit_version' => $row->edit_version + 1]);
                $stats['stays_updated']++;
            } else {
                DB::table('property_stays')->insert($vals + ['id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id,
                    'property_id' => $property->id, 'kind' => $kind, 'external_id' => $ext, 'value_state' => 'actual', 'created_by' => $this->ctx->user->id,
                    'created_at' => now(), 'edit_version' => 1]);
                $stats['stays_created']++;
            }
        }
        $stats['stays_archived'] += DB::table('property_stays')->where('property_id', $property->id)->where('source', self::SOURCE)->whereNull('archived_at')
            ->whereNotNull('external_id')->whereNotIn('external_id', $seen ?: ['-'])
            ->where('check_out', '>', "{$year}-01-01")->where('check_in', '<=', "{$year}-12-31")
            ->update(['archived_at' => now(), 'updated_at' => now()]);
    }

    private function upsertExpenses(object $c, object $property, int $year, array $rows, array &$stats): void
    {
        $seen = [];
        $existing = DB::table('property_expenses')->where('property_id', $property->id)->whereNotNull('external_id')->get()->keyBy('external_id');
        foreach ($rows as $r) {
            $ext = self::externalId($r['external_id'] ?? null, ['expense']);
            $on = self::date($r['incurred_on'] ?? null);
            $amount = self::money($r['amount'] ?? null);
            if (! $ext || ! $on || $amount === null) {
                continue;
            }
            $seen[] = $ext;
            $category = in_array($r['suggested_category'] ?? '', self::EXPENSE_CATEGORIES, true) ? $r['suggested_category'] : 'other';
            $treatment = ($r['treatment'] ?? '') === 'expense' ? 'expense' : 'undetermined';
            $notes = mb_substr(trim(implode(' · ', array_filter([(string) ($r['category'] ?? ''), (string) ($r['vendor'] ?? ''), (string) ($r['description'] ?? '')]))), 0, 255) ?: null;
            $txId = null;
            if (($r['import_source'] ?? null) === 'monarch' && ! empty($r['import_reference'])) {
                $txId = DB::table('transactions')->where('workspace_id', $property->workspace_id)->where('external_id', 'monarch:'.mb_substr((string) $r['import_reference'], 0, 180))->value('id');
                $txId && $stats['linked_to_monarch']++;
            }
            $vals = ['incurred_on' => $on, 'amount' => Dec::abs($amount), 'notes' => $notes, 'source' => self::SOURCE, 'source_ref' => $c->id, 'archived_at' => null, 'updated_at' => now()]
                + ($txId ? ['transaction_id' => $txId] : []);
            $row = $existing[$ext] ?? null;
            if ($row) {
                if ($row->updated_by === null) {
                    $vals += ['category' => $category, 'treatment' => $treatment];
                }
                DB::table('property_expenses')->where('id', $row->id)->update($vals + ['edit_version' => $row->edit_version + 1]);
                $stats['expenses_updated']++;
                $treatment = $row->updated_by === null ? $treatment : $row->treatment;
            } else {
                DB::table('property_expenses')->insert($vals + ['id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id,
                    'property_id' => $property->id, 'category' => $category, 'treatment' => $treatment, 'external_id' => $ext, 'value_state' => 'actual',
                    'created_by' => $this->ctx->user->id, 'created_at' => now(), 'edit_version' => 1]);
                $stats['expenses_created']++;
            }
            if ($treatment === 'undetermined') {
                $stats['undetermined']++;
            }
        }
        $stats['expenses_archived'] += DB::table('property_expenses')->where('property_id', $property->id)->where('source', self::SOURCE)->whereNull('archived_at')
            ->whereNotNull('external_id')->whereNotIn('external_id', $seen ?: ['-'])->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])
            ->update(['archived_at' => now(), 'updated_at' => now()]);
    }

    /** Compare what Legacy now holds for the year with the PMS P&L (revenue by check-in year, confirmed/completed). */
    private function reconcile(object $property, int $year, array $summary): array
    {
        $stays = DB::table('property_stays')->where('property_id', $property->id)->where('source', self::SOURCE)->whereNull('archived_at')
            ->where('kind', 'guest')->where('cancelled', false)->whereBetween('check_in', ["{$year}-01-01", "{$year}-12-31"])->get();
        $revenue = '0';
        foreach ($stays as $s) {
            $revenue = Dec::add($revenue, Dec::norm($s->gross_rent ?? '0'), Dec::norm($s->cleaning_fee ?? '0'), Dec::norm($s->damage_recovery ?? '0'));
        }
        $pms = self::money($summary['income']['gross_revenue'] ?? null);
        $expenses = (string) DB::table('property_expenses')->where('property_id', $property->id)->where('source', self::SOURCE)->whereNull('archived_at')
            ->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])->sum('amount');

        return ['year' => $year, 'pms_gross_revenue' => $pms, 'legacy_gross_revenue' => Dec::norm($revenue, 2),
            'revenue_difference' => $pms !== null ? Dec::norm(Dec::sub($revenue, $pms), 2) : null,
            'pms_expenses' => self::money($summary['expenses']['total'] ?? null), 'legacy_expenses' => Dec::norm($expenses ?: '0', 2),
            'pms_days' => is_array($summary['days'] ?? null) ? array_map('intval', array_intersect_key($summary['days'], array_flip(['rental', 'personal', 'owner_maintenance', 'blocked', 'available_unrented']))) : null];
    }

    private function summaryView(array $s): array
    {
        return ['gross_revenue' => self::money($s['income']['gross_revenue'] ?? null), 'taxes_collected' => self::money($s['income']['taxes_collected'] ?? null),
            'expenses' => self::money($s['expenses']['total'] ?? null), 'net_operating_income' => self::money($s['net_operating_income'] ?? null),
            'bookings' => (int) ($s['income']['booking_count'] ?? 0),
            'days' => is_array($s['days'] ?? null) ? array_map('intval', array_intersect_key($s['days'], array_flip(['rental', 'personal', 'owner_maintenance', 'blocked', 'available_unrented']))) : null];
    }

    private function property(object $c): object
    {
        $id = (json_decode((string) $c->config, true) ?: [])['property_id'] ?? null;
        $p = $id ? DB::table('properties')->where('workspace_id', $c->workspace_id)->where('id', $id)->whereNull('archived_at')->first() : null;
        if (! $p) {
            throw new IntegrationError('unsupported', 'Choose which property this PMS feeds.');
        }

        return $p;
    }

    /** Prior year stays open until it is closed out for taxes; volumes are small. */
    private function years(): array
    {
        $y = (int) now()->format('Y');

        return [$y - 1, $y];
    }

    private static function externalId(mixed $v, array $prefixes): ?string
    {
        return is_string($v) && preg_match('/^('.implode('|', $prefixes).'):[A-Za-z0-9_-]{1,40}$/', $v) ? $v : null;
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }

    private static function money(mixed $v): ?string
    {
        return (is_string($v) || is_int($v)) && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', (string) $v) ? Dec::norm((string) $v) : null;
    }

    public static function assertTarget(AccessContext $ctx, ?string $propertyId): string
    {
        $p = $propertyId && Str::isUlid($propertyId) ? $ctx->constrain(DB::table('properties'), 'real_estate', 'write')->where('id', $propertyId)->whereNull('archived_at')->first() : null;
        if (! $p) {
            throw new ApiError('validation_failed', 'Choose a property you can edit.', 422);
        }

        return $p->id;
    }
}
