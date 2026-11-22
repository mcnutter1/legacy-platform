<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $t) {
            $t->root();
            $t->string('display_name');
            $t->string('legal_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->date('death_date')->nullable();
            $t->string('citizenship', 32)->nullable();
            $t->boolean('is_us_citizen')->default(true);
            $t->text('ssn_last4')->nullable(); // encrypted cast
            $t->string('email')->nullable();
            $t->string('phone', 32)->nullable();
            $t->unsignedSmallInteger('life_expectancy_age')->nullable();
            $t->text('notes')->nullable();
            $t->tracking();
        });

        Schema::table('memberships', function (Blueprint $t) {
            $t->foreign(['workspace_id', 'person_id'])->references(['workspace_id', 'id'])->on('persons');
        });

        Schema::create('relationships', function (Blueprint $t) {
            $t->root();
            $t->wsRef('person_id', 'persons', false);
            $t->wsRef('related_person_id', 'persons', false);
            $t->string('kind', 32);
            $t->effective();
            $t->text('notes')->nullable();
            $t->tracking();
        });

        Schema::create('households', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('address_line')->nullable();
            $t->string('city')->nullable();
            $t->string('state', 8)->nullable();
            $t->string('postal_code', 16)->nullable();
            $t->effective();
            $t->tracking();
        });

        Schema::create('household_members', function (Blueprint $t) {
            $t->root();
            $t->wsRef('household_id', 'households', false);
            $t->wsRef('person_id', 'persons', false);
            $t->string('role', 24)->default('member');
            $t->effective();
            $t->tracking();
        });

        Schema::create('tax_units', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->unsignedSmallInteger('tax_year');
            $t->string('filing_status', 8); // single|mfj|mfs|hoh|qss
            $t->wsRef('primary_person_id', 'persons', false);
            $t->wsRef('spouse_person_id', 'persons');
            $t->string('state', 4)->default('NJ');
            $t->string('residency_status', 16)->default('resident');
            $t->date('part_year_from')->nullable();
            $t->date('part_year_to')->nullable();
            $t->text('notes')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'tax_year']);
        });

        Schema::create('tax_unit_dependents', function (Blueprint $t) {
            $t->root();
            $t->wsRef('tax_unit_id', 'tax_units', false);
            $t->wsRef('person_id', 'persons', false);
            $t->unsignedTinyInteger('months_lived')->default(12);
            $t->boolean('qualifies_ctc')->default(true);
            $t->boolean('qualifies_odc')->default(false);
            $t->boolean('full_time_student')->default(false);
            $t->tracking();
        });

        Schema::create('tax_residencies', function (Blueprint $t) {
            $t->root();
            $t->wsRef('person_id', 'persons', false);
            $t->string('jurisdiction', 16);
            $t->string('status', 16);
            $t->effective();
            $t->text('evidence')->nullable();
            $t->tracking();
        });

        Schema::create('legal_entities', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('kind', 24);
            $t->string('jurisdiction', 16)->nullable();
            $t->date('formed_on')->nullable();
            $t->date('dissolved_on')->nullable();
            $t->string('ein_last4', 4)->nullable();
            $t->string('tax_classification', 32)->nullable();
            $t->string('fiscal_year_end', 5)->default('12-31');
            $t->string('accounting_basis', 8)->default('cash');
            $t->string('registered_agent')->nullable();
            $t->string('status', 16)->default('active');
            $t->text('notes')->nullable();
            $t->tracking();
        });

        Schema::create('entity_classifications', function (Blueprint $t) {
            $t->root();
            $t->wsRef('entity_id', 'legal_entities', false);
            $t->string('classification', 32);
            $t->string('election_form', 16)->nullable();
            $t->effective();
            $t->ulid('evidence_document_id')->nullable();
            $t->tracking();
        });

        Schema::create('trust_details', function (Blueprint $t) {
            $t->root();
            $t->wsRef('entity_id', 'legal_entities', false);
            $t->string('trust_type', 32);
            $t->boolean('revocable')->default(true);
            $t->boolean('grantor_trust')->default(true);
            $t->json('settlor_person_ids')->nullable();
            $t->string('governing_law', 16)->nullable();
            $t->string('funding_status', 16)->default('unknown'); // unfunded|partial|funded|unknown
            $t->json('distribution_terms')->nullable();
            $t->boolean('gst_exempt')->default(false);
            $t->boolean('crummey_powers')->default(false);
            $t->tracking();
            $t->unique(['workspace_id', 'entity_id']);
        });

        Schema::create('ownership_interests', function (Blueprint $t) {
            $t->root();
            $t->string('owner_type', 16); // person|entity
            $t->ulid('owner_id');
            $t->string('subject_type', 24);
            $t->ulid('subject_id');
            $t->string('basis', 16)->default('legal_title'); // legal_title|beneficial|economic|voting
            $t->decimal('fraction', 16, 12);
            $t->string('title_form', 24)->nullable();
            $t->string('capacity', 16)->default('individual');
            $t->string('interest_class', 32)->nullable();
            $t->decimal('units', 28, 10)->nullable();
            $t->effective();
            $t->ulid('evidence_document_id')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'subject_type', 'subject_id']);
            $t->index(['workspace_id', 'owner_type', 'owner_id']);
        });

        Schema::create('control_roles', function (Blueprint $t) {
            $t->root();
            $t->wsRef('entity_id', 'legal_entities', false);
            $t->wsRef('person_id', 'persons', false);
            $t->string('role', 32);
            $t->effective();
            $t->tracking();
        });

        Schema::create('authority_roles', function (Blueprint $t) {
            $t->root();
            $t->string('kind', 32);
            $t->wsRef('holder_person_id', 'persons');
            $t->ulid('holder_contact_id')->nullable();
            $t->string('subject_type', 24)->nullable();
            $t->ulid('subject_id')->nullable();
            $t->unsignedTinyInteger('order')->default(1);
            $t->effective();
            $t->text('conditions')->nullable();
            $t->ulid('evidence_document_id')->nullable();
            $t->tracking();
        });

        Schema::create('beneficiary_designations', function (Blueprint $t) {
            $t->root();
            $t->string('subject_type', 24);
            $t->ulid('subject_id');
            $t->string('beneficiary_type', 16); // person|entity|class|estate|charity
            $t->ulid('beneficiary_id')->nullable();
            $t->string('beneficiary_label')->nullable();
            $t->string('tier', 16)->default('primary');
            $t->decimal('share_fraction', 16, 12);
            $t->boolean('per_stirpes')->default(false);
            $t->json('conditions')->nullable();
            $t->effective();
            $t->date('verified_on')->nullable();
            $t->ulid('evidence_document_id')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'subject_type', 'subject_id'], 'beneficiary_designations_subject_idx');
        });

        Schema::create('source_connections', function (Blueprint $t) {
            $t->root();
            $t->string('provider', 32);
            $t->string('label');
            $t->string('mode', 8)->default('file');
            $t->string('status', 24)->default('active');
            $t->text('credential_encrypted')->nullable();
            $t->json('config')->nullable();
            $t->json('cursor')->nullable();
            $t->unsignedSmallInteger('freshness_hours')->default(720);
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->text('last_error')->nullable();
            $t->tracking();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('institution')->nullable();
            $t->string('kind', 24);
            $t->string('tax_bucket', 16)->default('taxable');
            $t->char('currency', 3)->default('USD');
            $t->string('mask', 4)->nullable();
            $t->wsRef('owner_person_id', 'persons');
            $t->wsRef('owner_entity_id', 'legal_entities');
            $t->wsRef('connection_id', 'source_connections');
            $t->string('external_ref')->nullable();
            $t->date('opened_on')->nullable();
            $t->date('closed_on')->nullable();
            $t->string('status', 16)->default('open');
            $t->string('liquidity', 16)->default('daily');
            $t->text('restrictions')->nullable();
            $t->boolean('is_liability')->default(false);
            $t->tracking();
        });

        Schema::create('import_profiles', function (Blueprint $t) {
            $t->root();
            $t->string('provider', 32);
            $t->string('name');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('draft');
            $t->json('mapping');
            $t->json('account_map')->nullable();
            $t->json('category_map')->nullable();
            $t->json('rules')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->ulid('supersedes_id')->nullable();
            $t->tracking();
        });

        Schema::create('instruments', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->string('symbol', 32);
            $t->string('name')->nullable();
            $t->string('kind', 24)->default('equity');
            $t->string('asset_class', 24)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->string('cusip', 9)->nullable();
            $t->string('isin', 12)->nullable();
            $t->string('sector', 64)->nullable();
            $t->string('issuer')->nullable();
            $t->string('country', 2)->nullable();
            $t->decimal('last_price', 24, 8)->nullable();
            $t->date('last_price_on')->nullable();
            $t->string('price_source', 32)->nullable();
            $t->tracking();
            $t->unique(['workspace_id', 'symbol', 'kind']);
        });

        Schema::create('import_batches', function (Blueprint $t) {
            $t->root();
            $t->wsRef('connection_id', 'source_connections');
            $t->wsRef('profile_id', 'import_profiles');
            $t->string('provider', 32);
            $t->ulid('document_id')->nullable();
            $t->string('file_sha256', 64)->nullable();
            $t->string('status', 16)->default('received');
            $t->unsignedInteger('row_count')->default(0);
            $t->json('stats')->nullable();
            $t->json('coverage')->nullable();
            $t->json('reconciliation')->nullable();
            $t->ulid('job_id')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->ulid('committed_by')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->ulid('reversed_by')->nullable();
            $t->tracking();
        });

        Schema::create('import_rows', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('workspace_id');
            $t->wsRef('batch_id', 'import_batches', false);
            $t->unsignedInteger('row_no');
            $t->string('state', 16)->default('received');
            $t->string('external_id')->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->longText('raw_encrypted')->nullable();
            $t->json('normalized')->nullable();
            $t->json('issues')->nullable();
            $t->string('match_type', 16)->nullable();
            $t->ulid('matched_transaction_id')->nullable();
            $t->ulid('transaction_id')->nullable();
            $t->timestamps();
            $t->index(['batch_id', 'row_no']);
            $t->index(['workspace_id', 'fingerprint']);
        });

        Schema::create('transactions', function (Blueprint $t) {
            $t->root();
            $t->wsRef('account_id', 'accounts', false);
            $t->date('posted_on');
            $t->decimal('amount', 20, 4);
            $t->char('currency', 3)->default('USD');
            $t->decimal('fx_rate', 24, 12)->nullable();
            $t->decimal('amount_base', 20, 4)->nullable();
            $t->string('kind', 24);
            $t->string('description')->nullable();
            $t->string('merchant')->nullable();
            $t->string('category', 96)->nullable();
            $t->wsRef('instrument_id', 'instruments');
            $t->decimal('quantity', 28, 10)->nullable();
            $t->decimal('price', 24, 8)->nullable();
            $t->decimal('fees', 20, 4)->nullable();
            $t->string('external_id')->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->provenance();
            $t->wsRef('batch_id', 'import_batches');
            $t->ulid('superseded_by_id')->nullable();
            $t->string('transfer_group', 26)->nullable();
            $t->ulid('split_parent_id')->nullable();
            $t->json('tags')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('tax_candidate')->default(false);
            $t->string('tax_category', 64)->nullable();
            $t->string('business_purpose')->nullable();
            $t->ulid('entity_id')->nullable();
            $t->tracking();
            $t->unique(['workspace_id', 'account_id', 'external_id']);
            $t->index(['workspace_id', 'fingerprint']);
            $t->index(['workspace_id', 'posted_on']);
        });

        Schema::create('lots', function (Blueprint $t) {
            $t->root();
            $t->wsRef('account_id', 'accounts', false);
            $t->wsRef('instrument_id', 'instruments', false);
            $t->date('acquired_on');
            $t->decimal('quantity', 28, 10);
            $t->decimal('remaining_quantity', 28, 10);
            $t->decimal('cost_basis', 20, 4);
            $t->decimal('basis_adjustment', 20, 4)->default(0);
            $t->char('currency', 3)->default('USD');
            $t->ulid('source_transaction_id')->nullable();
            $t->string('origin', 24)->default('purchase'); // purchase|reinvest|rsu_vest|option_exercise|espp|gift|inheritance|transfer
            $t->date('closed_on')->nullable();
            $t->decimal('wash_sale_disallowed', 20, 4)->default(0);
            $t->provenance();
            $t->tracking();
        });

        Schema::create('holdings', function (Blueprint $t) {
            $t->root();
            $t->wsRef('account_id', 'accounts', false);
            $t->wsRef('instrument_id', 'instruments', false);
            $t->date('as_of');
            $t->decimal('quantity', 28, 10);
            $t->decimal('price', 24, 8)->nullable();
            $t->decimal('market_value', 20, 4)->nullable();
            $t->decimal('cost_basis', 20, 4)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->date('price_as_of')->nullable();
            $t->provenance();
            $t->tracking();
            $t->unique(['workspace_id', 'account_id', 'instrument_id', 'as_of']);
        });

        Schema::create('balance_snapshots', function (Blueprint $t) {
            $t->root();
            $t->wsRef('account_id', 'accounts', false);
            $t->date('as_of');
            $t->decimal('balance', 20, 4);
            $t->char('currency', 3)->default('USD');
            $t->provenance();
            $t->tracking();
            $t->index(['workspace_id', 'account_id', 'as_of']);
        });

        Schema::create('valuations', function (Blueprint $t) {
            $t->root();
            $t->string('subject_type', 24);
            $t->ulid('subject_id');
            $t->string('purpose', 16)->default('market');
            $t->decimal('value', 20, 4);
            $t->decimal('low', 20, 4)->nullable();
            $t->decimal('high', 20, 4)->nullable();
            $t->char('currency', 3)->default('USD');
            $t->date('as_of');
            $t->string('method', 24);
            $t->string('source')->nullable();
            $t->decimal('confidence', 5, 4)->nullable();
            $t->string('status', 16)->default('proposed');
            $t->ulid('accepted_by')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->text('rationale')->nullable();
            $t->ulid('evidence_document_id')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'subject_type', 'subject_id', 'purpose']);
        });

        Schema::create('fx_rates', function (Blueprint $t) {
            $t->id();
            $t->char('base', 3);
            $t->char('quote', 3);
            $t->decimal('rate', 24, 12);
            $t->date('as_of');
            $t->string('source', 32);
            $t->timestamps();
            $t->unique(['base', 'quote', 'as_of', 'source']);
        });

        Schema::create('books', function (Blueprint $t) {
            $t->root();
            $t->string('name');
            $t->string('owner_type', 16)->default('household');
            $t->ulid('owner_id')->nullable();
            $t->char('functional_currency', 3)->default('USD');
            $t->string('basis', 8)->default('cash');
            $t->tracking();
        });

        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->wsRef('book_id', 'books', false);
            $t->string('code', 16);
            $t->string('name');
            $t->string('type', 16); // asset|liability|equity|income|expense
            $t->wsRef('account_id', 'accounts');
            $t->timestamps();
            $t->unique(['book_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->root();
            $t->wsRef('book_id', 'books', false);
            $t->date('entry_date');
            $t->string('memo')->nullable();
            $t->string('source_type', 32)->nullable();
            $t->ulid('source_id')->nullable();
            $t->ulid('reversal_of_id')->nullable();
            $t->string('status', 16)->default('posted');
            $t->tracking();
            $t->index(['workspace_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->ulid('workspace_id');
            $t->wsRef('entry_id', 'journal_entries', false);
            $t->wsRef('ledger_account_id', 'ledger_accounts', false);
            $t->decimal('debit', 20, 4)->default(0);
            $t->decimal('credit', 20, 4)->default(0);
            $t->char('currency', 3)->default('USD');
            $t->decimal('fx_rate', 24, 12)->default(1);
            $t->decimal('functional_debit', 20, 4)->default(0);
            $t->decimal('functional_credit', 20, 4)->default(0);
            $t->string('memo')->nullable();
        });

        Schema::create('period_closes', function (Blueprint $t) {
            $t->root();
            $t->wsRef('book_id', 'books', false);
            $t->date('period_end');
            $t->string('status', 16)->default('closed');
            $t->text('reason')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->tracking();
        });

        Schema::create('record_links', function (Blueprint $t) {
            $t->root(scoped: false);
            $t->string('from_type', 32);
            $t->ulid('from_id');
            $t->string('to_type', 32);
            $t->ulid('to_id');
            $t->string('relation', 32);
            $t->effective();
            $t->json('provenance')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'from_type', 'from_id']);
            $t->index(['workspace_id', 'to_type', 'to_id']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropForeign(['workspace_id', 'person_id']));
        foreach (['record_links', 'period_closes', 'journal_lines', 'journal_entries', 'ledger_accounts', 'books', 'fx_rates', 'valuations',
            'balance_snapshots', 'holdings', 'lots', 'transactions', 'import_rows', 'import_batches', 'instruments', 'import_profiles',
            'accounts', 'source_connections', 'beneficiary_designations', 'authority_roles', 'control_roles', 'ownership_interests',
            'trust_details', 'entity_classifications', 'legal_entities', 'tax_residencies', 'tax_unit_dependents', 'tax_units',
            'household_members', 'households', 'relationships', 'persons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
