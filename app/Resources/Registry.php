<?php

namespace App\Resources;

/**
 * Canonical registry of workspace resources. Drives validation, visibility, the generic REST API,
 * OpenAPI/TypeScript generation and the generic UI forms.
 *
 * Field DSL: type[!] where ! = required. Types: s (string), t (text), i (int), y (year), m (money),
 * q (quantity), p (price), r (rate), f (fraction), pct, d (date), dt (datetime), b (bool), j (json),
 * cur (ISO 4217), e:a|b (enum), ref:resource, poly (ULID whose type is in the sibling *_type field).
 * A leading ~ marks server-managed (read-only) fields.
 */
final class Registry
{
    public const TITLE_FIELDS = ['name', 'display_name', 'title', 'employer_name', 'company_name', 'coverage', 'carrier', 'description'];

    private const ACCOUNT_KINDS = 'checking|savings|money_market|cd|treasury|brokerage|ira|roth_ira|sep_ira|solo_401k|401k|roth_401k|403b|457b|hsa|529|utma|crypto|private_fund|business|credit_card|loan|mortgage|heloc|other';
    private const TAX_BUCKETS = 'taxable|pretax|roth|hsa|education|tax_exempt|none';
    private const TXN_KINDS = 'spend|income|transfer|buy|sell|dividend|interest|fee|contribution|withdrawal|reinvest|split|corporate_action|tax|refund|payment|adjustment|other';
    private const VALUE_STATES = 'actual|provisional|projected|override';
    private const PROPERTY_KINDS = 'primary|second_home|rental_ltr|rental_str|mixed|land|commercial';
    private const POLICY_KINDS = 'homeowners|flood|auto|boat|jewelry|valuable_articles|umbrella|term_life|whole_life|universal_life|variable_life|disability|long_term_care|health|cyber|general_liability|professional_liability|business_owners|workers_comp|other';
    private const SUBJECT_TYPES = 'account|asset|property|liability|policy|entity|person|holding|document|trust|plan|equity_grant';

    public static function all(): array
    {
        static $defs = null;

        return $defs ??= self::definitions();
    }

    public static function get(string $type): ?array
    {
        $d = self::all()[$type] ?? null;

        return $d ? $d + ['type' => $type] : null;
    }

    /** Map of polymorphic subject_type values to resource types. */
    public const SUBJECT_MAP = [
        'account' => 'accounts', 'asset' => 'assets', 'property' => 'properties', 'liability' => 'liabilities',
        'policy' => 'insurance_policies', 'entity' => 'legal_entities', 'person' => 'persons', 'holding' => 'holdings',
        'document' => 'documents', 'trust' => 'legal_entities', 'plan' => 'plans', 'equity_grant' => 'equity_grants',
        'transaction' => 'transactions', 'tax_unit' => 'tax_units', 'estate_document' => 'estate_documents',
        'contact' => 'contacts', 'payroll_snapshot' => 'payroll_snapshots', 'tax_run' => 'tax_runs',
    ];

    private static function definitions(): array
    {
        return [
            // ---------------- Household ----------------
            'persons' => [
                'domain' => 'household', 'label' => 'People', 'title' => 'display_name', 'order' => ['display_name', 'asc'],
                'fields' => [
                    'display_name' => 's!', 'legal_name' => 's', 'birth_date' => 'd', 'death_date' => 'd', 'citizenship' => 's',
                    'is_us_citizen' => 'b', 'ssn_last4' => 's', 'email' => 's', 'phone' => 's', 'life_expectancy_age' => 'i', 'notes' => 't',
                ],
                'list' => ['display_name', 'birth_date', 'email'],
                'search' => ['display_name', 'legal_name'],
                'sensitive' => ['ssn_last4' => 'identity', 'email' => 'identity', 'phone' => 'identity'],
                'casts' => ['ssn_last4' => 'encrypted'],
                'patterns' => ['ssn_last4' => '/^\d{4}$/'],
            ],
            'relationships' => [
                'domain' => 'household', 'label' => 'Relationships',
                'fields' => [
                    'person_id' => 'ref:persons!', 'related_person_id' => 'ref:persons!',
                    'kind' => 'e:spouse|domestic_partner|former_spouse|parent|child|stepparent|stepchild|sibling|grandparent|grandchild|guardian|ward|in_law|other!',
                    'effective_from' => 'd', 'effective_to' => 'd', 'notes' => 't',
                ],
                'list' => ['person_id', 'kind', 'related_person_id', 'effective_from'],
            ],
            'households' => [
                'domain' => 'household', 'label' => 'Households', 'title' => 'name',
                'fields' => ['name' => 's!', 'address_line' => 's', 'city' => 's', 'state' => 's', 'postal_code' => 's', 'effective_from' => 'd', 'effective_to' => 'd'],
                'list' => ['name', 'city', 'state'],
                'sensitive' => ['address_line' => 'identity'],
            ],
            'household_members' => [
                'domain' => 'household', 'label' => 'Household members', 'parent' => 'household_id',
                'fields' => ['household_id' => 'ref:households!', 'person_id' => 'ref:persons!', 'role' => 'e:head|spouse|dependent|member', 'effective_from' => 'd', 'effective_to' => 'd'],
                'list' => ['household_id', 'person_id', 'role'],
            ],
            'ownership_interests' => [
                'domain' => 'household', 'label' => 'Ownership interests',
                'fields' => [
                    'owner_type' => 'e:person|entity!', 'owner_id' => 'poly!',
                    'subject_type' => 'e:'.self::SUBJECT_TYPES.'!', 'subject_id' => 'poly!',
                    'basis' => 'e:legal_title|beneficial|economic|voting', 'fraction' => 'f!',
                    'title_form' => 'e:sole|jtwros|tenancy_by_entirety|community|tenants_in_common|trust|entity',
                    'capacity' => 'e:individual|trustee|custodian|nominee|agent', 'interest_class' => 's', 'units' => 'q',
                    'effective_from' => 'd', 'effective_to' => 'd', 'evidence_document_id' => 'ref:documents',
                ],
                'list' => ['owner_type', 'owner_id', 'subject_type', 'subject_id', 'basis', 'fraction', 'title_form'],
                'poly' => ['owner_id' => 'owner_type', 'subject_id' => 'subject_type'],
            ],

            // ---------------- Governance & legal ----------------
            'legal_entities' => [
                'domain' => 'governance', 'label' => 'Entities & trusts', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:llc|corporation|s_corp|c_corp|partnership|trust|estate|foundation|sole_prop|other!',
                    'jurisdiction' => 's', 'formed_on' => 'd', 'dissolved_on' => 'd', 'ein_last4' => 's',
                    'tax_classification' => 'e:disregarded|partnership|s_corp|c_corp|grantor_trust|complex_trust|simple_trust|estate|exempt|none',
                    'fiscal_year_end' => 's', 'accounting_basis' => 'e:cash|accrual', 'registered_agent' => 's',
                    'status' => 'e:active|dormant|dissolved', 'notes' => 't',
                ],
                'list' => ['name', 'kind', 'jurisdiction', 'tax_classification', 'status'],
                'search' => ['name'],
                'patterns' => ['ein_last4' => '/^\d{4}$/', 'fiscal_year_end' => '/^\d{2}-\d{2}$/'],
            ],
            'entity_classifications' => [
                'domain' => 'governance', 'label' => 'Classification history', 'parent' => 'entity_id',
                'fields' => ['entity_id' => 'ref:legal_entities!', 'classification' => 's!', 'election_form' => 's', 'effective_from' => 'd!', 'effective_to' => 'd', 'evidence_document_id' => 'ref:documents'],
                'list' => ['entity_id', 'classification', 'effective_from', 'effective_to'],
            ],
            'control_roles' => [
                'domain' => 'governance', 'label' => 'Control roles', 'parent' => 'entity_id',
                'fields' => ['entity_id' => 'ref:legal_entities!', 'person_id' => 'ref:persons!', 'role' => 'e:manager|member|officer|director|trustee|successor_trustee|protector|signatory|registered_agent|executor!', 'effective_from' => 'd', 'effective_to' => 'd'],
                'list' => ['entity_id', 'person_id', 'role', 'effective_from'],
            ],
            'contacts' => [
                'domain' => 'governance', 'label' => 'Professional & emergency contacts', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:cpa|attorney|advisor|broker|insurance_agent|banker|physician|emergency|custodian|trustee|other!',
                    'organization' => 's', 'email' => 's', 'phone' => 's', 'address' => 't', 'notes' => 't', 'emergency' => 'b',
                ],
                'list' => ['name', 'kind', 'organization', 'emergency'],
                'search' => ['name', 'organization'],
            ],
            'decisions' => [
                'domain' => 'governance', 'label' => 'Decision log', 'title' => 'title', 'order' => ['decided_on', 'desc'],
                'fields' => ['title' => 's!', 'decided_on' => 'd!', 'context' => 't', 'decision' => 't!', 'participants' => 'j', 'references' => 'j', 'revisit_on' => 'd'],
                'list' => ['title', 'decided_on', 'revisit_on'],
            ],
            'meetings' => [
                'domain' => 'governance', 'label' => 'Family meetings', 'title' => 'title', 'order' => ['scheduled_at', 'desc'],
                'fields' => ['title' => 's!', 'scheduled_at' => 'dt!', 'cadence' => 'e:monthly|quarterly|semiannual|annual|adhoc', 'agenda' => 'j', 'minutes' => 't'],
                'list' => ['title', 'scheduled_at', 'cadence'],
            ],
            'family_goals' => [
                'domain' => 'planning', 'label' => 'Family goals', 'title' => 'title',
                'fields' => [
                    'title' => 's!', 'kind' => 'e:savings|education|home|retirement|giving|legacy|debt_freedom|travel|other',
                    'person_id' => 'ref:persons', 'target_amount' => 'm', 'target_date' => 'd',
                    'priority' => 'e:essential|important|aspirational', 'status' => 'e:active|achieved|paused|abandoned', 'notes' => 't',
                ],
                'list' => ['title', 'kind', 'target_amount', 'target_date', 'priority', 'status'],
            ],
            'legal_obligations' => [
                'domain' => 'governance', 'label' => 'Filings & obligations', 'title' => 'title', 'order' => ['due_on', 'asc'],
                'fields' => [
                    'title' => 's!', 'kind' => 'e:annual_report|franchise_tax|boi_report|trust_return|gift_return|estate_return|partnership_return|license|insurance_audit|other!',
                    'entity_id' => 'ref:legal_entities', 'jurisdiction' => 's', 'due_on' => 'd!', 'recurrence' => 'e:annual|quarterly|monthly|none',
                    'status' => 'e:open|filed|waived|na', 'responsible_person_id' => 'ref:persons', 'notes' => 't',
                ],
                'list' => ['title', 'kind', 'entity_id', 'due_on', 'status'],
            ],
            'tasks' => [
                'domain' => 'dynamic', 'label' => 'Tasks', 'title' => 'title', 'order' => ['due_on', 'asc'],
                'fields' => [
                    'domain' => 'e:household|ledger|portfolio|compensation|real_estate|assets|debt|tax|planning|estate|insurance|vault|governance',
                    'title' => 's!', 'description' => 't', 'due_on' => 'd', 'status' => 'e:open|in_progress|done|cancelled',
                    'priority' => 'e:low|normal|high', 'assignee_membership_id' => 's', 'related_type' => 's', 'related_id' => 's',
                ],
                'list' => ['title', 'due_on', 'status', 'priority'],
            ],
            'emergency_binders' => [
                'domain' => 'governance', 'label' => 'Emergency binders', 'title' => 'name',
                'fields' => ['name' => 's!', 'instructions' => 't', 'contents' => 'j!', 'recipient_membership_ids' => 'j', 'release_hours' => 'i', 'requires_secondary_approval' => 'b'],
                'list' => ['name', 'release_hours', 'requires_secondary_approval'],
            ],

            // ---------------- Estate ----------------
            'trust_details' => [
                'domain' => 'estate', 'label' => 'Trust details', 'parent' => 'entity_id',
                'fields' => [
                    'entity_id' => 'ref:legal_entities!', 'trust_type' => 'e:revocable_living|ilit|grat|slat|idgt|dynasty|qprt|crt|clat|special_needs|testamentary|other!',
                    'revocable' => 'b', 'grantor_trust' => 'b', 'settlor_person_ids' => 'j', 'governing_law' => 's',
                    'funding_status' => 'e:unfunded|partial|funded|unknown', 'distribution_terms' => 'j', 'gst_exempt' => 'b', 'crummey_powers' => 'b',
                ],
                'list' => ['entity_id', 'trust_type', 'revocable', 'funding_status'],
            ],
            'beneficiary_designations' => [
                'domain' => 'estate', 'label' => 'Beneficiary designations',
                'fields' => [
                    'subject_type' => 'e:account|policy|asset|trust|will|property!', 'subject_id' => 'poly!',
                    'beneficiary_type' => 'e:person|entity|class|estate|charity!', 'beneficiary_id' => 'poly', 'beneficiary_label' => 's',
                    'tier' => 'e:primary|contingent|remainder', 'share_fraction' => 'f!', 'per_stirpes' => 'b', 'conditions' => 'j',
                    'effective_from' => 'd', 'verified_on' => 'd', 'evidence_document_id' => 'ref:documents',
                ],
                'list' => ['subject_type', 'subject_id', 'beneficiary_type', 'beneficiary_id', 'tier', 'share_fraction', 'verified_on'],
                'poly' => ['subject_id' => 'subject_type', 'beneficiary_id' => 'beneficiary_type'],
            ],
            'authority_roles' => [
                'domain' => 'estate', 'label' => 'Authority & successors',
                'fields' => [
                    'kind' => 'e:guardian|poa_agent|healthcare_proxy|executor|successor_trustee|trustee|emergency_contact|delegate|successor_admin|custodian!',
                    'holder_person_id' => 'ref:persons', 'holder_contact_id' => 'ref:contacts', 'subject_type' => 'e:person|entity|account|trust',
                    'subject_id' => 'poly', 'order' => 'i', 'effective_from' => 'd', 'effective_to' => 'd', 'conditions' => 't', 'evidence_document_id' => 'ref:documents',
                ],
                'list' => ['kind', 'holder_person_id', 'holder_contact_id', 'subject_type', 'order'],
                'poly' => ['subject_id' => 'subject_type'],
            ],
            'estate_documents' => [
                'domain' => 'estate', 'label' => 'Estate documents', 'title' => 'title',
                'fields' => [
                    'kind' => 'e:will|codicil|revocable_trust|irrevocable_trust|trust_amendment|poa_financial|healthcare_directive|healthcare_proxy|guardianship|letter_of_instruction|prenup|postnup|buy_sell|other!',
                    'title' => 's!', 'person_id' => 'ref:persons', 'entity_id' => 'ref:legal_entities', 'executed_on' => 'd', 'review_due_on' => 'd',
                    'original_location' => 's', 'witness_status' => 'e:na|complete|missing|unknown', 'notary_status' => 'e:na|complete|missing|unknown',
                    'attorney_contact_id' => 'ref:contacts', 'document_id' => 'ref:documents', 'supersedes_id' => 'ref:estate_documents',
                    'status' => 'e:draft|executed|superseded|revoked', 'provisions' => 'j',
                ],
                'list' => ['title', 'kind', 'person_id', 'executed_on', 'review_due_on', 'status'],
            ],
            'estate_scenarios' => [
                'domain' => 'estate', 'label' => 'Estate scenarios', 'title' => 'name',
                'fields' => ['name' => 's!', 'death_order' => 'j!', 'assumed_year' => 'y!', 'assumptions' => 'j', 'overrides' => 'j'],
                'list' => ['name', 'assumed_year'],
            ],
            'estate_runs' => [
                'domain' => 'estate', 'label' => 'Estate runs', 'readonly' => true, 'order' => ['created_at', 'desc'], 'parent' => 'scenario_id',
                'fields' => ['scenario_id' => 'ref:estate_scenarios!', 'status' => '~s', 'engine_version' => '~s', 'input_hash' => '~s', 'summary' => '~j', 'flows' => '~j', 'unresolved' => '~j', 'input_manifest' => '~j', 'job_id' => '~s'],
                'list' => ['scenario_id', 'status', 'created_at'],
            ],

            // ---------------- Ledger ----------------
            'source_connections' => [
                'domain' => 'ledger', 'label' => 'Data sources', 'title' => 'label',
                'fields' => [
                    'provider' => 'e:monarch|snowball|invoices|property_manager|bank|broker|payroll|ssa|manual|other!', 'label' => 's!',
                    'mode' => 'e:file|api', 'status' => '~s', 'config' => 'j', 'freshness_hours' => 'i',
                    'last_sync_at' => '~dt', 'last_success_at' => '~dt', 'last_error' => '~t',
                ],
                'list' => ['label', 'provider', 'mode', 'status', 'last_success_at'],
            ],
            'accounts' => [
                'domain' => 'ledger', 'label' => 'Accounts', 'title' => 'name', 'order' => ['name', 'asc'],
                'fields' => [
                    'name' => 's!', 'institution' => 's', 'kind' => 'e:'.self::ACCOUNT_KINDS.'!', 'tax_bucket' => 'e:'.self::TAX_BUCKETS,
                    'currency' => 'cur', 'mask' => 's', 'owner_person_id' => 'ref:persons', 'owner_entity_id' => 'ref:legal_entities',
                    'connection_id' => 'ref:source_connections', 'external_ref' => 's', 'opened_on' => 'd', 'closed_on' => 'd',
                    'status' => 'e:open|closed|frozen', 'liquidity' => 'e:daily|t_plus|monthly|quarterly|restricted|illiquid', 'restrictions' => 't', 'is_liability' => 'b',
                ],
                'list' => ['name', 'institution', 'kind', 'tax_bucket', 'owner_person_id', 'status'],
                'search' => ['name', 'institution'],
                'patterns' => ['mask' => '/^\d{2,4}$/'],
            ],
            'transactions' => [
                'domain' => 'ledger', 'label' => 'Transactions', 'title' => 'description', 'order' => ['posted_on', 'desc'], 'parent' => 'account_id',
                'fields' => [
                    'account_id' => 'ref:accounts!', 'posted_on' => 'd!', 'amount' => 'm!', 'currency' => 'cur', 'kind' => 'e:'.self::TXN_KINDS.'!',
                    'description' => 's', 'merchant' => 's', 'category' => 's', 'instrument_id' => 'ref:instruments', 'quantity' => 'q', 'price' => 'p',
                    'fees' => 'm', 'external_id' => '~s', 'fx_rate' => 'p', 'amount_base' => '~m', 'source' => '~s', 'value_state' => 'e:'.self::VALUE_STATES,
                    'override_reason' => 't', 'batch_id' => '~s', 'superseded_by_id' => '~s', 'transfer_group' => 's', 'split_parent_id' => 'ref:transactions',
                    'tags' => 'j', 'notes' => 't', 'tax_candidate' => 'b', 'tax_category' => 's', 'business_purpose' => 's', 'entity_id' => 'ref:legal_entities',
                ],
                'list' => ['posted_on', 'account_id', 'description', 'category', 'kind', 'amount', 'source'],
                'search' => ['description', 'merchant', 'category'],
                'filters' => ['account_id', 'kind', 'category', 'tax_candidate', 'batch_id'],
                'date' => 'posted_on',
            ],
            'balance_snapshots' => [
                'domain' => 'ledger', 'label' => 'Balances', 'order' => ['as_of', 'desc'], 'parent' => 'account_id',
                'fields' => ['account_id' => 'ref:accounts!', 'as_of' => 'd!', 'balance' => 'm!', 'currency' => 'cur', 'source' => '~s', 'value_state' => 'e:'.self::VALUE_STATES],
                'list' => ['account_id', 'as_of', 'balance', 'currency'],
                'date' => 'as_of',
            ],
            'import_profiles' => [
                'domain' => 'ledger', 'label' => 'Import profiles', 'title' => 'name',
                'fields' => ['provider' => 'e:monarch|snowball|invoices|property_manager|generic_csv!', 'name' => 's!', 'version' => '~i', 'status' => '~s', 'mapping' => 'j!', 'account_map' => 'j', 'category_map' => 'j', 'rules' => 'j', 'approved_at' => '~dt'],
                'list' => ['name', 'provider', 'version', 'status'],
            ],
            'import_batches' => [
                'domain' => 'ledger', 'label' => 'Imports', 'readonly' => true, 'order' => ['created_at', 'desc'],
                'fields' => ['provider' => '~s', 'connection_id' => '~s', 'profile_id' => '~s', 'status' => '~s', 'row_count' => '~i', 'stats' => '~j', 'coverage' => '~j', 'reconciliation' => '~j', 'committed_at' => '~dt', 'reversed_at' => '~dt', 'job_id' => '~s', 'document_id' => '~s'],
                'list' => ['provider', 'status', 'row_count', 'created_at', 'committed_at'],
            ],
            'books' => [
                'domain' => 'ledger', 'label' => 'Books', 'title' => 'name',
                'fields' => ['name' => 's!', 'owner_type' => 'e:household|entity', 'owner_id' => 's', 'functional_currency' => 'cur', 'basis' => 'e:cash|accrual'],
                'list' => ['name', 'owner_type', 'functional_currency', 'basis'],
            ],
            'journal_entries' => [
                'domain' => 'ledger', 'label' => 'Journal entries', 'readonly' => true, 'order' => ['entry_date', 'desc'],
                'fields' => ['book_id' => '~s', 'entry_date' => '~d', 'memo' => '~s', 'source_type' => '~s', 'source_id' => '~s', 'reversal_of_id' => '~s', 'status' => '~s'],
                'list' => ['entry_date', 'memo', 'source_type', 'status'],
            ],
            'annual_snapshots' => [
                'domain' => 'ledger', 'label' => 'Year close', 'readonly' => true, 'order' => ['year', 'desc'],
                'fields' => ['year' => '~y', 'basis' => '~s', 'view' => '~s', 'version' => '~i', 'status' => '~s', 'data' => '~j', 'bridge' => '~j', 'coverage' => '~j', 'reopen_reason' => '~t', 'closed_at' => '~dt', 'content_hash' => '~s', 'supersedes_id' => '~s'],
                'list' => ['year', 'view', 'version', 'status', 'closed_at'],
            ],

            // ---------------- Portfolio ----------------
            'instruments' => [
                'domain' => 'portfolio', 'label' => 'Instruments', 'title' => 'symbol', 'unscoped' => true, 'order' => ['symbol', 'asc'],
                'fields' => [
                    'symbol' => 's!', 'name' => 's', 'kind' => 'e:equity|etf|mutual_fund|bond|treasury|cd|money_market|cash|crypto|option|private|reit|other',
                    'asset_class' => 'e:us_equity|intl_equity|em_equity|bonds|tips|munis|cash|real_estate|commodities|alternatives|crypto|private_equity|private_credit',
                    'currency' => 'cur', 'cusip' => 's', 'isin' => 's', 'sector' => 's', 'issuer' => 's', 'country' => 's',
                    'last_price' => 'p', 'last_price_on' => 'd', 'price_source' => 's',
                ],
                'list' => ['symbol', 'name', 'kind', 'asset_class', 'sector', 'last_price', 'last_price_on'],
                'search' => ['symbol', 'name'],
            ],
            'holdings' => [
                'domain' => 'portfolio', 'label' => 'Holdings', 'order' => ['as_of', 'desc'], 'parent' => 'account_id',
                'fields' => [
                    'account_id' => 'ref:accounts!', 'instrument_id' => 'ref:instruments!', 'as_of' => 'd!', 'quantity' => 'q!', 'price' => 'p',
                    'market_value' => 'm', 'cost_basis' => 'm', 'currency' => 'cur', 'price_as_of' => 'd', 'source' => '~s', 'value_state' => 'e:'.self::VALUE_STATES,
                ],
                'list' => ['account_id', 'instrument_id', 'as_of', 'quantity', 'price', 'market_value', 'cost_basis'],
                'date' => 'as_of',
            ],
            'lots' => [
                'domain' => 'portfolio', 'label' => 'Tax lots', 'order' => ['acquired_on', 'asc'], 'parent' => 'account_id',
                'fields' => [
                    'account_id' => 'ref:accounts!', 'instrument_id' => 'ref:instruments!', 'acquired_on' => 'd!', 'quantity' => 'q!', 'remaining_quantity' => 'q!',
                    'cost_basis' => 'm!', 'basis_adjustment' => 'm', 'currency' => 'cur', 'origin' => 'e:purchase|reinvest|rsu_vest|option_exercise|espp|gift|inheritance|transfer|corporate_action',
                    'source_transaction_id' => 'ref:transactions', 'closed_on' => 'd', 'wash_sale_disallowed' => 'm', 'source' => '~s',
                ],
                'list' => ['account_id', 'instrument_id', 'acquired_on', 'quantity', 'remaining_quantity', 'cost_basis', 'origin'],
            ],

            // ---------------- Assets & valuations ----------------
            'assets' => [
                'domain' => 'assets', 'label' => 'Assets', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:vehicle|boat|aircraft|jewelry|art|collectible|wine|business_interest|private_fund|digital_asset|intellectual_property|receivable|other!',
                    'description' => 't', 'serial_number' => 's', 'location' => 's', 'acquired_on' => 'd', 'cost' => 'm', 'currency' => 'cur',
                    'entity_id' => 'ref:legal_entities', 'liquidity' => 'e:daily|monthly|quarterly|restricted|illiquid', 'status' => 'e:owned|sold|gifted|lost',
                    'disposed_on' => 'd', 'disposal_amount' => 'm', 'unfunded_commitment' => 'm',
                    'make' => 's', 'model' => 's', 'model_year' => 'y', 'registration_state' => 's', 'registration_expires_on' => 'd',
                ],
                'list' => ['name', 'kind', 'make', 'model', 'model_year', 'acquired_on', 'cost', 'status'],
                'search' => ['name', 'serial_number'],
                'sensitive' => ['serial_number' => 'identity', 'location' => 'identity'],
            ],
            'valuations' => [
                'domain' => 'assets', 'label' => 'Valuations', 'order' => ['as_of', 'desc'],
                'fields' => [
                    'subject_type' => 'e:asset|property|account|entity|equity_grant!', 'subject_id' => 'poly!', 'purpose' => 'e:market|tax|replacement|estate|insurance',
                    'value' => 'm!', 'low' => 'm', 'high' => 'm', 'currency' => 'cur', 'as_of' => 'd!',
                    'method' => 'e:appraisal|licensed_feed|comps|owner_override|model|statement|409a|tender!', 'source' => 's', 'confidence' => 'pct',
                    'status' => '~s', 'rationale' => 't', 'evidence_document_id' => 'ref:documents',
                ],
                'list' => ['subject_type', 'subject_id', 'purpose', 'value', 'as_of', 'method', 'status'],
                'poly' => ['subject_id' => 'subject_type'],
            ],

            // ---------------- Compensation ----------------
            'employments' => [
                'domain' => 'compensation', 'label' => 'Employment', 'title' => 'employer_name',
                'fields' => [
                    'person_id' => 'ref:persons!', 'employer_name' => 's!', 'employer_entity_id' => 'ref:legal_entities', 'title' => 's',
                    'kind' => 'e:w2|advisor|contractor|board|owner|k1_partner', 'start_on' => 'd', 'end_on' => 'd', 'base_salary' => 'm', 'bonus_target_pct' => 'pct',
                    'pay_frequency' => 'e:weekly|biweekly|semimonthly|monthly|annual', 'work_state' => 's', 'retirement_deferral_pct' => 'pct',
                    'employer_match_pct' => 'pct', 'currency' => 'cur',
                ],
                'list' => ['person_id', 'employer_name', 'title', 'kind', 'start_on', 'end_on', 'base_salary'],
            ],
            'payroll_snapshots' => [
                'domain' => 'compensation', 'label' => 'Paychecks', 'order' => ['pay_date', 'desc'], 'parent' => 'employment_id',
                'fields' => [
                    'employment_id' => 'ref:employments!', 'person_id' => 'ref:persons!', 'pay_date' => 'd!', 'period_start' => 'd', 'period_end' => 'd',
                    'run_kind' => 'e:regular|off_cycle|bonus|correction|final|equity', 'status' => '~s', 'gross' => 'm!', 'net' => 'm',
                    'current' => 'j!', 'ytd' => 'j', 'checks' => '~j', 'document_id' => '~s', 'extraction_id' => '~s', 'supersedes_id' => 'ref:payroll_snapshots',
                    'approved_at' => '~dt', 'source' => '~s', 'value_state' => 'e:'.self::VALUE_STATES,
                ],
                'list' => ['pay_date', 'person_id', 'employment_id', 'run_kind', 'gross', 'net', 'status'],
                'date' => 'pay_date',
            ],
            'equity_grants' => [
                'domain' => 'compensation', 'label' => 'Equity grants', 'title' => 'company_name',
                'fields' => [
                    'person_id' => 'ref:persons!', 'employment_id' => 'ref:employments', 'company_name' => 's!', 'symbol' => 's',
                    'grant_type' => 'e:iso|nso|rsu|psu|rsa|miu|profits_interest|espp|carry|sar|phantom!', 'grant_ref' => 's', 'grant_date' => 'd!',
                    'units' => 'q!', 'strike_price' => 'p', 'fmv_at_grant' => 'p', 'expiration_date' => 'd', 'vest_start_date' => 'd', 'vesting' => 'j',
                    'acceleration' => 'j', 'separation_terms' => 'j', 'settlement' => 'e:shares|cash|either', 'election_83b_deadline' => 'd',
                    'election_83b_filed' => 'b', 'lockup_until' => 'd', 'participation_threshold' => 'p', 'currency' => 'cur',
                    'status' => 'e:active|fully_vested|exercised|forfeited|expired|cancelled', 'document_id' => 'ref:documents',
                ],
                'list' => ['company_name', 'grant_type', 'grant_date', 'units', 'strike_price', 'expiration_date', 'status'],
            ],
            'equity_tranches' => [
                'domain' => 'compensation', 'label' => 'Vesting tranches', 'order' => ['vest_date', 'asc'], 'parent' => 'grant_id',
                'fields' => ['grant_id' => 'ref:equity_grants!', 'vest_date' => 'd!', 'units' => 'q!', 'condition' => 'e:time|performance|liquidity|double_trigger', 'status' => 'e:scheduled|vested|forfeited', 'units_exercised' => '~q', 'units_settled' => '~q'],
                'list' => ['grant_id', 'vest_date', 'units', 'condition', 'status', 'units_exercised'],
            ],
            'equity_events' => [
                'domain' => 'compensation', 'label' => 'Equity events', 'order' => ['event_date', 'desc'], 'parent' => 'grant_id',
                'fields' => [
                    'grant_id' => 'ref:equity_grants!', 'tranche_id' => 'ref:equity_tranches', 'kind' => 'e:vest|exercise|settle|sell|sell_to_cover|forfeit|expire|disqualifying_disposition|qualifying_disposition|purchase!',
                    'event_date' => 'd!', 'units' => 'q!', 'fmv' => 'p', 'price' => 'p', 'units_withheld' => 'q', 'tax_withheld' => 'm', 'proceeds' => 'm',
                    'ordinary_income' => 'm', 'exercise_method' => 'e:cash|cashless|net|sell_to_cover|swap', 'payroll_snapshot_id' => 'ref:payroll_snapshots',
                    'transaction_id' => 'ref:transactions', 'lot_id' => 'ref:lots', 'reconciliation' => '~s', 'source' => '~s',
                ],
                'list' => ['event_date', 'grant_id', 'kind', 'units', 'fmv', 'ordinary_income', 'reconciliation'],
            ],
            'company_valuations' => [
                'domain' => 'compensation', 'label' => 'Company valuations', 'title' => 'company_name', 'order' => ['as_of', 'desc'],
                'fields' => ['company_name' => 's!', 'as_of' => 'd!', 'kind' => 'e:409a|preferred|tender|public|secondary|board!', 'price_per_share' => 'p!', 'fully_diluted_shares' => 'q', 'cap_table' => 'j', 'source' => 's'],
                'list' => ['company_name', 'as_of', 'kind', 'price_per_share'],
            ],

            // ---------------- Tax ----------------
            'tax_units' => [
                'domain' => 'tax', 'label' => 'Tax units', 'title' => 'name', 'year' => 'tax_year', 'order' => ['tax_year', 'desc'],
                'fields' => [
                    'name' => 's!', 'tax_year' => 'y!', 'filing_status' => 'e:single|mfj|mfs|hoh|qss!', 'primary_person_id' => 'ref:persons!',
                    'spouse_person_id' => 'ref:persons', 'state' => 'e:NJ|NONE', 'residency_status' => 'e:resident|part_year|nonresident',
                    'part_year_from' => 'd', 'part_year_to' => 'd', 'notes' => 't',
                ],
                'list' => ['name', 'tax_year', 'filing_status', 'state', 'residency_status'],
            ],
            'tax_unit_dependents' => [
                'domain' => 'tax', 'label' => 'Dependents', 'parent' => 'tax_unit_id',
                'fields' => ['tax_unit_id' => 'ref:tax_units!', 'person_id' => 'ref:persons!', 'months_lived' => 'i', 'qualifies_ctc' => 'b', 'qualifies_odc' => 'b', 'full_time_student' => 'b'],
                'list' => ['tax_unit_id', 'person_id', 'qualifies_ctc', 'qualifies_odc'],
            ],
            'tax_residencies' => [
                'domain' => 'tax', 'label' => 'Tax residency',
                'fields' => ['person_id' => 'ref:persons!', 'jurisdiction' => 's!', 'status' => 'e:resident|part_year|nonresident|domiciliary!', 'effective_from' => 'd', 'effective_to' => 'd', 'evidence' => 't'],
                'list' => ['person_id', 'jurisdiction', 'status', 'effective_from', 'effective_to'],
            ],
            'tax_documents' => [
                'domain' => 'tax', 'label' => 'Tax forms', 'year' => 'tax_year', 'order' => ['tax_year', 'desc'],
                'fields' => [
                    'tax_year' => 'y!', 'form' => 'e:W-2|W-2c|1099-B|1099-DIV|1099-INT|1099-NEC|1099-MISC|1099-R|1099-K|1098|1098-E|1098-T|K-1-1065|K-1-1120S|K-1-1041|SSA-1099|1095-A|5498|5498-SA|1099-SA|3921|3922|NJ-1099|other!',
                    'person_id' => 'ref:persons', 'entity_id' => 'ref:legal_entities', 'payer' => 's', 'boxes' => 'j!', 'status' => '~s', 'corrected' => 'b',
                    'supersedes_id' => 'ref:tax_documents', 'document_id' => 'ref:documents', 'source' => '~s',
                ],
                'list' => ['tax_year', 'form', 'person_id', 'payer', 'status', 'corrected'],
            ],
            'tax_inputs' => [
                'domain' => 'tax', 'label' => 'Tax inputs', 'parent' => 'tax_unit_id',
                'fields' => [
                    'tax_unit_id' => 'ref:tax_units!', 'key' => 's!', 'jurisdiction' => 'e:US|NJ', 'amount' => 'm', 'value' => 'j', 'person_id' => 'ref:persons',
                    'label' => 's', 'fact_refs' => 'j', 'source' => '~s', 'value_state' => 'e:'.self::VALUE_STATES, 'override_reason' => 't', 'override_expires_on' => 'd',
                ],
                'list' => ['tax_unit_id', 'key', 'jurisdiction', 'amount', 'value_state', 'label'],
            ],
            'tax_payments' => [
                'domain' => 'tax', 'label' => 'Tax payments', 'order' => ['due_on', 'asc'], 'parent' => 'tax_unit_id',
                'fields' => [
                    'tax_unit_id' => 'ref:tax_units!', 'jurisdiction' => 'e:US|NJ!', 'kind' => 'e:estimated|extension|balance_due|prior_year_overpayment',
                    'installment' => 'i', 'due_on' => 'd', 'paid_on' => 'd', 'amount' => 'm!', 'status' => 'e:planned|paid', 'source' => '~s',
                ],
                'list' => ['tax_unit_id', 'jurisdiction', 'kind', 'installment', 'due_on', 'paid_on', 'amount', 'status'],
            ],
            'tax_carryforwards' => [
                'domain' => 'tax', 'label' => 'Carryforwards', 'parent' => 'tax_unit_id',
                'fields' => [
                    'tax_unit_id' => 'ref:tax_units!', 'jurisdiction' => 'e:US|NJ', 'kind' => 'e:capital_loss_st|capital_loss_lt|passive_loss|amt_credit|charitable|nol|section_179|investment_interest|qbi_loss!',
                    'origin_year' => 'y!', 'amount' => 'm!', 'expires_year' => 'y', 'person_id' => 'ref:persons', 'activity_ref' => 's', 'source' => '~s',
                ],
                'list' => ['tax_unit_id', 'jurisdiction', 'kind', 'origin_year', 'amount', 'expires_year'],
            ],
            'tax_runs' => [
                'domain' => 'tax', 'label' => 'Tax runs', 'readonly' => true, 'year' => 'tax_year', 'order' => ['created_at', 'desc'], 'parent' => 'tax_unit_id',
                'fields' => [
                    'tax_unit_id' => '~s', 'tax_year' => '~y', 'scenario' => '~s', 'kind' => '~s', 'status' => '~s', 'rule_packs' => '~j', 'engine_version' => '~s',
                    'input_hash' => '~s', 'summary' => '~j', 'issues' => '~j', 'reconciliation' => '~j', 'reviewer_name' => '~s', 'reviewed_at' => '~dt', 'immutable' => '~b', 'job_id' => '~s',
                ],
                'list' => ['tax_year', 'scenario', 'kind', 'status', 'created_at'],
                'heavy' => ['lines', 'trace', 'input_manifest'],
            ],
            'tax_strategies' => [
                'domain' => 'tax', 'label' => 'Tax strategies', 'title' => 'title', 'year' => 'tax_year', 'parent' => 'tax_unit_id',
                'fields' => [
                    'tax_unit_id' => 'ref:tax_units!', 'tax_year' => 'y!', 'kind' => 's!', 'title' => 's!', 'assumptions' => 'j', 'evidence_required' => 'j',
                    'authorities' => 'j', 'deadline' => 'd', 'impact' => '~j', 'baseline_run_id' => '~s', 'strategy_run_id' => '~s',
                    'status' => 'e:proposed|reviewing|accepted|declined|implemented', 'professional_feedback' => 't', 'requires_professional' => 'b',
                ],
                'list' => ['title', 'tax_year', 'kind', 'deadline', 'status'],
            ],

            // ---------------- Real estate ----------------
            'properties' => [
                'domain' => 'real_estate', 'label' => 'Properties', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:'.self::PROPERTY_KINDS.'!', 'address_line' => 's', 'city' => 's', 'state' => 's', 'postal_code' => 's', 'country' => 's',
                    'acquired_on' => 'd', 'purchase_price' => 'm', 'closing_costs' => 'm', 'land_value' => 'm', 'building_value' => 'm', 'placed_in_service_on' => 'd',
                    'recovery_years' => 'pct', 'depreciation_method' => 'e:SL-MM|none', 'prior_depreciation' => 'm', 'converted_to_rental_on' => 'd',
                    'fmv_at_conversion' => 'm', 'entity_id' => 'ref:legal_entities', 'status' => 'e:owned|under_contract|sold',
                    'normalized_address' => '~s', 'county' => 's', 'municipality' => 's', 'mun_code' => 's', 'block' => 's', 'lot' => 's', 'qualifier' => 's',
                    'parcel_pin' => 's', 'latitude' => 'p', 'longitude' => 'p', 'property_class' => 's', 'year_built' => 'y', 'living_area_sqft' => 'i',
                    'lot_acres' => 'pct', 'assessed_land' => 'm', 'assessed_improvement' => 'm', 'assessed_total' => 'm', 'annual_property_tax' => 'm',
                    'deed_book' => 's', 'deed_page' => 's', 'deed_date' => 'd', 'last_sale_price' => 'm', 'records_source' => '~s',
                    'records_refreshed_at' => '~dt', 'address_verified_at' => '~dt',
                ],
                'list' => ['name', 'kind', 'city', 'state', 'block', 'lot', 'acquired_on', 'purchase_price', 'status'],
                'search' => ['name', 'city', 'block', 'parcel_pin'],
                'sensitive' => ['address_line' => 'identity', 'normalized_address' => 'identity', 'latitude' => 'identity', 'longitude' => 'identity'],
            ],
            'property_lookups' => [
                'domain' => 'real_estate', 'label' => 'Public record lookups', 'readonly' => true, 'order' => ['created_at', 'desc'], 'parent' => 'property_id',
                'fields' => ['property_id' => '~s', 'query' => '~s', 'sources' => '~j', 'result' => '~j', 'selected_pin' => '~s'],
                'list' => ['query', 'selected_pin', 'created_at'],
                'sensitive' => ['query' => 'identity', 'result' => 'identity'],
            ],
            'property_stays' => [
                'domain' => 'real_estate', 'label' => 'Stays & bookings', 'order' => ['check_in', 'desc'], 'parent' => 'property_id',
                'fields' => [
                    'property_id' => 'ref:properties!', 'check_in' => 'd!', 'check_out' => 'd!',
                    'kind' => 'e:guest|personal|owner_maintenance|family_free|family_fair_rent|donated|blocked!', 'gross_rent' => 'm', 'cleaning_fee' => 'm',
                    'platform_fee' => 'm', 'taxes_collected' => 'm', 'payout' => 'm', 'refund' => 'm', 'cancelled' => 'b', 'channel' => 's', 'source' => '~s',
                ],
                'list' => ['property_id', 'check_in', 'check_out', 'kind', 'gross_rent', 'payout', 'cancelled'],
                'date' => 'check_in',
            ],
            'property_use_logs' => [
                'domain' => 'real_estate', 'label' => 'Use & participation log', 'order' => ['log_date', 'desc'], 'parent' => 'property_id',
                'fields' => [
                    'property_id' => 'ref:properties!', 'log_date' => 'd!', 'kind' => 'e:rental|personal|maintenance|available|vacant|management!', 'hours' => 'pct',
                    'participant_person_id' => 'ref:persons', 'activity' => 's', 'evidence_document_id' => 'ref:documents',
                ],
                'list' => ['property_id', 'log_date', 'kind', 'hours', 'participant_person_id', 'activity'],
                'date' => 'log_date',
            ],
            'property_improvements' => [
                'domain' => 'real_estate', 'label' => 'Improvements', 'parent' => 'property_id',
                'fields' => ['property_id' => 'ref:properties!', 'description' => 's!', 'placed_in_service_on' => 'd!', 'cost' => 'm!', 'recovery_years' => 'pct', 'method' => 'e:SL-MM|SL|200DB|150DB', 'kind' => 'e:building|land_improvement|personal_property', 'bonus_eligible' => 'b'],
                'list' => ['property_id', 'description', 'placed_in_service_on', 'cost', 'recovery_years'],
            ],
            'property_expenses' => [
                'domain' => 'real_estate', 'label' => 'Property expenses', 'order' => ['incurred_on', 'desc'], 'parent' => 'property_id',
                'fields' => [
                    'property_id' => 'ref:properties!', 'incurred_on' => 'd!', 'category' => 'e:mortgage_interest|property_tax|insurance|repairs|maintenance|utilities|cleaning|management|supplies|hoa|advertising|travel|legal_professional|platform_fees|other!',
                    'amount' => 'm!', 'treatment' => 'e:expense|improvement|undetermined', 'improvement_id' => 'ref:property_improvements', 'transaction_id' => 'ref:transactions', 'notes' => 's', 'source' => '~s',
                ],
                'list' => ['property_id', 'incurred_on', 'category', 'amount', 'treatment'],
                'date' => 'incurred_on',
            ],

            // ---------------- Debt ----------------
            'liabilities' => [
                'domain' => 'debt', 'label' => 'Debts', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:mortgage|heloc|auto|boat|credit_card|personal_loc|student|business|intrafamily_note|policy_loan|margin|guarantee|contingent|other!',
                    'lender' => 's', 'account_id' => 'ref:accounts', 'currency' => 'cur', 'original_principal' => 'm', 'current_balance' => 'm!', 'balance_as_of' => 'd',
                    'interest_rate' => 'r', 'rate_type' => 'e:fixed|arm|variable|interest_only', 'rate_index' => 's', 'rate_margin' => 'r', 'rate_caps' => 'j',
                    'rate_reset_months' => 'i', 'next_reset_on' => 'd', 'compounding' => 'e:monthly|daily_actual_365|daily_actual_360|simple',
                    'term_months' => 'i', 'origination_on' => 'd', 'first_payment_on' => 'd', 'payment_amount' => 'm', 'payment_frequency' => 'e:monthly|biweekly|quarterly|annual|interest_only|none',
                    'escrow_amount' => 'm', 'maturity_on' => 'd', 'balloon_amount' => 'm', 'credit_limit' => 'm', 'collateral_type' => 'e:property|asset|account|policy|none',
                    'collateral_id' => 'poly', 'recourse' => 'b', 'contingent' => 'b', 'deductible_interest_kind' => 'e:qualified_residence|investment|business|rental|student|personal|none',
                    'prepayment_terms' => 't', 'status' => 'e:active|paid_off|defaulted|refinanced',
                ],
                'list' => ['name', 'kind', 'lender', 'current_balance', 'interest_rate', 'payment_amount', 'collateral_type', 'collateral_id', 'status'],
                'poly' => ['collateral_id' => 'collateral_type'],
            ],
            'liability_payments' => [
                'domain' => 'debt', 'label' => 'Debt payments', 'order' => ['paid_on', 'desc'], 'parent' => 'liability_id',
                'fields' => ['liability_id' => 'ref:liabilities!', 'paid_on' => 'd!', 'total' => 'm!', 'principal' => 'm', 'interest' => 'm', 'escrow' => 'm', 'fees' => 'm', 'transaction_id' => 'ref:transactions', 'source' => '~s'],
                'list' => ['liability_id', 'paid_on', 'total', 'principal', 'interest', 'escrow'],
                'date' => 'paid_on',
            ],

            // ---------------- Insurance ----------------
            'insurance_policies' => [
                'domain' => 'insurance', 'label' => 'Insurance policies', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:'.self::POLICY_KINDS.'!', 'carrier' => 's', 'agent' => 's', 'policy_number_last4' => 's',
                    'owner_type' => 'e:person|entity', 'owner_id' => 'poly', 'insured' => 'j', 'term_start' => 'd', 'term_end' => 'd', 'premium' => 'm',
                    'premium_frequency' => 'e:annual|semiannual|quarterly|monthly|single', 'deductible' => 'm', 'face_amount' => 'm', 'cash_value' => 'm',
                    'cash_value_as_of' => 'd', 'loan_balance' => 'm', 'surrender_charge' => 'm', 'cost_basis' => 'm', 'required_underlying_auto' => 'm',
                    'required_underlying_home' => 'm', 'carrier_rating' => 's', 'carrier_rating_source' => 's', 'retroactive_date' => 'd', 'tail_terms' => 's',
                    'status' => 'e:active|lapsed|cancelled|expired|quoted', 'document_id' => 'ref:documents',
                ],
                'list' => ['name', 'kind', 'carrier', 'term_end', 'premium', 'face_amount', 'status'],
                'poly' => ['owner_id' => 'owner_type'],
            ],
            'policy_coverages' => [
                'domain' => 'insurance', 'label' => 'Coverages', 'title' => 'coverage', 'parent' => 'policy_id',
                'fields' => [
                    'policy_id' => 'ref:insurance_policies!', 'coverage' => 's!', 'limit_amount' => 'm', 'sublimit' => 'm', 'deductible' => 'm', 'attachment_point' => 'm',
                    'valuation_basis' => 'e:RC|ACV|agreed|stated', 'subject_type' => 'e:property|asset|person|entity|vehicle', 'subject_id' => 'poly', 'exclusions' => 'j',
                    'endorsements' => 'j', 'waiting_period_days' => 'i', 'evidence_locator' => 's',
                ],
                'list' => ['policy_id', 'coverage', 'limit_amount', 'sublimit', 'deductible', 'valuation_basis', 'subject_type'],
                'poly' => ['subject_id' => 'subject_type'],
            ],
            'insurance_claims' => [
                'domain' => 'insurance', 'label' => 'Claims', 'order' => ['loss_date', 'desc'], 'parent' => 'policy_id',
                'fields' => ['policy_id' => 'ref:insurance_policies!', 'loss_date' => 'd!', 'description' => 's!', 'estimated_loss' => 'm', 'paid_amount' => 'm', 'status' => 'e:open|paid|denied|closed|withdrawn'],
                'list' => ['policy_id', 'loss_date', 'description', 'estimated_loss', 'paid_amount', 'status'],
            ],
            'insurance_shopping_cases' => [
                'domain' => 'insurance', 'label' => 'Insurance shopping', 'title' => 'name',
                'fields' => ['name' => 's!', 'policy_id' => 'ref:insurance_policies', 'need' => 'e:renewal|new_coverage|gap|rate_review!', 'baseline' => 'j', 'status' => 'e:open|quoting|decided|closed', 'decision' => 't'],
                'list' => ['name', 'need', 'policy_id', 'status'],
            ],
            'insurance_quotes' => [
                'domain' => 'insurance', 'label' => 'Quotes', 'title' => 'carrier', 'parent' => 'case_id',
                'fields' => ['case_id' => 'ref:insurance_shopping_cases!', 'carrier' => 's!', 'premium' => 'm!', 'term_start' => 'd', 'limits' => 'j', 'deductibles' => 'j', 'endorsements' => 'j', 'exclusions' => 'j', 'carrier_rating' => 's', 'notes' => 't'],
                'list' => ['case_id', 'carrier', 'premium', 'term_start', 'carrier_rating'],
            ],

            // ---------------- Planning ----------------
            'plans' => [
                'domain' => 'planning', 'label' => 'Plans', 'title' => 'name',
                'fields' => [
                    'name' => 's!', 'kind' => 'e:individual|joint|branch|consolidated', 'participant_person_ids' => 'j!', 'start_year' => 'y!', 'horizon_year' => 'y!',
                    'status' => 'e:draft|published|archived', 'is_template' => 'b',
                ],
                'list' => ['name', 'kind', 'start_year', 'horizon_year', 'status'],
            ],
            'plan_scenarios' => [
                'domain' => 'planning', 'label' => 'Scenarios', 'title' => 'name', 'parent' => 'plan_id',
                'fields' => [
                    'plan_id' => 'ref:plans!', 'parent_id' => 'ref:plan_scenarios', 'name' => 's!', 'is_baseline' => 'b', 'assumptions' => 'j!', 'events' => 'j',
                    'strategy' => 'j', 'people' => 'j', 'status' => 'e:draft|published',
                ],
                'list' => ['plan_id', 'name', 'is_baseline', 'status', 'updated_at'],
            ],
            'plan_runs' => [
                'domain' => 'planning', 'label' => 'Plan runs', 'readonly' => true, 'order' => ['created_at', 'desc'], 'parent' => 'scenario_id',
                'fields' => ['scenario_id' => '~s', 'kind' => '~s', 'status' => '~s', 'seed' => '~i', 'paths' => '~i', 'engine_version' => '~s', 'input_hash' => '~s', 'summary' => '~j', 'issues' => '~j', 'job_id' => '~s'],
                'list' => ['scenario_id', 'kind', 'status', 'paths', 'created_at'],
                'heavy' => ['years', 'input_manifest'],
            ],

            // ---------------- Vault & workflow ----------------
            'documents' => [
                'domain' => 'vault', 'label' => 'Documents', 'title' => 'title', 'order' => ['created_at', 'desc'],
                'fields' => [
                    'title' => 's!', 'doc_type' => 'e:statement|paystub|w2|1099|k1|tax_return|tax_form|receipt|invoice|contract|deed|title|appraisal|policy|declarations|will|trust|poa|directive|id|insurance_card|warranty|photo|import_file|report|correspondence|other',
                    'status' => '~s', 'tax_year' => 'y', 'current_version_id' => '~s', 'retention_policy' => 'e:indefinite|7y|10y|until_superseded|custom',
                    'retain_until' => 'd', 'legal_hold' => '~b', 'original_location' => 's', 'tags' => 'j', 'origin' => '~s', 'rejection_reason' => '~t',
                ],
                'list' => ['title', 'doc_type', 'status', 'tax_year', 'created_at'],
                'search' => ['title'],
                'no_create' => true,
            ],
            'document_extractions' => [
                'domain' => 'vault', 'label' => 'Extractions', 'readonly' => true, 'order' => ['created_at', 'desc'], 'parent' => 'document_id',
                'fields' => ['document_id' => '~s', 'version_id' => '~s', 'schema' => '~s', 'schema_version' => '~i', 'engine' => '~s', 'model' => '~s', 'status' => '~s', 'fields' => '~j', 'checks' => '~j', 'overrides' => '~j', 'target_type' => '~s', 'target_id' => '~s', 'approved_at' => '~dt', 'client_provenance' => '~s'],
                'list' => ['document_id', 'schema', 'engine', 'status', 'created_at'],
            ],
            'review_items' => [
                'domain' => 'dynamic', 'label' => 'Review inbox', 'title' => 'title', 'readonly' => true, 'order' => ['created_at', 'desc'],
                'fields' => ['domain' => '~s', 'kind' => '~s', 'subject_type' => '~s', 'subject_id' => '~s', 'title' => '~s', 'reason' => '~t', 'evidence' => '~j', 'severity' => '~s', 'status' => '~s', 'due_on' => '~d', 'resolution' => '~j', 'resolved_at' => '~dt'],
                'list' => ['title', 'kind', 'severity', 'status', 'due_on', 'created_at'],
                'filters' => ['status', 'kind', 'domain'],
            ],
            'report_manifests' => [
                'domain' => 'reports', 'label' => 'Reports', 'title' => 'title', 'readonly' => true, 'order' => ['created_at', 'desc'],
                'fields' => ['kind' => '~s', 'title' => '~s', 'params' => '~j', 'as_of' => '~d', 'currency' => '~s', 'run_ids' => '~j', 'source_scope' => '~j', 'redactions' => '~j', 'status' => '~s', 'document_id' => '~s', 'sha256' => '~s', 'job_id' => '~s'],
                'list' => ['title', 'kind', 'as_of', 'status', 'created_at'],
            ],
        ];
    }

    /** Parse a field DSL string. */
    public static function field(string $spec): array
    {
        $readonly = str_starts_with($spec, '~');
        $spec = ltrim($spec, '~');
        $required = str_ends_with($spec, '!');
        $spec = rtrim($spec, '!');
        $type = $spec;
        $values = null;
        $ref = null;
        if (str_starts_with($spec, 'e:')) {
            $type = 'enum';
            $values = explode('|', substr($spec, 2));
        } elseif (str_starts_with($spec, 'ref:')) {
            $type = 'ref';
            $ref = substr($spec, 4);
        }

        return compact('type', 'required', 'readonly', 'values', 'ref');
    }
}
