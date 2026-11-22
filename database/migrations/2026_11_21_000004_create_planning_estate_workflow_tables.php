<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------- Tax ----------------
        Schema::create('tax_rule_packs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('jurisdiction', 8); // US|NJ
            $t->unsignedSmallInteger('tax_year');
            $t->string('version', 32);
            $t->string('legislation_status', 16)->default('enacted'); // enacted|provisional|hypothetical
            $t->string('status', 16)->default('draft'); // draft|approved|active|superseded
            $t->string('content_hash', 64);
            $t->json('summary')->nullable();
            $t->string('reviewer_name')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->text('approval_note')->nullable();
            $t->timestamps();
            $t->unique(['jurisdiction', 'tax_year', 'version']);
        });

        Schema::create('tax_inputs', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->string('key', 64);
            $t->string('jurisdiction', 8)->default('US');
            $t->decimal('amount', 20, 4)->nullable();
            $t->json('value')->nullable();
            $t->wsRef('person_id', 'persons');
            $t->string('label')->nullable();
            $t->json('fact_refs')->nullable();
            $t->provenance();
            $t->tracking();
            $t->index(['workspace_id', 'tax_unit_id', 'key']);
        });

        Schema::create('tax_payments', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->string('jurisdiction', 8);
            $t->string('kind', 24)->default('estimated'); // estimated|extension|balance_due|prior_year_overpayment
            $t->unsignedTinyInteger('installment')->nullable();
            $t->date('due_on')->nullable();
            $t->date('paid_on')->nullable();
            $t->decimal('amount', 20, 4);
            $t->string('status', 16)->default('planned'); // planned|paid
            $t->provenance();
            $t->tracking();
        });

        Schema::create('tax_carryforwards', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->string('jurisdiction', 8)->default('US');
            $t->string('kind', 32); // capital_loss_st|capital_loss_lt|passive_loss|amt_credit|charitable|nol|section_179|investment_interest
            $t->unsignedSmallInteger('origin_year');
            $t->decimal('amount', 20, 4);
            $t->unsignedSmallInteger('expires_year')->nullable();
            $t->wsRef('person_id', 'persons');
            $t->ulid('activity_ref')->nullable();
            $t->provenance();
            $t->tracking();
        });

        Schema::create('tax_runs', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->unsignedSmallInteger('tax_year');
            $t->string('scenario', 64)->default('baseline');
            $t->string('kind', 16)->default('return'); // return|forecast|strategy
            $t->string('status', 16)->default('pending'); // pending|estimate|calculated|reconciled|cpa_reviewed|unsupported|failed
            $t->json('rule_packs')->nullable();
            $t->string('engine_version', 32)->nullable();
            $t->string('input_hash', 64)->nullable();
            $t->json('input_manifest')->nullable();
            $t->json('summary')->nullable();
            $t->json('lines')->nullable();
            $t->json('trace')->nullable();
            $t->json('issues')->nullable();
            $t->json('reconciliation')->nullable();
            $t->ulid('job_id')->nullable();
            $t->ulid('reviewed_by')->nullable();
            $t->string('reviewer_name')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->boolean('immutable')->default(false);
            $t->tracking();
            $t->index(['workspace_id', 'tax_unit_id', 'tax_year']);
        });

        Schema::create('tax_strategies', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->unsignedSmallInteger('tax_year');
            $t->string('kind', 48);
            $t->string('title');
            $t->json('assumptions')->nullable();
            $t->json('evidence_required')->nullable();
            $t->json('authorities')->nullable();
            $t->date('deadline')->nullable();
            $t->json('impact')->nullable();
            $t->ulid('baseline_run_id')->nullable();
            $t->ulid('strategy_run_id')->nullable();
            $t->string('status', 16)->default('proposed'); // proposed|reviewing|accepted|declined|implemented
            $t->text('professional_feedback')->nullable();
            $t->boolean('requires_professional')->default(true);
            $t->tracking();
        });

        // ---------------- Planning ----------------
        Schema::create('plans', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 16)->default('joint'); // individual|joint|branch|consolidated
            $t->json('participant_person_ids');
            $t->unsignedSmallInteger('start_year');
            $t->unsignedSmallInteger('horizon_year');
            $t->string('status', 16)->default('draft');
            $t->boolean('is_template')->default(false);
            $t->tracking();
        });

        Schema::create('plan_scenarios', function (Blueprint $t) {
            $t->root();
            $t->wsRef('plan_id', 'plans', false);
            $t->ulid('parent_id')->nullable();
            $t->string('name');
            $t->boolean('is_baseline')->default(false);
            $t->json('assumptions');
            $t->json('events')->nullable();
            $t->json('strategy')->nullable();
            $t->json('people')->nullable();
            $t->string('status', 16)->default('draft'); // draft|published
            $t->tracking();
        });

        Schema::create('plan_runs', function (Blueprint $t) {
            $t->root();
            $t->wsRef('scenario_id', 'plan_scenarios', false);
            $t->string('kind', 16); // deterministic|montecarlo|stress
            $t->string('status', 16)->default('pending');
            $t->unsignedBigInteger('seed')->nullable();
            $t->unsignedInteger('paths')->nullable();
            $t->string('engine_version', 32)->nullable();
            $t->string('input_hash', 64)->nullable();
            $t->json('input_manifest')->nullable();
            $t->json('summary')->nullable();
            $t->longText('years')->nullable();
            $t->json('issues')->nullable();
            $t->ulid('job_id')->nullable();
            $t->tracking();
        });

        Schema::create('annual_snapshots', function (Blueprint $t) {
            $t->root();
            $t->unsignedSmallInteger('year');
            $t->string('basis', 16)->default('calendar');
            $t->string('view', 16)->default('consolidated');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('draft'); // draft|closed|superseded
            $t->json('data')->nullable();
            $t->json('bridge')->nullable();
            $t->json('coverage')->nullable();
            $t->text('reopen_reason')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->ulid('supersedes_id')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('content_hash', 64)->nullable();
            $t->tracking();
        });

        // ---------------- Estate & governance ----------------
        Schema::create('estate_documents', function (Blueprint $t) {
            $t->root();
            $t->string('kind', 32); // will|codicil|revocable_trust|irrevocable_trust|poa_financial|healthcare_directive|healthcare_proxy|guardianship|letter_of_instruction|prenup|buy_sell|other
            $t->string('title');
            $t->wsRef('person_id', 'persons');
            $t->wsRef('entity_id', 'legal_entities');
            $t->date('executed_on')->nullable();
            $t->date('review_due_on')->nullable();
            $t->string('original_location')->nullable();
            $t->string('witness_status', 16)->nullable();
            $t->string('notary_status', 16)->nullable();
            $t->ulid('attorney_contact_id')->nullable();
            $t->ulid('document_id')->nullable();
            $t->ulid('supersedes_id')->nullable();
            $t->string('status', 16)->default('executed'); // draft|executed|superseded|revoked
            $t->json('provisions')->nullable();
            $t->tracking();
        });

        Schema::create('estate_scenarios', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->json('death_order');
            $t->unsignedSmallInteger('assumed_year');
            $t->json('assumptions')->nullable();
            $t->json('overrides')->nullable();
            $t->tracking();
        });

        Schema::create('estate_runs', function (Blueprint $t) {
            $t->root();
            $t->wsRef('scenario_id', 'estate_scenarios', false);
            $t->string('status', 16)->default('pending');
            $t->string('engine_version', 32)->nullable();
            $t->string('input_hash', 64)->nullable();
            $t->json('input_manifest')->nullable();
            $t->json('summary')->nullable();
            $t->json('flows')->nullable();
            $t->json('unresolved')->nullable();
            $t->ulid('job_id')->nullable();
            $t->tracking();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24); // cpa|attorney|advisor|broker|insurance_agent|banker|physician|emergency|custodian|other
            $t->string('organization')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 32)->nullable();
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('emergency')->default(false);
            $t->tracking();
        });

        Schema::create('family_goals', function (Blueprint $t) {
            $t->root();
            $t->string('title');
            $t->string('kind', 24)->default('savings');
            $t->wsRef('person_id', 'persons');
            $t->money('target_amount');
            $t->date('target_date')->nullable();
            $t->string('priority', 16)->default('important'); // essential|important|aspirational
            $t->string('status', 16)->default('active');
            $t->text('notes')->nullable();
            $t->tracking();
        });

        Schema::create('decisions', function (Blueprint $t) {
            $t->root();
            $t->string('title');
            $t->date('decided_on');
            $t->text('context')->nullable();
            $t->text('decision');
            $t->json('participants')->nullable();
            $t->json('references')->nullable();
            $t->date('revisit_on')->nullable();
            $t->tracking();
        });

        Schema::create('meetings', function (Blueprint $t) {
            $t->root();
            $t->string('title');
            $t->dateTime('scheduled_at');
            $t->string('cadence', 16)->nullable();
            $t->json('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->tracking();
        });

        Schema::create('legal_obligations', function (Blueprint $t) {
            $t->root();
            $t->string('title');
            $t->string('kind', 32); // annual_report|franchise_tax|boi_report|trust_return|gift_return|filing|license|other
            $t->wsRef('entity_id', 'legal_entities');
            $t->string('jurisdiction', 16)->nullable();
            $t->date('due_on');
            $t->string('recurrence', 16)->nullable(); // annual|quarterly|monthly|none
            $t->string('status', 16)->default('open');
            $t->wsRef('responsible_person_id', 'persons');
            $t->text('notes')->nullable();
            $t->tracking();
        });

        Schema::create('emergency_binders', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->text('instructions')->nullable();
            $t->json('contents'); // {contact_ids, policy_ids, account_ids, document_ids, estate_document_ids, asset_ids}
            $t->json('recipient_membership_ids')->nullable();
            $t->unsignedSmallInteger('release_hours')->default(72);
            $t->boolean('requires_secondary_approval')->default(true);
            $t->tracking();
        });

        Schema::create('break_glass_requests', function (Blueprint $t) {
            $t->root();
            $t->wsRef('binder_id', 'emergency_binders', false);
            $t->wsRef('requester_membership_id', 'memberships', false);
            $t->text('reason');
            $t->string('status', 16)->default('requested'); // requested|verified|approved|released|denied|expired|revoked
            $t->json('verifications')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->ulid('grant_id')->nullable();
            $t->tracking();
        });

        // ---------------- Workflow & reporting ----------------
        Schema::create('review_items', function (Blueprint $t) {
            $t->root();
            $t->string('domain', 16)->default('governance');
            $t->string('kind', 32);
            $t->string('subject_type', 32)->nullable();
            $t->ulid('subject_id')->nullable();
            $t->string('title');
            $t->text('reason');
            $t->json('evidence')->nullable();
            $t->string('severity', 8)->default('normal');
            $t->string('status', 16)->default('open');
            $t->ulid('assigned_membership_id')->nullable();
            $t->date('due_on')->nullable();
            $t->string('dedup_key', 128)->nullable();
            $t->ulid('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->json('resolution')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'status']);
            $t->unique(['workspace_id', 'dedup_key']);
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->root();
            $t->string('domain', 16)->default('governance');
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('due_on')->nullable();
            $t->string('status', 16)->default('open');
            $t->string('priority', 8)->default('normal');
            $t->ulid('assignee_membership_id')->nullable();
            $t->string('related_type', 32)->nullable();
            $t->ulid('related_id')->nullable();
            $t->string('dedup_key', 128)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->tracking();
            $t->unique(['workspace_id', 'dedup_key']);
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('membership_id', 'memberships', false);
            $t->string('kind', 48);
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->string('dedup_key', 128);
            $t->string('severity', 8)->default('normal');
            $t->date('due_on')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('snoozed_until')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'membership_id', 'dedup_key']);
        });

        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('notification_id', 'notifications', false);
            $t->string('channel', 8)->default('email');
            $t->text('recipient');
            $t->string('status', 16)->default('queued'); // queued|sending|accepted|failed|delivery_unknown|suppressed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->string('provider_message_id')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('membership_id', 'memberships', false);
            $t->boolean('email_enabled')->default(true);
            $t->string('email_digest', 16)->default('immediate'); // immediate|daily|weekly|off
            $t->string('quiet_start', 5)->nullable();
            $t->string('quiet_end', 5)->nullable();
            $t->string('timezone', 64)->default('America/New_York');
            $t->json('muted_kinds')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'membership_id']);
        });

        Schema::create('report_manifests', function (Blueprint $t) {
            $t->root();
            $t->string('kind', 32);
            $t->string('title');
            $t->json('params')->nullable();
            $t->date('as_of')->nullable();
            $t->char('currency', 3)->default('USD');
            $t->json('run_ids')->nullable();
            $t->json('source_scope')->nullable();
            $t->json('redactions')->nullable();
            $t->string('status', 16)->default('pending');
            $t->ulid('document_id')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->ulid('job_id')->nullable();
            $t->tracking();
        });

        Schema::create('export_grants', function (Blueprint $t) {
            $t->root();
            $t->wsRef('report_manifest_id', 'report_manifests', false);
            $t->string('recipient_name');
            $t->string('recipient_email');
            $t->string('token_hash', 64)->nullable()->unique();
            $t->string('verification_code_hash', 64)->nullable();
            $t->unsignedTinyInteger('verification_attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedSmallInteger('max_downloads')->default(3);
            $t->unsignedSmallInteger('download_count')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->tracking();
        });
    }

    public function down(): void
    {
        foreach (['export_grants', 'report_manifests', 'notification_preferences', 'notification_deliveries', 'notifications', 'tasks',
            'review_items', 'break_glass_requests', 'emergency_binders', 'legal_obligations', 'meetings', 'decisions', 'family_goals',
            'contacts', 'estate_runs', 'estate_scenarios', 'estate_documents', 'annual_snapshots', 'plan_runs', 'plan_scenarios', 'plans',
            'tax_strategies', 'tax_runs', 'tax_carryforwards', 'tax_payments', 'tax_inputs', 'tax_rule_packs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
