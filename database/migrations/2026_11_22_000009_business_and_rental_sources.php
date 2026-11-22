<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_stays', function (Blueprint $t) {
            $t->string('external_id', 64)->nullable();
            $t->money('damage_recovery');
            $t->unique(['property_id', 'external_id']);
        });

        Schema::table('property_expenses', function (Blueprint $t) {
            $t->string('external_id', 64)->nullable();
            $t->unique(['property_id', 'external_id']);
        });

        // Cash-basis business activity from a connected bookkeeping system, one row per source cash event.
        Schema::create('business_ledger_lines', function (Blueprint $t) {
            $t->root();
            $t->wsRef('entity_id', 'legal_entities', false);
            $t->wsRef('connection_id', 'source_connections', false);
            $t->string('external_id', 64);
            $t->date('posted_on');
            $t->string('account_ref', 64);
            $t->string('account_code', 32)->nullable();
            $t->string('account_name')->nullable();
            $t->string('account_type', 16);
            $t->decimal('amount', 20, 4);
            $t->string('description')->nullable();
            $t->string('source_key', 128)->nullable();
            $t->tracking();
            $t->unique(['connection_id', 'external_id']);
            $t->index(['workspace_id', 'entity_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_ledger_lines');
        Schema::table('property_expenses', function (Blueprint $t) {
            $t->dropUnique(['property_id', 'external_id']);
            $t->dropColumn('external_id');
        });
        Schema::table('property_stays', function (Blueprint $t) {
            $t->dropUnique(['property_id', 'external_id']);
            $t->dropColumn(['external_id', 'damage_recovery']);
        });
    }
};
