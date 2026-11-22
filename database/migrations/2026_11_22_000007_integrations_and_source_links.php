<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_connections', function (Blueprint $t) {
            $t->string('auth_kind', 16)->nullable();
            $t->string('server_url')->nullable();
            $t->string('credential_hint', 32)->nullable();
            $t->timestamp('credential_set_at')->nullable();
            $t->timestamp('token_expires_at')->nullable();
            $t->json('oauth_client')->nullable();
            $t->json('tool_catalog')->nullable();
            $t->json('tool_map')->nullable();
            $t->json('last_preview')->nullable();
            $t->json('last_sync_stats')->nullable();
            $t->ulid('owner_membership_id')->nullable();
            $t->ulid('last_job_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->unsignedSmallInteger('sync_interval_hours')->default(24);
        });

        // One row per (connection, external record): the canonical record plus every source that reported it.
        Schema::create('external_links', function (Blueprint $t) {
            $t->root();
            $t->wsRef('connection_id', 'source_connections', false);
            $t->string('provider', 32);
            $t->string('entity_type', 24);
            $t->ulid('entity_id');
            $t->string('external_id', 191);
            $t->string('external_name')->nullable();
            $t->string('match_method', 24)->default('created');
            $t->json('last_values')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->tracking();
            $t->unique(['connection_id', 'entity_type', 'external_id']);
            $t->index(['workspace_id', 'entity_type', 'entity_id']);
        });

        Schema::create('source_discrepancies', function (Blueprint $t) {
            $t->root();
            $t->string('entity_type', 24);
            $t->ulid('entity_id');
            $t->string('kind', 32);
            $t->string('field', 64)->nullable();
            $t->json('observed');
            $t->decimal('difference', 28, 10)->nullable();
            $t->ulid('candidate_id')->nullable();
            $t->string('status', 16)->default('open');
            $t->text('resolution')->nullable();
            $t->ulid('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'status']);
            $t->index(['workspace_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_discrepancies');
        Schema::dropIfExists('external_links');
        Schema::table('source_connections', fn (Blueprint $t) => $t->dropColumn(['auth_kind', 'server_url', 'credential_hint', 'credential_set_at', 'token_expires_at',
            'oauth_client', 'tool_catalog', 'tool_map', 'last_preview', 'last_sync_stats', 'owner_membership_id', 'last_job_id', 'approved_at', 'approved_by', 'sync_interval_hours']));
    }
};
