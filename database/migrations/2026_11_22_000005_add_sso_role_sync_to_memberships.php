<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            // Role follows McNutt Cloud roles until an administrator pins it.
            $t->boolean('sso_managed')->default(false);
            $t->timestamp('sso_suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            $t->dropColumn(['sso_managed', 'sso_suspended_at']);
        });
    }
};
