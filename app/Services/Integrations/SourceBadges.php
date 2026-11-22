<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

/** Adds `_sources` (where each record came from) to already-authorized resource rows, for provenance icons. */
final class SourceBadges
{
    public const TYPES = ['accounts', 'holdings', 'balance_snapshots', 'transactions', 'lots'];

    /** @param list<array> $rows presented rows (already visible to the caller) */
    public static function attach(string $type, array $rows): array
    {
        if (! in_array($type, self::TYPES, true) || ! $rows) {
            return $rows;
        }
        $ids = array_column($rows, 'id');
        if ($type === 'accounts') {
            $links = DB::table('external_links')->where('entity_type', 'account')->whereIn('entity_id', $ids)->whereNull('archived_at')->get()->groupBy('entity_id');
            $acct = DB::table('accounts')->whereIn('id', $ids)->pluck('connection_id', 'id');
            $conns = self::connections(array_merge($links->flatten()->pluck('connection_id')->all(), $acct->filter()->values()->all()));
            foreach ($rows as &$r) {
                $authority = $acct[$r['id']] ?? null;
                $out = [];
                foreach ($links[$r['id']] ?? [] as $l) {
                    $out[$l->connection_id] = self::badge($l->provider, $conns[$l->connection_id] ?? null, $l->connection_id === $authority, $l->last_seen_at);
                }
                if ($authority && ! isset($out[$authority]) && isset($conns[$authority])) {
                    $out[$authority] = self::badge($conns[$authority]->provider, $conns[$authority], true, $conns[$authority]->last_success_at);
                }
                $r['_sources'] = $out ? array_values($out) : [self::badge('manual', null, true, null)];
            }

            return $rows;
        }
        $meta = DB::table($type)->whereIn('id', $ids)->get(['id', 'source', 'source_ref'])->keyBy('id');
        $refs = $meta->pluck('source_ref')->filter()->unique()->values()->all();
        $batches = DB::table('import_batches')->whereIn('id', $refs)->pluck('connection_id', 'id');
        $conns = self::connections(array_merge($refs, $batches->filter()->values()->all()));
        foreach ($rows as &$r) {
            $m = $meta[$r['id']] ?? null;
            $src = $m->source ?? 'manual';
            $connId = $m && $m->source_ref ? (isset($conns[$m->source_ref]) ? $m->source_ref : ($batches[$m->source_ref] ?? null)) : null;
            $r['_sources'] = [self::badge($src, $connId ? ($conns[$connId] ?? null) : null, true, null, $connId === null && $src !== 'manual' ? 'file' : null)];
        }

        return $rows;
    }

    private static function connections(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? DB::table('source_connections')->whereIn('id', $ids)->get(['id', 'provider', 'label', 'mode', 'last_success_at'])->keyBy('id')->all() : [];
    }

    private static function badge(string $provider, ?object $conn, bool $authoritative, ?string $seen, ?string $mode = null): array
    {
        return ['provider' => $provider, 'connection_id' => $conn->id ?? null, 'label' => $conn->label ?? null, 'mode' => $mode ?? ($conn->mode ?? ($provider === 'manual' ? 'manual' : 'file')),
            'authoritative' => $authoritative, 'last_seen_at' => $seen];
    }
}
