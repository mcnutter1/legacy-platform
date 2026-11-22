<?php

namespace App\Services\Integrations;

use App\Services\ImportService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pulls accounts, balances, holdings and transactions from an MCP source and reconciles them with canonical records.
 * One account can be reported by several sources (external_links); only its authoritative source
 * (accounts.connection_id) writes balances/holdings/transactions, the others are compared and disagreements become
 * source_discrepancies. Ambiguous account matches are created separately and flagged as possible duplicates.
 */
final class SyncService
{
    public function __construct(private readonly AccessContext $ctx) {}

    /** Read-only pull from the provider using the approved (or suggested) tool mapping. */
    public function fetch(object $c): array
    {
        $p = IntegrationService::provider($c->provider);
        $client = IntegrationService::client($c);
        $map = json_decode((string) $c->tool_map, true) ?: [];
        $schemas = array_column(json_decode((string) $c->tool_catalog, true) ?: [], 'inputSchema', 'name');
        $cfg = json_decode((string) $c->config, true) ?: [];
        if (empty($map['accounts'])) {
            throw new IntegrationError('unsupported', 'No tool is mapped to accounts. Run Test and choose the accounts tool.');
        }
        $issues = [];
        $args = Args::build($schemas[$map['accounts']] ?? [], ['_limit' => 500]);
        if ($args === null) {
            throw new IntegrationError('unsupported', "{$map['accounts']} needs parameters Legacy cannot supply.");
        }
        $accounts = [];
        $skipped = 0;
        foreach (Shape::records($client->call($map['accounts'], $args), $p->accountKeys()) as $row) {
            if ($p->isAggregate($row)) {
                $skipped++;

                continue;
            }
            $a = $p->account($row);
            $a ? $accounts[$a['external_id']] = $a + ['holdings' => [], 'transactions' => []] : $issues[] = 'An account row had no id or name and was skipped.';
            if (count($accounts) >= 300) {
                break;
            }
        }
        if ($skipped) {
            $issues[] = "Skipped {$skipped} combined/aggregate portfolio(s).";
        }
        $end = now()->toDateString();
        $days = (int) ($cfg['transactions_days'] ?? 90);
        $since = $c->last_success_at ? max(strtotime($c->last_success_at.' -7 days'), strtotime("-{$days} days")) : strtotime("-{$days} days");
        $roles = ['holdings' => [], 'transactions' => ['_start' => date('Y-m-d', $since), '_end' => $end]];
        if (! ($cfg['import_transactions'] ?? true)) {
            unset($roles['transactions']);
        }
        foreach ($roles as $role => $extra) {
            $tool = $map[$role] ?? null;
            if (! $tool) {
                continue;
            }
            $schema = $schemas[$tool] ?? [];
            $parent = Args::parentKey($schema);
            $calls = $parent ? array_map(fn ($a) => [$a['external_id'], [$parent => $a['external_id']]], array_values(array_filter($accounts, fn ($a) => ! $a['closed'])))
                : [[null, []]];
            foreach (array_slice($calls, 0, 60) as [$owner, $values]) {
                $args = Args::build($schema, $values + $extra + ['_limit' => 500]);
                if ($args === null) {
                    $issues[] = "{$tool} needs parameters Legacy cannot supply; {$role} skipped.";
                    break;
                }
                try {
                    $rows = Shape::records($client->call($tool, $args), [$role, 'positions', 'items', 'data', 'results']);
                } catch (IntegrationError $e) {
                    if ($e->reason === 'unauthorized') {
                        throw $e;
                    }
                    $issues[] = "{$tool}: {$e->getMessage()}";

                    continue;
                }
                foreach ($rows as $row) {
                    $item = $role === 'holdings' ? $p->holding($row) : $p->transaction($row);
                    if (! $item) {
                        continue;
                    }
                    $ref = $owner ?? $item['account_ref'] ?? (count($accounts) === 1 ? array_key_first($accounts) : null);
                    if ($ref === null || ! isset($accounts[$ref])) {
                        $issues[] = "A {$role} row could not be tied to an account.";

                        continue;
                    }
                    $accounts[$ref][$role][] = $item;
                }
            }
        }
        foreach ($accounts as &$a) {
            if ($a['balance'] === null && $a['holdings']) {
                $vals = array_filter(array_column($a['holdings'], 'market_value'), fn ($v) => $v !== null);
                $a['balance'] = $vals ? Dec::add(...$vals) : null;
            }
            $a['as_of'] = min($a['as_of'] ?? $end, $end);
        }
        unset($a);

        return ['accounts' => $accounts, 'issues' => array_values(array_unique($issues)), 'tool_map' => $map, 'fetched_at' => now()->toIso8601String()];
    }

    /** Decide, without writing, how each source account maps to canonical accounts. */
    public function plan(object $c, array $snap): array
    {
        $links = DB::table('external_links')->where('connection_id', $c->id)->where('entity_type', 'account')->whereNull('archived_at')->pluck('entity_id', 'external_id');
        $candidates = $this->ctx->constrain(DB::table('accounts'), 'ledger', 'write')->whereNull('archived_at')->where('visibility', '!=', 'ignored')->get()->keyBy('id');
        $claimed = $links->values()->flip();
        $latest = DB::table('balance_snapshots')->whereIn('account_id', $candidates->keys())->whereNull('archived_at')->orderByDesc('as_of')->get()->groupBy('account_id')->map(fn ($g) => $g->first()->balance);
        $linkCount = DB::table('external_links')->whereIn('entity_id', $candidates->keys())->where('entity_type', 'account')->whereNull('archived_at')
            ->selectRaw('entity_id, count(*) as n')->groupBy('entity_id')->pluck('n', 'entity_id');
        $out = [];
        foreach ($snap['accounts'] as $ext => $a) {
            $row = ['external_id' => (string) $ext, 'name' => $a['name'], 'institution' => $a['institution'], 'kind' => $a['kind'], 'mask' => $a['mask'],
                'balance' => $a['balance'], 'currency' => $a['currency'], 'holdings' => count($a['holdings']), 'transactions' => count($a['transactions']),
                'action' => 'new', 'account_id' => null, 'candidate' => null, 'authoritative' => true, 'visibility' => 'visible'];
            $linked = $links[(string) $ext] ?? null;
            if ($linked && ($acct = $candidates[$linked] ?? DB::table('accounts')->find($linked))) {
                $row['action'] = 'linked';
                $row['account_id'] = $acct->id;
                $row['visibility'] = $acct->visibility;
                $row['authoritative'] = $acct->connection_id === $c->id || ($acct->connection_id === null && (int) ($linkCount[$acct->id] ?? 0) <= 1);
                $out[] = $row;

                continue;
            }
            $scored = [];
            foreach ($candidates as $cand) {
                if (isset($claimed[$cand->id])) {
                    continue;
                }
                [$score, $why] = $this->score($a, $cand, $latest[$cand->id] ?? null, $c);
                if ($score > 0) {
                    $scored[] = ['id' => $cand->id, 'name' => $cand->name, 'score' => $score, 'reasons' => $why, 'connection_id' => $cand->connection_id];
                }
            }
            usort($scored, fn ($x, $y) => $y['score'] <=> $x['score']);
            $top = $scored[0] ?? null;
            $second = $scored[1]['score'] ?? 0;
            if ($top && $top['score'] >= 6 && $top['score'] - $second >= 2) {
                $row['action'] = 'link';
                $row['account_id'] = $top['id'];
                $row['authoritative'] = $top['connection_id'] === null && (int) ($linkCount[$top['id']] ?? 0) === 0;
                $claimed[$top['id']] = true;
            } elseif ($top && $top['score'] >= 3) {
                $row['action'] = 'possible_duplicate';
            }
            $row['candidate'] = $top;
            $out[] = $row;
        }

        return $out;
    }

    public function preview(object $c): array
    {
        $snap = $this->fetch($c);
        $plan = $this->plan($c, $snap);
        $summary = ['accounts' => $plan, 'issues' => $snap['issues'], 'tool_map' => $snap['tool_map'], 'fetched_at' => $snap['fetched_at'],
            'totals' => ['accounts' => count($plan), 'holdings' => array_sum(array_column($plan, 'holdings')), 'transactions' => array_sum(array_column($plan, 'transactions')),
                'new' => count(array_filter($plan, fn ($r) => $r['action'] === 'new')), 'link' => count(array_filter($plan, fn ($r) => in_array($r['action'], ['link', 'linked'], true))),
                'possible_duplicate' => count(array_filter($plan, fn ($r) => $r['action'] === 'possible_duplicate')),
                'excluded' => count(array_filter($plan, fn ($r) => $r['visibility'] !== 'visible'))]];
        DB::table('source_connections')->where('id', $c->id)->update(['last_preview' => json_encode($summary), 'last_sync_at' => now(), 'last_error' => null,
            'status' => $c->approved_at ? $c->status : 'needs_review', 'updated_at' => now()]);

        return ['preview' => true] + $summary['totals'] + ['issues' => count($snap['issues'])];
    }

    public function sync(object $c): array
    {
        if (! $c->approved_at) {
            throw new IntegrationError('not_approved', 'Approve the mapping before syncing.');
        }
        $snap = $this->fetch($c);
        $p = IntegrationService::provider($c->provider);
        $today = now()->toDateString();
        $stats = ['accounts_created' => 0, 'accounts_linked' => 0, 'balances' => 0, 'holdings' => 0, 'observed_only' => 0, 'possible_duplicates' => 0, 'skipped_no_access' => 0, 'ignored' => 0];
        $txRows = [];
        $touched = DB::transaction(function () use ($c, $snap, $p, $today, &$stats, &$txRows) {
            $touched = [];
            foreach ($this->plan($c, $snap) as $row) {
                $a = $snap['accounts'][$row['external_id']];
                $accountId = $row['account_id'];
                if (in_array($row['action'], ['new', 'possible_duplicate'], true)) {
                    $accountId = $this->createAccount($c, $a);
                    $stats['accounts_created']++;
                    if ($row['action'] === 'possible_duplicate') {
                        $this->flag('account', $accountId, 'possible_duplicate', null, [['connection_id' => $c->id, 'provider' => $c->provider, 'value' => $a['name']],
                            ['account_id' => $row['candidate']['id'], 'value' => $row['candidate']['name'], 'score' => $row['candidate']['score'], 'reasons' => $row['candidate']['reasons']]],
                            null, $row['candidate']['id'], $c->scope_id);
                        $stats['possible_duplicates']++;
                    }
                } elseif ($row['action'] === 'link') {
                    $stats['accounts_linked']++;
                }
                $account = DB::table('accounts')->find($accountId);
                if (! $account || ! $this->ctx->can('ledger', 'write', $account->scope_id)) {
                    $stats['skipped_no_access']++;

                    continue;
                }
                if ($row['authoritative'] && $account->connection_id === null) {
                    DB::table('accounts')->where('id', $account->id)->update(['connection_id' => $c->id, 'external_ref' => $account->external_ref ?: $a['external_id'],
                        'edit_version' => $account->edit_version + 1, 'updated_at' => now()]);
                    $account->connection_id = $c->id;
                }
                $this->link($c, 'account', $account, $a, $row['action'] === 'linked' ? null : ($row['action'] === 'link' ? 'auto:'.implode(',', $row['candidate']['reasons']) : 'created'));
                if ($account->visibility === 'ignored') {
                    $stats['ignored']++;

                    continue;
                }
                $touched[] = $account->id;
                if ($account->connection_id !== $c->id) {
                    $stats['observed_only']++;

                    continue;
                }
                if ($a['balance'] !== null) {
                    $this->balance($c, $account, $a, $today);
                    $stats['balances']++;
                }
                if ($a['holdings']) {
                    if ($this->ctx->can('portfolio', 'write', $account->scope_id)) {
                        $stats['holdings'] += $this->holdings($c, $account, $a['holdings'], $today);
                    } else {
                        $stats['skipped_no_access']++;
                    }
                }
                foreach ($a['transactions'] as $t) {
                    if (! $t['pending']) {
                        $txRows[] = ['account' => $account, 't' => $t];
                    }
                }
            }
            foreach (array_unique($touched) as $id) {
                $this->reconcileAccount($id);
            }

            return $touched;
        });
        $batch = $txRows ? $this->stageTransactions($c, $txRows) : null;
        $cfg = json_decode((string) $c->config, true) ?: [];
        if ($batch && ($batch['status'] ?? null) === 'previewed' && ($cfg['auto_commit_transactions'] ?? false) && (($batch['matching']['needs_review'] ?? 1) === 0)) {
            $batch['commit'] = app(ImportService::class)->commit($this->ctx, $batch['batch_id']);
        }
        $stats += ['transactions_staged' => $batch['rows'] ?? 0, 'import_batch_id' => $batch['batch_id'] ?? null, 'issues' => $snap['issues'],
            'open_conflicts' => DB::table('source_discrepancies')->where('workspace_id', $c->workspace_id)->whereIn('entity_id', $touched ?: ['-'])->where('status', 'open')->count()];
        DB::table('source_connections')->where('id', $c->id)->update(['last_sync_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'status' => 'active',
            'last_sync_stats' => json_encode($stats), 'updated_at' => now()]);
        Audit::log('integration.synced', 'source_connection', $c->id, array_diff_key($stats, ['issues' => 1]), scopeId: $c->scope_id);
        Outbox::publish('integration.synced', ['connection_id' => $c->id], 'source_connection', $c->id);

        return $stats;
    }

    /** Fold one account into another (after a confirmed duplicate). Moves source links and history; archives the duplicate. */
    public function merge(string $fromId, string $intoId): array
    {
        $acc = fn ($id) => Str::isUlid($id) ? $this->ctx->constrain(DB::table('accounts'), 'ledger', 'write')->where('id', $id)->whereNull('archived_at')->first() : null;
        $from = $acc($fromId);
        $into = $acc($intoId);
        if (! $from || ! $into) {
            throw ApiError::notFound();
        }
        if ($from->id === $into->id) {
            throw new ApiError('validation_failed', 'Choose two different accounts.');
        }
        $clash = DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $from->id)->whereNull('archived_at')
            ->whereIn('connection_id', DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $into->id)->whereNull('archived_at')->select('connection_id'))->exists();
        if ($clash) {
            throw ApiError::conflict('Both accounts are reported separately by the same connection, so they are different accounts.');
        }

        return DB::transaction(function () use ($from, $into) {
            $moved = ['links' => DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $from->id)->update(['entity_id' => $into->id, 'match_method' => 'merged', 'updated_at' => now()])];
            // Balances/holdings fed by a non-authoritative source are re-derivable observations; archive them instead of mixing sources.
            $foreign = fn ($q) => $q->where('source_ref', $from->connection_id)->where('source_ref', '!=', (string) $into->connection_id);
            $moved['balances_archived'] = $from->connection_id && $into->connection_id && $from->connection_id !== $into->connection_id
                ? DB::table('balance_snapshots')->where('account_id', $from->id)->where($foreign)->update(['archived_at' => now()]) : 0;
            $moved['balances'] = DB::table('balance_snapshots')->where('account_id', $from->id)->whereNull('archived_at')->update(['account_id' => $into->id, 'scope_id' => $into->scope_id]);
            $moved['holdings'] = 0;
            foreach (DB::table('holdings')->where('account_id', $from->id)->get() as $h) {
                if (! DB::table('holdings')->where('account_id', $into->id)->where('instrument_id', $h->instrument_id)->where('as_of', $h->as_of)->exists()) {
                    DB::table('holdings')->where('id', $h->id)->update(['account_id' => $into->id, 'scope_id' => $into->scope_id]);
                    $moved['holdings']++;
                }
            }
            $moved['transactions'] = 0;
            foreach (DB::table('transactions')->where('account_id', $from->id)->get(['id', 'external_id']) as $t) {
                if (! $t->external_id || ! DB::table('transactions')->where('workspace_id', $into->workspace_id)->where('account_id', $into->id)->where('external_id', $t->external_id)->exists()) {
                    DB::table('transactions')->where('id', $t->id)->update(['account_id' => $into->id, 'scope_id' => $into->scope_id]);
                    $moved['transactions']++;
                }
            }
            $moved['lots'] = DB::table('lots')->where('account_id', $from->id)->update(['account_id' => $into->id, 'scope_id' => $into->scope_id]);
            $patch = ['edit_version' => $into->edit_version + 1, 'updated_at' => now(), 'updated_by' => $this->ctx->user->id];
            foreach (['connection_id', 'institution', 'mask', 'external_ref'] as $f) {
                if (! $into->{$f} && $from->{$f}) {
                    $patch[$f] = $from->{$f};
                }
            }
            DB::table('accounts')->where('id', $into->id)->update($patch);
            DB::table('accounts')->where('id', $from->id)->update(['archived_at' => now(), 'status' => 'closed', 'edit_version' => $from->edit_version + 1, 'updated_by' => $this->ctx->user->id]);
            DB::table('source_discrepancies')->where('entity_type', 'account')->whereIn('entity_id', [$from->id, $into->id])->where('kind', 'possible_duplicate')->where('status', 'open')
                ->update(['status' => 'resolved', 'resolution' => 'Merged', 'resolved_by' => $this->ctx->user->id, 'resolved_at' => now()]);
            DB::table('source_discrepancies')->where('entity_type', 'account')->where('entity_id', $from->id)->where('status', 'open')->update(['entity_id' => $into->id]);
            $this->reconcileAccount($into->id);
            Audit::log('accounts.merged', 'accounts', $into->id, ['from' => $from->id] + $moved, scopeId: $into->scope_id);

            return ['account_id' => $into->id, 'moved' => $moved];
        });
    }

    /** Choose which linked source is authoritative for an account's balances, holdings and transactions. */
    public function setAuthority(string $accountId, ?string $connectionId): array
    {
        $a = Str::isUlid($accountId) ? $this->ctx->constrain(DB::table('accounts'), 'ledger', 'write')->where('id', $accountId)->first() : null;
        if (! $a) {
            throw ApiError::notFound();
        }
        if ($connectionId !== null && ! DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $a->id)->where('connection_id', $connectionId)->whereNull('archived_at')->exists()) {
            throw new ApiError('validation_failed', 'That source does not report this account.');
        }
        DB::table('accounts')->where('id', $a->id)->update(['connection_id' => $connectionId, 'edit_version' => $a->edit_version + 1, 'updated_by' => $this->ctx->user->id, 'updated_at' => now()]);
        Audit::log('accounts.authority_changed', 'accounts', $a->id, ['connection_id' => $connectionId], scopeId: $a->scope_id);

        return ['account_id' => $a->id, 'connection_id' => $connectionId];
    }

    public function resolve(string $id, string $action, ?string $note, ?string $preferConnection): array
    {
        $d = Str::isUlid($id) ? $this->ctx->constrain(DB::table('source_discrepancies'), 'ledger', 'write')->where('id', $id)->first() : null;
        if (! $d) {
            throw ApiError::notFound();
        }
        if (! in_array($action, ['ignore', 'resolve', 'reopen'], true)) {
            throw new ApiError('validation_failed', 'Unknown action.');
        }
        if ($preferConnection && $d->entity_type === 'account') {
            $this->setAuthority($d->entity_id, $preferConnection);
        }
        DB::table('source_discrepancies')->where('id', $d->id)->update([
            'status' => ['ignore' => 'ignored', 'resolve' => 'resolved', 'reopen' => 'open'][$action], 'resolution' => $note ? mb_substr($note, 0, 1000) : ($preferConnection ? 'Preferred source changed' : null),
            'resolved_by' => $action === 'reopen' ? null : $this->ctx->user->id, 'resolved_at' => $action === 'reopen' ? null : now(), 'edit_version' => $d->edit_version + 1, 'updated_at' => now(),
        ]);
        Audit::log('source_discrepancy.'.$action, 'source_discrepancies', $d->id, ['prefer' => $preferConnection], scopeId: $d->scope_id);

        return ['id' => $d->id, 'status' => $action];
    }

    /** Compare what each linked source last reported for an account; open, update or auto-resolve conflicts. */
    public function reconcileAccount(string $accountId): void
    {
        $account = DB::table('accounts')->find($accountId);
        $links = DB::table('external_links')->where('entity_type', 'account')->where('entity_id', $accountId)->whereNull('archived_at')->get();
        $labels = DB::table('source_connections')->whereIn('id', $links->pluck('connection_id'))->pluck('label', 'id');
        $obs = $links->map(fn ($l) => ['connection_id' => $l->connection_id, 'provider' => $l->provider, 'label' => $labels[$l->connection_id] ?? $l->provider,
            'v' => json_decode((string) $l->last_values, true) ?: []])->values()->all();
        $found = [];
        if (count($obs) >= 2) {
            $bal = array_values(array_filter($obs, fn ($o) => isset($o['v']['balance'])));
            if (count($bal) >= 2) {
                $vals = array_map(fn ($o) => $o['v']['balance'], $bal);
                usort($vals, fn ($x, $y) => Dec::cmp($x, $y));
                $diff = Dec::sub(end($vals), $vals[0]);
                $dates = array_map(fn ($o) => strtotime($o['v']['as_of'] ?? 'today'), $bal);
                $tolerance = Dec::norm(max(1.0, 0.005 * max(abs((float) end($vals)), abs((float) $vals[0]))));
                if (max($dates) - min($dates) <= 3 * 86400 && Dec::cmp($diff, $tolerance) > 0) {
                    $found['balance|balance'] = [array_map(fn ($o) => ['connection_id' => $o['connection_id'], 'provider' => $o['provider'], 'label' => $o['label'],
                        'value' => $o['v']['balance'], 'as_of' => $o['v']['as_of'] ?? null], $bal), $diff];
                }
            }
            $hold = array_values(array_filter($obs, fn ($o) => isset($o['v']['holdings']) && is_array($o['v']['holdings']) && $o['v']['holdings']));
            if (count($hold) >= 2) {
                $symbols = array_unique(array_merge(...array_map(fn ($o) => array_keys($o['v']['holdings']), $hold)));
                foreach (array_slice($symbols, 0, 100) as $sym) {
                    $q = array_map(fn ($o) => $o['v']['holdings'][$sym] ?? null, $hold);
                    $present = array_filter($q, fn ($x) => $x !== null);
                    $observed = array_map(fn ($o, $x) => ['connection_id' => $o['connection_id'], 'provider' => $o['provider'], 'label' => $o['label'], 'value' => $x], $hold, $q);
                    if (count($present) < count($q)) {
                        $found['holding_missing|'.$sym] = [$observed, null];
                    } else {
                        sort($present, SORT_NUMERIC);
                        $d = Dec::sub(Dec::norm(end($present), 10), Dec::norm($present[0], 10));
                        if ((float) $d > max(0.0001, 0.001 * abs((float) end($present)))) {
                            $found['holding_quantity|'.$sym] = [$observed, $d];
                        }
                    }
                }
            }
        }
        $existing = DB::table('source_discrepancies')->where('entity_type', 'account')->where('entity_id', $accountId)
            ->whereIn('kind', ['balance', 'holding_quantity', 'holding_missing'])->whereIn('status', ['open', 'ignored'])->get()->keyBy(fn ($d) => $d->kind.'|'.$d->field);
        foreach ($found as $key => [$observed, $diff]) {
            [$kind, $field] = explode('|', $key, 2);
            $this->flag('account', $accountId, $kind, $field, $observed, $diff, null, $account->scope_id);
        }
        foreach ($existing as $key => $d) {
            if (! isset($found[$key]) && $d->status === 'open') {
                DB::table('source_discrepancies')->where('id', $d->id)->update(['status' => 'resolved', 'resolution' => 'Sources agree again', 'resolved_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function flag(string $type, string $entityId, string $kind, ?string $field, array $observed, ?string $diff, ?string $candidate, string $scopeId): void
    {
        $existing = DB::table('source_discrepancies')->where('entity_type', $type)->where('entity_id', $entityId)->where('kind', $kind)
            ->where(fn ($q) => $field === null ? $q->whereNull('field') : $q->where('field', $field))->whereIn('status', ['open', 'ignored'])->first();
        if ($existing) {
            // An ignored conflict stays ignored until the size of the disagreement changes.
            $same = $existing->difference === null ? $diff === null : ($diff !== null && Dec::cmp(Dec::norm($existing->difference, 10), Dec::norm($diff, 10)) === 0);
            DB::table('source_discrepancies')->where('id', $existing->id)->update(['observed' => json_encode($observed), 'difference' => $diff, 'last_seen_at' => now(),
                'status' => $existing->status === 'ignored' && ! $same ? 'open' : $existing->status, 'updated_at' => now()]);

            return;
        }
        DB::table('source_discrepancies')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ctx->workspace->id, 'scope_id' => $scopeId, 'entity_type' => $type,
            'entity_id' => $entityId, 'kind' => $kind, 'field' => $field, 'observed' => json_encode($observed), 'difference' => $diff, 'candidate_id' => $candidate,
            'status' => 'open', 'last_seen_at' => now(), 'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
    }

    public function score(array $a, object $cand, ?string $latestBalance, object $c): array
    {
        $score = 0;
        $why = [];
        if ($cand->connection_id === $c->id && $cand->external_ref === $a['external_id']) {
            return [20, ['external_ref']];
        }
        if ($a['mask'] && $cand->mask) {
            if ($a['mask'] !== $cand->mask) {
                return [0, []];
            }
            $score += 4;
            $why[] = 'mask';
        }
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtolower((string) $s))));
        $ia = $norm($a['institution']);
        $ib = $norm($cand->institution);
        if ($ia !== '' && $ib !== '' && (str_contains($ia, $ib) || str_contains($ib, $ia))) {
            $score += 2;
            $why[] = 'institution';
        }
        $na = $norm($a['name']);
        $nb = $norm($cand->name);
        if ($na !== '' && $na === $nb) {
            $score += 3;
            $why[] = 'name';
        } else {
            $ta = array_filter(explode(' ', $na), fn ($w) => strlen($w) > 2);
            $tb = array_filter(explode(' ', $nb), fn ($w) => strlen($w) > 2);
            if ($ta && $tb && count(array_intersect($ta, $tb)) / count(array_unique(array_merge($ta, $tb))) >= 0.5) {
                $score += 1;
                $why[] = 'similar_name';
            }
        }
        $family = fn ($k) => match (true) {
            in_array($k, ['checking', 'savings', 'money_market', 'cd'], true) => 'cash',
            in_array($k, ['credit_card', 'loan', 'mortgage', 'heloc'], true) => 'debt',
            in_array($k, ['other', null, ''], true) => null,
            default => 'invest',
        };
        if ($family($a['kind']) && $family($a['kind']) === $family($cand->kind)) {
            $score += 1;
            $why[] = 'type';
        } elseif ($family($a['kind']) && $family($cand->kind) && $family($a['kind']) !== $family($cand->kind)) {
            $score -= 3;
        }
        if ($a['balance'] !== null && $latestBalance !== null) {
            $x = abs((float) $a['balance']);
            $y = abs((float) $latestBalance);
            $rel = max($x, $y) > 0 ? abs($x - $y) / max($x, $y) : 0;
            if ($rel <= 0.01) {
                $score += 2;
                $why[] = 'balance';
            } elseif ($rel <= 0.05) {
                $score += 1;
                $why[] = 'balance_close';
            }
        }

        return [$score, $why];
    }

    private function createAccount(object $c, array $a): string
    {
        $id = (string) Str::ulid();
        DB::table('accounts')->insert([
            'id' => $id, 'workspace_id' => $c->workspace_id, 'scope_id' => $c->scope_id, 'name' => mb_substr($a['name'], 0, 255), 'institution' => $a['institution'],
            'kind' => $a['kind'], 'tax_bucket' => match ($a['kind']) {
                'ira', '401k', '403b', '457b', 'sep_ira', 'solo_401k' => 'pretax', 'roth_ira', 'roth_401k' => 'roth', 'hsa' => 'hsa', '529' => 'education',
                'credit_card', 'loan', 'mortgage', 'heloc' => 'none', default => 'taxable',
            },
            'currency' => preg_match('/^[A-Z]{3}$/', $a['currency']) ? $a['currency'] : 'USD', 'mask' => $a['mask'], 'connection_id' => $c->id,
            'external_ref' => mb_substr($a['external_id'], 0, 255), 'status' => $a['closed'] ? 'closed' : 'open', 'is_liability' => $a['is_liability'],
            'created_by' => $this->ctx->user->id, 'updated_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        Audit::log('accounts.created', 'accounts', $id, ['source' => $c->provider, 'connection_id' => $c->id], scopeId: $c->scope_id);

        return $id;
    }

    private function link(object $c, string $type, object $entity, array $a, ?string $method): void
    {
        $values = ['name' => $a['name'], 'balance' => $a['balance'] !== null ? Dec::norm($a['balance']) : null, 'as_of' => $a['as_of'], 'currency' => $a['currency'],
            'holdings' => $a['holdings'] ? array_reduce($a['holdings'], function ($m, $h) {
                $m[$h['symbol']] = Dec::norm(Dec::add($m[$h['symbol']] ?? '0', $h['quantity']), 10);

                return $m;
            }, []) : null];
        $existing = DB::table('external_links')->where('connection_id', $c->id)->where('entity_type', $type)->where('external_id', $a['external_id'])->first();
        $row = ['entity_id' => $entity->id, 'scope_id' => $entity->scope_id, 'external_name' => mb_substr($a['name'], 0, 255), 'last_values' => json_encode($values),
            'last_seen_at' => now(), 'archived_at' => null, 'updated_at' => now()];
        if ($existing) {
            DB::table('external_links')->where('id', $existing->id)->update($row + ($method ? ['match_method' => mb_substr($method, 0, 24)] : []));

            return;
        }
        DB::table('external_links')->insert($row + ['id' => (string) Str::ulid(), 'workspace_id' => $c->workspace_id, 'connection_id' => $c->id, 'provider' => $c->provider,
            'entity_type' => $type, 'external_id' => $a['external_id'], 'match_method' => mb_substr($method ?? 'created', 0, 24), 'created_by' => $this->ctx->user->id,
            'created_at' => now(), 'edit_version' => 1]);
    }

    private function balance(object $c, object $account, array $a, string $today): void
    {
        $asOf = $a['as_of'] ?: $today;
        $q = DB::table('balance_snapshots')->where('account_id', $account->id)->where('as_of', $asOf)->where('source', $c->provider)->where('source_ref', $c->id)->whereNull('archived_at');
        $vals = ['balance' => Dec::norm($a['balance']), 'currency' => $a['currency'], 'updated_at' => now()];
        if ($id = $q->value('id')) {
            DB::table('balance_snapshots')->where('id', $id)->update($vals);

            return;
        }
        DB::table('balance_snapshots')->insert($vals + ['id' => (string) Str::ulid(), 'workspace_id' => $account->workspace_id, 'scope_id' => $account->scope_id,
            'account_id' => $account->id, 'as_of' => $asOf, 'source' => $c->provider, 'source_ref' => $c->id, 'value_state' => 'actual',
            'created_by' => $this->ctx->user->id, 'created_at' => now(), 'edit_version' => 1]);
    }

    private function holdings(object $c, object $account, array $holdings, string $today): int
    {
        $n = 0;
        $merged = [];
        foreach ($holdings as $h) {
            $k = $h['symbol'];
            if (isset($merged[$k])) {
                foreach (['quantity', 'market_value', 'cost_basis'] as $f) {
                    $merged[$k][$f] = $h[$f] !== null ? Dec::add($merged[$k][$f] ?? '0', $h[$f]) : $merged[$k][$f];
                }
            } else {
                $merged[$k] = $h;
            }
        }
        foreach ($merged as $h) {
            $instrument = DB::table('instruments')->where('workspace_id', $account->workspace_id)->where('symbol', $h['symbol'])->orderByRaw('kind = ? desc', [$h['instrument_kind']])->first();
            $instrumentId = $instrument->id ?? (string) Str::ulid();
            if (! $instrument) {
                DB::table('instruments')->insert(['id' => $instrumentId, 'workspace_id' => $account->workspace_id, 'symbol' => $h['symbol'], 'name' => $h['name'],
                    'kind' => $h['instrument_kind'], 'currency' => $h['currency'], 'last_price' => $h['price'] !== null ? Dec::norm($h['price'], 8) : null,
                    'last_price_on' => $h['price'] !== null ? $today : null, 'price_source' => $h['price'] !== null ? $c->provider : null,
                    'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            }
            $vals = ['quantity' => Dec::norm($h['quantity'], 10), 'price' => $h['price'] !== null ? Dec::norm($h['price'], 8) : null,
                'market_value' => $h['market_value'] !== null ? Dec::norm($h['market_value']) : null, 'cost_basis' => $h['cost_basis'] !== null ? Dec::norm($h['cost_basis']) : null,
                'currency' => $h['currency'], 'price_as_of' => $h['price'] !== null ? $today : null, 'source' => $c->provider, 'source_ref' => $c->id, 'value_state' => 'actual', 'updated_at' => now()];
            $existing = DB::table('holdings')->where('workspace_id', $account->workspace_id)->where('account_id', $account->id)->where('instrument_id', $instrumentId)->where('as_of', $today)->first();
            if ($existing) {
                DB::table('holdings')->where('id', $existing->id)->update($vals + ['edit_version' => $existing->edit_version + 1, 'archived_at' => null]);
            } else {
                DB::table('holdings')->insert($vals + ['id' => (string) Str::ulid(), 'workspace_id' => $account->workspace_id, 'scope_id' => $account->scope_id,
                    'account_id' => $account->id, 'instrument_id' => $instrumentId, 'as_of' => $today, 'created_by' => $this->ctx->user->id, 'created_at' => now(), 'edit_version' => 1]);
            }
            $n++;
        }

        return $n;
    }

    /** Stage pulled transactions as an import batch so the existing review/commit/reverse workbench applies. */
    private function stageTransactions(object $c, array $rows): ?array
    {
        $ext = fn ($r) => $r['t']['external_id'] ? mb_substr($c->provider.':'.$r['t']['external_id'], 0, 191) : null;
        $known = DB::table('transactions')->where('workspace_id', $c->workspace_id)->whereIn('external_id', array_filter(array_map($ext, $rows)))
            ->get(['account_id', 'external_id'])->map(fn ($t) => $t->account_id.'|'.$t->external_id)->flip();
        $out = [];
        foreach ($rows as $i => $r) {
            $t = $r['t'];
            $e = $ext($r);
            if ($e && isset($known[$r['account']->id.'|'.$e])) {
                continue;
            }
            $fp = hash('sha256', $e ? "mcp|{$c->id}|{$r['account']->id}|{$e}" : implode('|', [$r['account']->id, $t['posted_on'], Dec::norm($t['amount']), $t['currency'], mb_strtolower((string) $t['description'])]));
            $out[] = ['row_no' => $i + 1, 'external_id' => $e, 'fingerprint' => $fp, 'state' => 'mapped', 'issues' => [], 'normalized' => array_filter([
                'account_id' => $r['account']->id, 'posted_on' => $t['posted_on'], 'amount' => Dec::norm($t['amount']), 'currency' => $t['currency'], 'kind' => $t['kind'],
                'description' => $t['description'], 'merchant' => $t['merchant'], 'category' => $t['category'], 'notes' => $t['notes'], 'symbol' => $t['symbol'],
                'quantity' => $t['quantity'], 'price' => $t['price'], 'fees' => $t['fees'],
            ], fn ($v) => $v !== null)];
        }
        if (! $out) {
            return null;
        }
        $id = (string) Str::ulid();
        DB::table('import_batches')->insert(['id' => $id, 'workspace_id' => $c->workspace_id, 'scope_id' => $c->scope_id, 'provider' => $c->provider, 'connection_id' => $c->id,
            'status' => 'received', 'created_by' => $this->ctx->user->id, 'updated_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        $batch = DB::table('import_batches')->find($id);
        $res = DB::transaction(fn () => app(ImportService::class)->stage($batch, ['ok_schema' => true, 'rows' => $out, 'stats' => ['source' => 'mcp', 'connection_id' => $c->id]]));
        Audit::log('import.received', 'import_batch', $id, ['provider' => $c->provider, 'via' => 'mcp'], scopeId: $c->scope_id);

        return ['batch_id' => $id] + $res;
    }
}
