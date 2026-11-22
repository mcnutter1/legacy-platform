<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // visible: counted everywhere; hidden: kept in sync but left out of totals and lists; ignored: sync stops writing to it as well.
        Schema::table('accounts', function (Blueprint $t) {
            $t->string('visibility', 16)->default('visible');
            $t->string('visibility_reason', 32)->nullable();
            $t->timestamp('visibility_changed_at')->nullable();
            $t->index(['workspace_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'visibility']);
            $t->dropColumn(['visibility', 'visibility_reason', 'visibility_changed_at']);
        });
    }
};
