<?php

namespace App\Services;

use App\Jobs\JobService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import workbench: stage → normalize (runner) → match/dedupe → review → commit → reverse.
 * Overlapping imports enrich canonical records; replaying the same file is a no-op.
 */
final class ImportService
{
    public function __construct(private readonly VaultService $vault, private readonly JobService $jobs) {}

    public function upload(AccessContext $ctx, string $bytes, string $filename, array $data): array
    {
        $provider = (string) ($data['provider'] ?? '');
        if (! in_array($provider, ['monarch', 'snowball', 'generic_csv', 'invoices', 'property_manager'], true)) {
            throw new ApiError('validation_failed', 'Unsupported import provider.');
        }
        $scopeId = (string) ($data['scope_id'] ?? '');
        if (! $ctx->can('ledger', 'write', $scopeId)) {
            throw ApiError::forbidden();
        }
        $profileId = $data['profile_id'] ?? null;
        if ($profileId && ! DB::table('import_profiles')->where('workspace_id', $ctx->workspace->id)->where('id', $profileId)->exists()) {
            throw ApiError::notFound('Profile not found.');
        }
        $stored = $this->vault->store($ctx, $bytes, ['scope_id' => $scopeId, 'filename' => $filename, 'title' => 'Import: '.$filename,
            'doc_type' => 'import_file', 'origin' => 'import', 'allow_duplicate' => true]);
        $batchId = (string) Str::ulid();
        DB::table('import_batches')->insert([
            'id' => $batchId, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $scopeId, 'provider' => $provider,
            'connection_id' => $data['connection_id'] ?? null, 'profile_id' => $profileId, 'document_id' => $stored['document_id'],
            'file_sha256' => $stored['sha256'], 'status' => 'received', 'created_by' => $ctx->user->id, 'updated_by' => $ctx->user->id,
            'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        $job = $this->jobs->enqueue($ctx, 'import.normalize', ['batch_id' => $batchId], 'import_batch', $batchId, $scopeId);
        DB::table('import_batches')->where('id', $batchId)->update(['job_id' => $job->id]);
        Audit::log('import.received', 'import_batch', $batchId, ['provider' => $provider], scopeId: $scopeId);

        return ['batch_id' => $batchId, 'job' => $job->present()];
    }

    /** Called by the normalize handler inside the commit transaction. Idempotent per batch. */
    public function stage(object $batch, array $result): array
    {
        DB::table('import_rows')->where('batch_id', $batch->id)->delete();
        if (! ($result['ok_schema'] ?? false)) {
            DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'failed', 'stats' => json_encode([
                'schema_drift' => $result['schema_drift'] ?? null, 'suggested_mapping' => $result['suggested_mapping'] ?? [], 'error' => $result['error'] ?? 'Schema mismatch',
            ]), 'updated_at' => now()]);

            return ['status' => 'failed'];
        }
        $counts = ['new' => 0, 'duplicate' => 0, 'ambiguous' => 0, 'needs_review' => 0, 'enrich' => 0];
        $now = now();
        $inserts = [];
        foreach ($result['rows'] as $r) {
            $n = $r['normalized'];
            $match = null;
            $matched = null;
            $state = $r['state'];
            $issues = $r['issues'];
            $accountId = $n['account_id'] ?? null;
            if ($accountId && ! DB::table('accounts')->where('workspace_id', $batch->workspace_id)->where('id', $accountId)->exists()) {
                $issues[] = 'unknown_account';
                $state = 'needs_review';
            }
            $dupe = DB::table('transactions')->where('workspace_id', $batch->workspace_id)->where('fingerprint', $r['fingerprint'])->whereNull('archived_at')->value('id');
            if ($dupe) {
                $match = 'duplicate';
                $matched = $dupe;
                $state = 'reconciled';
            } elseif ($accountId && ! $issues && isset($n['amount'], $n['posted_on'])) {
                $from = date('Y-m-d', strtotime($n['posted_on'].' -3 days'));
                $to = date('Y-m-d', strtotime($n['posted_on'].' +3 days'));
                $candidates = DB::table('transactions')->where('workspace_id', $batch->workspace_id)->where('account_id', $accountId)
                    ->whereBetween('posted_on', [$from, $to])->where('amount', $n['amount'])->whereNull('archived_at')->limit(3)->pluck('id');
                if ($candidates->count() === 1 && $batch->provider !== DB::table('transactions')->where('id', $candidates[0])->value('source')) {
                    $match = 'enrich';
                    $matched = $candidates[0];
                    $state = 'mapped';
                } elseif ($candidates->count() >= 1) {
                    $match = 'ambiguous';
                    $state = 'needs_review';
                    $issues[] = 'possible_duplicate';
                } else {
                    $match = 'new';
                }
            } else {
                $match = $issues ? null : 'new';
            }
            if ($match) {
                $counts[$match]++;
            }
            if ($state === 'needs_review') {
                $counts['needs_review']++;
            }
            $inserts[] = [
                'id' => (string) Str::ulid(), 'workspace_id' => $batch->workspace_id, 'batch_id' => $batch->id, 'row_no' => $r['row_no'],
                'state' => $state, 'external_id' => $r['external_id'], 'fingerprint' => $r['fingerprint'], 'normalized' => json_encode($n),
                'issues' => json_encode(array_values(array_unique($issues))), 'match_type' => $match, 'matched_transaction_id' => $matched,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($inserts) >= 500) {
                DB::table('import_rows')->insert($inserts);
                $inserts = [];
            }
        }
        if ($inserts) {
            DB::table('import_rows')->insert($inserts);
        }
        DB::table('import_batches')->where('id', $batch->id)->update([
            'status' => 'previewed', 'row_count' => count($result['rows']),
            'stats' => json_encode(($result['stats'] ?? []) + ['matching' => $counts, 'schema_drift' => $result['schema_drift'] ?? null]),
            'coverage' => json_encode($result['coverage'] ?? null), 'updated_at' => now(),
        ]);

        return ['status' => 'previewed', 'rows' => count($result['rows']), 'matching' => $counts];
    }

    public function batch(AccessContext $ctx, string $id, string $action = 'read'): object
    {
        $b = DB::table('import_batches')->where('workspace_id', $ctx->workspace->id)->where('id', $id)->first();
        if (! $b || ! $ctx->can('ledger', $action, $b->scope_id)) {
            throw ApiError::notFound();
        }

        return $b;
    }

    public function rows(AccessContext $ctx, string $batchId, ?string $state, int $limit, ?string $after): array
    {
        $b = $this->batch($ctx, $batchId);
        $q = DB::table('import_rows')->where('batch_id', $b->id)->orderBy('row_no');
        if ($state) {
            $q->where('state', $state);
        }
        if ($after) {
            $q->where('row_no', '>', (int) $after);
        }
        $rows = $q->limit(min(500, max(1, $limit)))->get()->map(fn ($r) => [
            'id' => $r->id, 'row_no' => $r->row_no, 'state' => $r->state, 'match_type' => $r->match_type,
            'matched_transaction_id' => $r->matched_transaction_id, 'transaction_id' => $r->transaction_id,
            'normalized' => json_decode((string) $r->normalized, true), 'issues' => json_decode((string) $r->issues, true),
        ]);

        return ['data' => $rows, 'next_cursor' => $rows->count() ? (string) $rows->last()['row_no'] : null];
    }

    /** Resolve a review row: accept as new, mark as a duplicate of an existing transaction, map account, or skip. */
    public function resolveRow(AccessContext $ctx, string $batchId, string $rowId, array $data): array
    {
        $b = $this->batch($ctx, $batchId, 'write');
        if ($b->status !== 'previewed') {
            throw ApiError::conflict('Only previewed batches can be edited.');
        }
        $row = DB::table('import_rows')->where('batch_id', $b->id)->where('id', $rowId)->first();
        if (! $row) {
            throw ApiError::notFound();
        }
        $n = json_decode((string) $row->normalized, true);
        $issues = json_decode((string) $row->issues, true) ?: [];
        $action = $data['action'] ?? '';
        $update = ['updated_at' => now()];
        switch ($action) {
            case 'accept_new':
                if (! empty($data['account_id'])) {
                    if (! DB::table('accounts')->where('workspace_id', $ctx->workspace->id)->where('id', $data['account_id'])->exists()) {
                        throw ApiError::notFound('Account not found.');
                    }
                    $n['account_id'] = $data['account_id'];
                }
                if (empty($n['account_id']) || empty($n['posted_on']) || ! isset($n['amount'])) {
                    throw new ApiError('validation_failed', 'Row needs an account, date and amount before it can be accepted.');
                }
                $update += ['state' => 'mapped', 'match_type' => 'new', 'normalized' => json_encode($n), 'issues' => json_encode(array_values(array_diff($issues, ['unmapped_account', 'possible_duplicate', 'unknown_account'])))];
                break;
            case 'duplicate_of':
                $tx = DB::table('transactions')->where('workspace_id', $ctx->workspace->id)->where('id', $data['transaction_id'] ?? '')->first();
                if (! $tx || ! $ctx->can('ledger', 'read', $tx->scope_id)) {
                    throw ApiError::notFound('Transaction not found.');
                }
                $update += ['state' => 'reconciled', 'match_type' => 'duplicate', 'matched_transaction_id' => $tx->id];
                break;
            case 'skip':
                $update += ['state' => 'superseded', 'match_type' => 'skipped'];
                break;
            default:
                throw new ApiError('validation_failed', 'Unknown action.');
        }
        DB::table('import_rows')->where('id', $row->id)->update($update);
        Audit::log('import.row_resolved', 'import_batch', $b->id, ['row' => $row->row_no, 'action' => $action], scopeId: $b->scope_id);

        return ['row_id' => $row->id, 'state' => $update['state']];
    }

    public function commit(AccessContext $ctx, string $batchId): array
    {
        $b = $this->batch($ctx, $batchId, 'write');
        if ($b->status === 'committed') {
            return ['status' => 'committed', 'created' => 0, 'replay' => true];
        }
        if ($b->status !== 'previewed') {
            throw ApiError::conflict('Batch is not ready to commit.');
        }
        if (DB::table('import_rows')->where('batch_id', $b->id)->where('state', 'needs_review')->exists()) {
            throw ApiError::conflict('Resolve every row that needs review before committing.');
        }
        $profile = $b->profile_id ? DB::table('import_profiles')->find($b->profile_id) : null;
        $rules = $profile ? (json_decode((string) $profile->rules, true) ?: []) : [];

        return DB::transaction(function () use ($ctx, $b, $rules) {
            $locked = DB::table('import_batches')->where('id', $b->id)->lockForUpdate()->first();
            if ($locked->status !== 'previewed') {
                throw ApiError::conflict('Batch changed during commit.');
            }
            $created = 0;
            $enriched = 0;
            foreach (DB::table('import_rows')->where('batch_id', $b->id)->where('state', 'mapped')->orderBy('row_no')->cursor() as $row) {
                $n = json_decode((string) $row->normalized, true);
                $account = DB::table('accounts')->where('workspace_id', $ctx->workspace->id)->where('id', $n['account_id'] ?? '')->first();
                if (! $account || ! $ctx->can('ledger', 'write', $account->scope_id)) {
                    throw ApiError::forbidden('A row targets an account you cannot write to.');
                }
                if ($row->match_type === 'enrich' && $row->matched_transaction_id) {
                    // Enrich the canonical record without posting a second ledger event.
                    $tx = DB::table('transactions')->find($row->matched_transaction_id);
                    DB::table('transactions')->where('id', $tx->id)->update(array_filter([
                        'merchant' => $tx->merchant ?: ($n['merchant'] ?? null), 'category' => $tx->category ?: ($n['category'] ?? null),
                        'notes' => $tx->notes ?: ($n['notes'] ?? null), 'tags' => $tx->tags ?: (! empty($n['tags']) ? json_encode($n['tags']) : null),
                        'edit_version' => $tx->edit_version + 1, 'updated_at' => now(),
                    ], fn ($v) => $v !== null));
                    DB::table('import_rows')->where('id', $row->id)->update(['state' => 'reconciled', 'transaction_id' => $tx->id]);
                    $enriched++;
                    continue;
                }
                $txId = (string) Str::ulid();
                $instrumentId = null;
                if (! empty($n['symbol'])) {
                    $instrumentId = DB::table('instruments')->where('workspace_id', $ctx->workspace->id)->where('symbol', $n['symbol'])->value('id');
                    if (! $instrumentId) {
                        $instrumentId = (string) Str::ulid();
                        DB::table('instruments')->insert(['id' => $instrumentId, 'workspace_id' => $ctx->workspace->id, 'symbol' => $n['symbol'],
                            'kind' => 'equity', 'currency' => $n['currency'] ?? 'USD', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                DB::table('transactions')->insert([
                    'id' => $txId, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $account->scope_id, 'account_id' => $account->id,
                    'posted_on' => $n['posted_on'], 'amount' => Dec::norm($n['amount'] ?? '0'), 'currency' => $n['currency'] ?? $account->currency,
                    'kind' => $n['kind'] ?? 'other', 'description' => mb_substr((string) ($n['description'] ?? ''), 0, 255) ?: null,
                    'merchant' => $n['merchant'] ?? null, 'category' => $n['category'] ?? null, 'instrument_id' => $instrumentId,
                    'quantity' => isset($n['quantity']) ? Dec::norm($n['quantity'], 10) : null, 'price' => isset($n['price']) ? Dec::norm($n['price'], 8) : null,
                    'fees' => isset($n['fees']) ? Dec::norm($n['fees']) : null, 'fingerprint' => $row->fingerprint, 'external_id' => $row->external_id,
                    'source' => $b->provider, 'source_ref' => $b->id, 'value_state' => 'actual', 'batch_id' => $b->id,
                    'tags' => ! empty($n['tags']) ? json_encode($n['tags']) : null, 'notes' => $n['notes'] ?? null,
                    'tax_candidate' => (bool) (($rules['tax_candidate_categories'] ?? null) && in_array($n['category'] ?? '', $rules['tax_candidate_categories'], true)),
                    'created_by' => $ctx->user->id, 'updated_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
                if ($instrumentId && in_array($n['kind'] ?? '', ['buy', 'reinvest'], true) && ! empty($n['quantity'])) {
                    DB::table('lots')->insert([
                        'id' => (string) Str::ulid(), 'workspace_id' => $ctx->workspace->id, 'scope_id' => $account->scope_id, 'account_id' => $account->id,
                        'instrument_id' => $instrumentId, 'acquired_on' => $n['posted_on'], 'quantity' => Dec::norm($n['quantity'], 10),
                        'remaining_quantity' => Dec::norm($n['quantity'], 10), 'cost_basis' => Dec::abs(Dec::norm($n['amount'])),
                        'currency' => $n['currency'] ?? 'USD', 'source_transaction_id' => $txId, 'origin' => $n['kind'] === 'reinvest' ? 'reinvest' : 'purchase',
                        'source' => $b->provider, 'source_ref' => $b->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                    ]);
                } elseif ($instrumentId && ($n['kind'] ?? '') === 'sell' && ! empty($n['quantity'])) {
                    app(PortfolioService::class)->relieveLots($ctx->workspace->id, $account->id, $instrumentId, Dec::norm($n['quantity'], 10), $n['posted_on']);
                }
                if ($rules['auto_post'] ?? false) {
                    app(LedgerService::class)->postTransaction($txId);
                }
                DB::table('import_rows')->where('id', $row->id)->update(['state' => 'reconciled', 'transaction_id' => $txId, 'updated_at' => now()]);
                $created++;
            }
            $this->pairTransfers($ctx->workspace->id, $b->id);
            $recon = $this->reconcile($b->id);
            DB::table('import_batches')->where('id', $b->id)->update(['status' => 'committed', 'committed_at' => now(), 'committed_by' => $ctx->user->id,
                'reconciliation' => json_encode($recon), 'updated_at' => now()]);
            if ($b->connection_id) {
                DB::table('source_connections')->where('id', $b->connection_id)->update(['last_sync_at' => now(), 'last_success_at' => now(), 'status' => 'active']);
            }
            Audit::log('import.committed', 'import_batch', $b->id, ['created' => $created, 'enriched' => $enriched], scopeId: $b->scope_id);
            Outbox::publish('import.committed', ['batch_id' => $b->id, 'created' => $created], 'import_batch', $b->id);

            return ['status' => 'committed', 'created' => $created, 'enriched' => $enriched, 'reconciliation' => $recon];
        });
    }

    /** Pair opposite-signed transfers across accounts within 3 days so consolidated cash flow stays neutral. */
    private function pairTransfers(string $workspaceId, string $batchId): void
    {
        $transfers = DB::table('transactions')->where('workspace_id', $workspaceId)->where('batch_id', $batchId)->where('kind', 'transfer')->whereNull('transfer_group')->get();
        foreach ($transfers as $t) {
            $other = DB::table('transactions')->where('workspace_id', $workspaceId)->where('kind', 'transfer')->whereNull('transfer_group')
                ->where('account_id', '!=', $t->account_id)->where('amount', Dec::neg(Dec::norm($t->amount)))
                ->whereBetween('posted_on', [date('Y-m-d', strtotime($t->posted_on.' -3 days')), date('Y-m-d', strtotime($t->posted_on.' +3 days'))])
                ->where('id', '!=', $t->id)->first();
            if ($other) {
                $g = (string) Str::ulid();
                DB::table('transactions')->whereIn('id', [$t->id, $other->id])->update(['transfer_group' => $g]);
            }
        }
    }

    private function reconcile(string $batchId): array
    {
        $rows = DB::table('transactions')->where('batch_id', $batchId)->selectRaw('account_id, count(*) as n, sum(amount) as total, min(posted_on) as first, max(posted_on) as last')
            ->groupBy('account_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $bal = DB::table('balance_snapshots')->where('account_id', $r->account_id)->where('as_of', '<=', $r->last)->orderByDesc('as_of')->first();
            $out[] = ['account_id' => $r->account_id, 'transactions' => (int) $r->n, 'net_amount' => Dec::norm($r->total), 'from' => $r->first, 'to' => $r->last,
                'latest_balance_snapshot' => $bal ? ['as_of' => $bal->as_of, 'balance' => Dec::norm($bal->balance)] : null];
        }

        return ['accounts' => $out, 'unpaired_transfers' => DB::table('transactions')->where('batch_id', $batchId)->where('kind', 'transfer')->whereNull('transfer_group')->count()];
    }

    public function reverse(AccessContext $ctx, string $batchId, string $reason): array
    {
        $b = $this->batch($ctx, $batchId, 'approve');
        if ($b->status !== 'committed') {
            throw ApiError::conflict('Only committed batches can be reversed.');
        }
        $txs = DB::table('transactions')->where('batch_id', $b->id)->whereNull('archived_at')->get();
        foreach ($txs as $t) {
            if (app(LedgerService::class)->isClosed($t->workspace_id, $t->posted_on)) {
                throw ApiError::conflict('This batch includes transactions in a closed period. Reopen the period or post a correcting entry.');
            }
        }

        return DB::transaction(function () use ($ctx, $b, $txs, $reason) {
            foreach ($txs as $t) {
                app(LedgerService::class)->reverseFor('transaction', $t->id, 'Import batch reversed');
            }
            DB::table('transactions')->where('batch_id', $b->id)->update(['archived_at' => now(), 'updated_at' => now()]);
            DB::table('lots')->where('source_ref', $b->id)->update(['archived_at' => now()]);
            DB::table('import_rows')->where('batch_id', $b->id)->update(['state' => 'superseded']);
            DB::table('import_batches')->where('id', $b->id)->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $ctx->user->id, 'updated_at' => now()]);
            Audit::log('import.reversed', 'import_batch', $b->id, ['transactions' => $txs->count(), 'reason' => mb_substr($reason, 0, 200)], scopeId: $b->scope_id);

            return ['status' => 'reversed', 'transactions' => $txs->count()];
        });
    }

    /** Version a profile on change; approval is required before unattended (auto-post) use. */
    public function approveProfile(AccessContext $ctx, string $profileId): void
    {
        $p = DB::table('import_profiles')->where('workspace_id', $ctx->workspace->id)->where('id', $profileId)->first();
        if (! $p || ! $ctx->can('ledger', 'approve', $p->scope_id)) {
            throw ApiError::forbidden();
        }
        DB::table('import_profiles')->where('workspace_id', $ctx->workspace->id)->where('provider', $p->provider)->where('name', $p->name)
            ->where('status', 'approved')->where('id', '!=', $p->id)->update(['status' => 'retired']);
        DB::table('import_profiles')->where('id', $p->id)->update(['status' => 'approved', 'approved_by' => $ctx->user->id, 'approved_at' => now()]);
        Audit::log('import_profile.approved', 'import_profile', $p->id, scopeId: $p->scope_id);
    }
}
