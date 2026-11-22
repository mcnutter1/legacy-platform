<?php

namespace App\Services;

use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Debts linked to an account (credit cards, mortgages, HELOCs) take their balance from that account. */
final class LiabilityBalances
{
    public static function refreshForAccount(string $accountId): int
    {
        $n = 0;
        foreach (DB::table('liabilities')->where('account_id', $accountId)->whereNull('archived_at')->get() as $l) {
            $n += self::refresh($l) ? 1 : 0;
        }

        return $n;
    }

    /** @return bool whether a balance was copied */
    public static function refresh(object $liability): bool
    {
        if (! $liability->account_id) {
            return false;
        }
        [$value, , $asOf] = app(NetWorthService::class)->accountValue($liability->account_id, now()->toDateString());
        if ($value === null) {
            return false;
        }
        $balance = Dec::abs($value);
        if ($liability->current_balance !== null && Dec::cmp(Dec::norm($liability->current_balance), $balance) === 0 && $liability->balance_as_of === $asOf) {
            return false;
        }
        DB::table('liabilities')->where('id', $liability->id)->update(['current_balance' => $balance, 'balance_as_of' => $asOf, 'updated_at' => now()]);

        return true;
    }
}
