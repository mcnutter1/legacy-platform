<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('central_principal_type', 16);
            $t->string('central_principal_id', 128);
            $t->string('email')->nullable();
            $t->string('name')->nullable();
            $t->string('username')->nullable();
            $t->json('central_roles')->nullable();
            $t->text('totp_secret')->nullable();
            $t->timestamp('totp_enabled_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
            $t->unique(['central_principal_type', 'central_principal_id']);
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id', 26)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration')->index();
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration')->index();
        });

        Schema::create('auth_callback_nonces', function (Blueprint $t) {
            $t->string('sig_hash', 64)->primary();
            $t->timestamp('expires_at')->index();
        });

        Schema::create('workspaces', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug', 64)->unique();
            $t->char('base_currency', 3)->default('USD');
            $t->string('timezone', 64)->default('America/New_York');
            $t->string('home_jurisdiction', 16)->default('US-NJ');
            $t->json('settings')->nullable();
            $t->ulid('created_by')->nullable();
            $t->unsignedInteger('edit_version')->default(1);
            $t->timestamps();
        });

        Schema::create('scopes', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->string('name');
            $t->string('kind', 16); // joint|private|branch|entity|shared|advisor
            $t->ulid('owner_membership_id')->nullable();
            $t->text('description')->nullable();
            $t->tracking();
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('users');
            $t->ulid('person_id')->nullable();
            $t->string('role', 24); // administrator|principal|member|advisor|dependent
            $t->string('status', 24)->default('active'); // invited|pending_approval|active|suspended|removed
            $t->string('label')->nullable();
            $t->string('invited_email')->nullable();
            $t->string('invite_token_hash', 64)->nullable()->unique();
            $t->timestamp('invite_expires_at')->nullable();
            $t->unsignedInteger('access_version')->default(1);
            $t->timestamp('expires_at')->nullable();
            $t->boolean('is_recovery_admin')->default(false);
            $t->tracking();
            $t->unique(['workspace_id', 'user_id']);
        });

        Schema::create('scope_stewards', function (Blueprint $t) {
            $t->id();
            $t->ulid('workspace_id');
            $t->wsRef('scope_id', 'scopes', false);
            $t->wsRef('membership_id', 'memberships', false);
            $t->timestamps();
            $t->unique(['scope_id', 'membership_id']);
        });

        Schema::create('access_grants', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('membership_id', 'memberships', false);
            $t->wsRef('scope_id', 'scopes', false);
            $t->json('domains');
            $t->json('actions');
            $t->json('years')->nullable();
            $t->json('conditions')->nullable();
            $t->string('purpose')->nullable();
            $t->ulid('granted_by')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->ulid('revoked_by')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'membership_id']);
        });

        Schema::create('consents', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('membership_id', 'memberships', false);
            $t->string('kind', 48);
            $t->string('subject_type', 48)->nullable();
            $t->ulid('subject_id')->nullable();
            $t->json('details')->nullable();
            $t->timestamp('granted_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('workspace_id')->nullable()->index();
            $t->string('actor_type', 24);
            $t->string('actor_id', 64)->nullable();
            $t->ulid('membership_id')->nullable();
            $t->string('action', 96);
            $t->string('subject_type', 64)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->ulid('scope_id')->nullable();
            $t->string('correlation_id', 64)->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->string('prev_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->timestamp('created_at', 6);
            $t->index(['workspace_id', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('outbox_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('workspace_id')->nullable()->index();
            $t->string('type', 96);
            $t->string('aggregate_type', 64)->nullable();
            $t->string('aggregate_id', 64)->nullable();
            $t->json('payload');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('available_at')->index();
            $t->timestamp('dispatched_at')->nullable()->index();
            $t->timestamp('failed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('created_at');
        });

        Schema::create('outbox_handled', function (Blueprint $t) {
            $t->ulid('event_id');
            $t->string('handler', 128);
            $t->timestamp('handled_at');
            $t->primary(['event_id', 'handler']);
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('principal', 96);
            $t->string('key_hash', 64);
            $t->string('request_hash', 64);
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->longText('response_body')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('expires_at')->index();
            $t->unique(['principal', 'key_hash']);
        });

        Schema::create('compute_jobs', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->ulid('scope_id')->nullable();
            $t->string('type', 64);
            $t->string('queue', 16)->default('interactive');
            $t->string('status', 16)->default('queued'); // queued|leased|succeeded|failed|timed_out|cancelled|retryable
            $t->unsignedSmallInteger('priority')->default(100);
            $t->ulid('initiator_user_id')->nullable();
            $t->ulid('initiator_membership_id')->nullable();
            $t->string('initiator_kind', 16)->default('user');
            $t->string('subject_type', 64)->nullable();
            $t->ulid('subject_id')->nullable();
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->json('params')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('max_attempts')->default(3);
            $t->string('lease_token', 64)->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('available_at');
            $t->json('result')->nullable();
            $t->text('error')->nullable();
            $t->json('metrics')->nullable();
            $t->string('input_hash', 64)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->timestamps();
            $t->index(['queue', 'status', 'available_at', 'priority']);
        });

        Schema::create('oauth_clients', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('client_id', 64)->unique();
            $t->string('name');
            $t->json('redirect_uris');
            $t->string('client_secret_hash', 64)->nullable();
            $t->string('registration', 16)->default('dynamic');
            $t->json('scopes')->nullable();
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
        });

        Schema::create('oauth_codes', function (Blueprint $t) {
            $t->string('code_hash', 64)->primary();
            $t->string('client_id', 64);
            $t->ulid('user_id');
            $t->ulid('workspace_id');
            $t->ulid('membership_id');
            $t->json('scopes');
            $t->text('redirect_uri');
            $t->string('code_challenge', 128);
            $t->string('resource')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
        });

        Schema::create('oauth_tokens', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('kind', 8);
            $t->string('token_hash', 64)->unique();
            $t->string('client_id', 64)->index();
            $t->ulid('user_id');
            $t->ulid('workspace_id')->index();
            $t->ulid('membership_id')->index();
            $t->json('scopes');
            $t->string('resource')->nullable();
            $t->ulid('family_id')->index();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['oauth_tokens', 'oauth_codes', 'oauth_clients', 'compute_jobs', 'idempotency_keys', 'outbox_handled', 'outbox_events',
            'audit_events', 'consents', 'access_grants', 'scope_stewards', 'memberships', 'scopes', 'workspaces',
            'auth_callback_nonces', 'cache_locks', 'cache', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
