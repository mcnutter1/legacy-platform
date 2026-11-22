<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_connections', function (Blueprint $t) {
            $t->timestamp('next_sync_at')->nullable();
            $t->index(['status', 'next_sync_at']);
        });
        // Approved connections become due at the next scheduler tick.
        DB::table('source_connections')->whereNotNull('approved_at')->whereNull('archived_at')->update(['next_sync_at' => now()]);

        // System-wide scheduler liveness (no workspace data).
        Schema::create('scheduler_heartbeats', function (Blueprint $t) {
            $t->string('task', 64)->primary();
            $t->timestamp('last_started_at')->nullable();
            $t->timestamp('last_finished_at')->nullable();
            $t->string('last_status', 16)->nullable();
            $t->unsignedInteger('last_duration_ms')->nullable();
            $t->unsignedInteger('failures')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::table('source_connections', function (Blueprint $t) {
            $t->dropIndex(['status', 'next_sync_at']);
            $t->dropColumn('next_sync_at');
        });
    }
};
