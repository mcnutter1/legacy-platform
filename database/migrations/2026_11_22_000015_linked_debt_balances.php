<?php

use App\Services\LiabilityBalances;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Debts already linked to an account take that account's balance from now on. */
    public function up(): void
    {
        foreach (DB::table('liabilities')->whereNotNull('account_id')->whereNull('archived_at')->get() as $l) {
            LiabilityBalances::refresh($l);
        }
    }

    public function down(): void {}
};
