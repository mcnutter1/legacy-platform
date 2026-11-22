<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Models\Scope;
use App\Models\User;
use App\Models\Workspace;
use App\Resources\ResourceService;
use App\Services\AccessService;
use App\Support\AccessContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Synthetic demonstration data (fictional family). Refused in production. */
class DemoSeedCommand extends Command
{
    protected $signature = 'legacy:demo {uid=dev-admin : central principal id of the owner} {--name=Example Family Office}';

    protected $description = 'Create a workspace populated with synthetic data for local or staging use';

    public function handle(AccessService $access): int
    {
        if (app()->environment('production')) {
            $this->error('Refused in production.');

            return self::FAILURE;
        }
        $user = User::firstOrCreate(['central_principal_type' => 'user', 'central_principal_id' => $this->argument('uid')],
            ['name' => 'Alex Example', 'email' => 'alex@example.test']);
        config(['legacy.auth.workspace_creators' => ['*']]);
        $ws = $access->createWorkspace($user, $this->option('name'));
        $m = Membership::where('workspace_id', $ws->id)->where('user_id', $user->id)->first();
        $ctx = new AccessContext($user, $ws, $m);
        app()->instance(AccessContext::class, $ctx);
        $joint = Scope::where('workspace_id', $ws->id)->where('kind', 'joint')->first()->id;
        $svc = new ResourceService($ctx);
        $mk = fn (string $type, array $data) => $svc->create($type, $data + ['scope_id' => $joint]);
        $y = (int) now()->year;

        $alex = $mk('persons', ['display_name' => 'Alex Example', 'birth_date' => '1978-04-12', 'is_us_citizen' => true, 'life_expectancy_age' => 92]);
        $jordan = $mk('persons', ['display_name' => 'Jordan Example', 'birth_date' => '1980-09-03', 'is_us_citizen' => true, 'life_expectancy_age' => 94]);
        $riley = $mk('persons', ['display_name' => 'Riley Example', 'birth_date' => ($y - 14).'-02-20']);
        $casey = $mk('persons', ['display_name' => 'Casey Example', 'birth_date' => ($y - 9).'-07-11']);
        DB::table('memberships')->where('id', $m->id)->update(['person_id' => $alex->id]);
        $mk('relationships', ['person_id' => $alex->id, 'related_person_id' => $jordan->id, 'kind' => 'spouse', 'effective_from' => '2006-06-17']);
        foreach ([$riley, $casey] as $kid) {
            $mk('relationships', ['person_id' => $alex->id, 'related_person_id' => $kid->id, 'kind' => 'child']);
            $mk('relationships', ['person_id' => $jordan->id, 'related_person_id' => $kid->id, 'kind' => 'child']);
        }
        $hh = $mk('households', ['name' => 'Example household', 'city' => 'Princeton', 'state' => 'NJ']);

        $checking = $mk('accounts', ['name' => 'Joint checking', 'institution' => 'Example Bank', 'kind' => 'checking', 'tax_bucket' => 'taxable', 'mask' => '1234']);
        $brokerage = $mk('accounts', ['name' => 'Joint brokerage', 'institution' => 'Example Brokerage', 'kind' => 'brokerage', 'tax_bucket' => 'taxable']);
        $ira = $mk('accounts', ['name' => 'Alex 401(k)', 'institution' => 'Example Recordkeeper', 'kind' => '401k', 'tax_bucket' => 'pretax']);
        $roth = $mk('accounts', ['name' => 'Jordan Roth IRA', 'institution' => 'Example Brokerage', 'kind' => 'roth_ira', 'tax_bucket' => 'roth']);
        foreach ([[$ira, $alex], [$roth, $jordan]] as [$a, $person]) {
            $mk('ownership_interests', ['owner_type' => 'person', 'owner_id' => $person->id, 'subject_type' => 'account', 'subject_id' => $a->id, 'fraction' => '1', 'title_form' => 'sole']);
        }
        $college = $mk('accounts', ['name' => 'NJBEST 529', 'institution' => 'Example 529', 'kind' => '529', 'tax_bucket' => 'education']);
        foreach ([[$checking, '48250.00'], [$ira, '612400.00'], [$roth, '148900.00'], [$college, '86300.00']] as [$a, $bal]) {
            $mk('balance_snapshots', ['account_id' => $a->id, 'as_of' => now()->subDays(3)->toDateString(), 'balance' => $bal]);
        }
        foreach ([['VTI', 'Total US Stock ETF', 'us_equity', 'Broad market', '1450', '285.40'], ['VXUS', 'Total Intl Stock ETF', 'intl_equity', 'Broad market', '2100', '64.10'],
            ['BND', 'Total Bond ETF', 'bonds', 'Fixed income', '1800', '72.55'], ['EXMPL', 'Example Corp', 'us_equity', 'Technology', '3000', '118.20']] as [$sym, $name, $cls, $sector, $qty, $px]) {
            $instr = $svc->create('instruments', ['symbol' => $sym, 'name' => $name, 'kind' => $sym === 'EXMPL' ? 'equity' : 'etf', 'asset_class' => $cls, 'sector' => $sector,
                'issuer' => $sym === 'EXMPL' ? 'Example Corp' : 'Example Funds', 'last_price' => $px, 'last_price_on' => now()->toDateString()]);
            $mk('holdings', ['account_id' => $brokerage->id, 'instrument_id' => $instr->id, 'as_of' => now()->subDays(1)->toDateString(), 'quantity' => $qty, 'price' => $px,
                'market_value' => bcmul($qty, $px, 2), 'cost_basis' => bcmul($qty, bcmul($px, '0.72', 4), 2), 'price_as_of' => now()->subDays(1)->toDateString()]);
            $mk('lots', ['account_id' => $brokerage->id, 'instrument_id' => $instr->id, 'acquired_on' => '2019-03-15', 'quantity' => $qty, 'remaining_quantity' => $qty,
                'cost_basis' => bcmul($qty, bcmul($px, '0.72', 4), 2)]);
        }

        $home = $mk('properties', ['name' => 'Princeton residence', 'kind' => 'primary', 'city' => 'Princeton', 'state' => 'NJ', 'acquired_on' => '2014-08-01',
            'purchase_price' => '925000', 'closing_costs' => '18500', 'land_value' => '310000', 'building_value' => '615000']);
        $shore = $mk('properties', ['name' => 'Shore cottage', 'kind' => 'rental_str', 'city' => 'Cape May', 'state' => 'NJ', 'acquired_on' => '2020-05-15',
            'purchase_price' => '780000', 'land_value' => '340000', 'building_value' => '440000', 'placed_in_service_on' => '2020-06-01', 'recovery_years' => '27.5']);
        foreach ([[$home, '1480000'], [$shore, '1010000']] as [$p, $v]) {
            $val = $mk('valuations', ['subject_type' => 'property', 'subject_id' => $p->id, 'purpose' => 'market', 'value' => $v, 'low' => bcmul($v, '0.94', 0), 'high' => bcmul($v, '1.06', 0),
                'as_of' => now()->subMonths(2)->toDateString(), 'method' => 'comps', 'source' => 'Synthetic comparables', 'confidence' => '0.7']);
            DB::table('valuations')->where('id', $val->id)->update(['status' => 'accepted', 'accepted_by' => $user->id, 'accepted_at' => now()]);
            $mk('ownership_interests', ['owner_type' => 'person', 'owner_id' => $alex->id, 'subject_type' => 'property', 'subject_id' => $p->id, 'fraction' => '0.5', 'title_form' => 'tenancy_by_entirety']);
            $mk('ownership_interests', ['owner_type' => 'person', 'owner_id' => $jordan->id, 'subject_type' => 'property', 'subject_id' => $p->id, 'fraction' => '0.5', 'title_form' => 'tenancy_by_entirety']);
        }
        $ty = $y - 1;
        $stays = [['06-14', '06-21'], ['06-21', '06-28'], ['07-05', '07-12'], ['07-12', '07-19'], ['07-19', '07-26'], ['08-02', '08-09'], ['08-09', '08-16'], ['08-16', '08-23']];
        foreach ($stays as [$in, $out]) {
            $mk('property_stays', ['property_id' => $shore->id, 'check_in' => "{$ty}-{$in}", 'check_out' => "{$ty}-{$out}", 'kind' => 'guest', 'gross_rent' => '4200', 'cleaning_fee' => '250',
                'platform_fee' => '630', 'payout' => '3820', 'channel' => 'direct']);
        }
        $mk('property_stays', ['property_id' => $shore->id, 'check_in' => "{$ty}-07-26", 'check_out' => "{$ty}-08-02", 'kind' => 'personal']);
        foreach ([['mortgage_interest', '21400'], ['property_tax', '9800'], ['insurance', '3100'], ['utilities', '4200'], ['cleaning', '2400'], ['repairs', '1850']] as [$cat, $amt]) {
            $mk('property_expenses', ['property_id' => $shore->id, 'incurred_on' => "{$ty}-09-30", 'category' => $cat, 'amount' => $amt, 'treatment' => 'expense']);
        }
        $mk('property_expenses', ['property_id' => $home->id, 'incurred_on' => "{$ty}-10-31", 'category' => 'property_tax', 'amount' => '18900', 'treatment' => 'expense']);

        $mortgage = $mk('liabilities', ['name' => 'Residence mortgage', 'kind' => 'mortgage', 'lender' => 'Example Mortgage', 'original_principal' => '740000', 'current_balance' => '512300',
            'balance_as_of' => now()->subDays(10)->toDateString(), 'interest_rate' => '0.0325', 'term_months' => 360, 'origination_on' => '2014-08-01', 'first_payment_on' => '2014-09-01',
            'payment_amount' => '3220.48', 'escrow_amount' => '1980', 'collateral_type' => 'property', 'collateral_id' => $home->id, 'deductible_interest_kind' => 'qualified_residence']);
        $mk('liability_payments', ['liability_id' => $mortgage->id, 'paid_on' => "{$ty}-12-01", 'total' => '3220.48', 'principal' => '1840.10', 'interest' => '16550.00']);
        $mk('liabilities', ['name' => 'Shore cottage mortgage', 'kind' => 'mortgage', 'current_balance' => '498000', 'interest_rate' => '0.0545', 'term_months' => 360,
            'payment_amount' => '2980.22', 'collateral_type' => 'property', 'collateral_id' => $shore->id, 'deductible_interest_kind' => 'rental']);
        $mk('liabilities', ['name' => 'Auto loan', 'kind' => 'auto', 'current_balance' => '18400', 'interest_rate' => '0.0649', 'payment_amount' => '612', 'term_months' => 36]);

        $emp = $mk('employments', ['person_id' => $alex->id, 'employer_name' => 'Example Technologies', 'title' => 'VP Engineering', 'kind' => 'w2', 'start_on' => '2019-01-07',
            'base_salary' => '265000', 'bonus_target_pct' => '0.20', 'pay_frequency' => 'biweekly', 'work_state' => 'NJ']);
        $mk('employments', ['person_id' => $jordan->id, 'employer_name' => 'Example Health', 'title' => 'Director', 'kind' => 'w2', 'start_on' => '2016-03-01',
            'base_salary' => '148000', 'pay_frequency' => 'semimonthly', 'work_state' => 'NJ']);
        $grant = $mk('equity_grants', ['person_id' => $alex->id, 'employment_id' => $emp->id, 'company_name' => 'Example Technologies', 'grant_type' => 'rsu', 'grant_date' => ($y - 2).'-03-01',
            'units' => '4800', 'vest_start_date' => ($y - 2).'-03-01', 'vesting' => ['cliff_months' => 12, 'total_months' => 48, 'frequency_months' => 3]]);
        app(\App\Services\EquityService::class)->generateTranches($ctx, $grant->id);
        $mk('company_valuations', ['company_name' => 'Example Technologies', 'as_of' => now()->subMonths(1)->toDateString(), 'kind' => '409a', 'price_per_share' => '42.50']);

        $unit = $mk('tax_units', ['name' => 'Alex & Jordan', 'tax_year' => $ty, 'filing_status' => 'mfj', 'primary_person_id' => $alex->id, 'spouse_person_id' => $jordan->id, 'state' => 'NJ']);
        foreach ([$riley, $casey] as $kid) {
            $mk('tax_unit_dependents', ['tax_unit_id' => $unit->id, 'person_id' => $kid->id, 'qualifies_ctc' => true]);
        }
        foreach ([[$alex, 'Example Technologies', ['box1' => '298500', 'box2' => '58200', 'box3' => '176100', 'box4' => '10918.20', 'box5' => '321500', 'box6' => '4661.75', 'box16' => '321500', 'box17' => '19850', 'box12' => ['D' => '23000']]],
            [$jordan, 'Example Health', ['box1' => '139500', 'box2' => '17900', 'box3' => '148000', 'box4' => '9176', 'box5' => '148000', 'box6' => '2146', 'box16' => '148000', 'box17' => '6900', 'box12' => ['D' => '8500']]]] as [$p, $payer, $boxes]) {
            $doc = $mk('tax_documents', ['tax_year' => $ty, 'form' => 'W-2', 'person_id' => $p->id, 'payer' => $payer, 'boxes' => $boxes]);
            DB::table('tax_documents')->where('id', $doc->id)->update(['status' => 'approved']);
        }
        foreach ([['1099-INT', 'Example Bank', ['box1' => '1840']], ['1099-DIV', 'Example Brokerage', ['box1a' => '9620', 'box1b' => '8410', 'box2a' => '1250', 'box7' => '112']],
            ['1099-B', 'Example Brokerage', ['transactions' => [['term' => 'long', 'proceeds' => '42000', 'basis' => '30500', 'description' => 'VTI'], ['term' => 'short', 'proceeds' => '9800', 'basis' => '11200', 'description' => 'EXMPL']]]]] as [$form, $payer, $boxes]) {
            $doc = $mk('tax_documents', ['tax_year' => $ty, 'form' => $form, 'payer' => $payer, 'boxes' => $boxes]);
            DB::table('tax_documents')->where('id', $doc->id)->update(['status' => 'approved']);
        }
        $mk('tax_inputs', ['tax_unit_id' => $unit->id, 'key' => 'itemized.charitable_cash', 'amount' => '8500', 'label' => 'Cash gifts (receipts in vault)']);
        $mk('tax_inputs', ['tax_unit_id' => $unit->id, 'key' => 'prior_year.federal_total_tax', 'amount' => '78400', 'label' => 'Prior-year federal tax (from filed return)']);
        $mk('tax_inputs', ['tax_unit_id' => $unit->id, 'key' => 'prior_year.federal_agi', 'amount' => '402000']);
        $mk('tax_payments', ['tax_unit_id' => $unit->id, 'jurisdiction' => 'US', 'kind' => 'estimated', 'installment' => 1, 'due_on' => "{$ty}-04-15", 'paid_on' => "{$ty}-04-10", 'amount' => '4000', 'status' => 'paid']);

        $plan = $mk('plans', ['name' => 'Retirement & legacy plan', 'kind' => 'joint', 'participant_person_ids' => [$alex->id, $jordan->id], 'start_year' => $y, 'horizon_year' => $y + 45]);
        $mk('plan_scenarios', ['plan_id' => $plan->id, 'name' => 'Baseline', 'is_baseline' => true, 'assumptions' => [
            'inflation' => 0.025, 'essential_spending' => 145000, 'discretionary_spending' => 55000, 'healthcare_pre65' => 18000, 'healthcare_65' => 9000,
            'spending_phases' => [['from_year' => $y + 25, 'multiplier' => 0.85]], 'mc_paths' => 1000, 'mc_seed' => 20261121,
            'property_appreciation' => 0.03], 'people' => [
            ['id' => $alex->id, 'retire_age' => 60, 'ss_pia' => 3900, 'ss_claim_age' => 67], ['id' => $jordan->id, 'retire_age' => 62, 'ss_pia' => 2600, 'ss_claim_age' => 67]],
            'events' => [['year' => $y + 4, 'kind' => 'education', 'amount' => 140000, 'label' => 'Riley college']], 'strategy' => ['min_cash' => 50000]]);

        $policy = $mk('insurance_policies', ['name' => 'Homeowners', 'kind' => 'homeowners', 'carrier' => 'Example Mutual', 'term_start' => now()->subMonths(10)->toDateString(),
            'term_end' => now()->addMonths(2)->toDateString(), 'premium' => '4280', 'deductible' => '2500']);
        $mk('policy_coverages', ['policy_id' => $policy->id, 'coverage' => 'Dwelling', 'limit_amount' => '1250000', 'valuation_basis' => 'RC', 'subject_type' => 'property', 'subject_id' => $home->id]);
        $mk('policy_coverages', ['policy_id' => $policy->id, 'coverage' => 'Personal liability', 'limit_amount' => '500000']);
        $umb = $mk('insurance_policies', ['name' => 'Umbrella', 'kind' => 'umbrella', 'carrier' => 'Example Mutual', 'term_end' => now()->addMonths(7)->toDateString(), 'premium' => '890',
            'face_amount' => '3000000', 'required_underlying_home' => '500000', 'required_underlying_auto' => '500000']);
        $mk('insurance_policies', ['name' => 'Alex term life', 'kind' => 'term_life', 'carrier' => 'Example Life', 'face_amount' => '2500000', 'premium' => '2100',
            'owner_type' => 'person', 'owner_id' => $alex->id, 'insured' => [['person_id' => $alex->id]], 'term_end' => ($y + 18).'-05-01']);

        $trust = $mk('legal_entities', ['name' => 'Example Family Revocable Trust', 'kind' => 'trust', 'jurisdiction' => 'NJ', 'tax_classification' => 'grantor_trust', 'formed_on' => '2018-11-02']);
        $mk('trust_details', ['entity_id' => $trust->id, 'trust_type' => 'revocable_living', 'settlor_person_ids' => [$alex->id, $jordan->id], 'funding_status' => 'partial',
            'distribution_terms' => ['age_schedule' => [['age' => 25, 'fraction' => 0.33], ['age' => 30, 'fraction' => 0.5], ['age' => 35, 'fraction' => 1]]]]);
        $mk('estate_documents', ['kind' => 'will', 'title' => 'Last will — Alex', 'person_id' => $alex->id, 'executed_on' => '2018-11-02', 'review_due_on' => now()->addDays(20)->toDateString(),
            'original_location' => 'Attorney vault', 'status' => 'executed', 'provisions' => ['residuary' => [['type' => 'person', 'id' => $jordan->id, 'share' => '1']], 'pour_over_trust_id' => null]]);
        $mk('estate_documents', ['kind' => 'will', 'title' => 'Last will — Jordan', 'person_id' => $jordan->id, 'executed_on' => '2018-11-02', 'status' => 'executed',
            'provisions' => ['residuary' => [], 'pour_over_trust_id' => $trust->id]]);
        $mk('beneficiary_designations', ['subject_type' => 'account', 'subject_id' => $ira->id, 'beneficiary_type' => 'person', 'beneficiary_id' => $jordan->id, 'tier' => 'primary', 'share_fraction' => '1', 'verified_on' => '2019-02-01']);
        $mk('beneficiary_designations', ['subject_type' => 'account', 'subject_id' => $ira->id, 'beneficiary_type' => 'person', 'beneficiary_id' => $riley->id, 'tier' => 'contingent', 'share_fraction' => '0.5']);
        $mk('beneficiary_designations', ['subject_type' => 'account', 'subject_id' => $ira->id, 'beneficiary_type' => 'person', 'beneficiary_id' => $casey->id, 'tier' => 'contingent', 'share_fraction' => '0.5']);
        $mk('estate_scenarios', ['name' => 'Alex first, then Jordan', 'death_order' => [$alex->id, $jordan->id], 'assumed_year' => $y]);
        $cpa = $mk('contacts', ['name' => 'Morgan Ledger, CPA', 'kind' => 'cpa', 'organization' => 'Example CPA LLP', 'email' => 'cpa@example.test']);
        $atty = $mk('contacts', ['name' => 'Taylor Counsel, Esq.', 'kind' => 'attorney', 'organization' => 'Example Law', 'email' => 'counsel@example.test', 'emergency' => true]);
        $mk('emergency_binders', ['name' => 'If something happens', 'instructions' => 'Call Taylor Counsel first. Originals are in the attorney vault.',
            'contents' => ['contact_ids' => [$cpa->id, $atty->id], 'policy_ids' => [$policy->id, $umb->id], 'account_ids' => [$checking->id, $ira->id]], 'release_hours' => 72]);
        $mk('legal_obligations', ['title' => 'Trust annual review', 'kind' => 'other', 'entity_id' => $trust->id, 'due_on' => now()->addDays(25)->toDateString(), 'recurrence' => 'annual']);
        $mk('family_goals', ['title' => 'Fully fund both 529s', 'kind' => 'education', 'target_amount' => '320000', 'target_date' => ($y + 8).'-08-31', 'priority' => 'important']);
        $mk('source_connections', ['provider' => 'monarch', 'label' => 'Monarch Money export', 'mode' => 'file', 'freshness_hours' => 720]);
        $mk('source_connections', ['provider' => 'snowball', 'label' => 'Snowball Analytics export', 'mode' => 'file', 'freshness_hours' => 720]);

        $this->info("Demo workspace {$ws->id} created for {$this->argument('uid')} (synthetic data only).");

        return self::SUCCESS;
    }
}
