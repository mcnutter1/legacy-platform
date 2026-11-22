<?php

namespace App\Services;

use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry book per household/entity. Entries must balance in functional currency; corrections are
 * reversals, never edits; closed periods reject new postings.
 */
final class LedgerService
{
    public function book(string $workspaceId, string $scopeId): object
    {
        $b = DB::table('books')->where('workspace_id', $workspaceId)->where('owner_type', 'household')->where('scope_id', $scopeId)->first();
        if ($b) {
            return $b;
        }
        $id = (string) Str::ulid();
        DB::table('books')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'scope_id' => $scopeId, 'name' => 'Household book', 'owner_type' => 'household',
            'functional_currency' => 'USD', 'basis' => 'cash', 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);

        return DB::table('books')->find($id);
    }

    public function ledgerAccount(object $book, string $code, string $name, string $type, ?string $accountId = null): string
    {
        $id = DB::table('ledger_accounts')->where('book_id', $book->id)->where('code', $code)->value('id');
        if ($id) {
            return $id;
        }
        $id = (string) Str::ulid();
        DB::table('ledger_accounts')->insert(['id' => $id, 'workspace_id' => $book->workspace_id, 'book_id' => $book->id, 'code' => $code,
            'name' => mb_substr($name, 0, 255), 'type' => $type, 'account_id' => $accountId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function isClosed(string $workspaceId, string $date): bool
    {
        return DB::table('period_closes')->where('workspace_id', $workspaceId)->where('status', 'closed')->where('period_end', '>=', $date)->exists();
    }

    /** @param list<array{ledger_account_id:string,debit?:string,credit?:string,currency?:string,fx_rate?:string,memo?:string}> $lines */
    public function post(object $book, string $date, string $memo, array $lines, ?string $sourceType = null, ?string $sourceId = null, ?string $reversalOf = null): string
    {
        if ($this->isClosed($book->workspace_id, $date)) {
            throw ApiError::conflict('The period containing '.$date.' is closed.');
        }
        $dr = '0';
        $cr = '0';
        foreach ($lines as &$l) {
            $rate = Dec::norm($l['fx_rate'] ?? '1', 12);
            $l['functional_debit'] = Dec::mul(Dec::norm($l['debit'] ?? '0'), $rate);
            $l['functional_credit'] = Dec::mul(Dec::norm($l['credit'] ?? '0'), $rate);
            $dr = Dec::add($dr, $l['functional_debit']);
            $cr = Dec::add($cr, $l['functional_credit']);
        }
        unset($l);
        if (Dec::cmp($dr, $cr) !== 0) {
            throw new ApiError('unbalanced', "Journal does not balance ({$dr} vs {$cr}); add an explicit FX leg.");
        }
        $id = (string) Str::ulid();
        DB::table('journal_entries')->insert(['id' => $id, 'workspace_id' => $book->workspace_id, 'scope_id' => $book->scope_id, 'book_id' => $book->id,
            'entry_date' => $date, 'memo' => mb_substr($memo, 0, 255), 'source_type' => $sourceType, 'source_id' => $sourceId, 'reversal_of_id' => $reversalOf,
            'status' => 'posted', 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        foreach ($lines as $l) {
            DB::table('journal_lines')->insert(['workspace_id' => $book->workspace_id, 'entry_id' => $id, 'ledger_account_id' => $l['ledger_account_id'],
                'debit' => Dec::norm($l['debit'] ?? '0'), 'credit' => Dec::norm($l['credit'] ?? '0'), 'currency' => $l['currency'] ?? $book->functional_currency,
                'fx_rate' => Dec::norm($l['fx_rate'] ?? '1', 12), 'functional_debit' => $l['functional_debit'], 'functional_credit' => $l['functional_credit'],
                'memo' => $l['memo'] ?? null]);
        }

        return $id;
    }

    /** Cash-basis posting: bank/brokerage account against an income/expense category (transfers hit a clearing account). */
    public function postTransaction(string $transactionId): ?string
    {
        $t = DB::table('transactions')->find($transactionId);
        if (! $t || DB::table('journal_entries')->where('source_type', 'transaction')->where('source_id', $t->id)->where('status', 'posted')->exists()) {
            return null;
        }
        $acct = DB::table('accounts')->find($t->account_id);
        $book = $this->book($t->workspace_id, $t->scope_id);
        $cash = $this->ledgerAccount($book, 'A-'.substr($acct->id, -8), $acct->name, $acct->is_liability ? 'liability' : 'asset', $acct->id);
        $amount = Dec::norm($t->amount);
        $abs = Dec::abs($amount);
        if (Dec::isZero($abs)) {
            return null;
        }
        $isInflow = Dec::cmp($amount, '0') > 0;
        $cat = $t->kind === 'transfer' ? ['T-CLEAR', 'Transfers clearing', 'asset']
            : ($isInflow ? ['I-'.strtoupper(substr(Str::slug($t->category ?: $t->kind), 0, 12)), 'Income: '.($t->category ?: $t->kind), 'income']
                : ['E-'.strtoupper(substr(Str::slug($t->category ?: $t->kind), 0, 12)), 'Expense: '.($t->category ?: $t->kind), 'expense']);
        $other = $this->ledgerAccount($book, $cat[0], $cat[1], $cat[2]);
        $rate = $t->fx_rate ? Dec::norm($t->fx_rate, 12) : '1';
        $lines = $isInflow
            ? [['ledger_account_id' => $cash, 'debit' => $abs, 'currency' => $t->currency, 'fx_rate' => $rate], ['ledger_account_id' => $other, 'credit' => $abs, 'currency' => $t->currency, 'fx_rate' => $rate]]
            : [['ledger_account_id' => $other, 'debit' => $abs, 'currency' => $t->currency, 'fx_rate' => $rate], ['ledger_account_id' => $cash, 'credit' => $abs, 'currency' => $t->currency, 'fx_rate' => $rate]];

        return $this->post($book, $t->posted_on, (string) ($t->description ?? $t->kind), $lines, 'transaction', $t->id);
    }

    public function reverseFor(string $sourceType, string $sourceId, string $memo): void
    {
        foreach (DB::table('journal_entries')->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'posted')->get() as $e) {
            $book = DB::table('books')->find($e->book_id);
            $lines = DB::table('journal_lines')->where('entry_id', $e->id)->get()->map(fn ($l) => [
                'ledger_account_id' => $l->ledger_account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'currency' => $l->currency, 'fx_rate' => $l->fx_rate,
            ])->all();
            $date = $this->isClosed($e->workspace_id, $e->entry_date) ? now()->toDateString() : $e->entry_date;
            $this->post($book, $date, 'Reversal: '.$memo, $lines, $sourceType, $sourceId, $e->id);
            DB::table('journal_entries')->where('id', $e->id)->update(['status' => 'reversed', 'updated_at' => now()]);
        }
    }

    public function closePeriod(\App\Support\AccessContext $ctx, string $bookId, string $periodEnd): void
    {
        $book = DB::table('books')->where('workspace_id', $ctx->workspace->id)->where('id', $bookId)->first();
        if (! $book || ! $ctx->can('ledger', 'approve', $book->scope_id)) {
            throw ApiError::forbidden();
        }
        $unbalanced = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.book_id', $book->id)->where('journal_entries.entry_date', '<=', $periodEnd)
            ->selectRaw('sum(functional_debit) - sum(functional_credit) as diff')->value('diff');
        if ($unbalanced !== null && ! Dec::isZero(Dec::norm($unbalanced))) {
            throw ApiError::conflict('Book does not balance through '.$periodEnd.'.');
        }
        DB::table('period_closes')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ctx->workspace->id, 'scope_id' => $book->scope_id,
            'book_id' => $book->id, 'period_end' => $periodEnd, 'status' => 'closed', 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        Audit::log('ledger.period_closed', 'book', $book->id, ['period_end' => $periodEnd], scopeId: $book->scope_id);
    }

    public function reopenPeriod(\App\Support\AccessContext $ctx, string $closeId, string $reason): void
    {
        $pc = DB::table('period_closes')->where('workspace_id', $ctx->workspace->id)->where('id', $closeId)->first();
        if (! $pc || ! $ctx->can('ledger', 'approve', $pc->scope_id) || mb_strlen(trim($reason)) < 10) {
            throw new ApiError('validation_failed', 'Reopening requires approval rights and a reason of at least 10 characters.', 422);
        }
        DB::table('period_closes')->where('id', $pc->id)->update(['status' => 'reopened', 'reason' => $reason, 'approved_by' => $ctx->user->id, 'updated_at' => now()]);
        Audit::log('ledger.period_reopened', 'book', $pc->book_id, ['period_end' => $pc->period_end], scopeId: $pc->scope_id);
    }
}
