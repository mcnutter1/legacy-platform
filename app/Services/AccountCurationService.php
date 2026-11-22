<?php

namespace App\Services;

use App\Services\Integrations\SyncService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Hide or ignore accounts (aggregates, duplicates, not ours) and find likely duplicates among existing accounts. */
final class AccountCurationService
{
    public const VISIBILITY = ['visible', 'hidden', 'ignored'];

    public const REASONS = ['aggregate', 'duplicate', 'not_ours', 'closed', 'other'];

    public function __construct(private readonly AccessContext $ctx) {}

    public function setVisibility(string $accountId, string $visibility, ?string $reason): array
    {
        if (! in_array($visibility, self::VISIBILITY, true) || ($reason !== null && ! in_array($reason, self::REASONS, true))) {
            throw new ApiError('validation_failed', 'Unknown visibility or reason.', 422);
        }
        $a = $this->account($accountId);
        DB::table('accounts')->where('id', $a->id)->update(['visibility' => $visibility, 'visibility_reason' => $visibility === 'visible' ? null : $reason,
            'visibility_changed_at' => now(), 'edit_version' => $a->edit_version + 1, 'updated_by' => $this->ctx->user->id, 'updated_at' => now()]);
        if ($visibility !== 'visible') {
            // An excluded account no longer needs its source disagreements reviewed.
            DB::table('source_discrepancies')->where('entity_type', 'account')->where('entity_id', $a->id)->where('status', 'open')
                ->update(['status' => 'ignored', 'resolution' => 'Account '.$visibility, 'resolved_by' => $this->ctx->user->id, 'resolved_at' => now(), 'updated_at' => now()]);
        }
        Audit::log('accounts.visibility_changed', 'accounts', $a->id, ['visibility' => $visibility, 'reason' => $reason], scopeId: $a->scope_id);

        return ['account_id' => $a->id, 'visibility' => $visibility, 'visibility_reason' => $visibility === 'visible' ? null : $reason];
    }

    /** Pairs of existing accounts that look like the same account, best first. */
    public function duplicates(?string $forAccountId = null): array
    {
        $accounts = $this->ctx->constrain(DB::table('accounts'), 'ledger', 'write')->whereNull('archived_at')->where('visibility', '!=', 'ignored')->get()->keyBy('id');
        $latest = DB::table('balance_snapshots')->whereIn('account_id', $accounts->keys())->whereNull('archived_at')->orderByDesc('as_of')->get()
            ->groupBy('account_id')->map(fn ($g) => ['balance' => $g->first()->balance, 'as_of' => $g->first()->as_of]);
        $sources = DB::table('external_links')->where('entity_type', 'account')->whereIn('entity_id', $accounts->keys())->whereNull('archived_at')->get()->groupBy('entity_id');
        $labels = DB::table('source_connections')->whereIn('id', $sources->flatten()->pluck('connection_id')->unique())->get(['id', 'label', 'provider'])->keyBy('id');
        $pairKey = fn ($x, $y) => strcmp($x, $y) < 0 ? "{$x}|{$y}" : "{$y}|{$x}";
        $dupes = DB::table('source_discrepancies')->where('workspace_id', $this->ctx->workspace->id)->where('entity_type', 'account')->where('kind', 'possible_duplicate')
            ->whereIn('status', ['open', 'ignored'])->whereNotNull('candidate_id')->get();
        $dismissed = $dupes->where('status', 'ignored')->mapWithKeys(fn ($d) => [$pairKey($d->entity_id, $d->candidate_id) => true]);
        $flagged = $dupes->where('status', 'open')->mapWithKeys(fn ($d) => [$pairKey($d->entity_id, $d->candidate_id) => $d->id]);
        $scorer = new SyncService($this->ctx);
        $none = (object) ['id' => ''];
        $list = $accounts->values();
        $out = [];
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                [$x, $y] = [$list[$i], $list[$j]];
                if ($forAccountId && $x->id !== $forAccountId && $y->id !== $forAccountId) {
                    continue;
                }
                $key = $pairKey($x->id, $y->id);
                if (isset($dismissed[$key])) {
                    continue;
                }
                [$score, $why] = $scorer->score(['external_id' => '', 'mask' => $x->mask, 'institution' => $x->institution, 'name' => $x->name, 'kind' => $x->kind,
                    'balance' => $latest[$x->id]['balance'] ?? null], $y, $latest[$y->id]['balance'] ?? null, $none);
                if ($score < ($forAccountId ? 2 : 5) && ! isset($flagged[$key])) {
                    continue;
                }
                $conns = fn ($id) => ($sources[$id] ?? collect())->pluck('connection_id')->unique()->values()->all();
                $same = array_values(array_intersect($conns($x->id), $conns($y->id)));
                $view = fn ($a) => ['id' => $a->id, 'name' => $a->name, 'institution' => $a->institution, 'mask' => $a->mask, 'kind' => $a->kind, 'visibility' => $a->visibility,
                    'balance' => $latest[$a->id]['balance'] ?? null, 'as_of' => $latest[$a->id]['as_of'] ?? null, 'authoritative_connection_id' => $a->connection_id,
                    'sources' => array_map(fn ($cid) => ['connection_id' => $cid, 'label' => $labels[$cid]->label ?? null, 'provider' => $labels[$cid]->provider ?? null], $conns($a->id))];
                $out[] = ['key' => $key, 'score' => $score, 'reasons' => $why, 'flagged' => isset($flagged[$key]), 'conflict_id' => $flagged[$key] ?? null,
                    'same_source' => (bool) $same, 'a' => $view($x), 'b' => $view($y)];
            }
        }
        usort($out, fn ($p, $q) => [$q['flagged'], $q['score']] <=> [$p['flagged'], $p['score']]);

        return array_slice($out, 0, 100);
    }

    /** Remember that two accounts are different so they stop being suggested. */
    public function notDuplicate(string $accountId, string $otherId): array
    {
        $a = $this->account($accountId);
        $b = $this->account($otherId);
        $existing = DB::table('source_discrepancies')->where('entity_type', 'account')->where('kind', 'possible_duplicate')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('entity_id', $a->id)->where('candidate_id', $b->id))->orWhere(fn ($w) => $w->where('entity_id', $b->id)->where('candidate_id', $a->id)))
            ->whereIn('status', ['open', 'ignored'])->get();
        if ($existing->isNotEmpty()) {
            DB::table('source_discrepancies')->whereIn('id', $existing->pluck('id'))->update(['status' => 'ignored', 'resolution' => 'Not a duplicate',
                'resolved_by' => $this->ctx->user->id, 'resolved_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('source_discrepancies')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ctx->workspace->id, 'scope_id' => $a->scope_id, 'entity_type' => 'account',
                'entity_id' => $a->id, 'kind' => 'possible_duplicate', 'field' => null, 'observed' => json_encode([['account_id' => $b->id, 'value' => $b->name]]), 'difference' => null,
                'candidate_id' => $b->id, 'status' => 'ignored', 'resolution' => 'Not a duplicate', 'resolved_by' => $this->ctx->user->id, 'resolved_at' => now(),
                'last_seen_at' => now(), 'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        }
        Audit::log('accounts.not_duplicate', 'accounts', $a->id, ['other' => $b->id], scopeId: $a->scope_id);

        return ['account_id' => $a->id, 'other_account_id' => $b->id];
    }

    private function account(string $id): object
    {
        $a = Str::isUlid($id) ? $this->ctx->constrain(DB::table('accounts'), 'ledger', 'write')->where('id', $id)->whereNull('archived_at')->first() : null;
        if (! $a) {
            throw ApiError::notFound();
        }

        return $a;
    }
}
