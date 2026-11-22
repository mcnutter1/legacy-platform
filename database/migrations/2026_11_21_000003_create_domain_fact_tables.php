<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------- Vault ----------------
        Schema::create('documents', function (Blueprint $t) {
            $t->root();
            $t->string('title');
            $t->string('doc_type', 48)->default('other');
            $t->string('status', 16)->default('quarantined'); // quarantined|clean|rejected|processing|ready|purged
            $t->unsignedSmallInteger('tax_year')->nullable();
            $t->ulid('current_version_id')->nullable();
            $t->string('retention_policy', 32)->default('indefinite');
            $t->date('retain_until')->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->string('original_location')->nullable();
            $t->json('tags')->nullable();
            $t->string('origin', 24)->default('upload'); // upload|mobile|mcp|import|generated
            $t->text('rejection_reason')->nullable();
            $t->tracking();
        });

        Schema::create('document_versions', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('document_id', 'documents', false);
            $t->unsignedInteger('version_no');
            $t->string('original_filename')->nullable();
            $t->string('detected_mime', 96);
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('storage_path');
            $t->text('wrapped_key');
            $t->string('key_id', 16);
            $t->unsignedInteger('page_count')->nullable();
            $t->string('scan_status', 16)->default('pending'); // pending|clean|infected|unscanned|error
            $t->string('scan_engine', 32)->nullable();
            $t->json('content_flags')->nullable();
            $t->string('text_path')->nullable();
            $t->ulid('uploaded_by')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'sha256']);
        });

        Schema::create('document_extractions', function (Blueprint $t) {
            $t->root();
            $t->wsRef('document_id', 'documents', false);
            $t->wsRef('version_id', 'document_versions', false);
            $t->string('schema', 48);
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->string('engine', 24); // deterministic|openai|manual
            $t->string('model')->nullable();
            $t->string('run_ref')->nullable();
            $t->string('status', 16)->default('pending'); // pending|needs_review|approved|rejected|failed
            $t->json('fields')->nullable();
            $t->json('checks')->nullable();
            $t->json('overrides')->nullable();
            $t->string('target_type', 32)->nullable();
            $t->ulid('target_id')->nullable();
            $t->string('client_provenance')->nullable();
            $t->ulid('reviewed_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->ulid('job_id')->nullable();
            $t->tracking();
        });

        Schema::create('download_tokens', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary();
            $t->ulid('workspace_id');
            $t->ulid('version_id');
            $t->ulid('user_id')->nullable();
            $t->ulid('export_grant_id')->nullable();
            $t->string('disposition', 16)->default('attachment');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
        });

        Schema::create('upload_links', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary();
            $t->ulid('workspace_id');
            $t->ulid('membership_id');
            $t->string('purpose', 32);
            $t->json('context')->nullable();
            $t->ulid('draft_id')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });

        // ---------------- Compensation ----------------
        Schema::create('employments', function (Blueprint $t) {
            $t->root();
            $t->wsRef('person_id', 'persons', false);
            $t->string('employer_name');
            $t->wsRef('employer_entity_id', 'legal_entities');
            $t->string('title')->nullable();
            $t->string('kind', 16)->default('w2');
            $t->date('start_on')->nullable();
            $t->date('end_on')->nullable();
            $t->money('base_salary');
            $t->decimal('bonus_target_pct', 8, 4)->nullable();
            $t->string('pay_frequency', 16)->default('biweekly');
            $t->string('work_state', 4)->default('NJ');
            $t->decimal('retirement_deferral_pct', 8, 4)->nullable();
            $t->decimal('employer_match_pct', 8, 4)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->tracking();
        });

        Schema::create('payroll_snapshots', function (Blueprint $t) {
            $t->root();
            $t->wsRef('employment_id', 'employments', false);
            $t->wsRef('person_id', 'persons', false);
            $t->date('pay_date');
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('run_kind', 16)->default('regular');
            $t->string('status', 16)->default('draft');
            $t->money('gross');
            $t->money('net');
            $t->json('current');
            $t->json('ytd')->nullable();
            $t->json('checks')->nullable();
            $t->ulid('document_id')->nullable();
            $t->ulid('extraction_id')->nullable();
            $t->ulid('supersedes_id')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->provenance();
            $t->tracking();
            $t->index(['workspace_id', 'person_id', 'pay_date']);
        });

        Schema::create('tax_documents', function (Blueprint $t) {
            $t->root();
            $t->unsignedSmallInteger('tax_year');
            $t->string('form', 16);
            $t->wsRef('person_id', 'persons');
            $t->wsRef('entity_id', 'legal_entities');
            $t->string('payer')->nullable();
            $t->json('boxes');
            $t->string('status', 16)->default('draft');
            $t->boolean('corrected')->default(false);
            $t->ulid('supersedes_id')->nullable();
            $t->ulid('document_id')->nullable();
            $t->provenance();
            $t->tracking();
            $t->index(['workspace_id', 'tax_year']);
        });

        Schema::create('equity_grants', function (Blueprint $t) {
            $t->root();
            $t->wsRef('person_id', 'persons', false);
            $t->wsRef('employment_id', 'employments');
            $t->string('company_name');
            $t->string('symbol', 16)->nullable();
            $t->string('grant_type', 16); // iso|nso|rsu|psu|rsa|miu|profits_interest|espp|carry|sar|phantom
            $t->string('grant_ref')->nullable();
            $t->date('grant_date');
            $t->decimal('units', 28, 10);
            $t->decimal('strike_price', 24, 8)->nullable();
            $t->decimal('fmv_at_grant', 24, 8)->nullable();
            $t->date('expiration_date')->nullable();
            $t->date('vest_start_date')->nullable();
            $t->json('vesting')->nullable(); // {cliff_months, total_months, frequency_months, performance}
            $t->json('acceleration')->nullable();
            $t->json('separation_terms')->nullable();
            $t->string('settlement', 16)->nullable();
            $t->date('election_83b_deadline')->nullable();
            $t->boolean('election_83b_filed')->default(false);
            $t->date('lockup_until')->nullable();
            $t->decimal('participation_threshold', 24, 8)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->string('status', 16)->default('active');
            $t->ulid('document_id')->nullable();
            $t->tracking();
        });

        Schema::create('equity_tranches', function (Blueprint $t) {
            $t->root();
            $t->wsRef('grant_id', 'equity_grants', false);
            $t->date('vest_date');
            $t->decimal('units', 28, 10);
            $t->string('condition', 16)->default('time'); // time|performance|liquidity|double_trigger
            $t->string('status', 16)->default('scheduled'); // scheduled|vested|forfeited
            $t->decimal('units_exercised', 28, 10)->default(0);
            $t->decimal('units_settled', 28, 10)->default(0);
            $t->tracking();
        });

        Schema::create('equity_events', function (Blueprint $t) {
            $t->root();
            $t->wsRef('grant_id', 'equity_grants', false);
            $t->wsRef('tranche_id', 'equity_tranches');
            $t->string('kind', 24); // vest|exercise|settle|sell|sell_to_cover|forfeit|expire|disqualifying_disposition|qualifying_disposition
            $t->date('event_date');
            $t->decimal('units', 28, 10);
            $t->decimal('fmv', 24, 8)->nullable();
            $t->decimal('price', 24, 8)->nullable();
            $t->decimal('units_withheld', 28, 10)->nullable();
            $t->money('tax_withheld');
            $t->money('proceeds');
            $t->money('ordinary_income');
            $t->string('exercise_method', 16)->nullable();
            $t->wsRef('payroll_snapshot_id', 'payroll_snapshots');
            $t->wsRef('transaction_id', 'transactions');
            $t->wsRef('lot_id', 'lots');
            $t->string('reconciliation', 16)->default('pending'); // pending|matched_payroll|matched_broker|reconciled
            $t->provenance();
            $t->tracking();
        });

        Schema::create('company_valuations', function (Blueprint $t) {
            $t->root();
            $t->string('company_name');
            $t->date('as_of');
            $t->string('kind', 16);
            $t->decimal('price_per_share', 24, 8);
            $t->decimal('fully_diluted_shares', 28, 4)->nullable();
            $t->json('cap_table')->nullable();
            $t->string('source')->nullable();
            $t->tracking();
        });

        // ---------------- Real estate & assets ----------------
        Schema::create('properties', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24);
            $t->string('address_line')->nullable();
            $t->string('city')->nullable();
            $t->string('state', 8)->nullable();
            $t->string('postal_code', 16)->nullable();
            $t->string('country', 2)->default('US');
            $t->date('acquired_on')->nullable();
            $t->money('purchase_price');
            $t->money('closing_costs');
            $t->money('land_value');
            $t->money('building_value');
            $t->date('placed_in_service_on')->nullable();
            $t->decimal('recovery_years', 5, 2)->nullable();
            $t->string('depreciation_method', 16)->default('SL-MM');
            $t->money('prior_depreciation');
            $t->date('converted_to_rental_on')->nullable();
            $t->money('fmv_at_conversion');
            $t->wsRef('entity_id', 'legal_entities');
            $t->string('status', 16)->default('owned');
            $t->tracking();
        });

        Schema::create('property_stays', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->date('check_in');
            $t->date('check_out');
            $t->string('kind', 24)->default('guest');
            $t->money('gross_rent');
            $t->money('cleaning_fee');
            $t->money('platform_fee');
            $t->money('taxes_collected');
            $t->money('payout');
            $t->money('refund');
            $t->boolean('cancelled')->default(false);
            $t->string('booking_ref_hash', 64)->nullable();
            $t->string('channel', 32)->nullable();
            $t->provenance();
            $t->tracking();
            $t->index(['workspace_id', 'property_id', 'check_in']);
        });

        Schema::create('property_use_logs', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->date('log_date');
            $t->string('kind', 16); // rental|personal|maintenance|available|vacant
            $t->decimal('hours', 6, 2)->nullable();
            $t->wsRef('participant_person_id', 'persons');
            $t->string('activity')->nullable();
            $t->ulid('evidence_document_id')->nullable();
            $t->provenance();
            $t->tracking();
        });

        Schema::create('property_improvements', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->string('description');
            $t->date('placed_in_service_on');
            $t->decimal('cost', 20, 4);
            $t->decimal('recovery_years', 5, 2)->default(27.5);
            $t->string('method', 16)->default('SL-MM');
            $t->string('kind', 24)->default('building');
            $t->boolean('bonus_eligible')->default(false);
            $t->tracking();
        });

        Schema::create('property_expenses', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->date('incurred_on');
            $t->string('category', 32);
            $t->decimal('amount', 20, 4);
            $t->string('treatment', 16)->default('expense');
            $t->wsRef('improvement_id', 'property_improvements');
            $t->wsRef('transaction_id', 'transactions');
            $t->string('notes')->nullable();
            $t->provenance();
            $t->tracking();
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24);
            $t->text('description')->nullable();
            $t->string('serial_number')->nullable();
            $t->string('location')->nullable();
            $t->date('acquired_on')->nullable();
            $t->money('cost');
            $t->char('currency', 3)->default('USD');
            $t->wsRef('entity_id', 'legal_entities');
            $t->string('liquidity', 16)->default('illiquid');
            $t->string('status', 16)->default('owned');
            $t->date('disposed_on')->nullable();
            $t->money('disposal_amount');
            $t->decimal('unfunded_commitment', 20, 4)->nullable();
            $t->tracking();
        });

        // ---------------- Debts ----------------
        Schema::create('liabilities', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24);
            $t->string('lender')->nullable();
            $t->wsRef('account_id', 'accounts');
            $t->char('currency', 3)->default('USD');
            $t->money('original_principal');
            $t->money('current_balance');
            $t->date('balance_as_of')->nullable();
            $t->decimal('interest_rate', 12, 8)->nullable();
            $t->string('rate_type', 16)->default('fixed');
            $t->string('rate_index', 24)->nullable();
            $t->decimal('rate_margin', 12, 8)->nullable();
            $t->json('rate_caps')->nullable();
            $t->unsignedSmallInteger('rate_reset_months')->nullable();
            $t->date('next_reset_on')->nullable();
            $t->string('compounding', 24)->default('monthly');
            $t->unsignedSmallInteger('term_months')->nullable();
            $t->date('origination_on')->nullable();
            $t->date('first_payment_on')->nullable();
            $t->money('payment_amount');
            $t->string('payment_frequency', 16)->default('monthly');
            $t->money('escrow_amount');
            $t->date('maturity_on')->nullable();
            $t->money('balloon_amount');
            $t->money('credit_limit');
            $t->string('collateral_type', 24)->nullable();
            $t->ulid('collateral_id')->nullable();
            $t->boolean('recourse')->default(true);
            $t->boolean('contingent')->default(false);
            $t->string('deductible_interest_kind', 24)->default('none');
            $t->text('prepayment_terms')->nullable();
            $t->string('status', 16)->default('active');
            $t->tracking();
        });

        Schema::create('liability_payments', function (Blueprint $t) {
            $t->root();
            $t->wsRef('liability_id', 'liabilities', false);
            $t->date('paid_on');
            $t->decimal('total', 20, 4);
            $t->money('principal');
            $t->money('interest');
            $t->money('escrow');
            $t->money('fees');
            $t->wsRef('transaction_id', 'transactions');
            $t->provenance();
            $t->tracking();
        });

        // ---------------- Insurance ----------------
        Schema::create('insurance_policies', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24);
            $t->string('carrier')->nullable();
            $t->string('agent')->nullable();
            $t->string('policy_number_last4', 4)->nullable();
            $t->string('owner_type', 16)->nullable();
            $t->ulid('owner_id')->nullable();
            $t->json('insured')->nullable();
            $t->date('term_start')->nullable();
            $t->date('term_end')->nullable();
            $t->money('premium');
            $t->string('premium_frequency', 16)->default('annual');
            $t->money('deductible');
            $t->money('face_amount');
            $t->money('cash_value');
            $t->date('cash_value_as_of')->nullable();
            $t->money('loan_balance');
            $t->money('surrender_charge');
            $t->money('cost_basis');
            $t->money('required_underlying_auto');
            $t->money('required_underlying_home');
            $t->string('carrier_rating', 16)->nullable();
            $t->string('carrier_rating_source')->nullable();
            $t->date('retroactive_date')->nullable();
            $t->string('tail_terms')->nullable();
            $t->string('status', 16)->default('active');
            $t->ulid('document_id')->nullable();
            $t->tracking();
        });

        Schema::create('policy_coverages', function (Blueprint $t) {
            $t->root();
            $t->wsRef('policy_id', 'insurance_policies', false);
            $t->string('coverage', 64);
            $t->money('limit_amount');
            $t->money('sublimit');
            $t->money('deductible');
            $t->money('attachment_point');
            $t->string('valuation_basis', 8)->nullable(); // RC|ACV|agreed
            $t->string('subject_type', 24)->nullable();
            $t->ulid('subject_id')->nullable();
            $t->json('exclusions')->nullable();
            $t->json('endorsements')->nullable();
            $t->unsignedSmallInteger('waiting_period_days')->nullable();
            $t->string('evidence_locator')->nullable();
            $t->tracking();
        });

        Schema::create('insurance_claims', function (Blueprint $t) {
            $t->root();
            $t->wsRef('policy_id', 'insurance_policies', false);
            $t->date('loss_date');
            $t->string('description');
            $t->money('estimated_loss');
            $t->money('paid_amount');
            $t->string('status', 16)->default('open');
            $t->tracking();
        });

        Schema::create('insurance_shopping_cases', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->wsRef('policy_id', 'insurance_policies');
            $t->string('need', 32);
            $t->json('baseline')->nullable();
            $t->string('status', 16)->default('open');
            $t->text('decision')->nullable();
            $t->tracking();
        });

        Schema::create('insurance_quotes', function (Blueprint $t) {
            $t->root();
            $t->wsRef('case_id', 'insurance_shopping_cases', false);
            $t->string('carrier');
            $t->money('premium');
            $t->date('term_start')->nullable();
            $t->json('limits')->nullable();
            $t->json('deductibles')->nullable();
            $t->json('endorsements')->nullable();
            $t->json('exclusions')->nullable();
            $t->string('carrier_rating', 16)->nullable();
            $t->text('notes')->nullable();
            $t->tracking();
        });
    }

    public function down(): void
    {
        foreach (['insurance_quotes', 'insurance_shopping_cases', 'insurance_claims', 'policy_coverages', 'insurance_policies',
            'liability_payments', 'liabilities', 'assets', 'property_expenses', 'property_improvements', 'property_use_logs',
            'property_stays', 'properties', 'company_valuations', 'equity_events', 'equity_tranches', 'equity_grants',
            'tax_documents', 'payroll_snapshots', 'employments', 'upload_links', 'download_tokens', 'document_extractions',
            'document_versions', 'documents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
