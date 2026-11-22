<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-data source choice for linked accounts: {"balance": connection_id, "holdings": …, "transactions": …}; missing = automatic.
        Schema::table('accounts', function (Blueprint $t) {
            $t->json('source_roles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn('source_roles');
        });
    }
};
