<?php

namespace App\Services\Tax;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the runner tax input from canonical, approved facts visible to the initiator. Every item carries
 * fact references for line-level trace; gaps become explicit missing inputs, never silent zeros.
 */
final class TaxInputBuilder
{
    private array $missing = [];
    private array $assumptions = [];

    public function __construct(private readonly AccessContext $ctx) {}

    public function build(string $taxUnitId, array $overrides = []): array
    {
        $unit = $this->ctx->constrain(DB::table('tax_units'), 'tax', 'read', 'tax_year')->where('id', $taxUnitId)->first();
        if (! $unit) {
            throw ApiError::notFound('Tax unit not found.');
        }
        $year = (int) $unit->tax_year;
        $yearEnd = "{$year}-12-31";
        $personIds = array_values(array_filter([$unit->primary_person_id, $unit->spouse_person_id]));
        $persons = DB::table('persons')->whereIn('id', $personIds)->get()->keyBy('id');
        $people = [];
        foreach ($personIds as $pid) {
            $p = $persons[$pid] ?? null;
            $age = $p && $p->birth_date ? (int) floor((strtotime($yearEnd) - strtotime($p->birth_date)) / (365.2425 * 86400)) : 40;
            if (! $p || ! $p->birth_date) {
                $this->missing[] = 'Birth date missing for a taxpayer; age-based items (65+, senior deduction, catch-up) assume under 65.';
            }
            $people[] = ['id' => $pid, 'role' => $pid === $unit->primary_person_id ? 'primary' : 'spouse', 'age' => max(0, $age)];
        }
        $dependents = DB::table('tax_unit_dependents as d')->join('persons as p', 'p.id', '=', 'd.person_id')->where('d.tax_unit_id', $unit->id)->whereNull('d.archived_at')
            ->get(['d.*', 'p.birth_date'])->map(fn ($d) => [
                'id' => $d->person_id, 'age' => $d->birth_date ? (int) floor((strtotime($yearEnd) - strtotime($d->birth_date)) / (365.2425 * 86400)) : 10,
                'ctc' => (bool) $d->qualifies_ctc, 'odc' => (bool) $d->qualifies_odc, 'college_student' => (bool) $d->full_time_student,
            ])->all();

        $docs = $this->ctx->constrain(DB::table('tax_documents')->whereNull('archived_at')->where('status', 'approved')->where('tax_year', $year), 'tax', 'read', 'tax_year')
            ->where(fn ($q) => $q->whereIn('person_id', $personIds)->orWhereNull('person_id'))->get();
        // A corrected form supersedes the original.
        $superseded = $docs->pluck('supersedes_id')->filter()->all();
        $docs = $docs->reject(fn ($d) => in_array($d->id, $superseded, true));
        $box = fn ($d, $k, $default = '0') => (string) (json_decode((string) $d->boxes, true)[$k] ?? $default);

        $income = ['wages' => [], 'interest' => [], 'dividends' => [], 'capital' => [], 'schedule_c' => [], 'rentals' => [], 'k1' => [], 'retirement' => [], 'social_security' => [], 'other' => []];
        $w2Persons = [];
        foreach ($docs as $d) {
            $b = json_decode((string) $d->boxes, true) ?: [];
            $fact = ['tax_document:'.$d->id];
            switch ($d->form) {
                case 'W-2':
                case 'W-2c':
                    $w2Persons[$d->person_id] = true;
                    $income['wages'][] = ['person' => $d->person_id, 'employer' => (string) $d->payer, 'box1' => $box($d, 'box1'), 'box3' => $box($d, 'box3'),
                        'box5' => $box($d, 'box5'), 'box16_nj' => $b['box16'] ?? null, 'fed_wh' => $box($d, 'box2'), 'nj_wh' => $box($d, 'box17'),
                        'ss_tax' => $box($d, 'box4'), 'medicare_tax' => $box($d, 'box6'), 'box12' => array_map('strval', (array) ($b['box12'] ?? [])) ?: new \stdClass,
                        'tips' => (string) ($b['qualified_tips'] ?? '0'), 'overtime_premium' => (string) ($b['qualified_overtime'] ?? '0'), 'facts' => $fact];
                    break;
                case '1099-INT':
                    $income['interest'][] = ['amount' => $box($d, 'box1'), 'us_obligations' => $box($d, 'box3'), 'tax_exempt' => $box($d, 'box8'), 'nj_exempt' => $box($d, 'nj_exempt'), 'facts' => $fact];
                    break;
                case '1099-DIV':
                    $income['dividends'][] = ['ordinary' => $box($d, 'box1a'), 'qualified' => $box($d, 'box1b'), 'cap_gain_dist' => $box($d, 'box2a'),
                        'unrecaptured_1250' => $box($d, 'box2b'), 'section_199a' => $box($d, 'box5'), 'foreign_tax' => $box($d, 'box7'),
                        'exempt_interest' => $box($d, 'box12'), 'nj_exempt' => $box($d, 'nj_exempt'), 'facts' => $fact];
                    break;
                case '1099-B':
                    foreach ((array) ($b['transactions'] ?? []) as $tx) {
                        $income['capital'][] = ['term' => ($tx['term'] ?? 'long') === 'short' ? 'short' : 'long', 'proceeds' => (string) ($tx['proceeds'] ?? '0'),
                            'basis' => (string) ($tx['basis'] ?? '0'), 'adjustment' => (string) ($tx['adjustment'] ?? '0'), 'collectible' => (bool) ($tx['collectible'] ?? false),
                            'description' => mb_substr((string) ($tx['description'] ?? ''), 0, 80), 'facts' => $fact];
                        if (($tx['basis_reported'] ?? true) === false && empty($tx['adjustment'])) {
                            $this->missing[] = '1099-B from '.$d->payer.' reports no basis for a sale; supply adjusted basis (e.g. RSU vest FMV) to avoid double counting W-2 income.';
                        }
                    }
                    break;
                case '1099-R':
                    $income['retirement'][] = ['person' => $d->person_id ?? $personIds[0], 'gross' => $box($d, 'box1'), 'taxable' => $box($d, 'box2a', $box($d, 'box1')),
                        'nj_taxable' => $b['nj_taxable'] ?? null, 'kind' => ($b['ira'] ?? false) ? 'ira' : (($b['roth'] ?? false) ? 'roth' : 'pension'), 'facts' => $fact];
                    break;
                case 'SSA-1099':
                    $income['social_security'][] = ['person' => $d->person_id ?? $personIds[0], 'benefits' => $box($d, 'box5'), 'facts' => $fact];
                    break;
                case 'K-1-1065':
                case 'K-1-1120S':
                case 'K-1-1041':
                    $income['k1'][] = ['entity' => (string) $d->payer, 'kind' => substr($d->form, 4), 'person' => $d->person_id, 'ordinary' => $box($d, 'ordinary'),
                        'rental' => $box($d, 'rental'), 'interest' => $box($d, 'interest'), 'dividends_ordinary' => $box($d, 'dividends_ordinary'),
                        'dividends_qualified' => $box($d, 'dividends_qualified'), 'st_gain' => $box($d, 'st_gain'), 'lt_gain' => $box($d, 'lt_gain'),
                        'sec1231' => $box($d, 'sec1231'), 'guaranteed_payments' => $box($d, 'guaranteed_payments'), 'se_earnings' => $box($d, 'se_earnings'),
                        'sec179' => $box($d, 'sec179'), 'qbi' => $box($d, 'qbi'), 'w2_wages' => $box($d, 'w2_wages'), 'ubia' => $box($d, 'ubia'),
                        'passive' => (bool) ($b['passive'] ?? false), 'basis_available' => $b['basis_available'] ?? null, 'at_risk' => $b['at_risk'] ?? null,
                        'nj_source' => $b['nj_source'] ?? null, 'facts' => $fact];
                    break;
                case '1099-NEC':
                case '1099-MISC':
                case '1099-K':
                    break; // reconciled against Schedule C business records below
                case '1099-G':
                    $income['other'][] = ['label' => 'State refund / unemployment', 'amount' => $box($d, 'box1'), 'nj_amount' => '0', 'facts' => $fact];
                    break;
            }
        }

        // Wages not yet on a W-2: project from approved payroll YTD.
        foreach ($personIds as $pid) {
            if (isset($w2Persons[$pid])) {
                continue;
            }
            $snaps = $this->ctx->constrain(DB::table('payroll_snapshots'), 'compensation', 'read')->where('person_id', $pid)->where('status', 'approved')
                ->whereNull('archived_at')->whereBetween('pay_date', ["{$year}-01-01", $yearEnd])->orderByDesc('pay_date')->get();
            foreach ($snaps->groupBy('employment_id') as $empId => $rows) {
                $latest = $rows->first();
                $ytd = json_decode((string) $latest->ytd, true) ?: [];
                $emp = DB::table('employments')->find($empId);
                $periods = ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12, 'annual' => 1][$emp->pay_frequency ?? 'biweekly'] ?? 26;
                $elapsed = max(1, (int) ceil((strtotime($latest->pay_date) - strtotime("{$year}-01-01")) / (365 / $periods * 86400)));
                $endedEarly = $emp->end_on && $emp->end_on <= $yearEnd;
                $factor = $endedEarly || strtotime($latest->pay_date) >= strtotime("{$year}-12-15") ? '1' : Dec::div((string) $periods, (string) $elapsed, 6);
                $proj = fn ($k) => isset($ytd[$k]) ? Dec::round(bcmul(Dec::norm($ytd[$k]), $factor, 6), 2) : '0';
                $income['wages'][] = ['person' => $pid, 'employer' => (string) ($emp->employer_name ?? ''), 'box1' => $proj('federal_taxable_wages') !== '0' ? $proj('federal_taxable_wages') : $proj('gross'),
                    'box3' => $proj('ss_wages') !== '0' ? $proj('ss_wages') : $proj('gross'), 'box5' => $proj('medicare_wages') !== '0' ? $proj('medicare_wages') : $proj('gross'),
                    'box16_nj' => $proj('state_wages') !== '0' ? $proj('state_wages') : null, 'fed_wh' => $proj('federal_withholding'), 'nj_wh' => $proj('nj_withholding'),
                    'ss_tax' => $proj('social_security'), 'medicare_tax' => $proj('medicare'), 'box12' => ['D' => $proj('pretax_401k')],
                    'facts' => $rows->pluck('id')->map(fn ($i) => 'payroll_snapshot:'.$i)->all()];
                if ($factor !== '1') {
                    $this->assumptions[] = 'Wages for '.($emp->employer_name ?? 'an employer').' projected from payroll YTD through '.$latest->pay_date." (×{$factor}); bonuses/vests not yet paid are excluded unless entered as inputs.";
                }
                $this->missing[] = 'W-2 not yet received from '.($emp->employer_name ?? 'an employer').'; payroll is used until the W-2 is approved.';
            }
            if ($snaps->isEmpty() && DB::table('employments')->where('person_id', $pid)->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('end_on')->orWhere('end_on', '>=', "{$year}-01-01"))->where(fn ($q) => $q->whereNull('start_on')->orWhere('start_on', '<=', $yearEnd))->exists()) {
                $this->missing[] = 'Employment recorded but no W-2 or approved payroll for the year.';
            }
        }

        $income['schedule_c'] = $this->scheduleC($year, $personIds);
        $rentalRows = $this->rentals($year);
        $income['rentals'] = $rentalRows['rentals'];

        $inputs = DB::table('tax_inputs')->where('tax_unit_id', $unit->id)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('override_expires_on')->orWhere('override_expires_on', '>=', now()->toDateString()))->get();
        $kv = [];
        foreach ($inputs as $i) {
            $kv[$i->key] = $i->amount !== null ? Dec::norm($i->amount) : json_decode((string) $i->value, true);
            if ($i->value_state === 'projected' || $i->value_state === 'provisional') {
                $this->assumptions[] = ($i->label ?: $i->key).' is '.$i->value_state.'.';
            }
        }
        $get = fn (string $k, $d = '0') => $kv[$k] ?? $d;

        $paidTax = function (string $j, string $kind) use ($unit, $year) {
            return (string) DB::table('tax_payments')->where('tax_unit_id', $unit->id)->where('jurisdiction', $j)->where('status', 'paid')->whereNull('archived_at')
                ->where('kind', $kind)->whereBetween('paid_on', ["{$year}-01-01", ($year + 1).'-04-15'])->sum('amount');
        };
        $njPaidInYear = (string) DB::table('tax_payments')->where('tax_unit_id', $unit->id)->where('jurisdiction', 'NJ')->where('status', 'paid')->whereNull('archived_at')
            ->whereBetween('paid_on', ["{$year}-01-01", $yearEnd])->sum('amount');
        $njWh = array_reduce($income['wages'], fn ($c, $w) => Dec::add($c, Dec::norm($w['nj_wh'] ?? '0')), '0');

        $mortgageInterest = (string) DB::table('liability_payments as p')->join('liabilities as l', 'l.id', '=', 'p.liability_id')
            ->where('l.workspace_id', $this->ctx->workspace->id)->where('l.deductible_interest_kind', 'qualified_residence')->whereNull('p.archived_at')
            ->whereBetween('p.paid_on', ["{$year}-01-01", $yearEnd])->sum('p.interest');
        $propertyTax = (string) DB::table('property_expenses as e')->join('properties as p', 'p.id', '=', 'e.property_id')
            ->where('p.workspace_id', $this->ctx->workspace->id)->whereIn('p.kind', ['primary', 'second_home'])->where('e.category', 'property_tax')
            ->whereNull('e.archived_at')->whereBetween('e.incurred_on', ["{$year}-01-01", $yearEnd])->sum('e.amount');

        $carry = DB::table('tax_carryforwards')->where('tax_unit_id', $unit->id)->whereNull('archived_at')->where('origin_year', '<', $year)
            ->where(fn ($q) => $q->whereNull('expires_year')->orWhere('expires_year', '>=', $year))->get();
        $cf = fn (string $kind) => (string) $carry->where('kind', $kind)->where('jurisdiction', 'US')->sum('amount');
        $passive = [];
        foreach ($carry->where('kind', 'passive_loss') as $c) {
            if ($c->activity_ref) {
                $passive[$c->activity_ref] = Dec::add($passive[$c->activity_ref] ?? '0', Dec::norm($c->amount));
            }
        }
        $prior = DB::table('tax_runs')->where('workspace_id', $this->ctx->workspace->id)->where('tax_year', $year - 1)
            ->whereIn('status', ['reconciled', 'cpa_reviewed', 'calculated'])->orderByRaw("case status when 'cpa_reviewed' then 0 when 'reconciled' then 1 else 2 end")->orderByDesc('created_at')->first();
        $priorSummary = $prior ? json_decode((string) $prior->summary, true) : null;

        $packs = [];
        foreach (['US', 'NJ'] as $j) {
            $p = DB::table('tax_rule_packs')->where('jurisdiction', $j)->where('tax_year', $year)->whereIn('status', ['approved', 'active', 'draft'])
                ->orderByRaw("case status when 'active' then 0 when 'approved' then 1 else 2 end")->orderByDesc('version')->first();
            if ($p) {
                $packs[$j] = ['version' => $p->version, 'hash' => $p->content_hash, 'approved' => in_array($p->status, ['approved', 'active'], true)];
            }
        }

        $input = [
            'tax_year' => $year, 'filing_status' => $unit->filing_status, 'state' => $unit->state === 'NJ' ? 'NJ' : 'NONE', 'residency' => $unit->residency_status,
            'people' => $people, 'dependents' => $dependents, 'income' => $income,
            'adjustments' => ['hsa' => $get('adjustments.hsa'), 'ira_deductible' => $get('adjustments.ira_deductible'), 'student_loan_interest' => $get('adjustments.student_loan_interest'),
                'se_health_insurance' => $get('adjustments.se_health_insurance'), 'se_retirement' => $get('adjustments.se_retirement'), 'educator' => $get('adjustments.educator'),
                'car_loan_interest' => $get('adjustments.car_loan_interest'), 'other' => $get('adjustments.other')],
            'itemized' => ['medical' => $get('itemized.medical'), 'state_local_income_tax' => Dec::add($njWh, Dec::norm($njPaidInYear ?: '0'), Dec::norm((string) $get('itemized.other_state_tax'))),
                'real_estate_tax' => Dec::add(Dec::norm($propertyTax ?: '0'), Dec::norm((string) $get('itemized.real_estate_tax'))), 'personal_property_tax' => $get('itemized.personal_property_tax'),
                'mortgage_interest' => $kv['itemized.mortgage_interest'] ?? Dec::norm($mortgageInterest ?: '0'), 'mortgage_points' => $get('itemized.mortgage_points'),
                'mortgage_principal_outstanding' => $get('itemized.mortgage_principal_outstanding'), 'investment_interest' => $get('itemized.investment_interest'),
                'charitable_cash' => $get('itemized.charitable_cash'), 'charitable_noncash' => $get('itemized.charitable_noncash'), 'other' => $get('itemized.other')],
            'credits' => ['child_care' => $get('credits.child_care'), 'education' => $get('credits.education'), 'energy' => $get('credits.energy'),
                'foreign_tax' => array_reduce($income['dividends'], fn ($c, $d) => Dec::add($c, Dec::norm($d['foreign_tax'])), '0'), 'other_nonrefundable' => $get('credits.other')],
            'payments' => ['federal_estimated' => Dec::norm($paidTax('US', 'estimated') ?: '0'), 'federal_extension' => Dec::norm($paidTax('US', 'extension') ?: '0'),
                'prior_year_overpayment_federal' => Dec::norm($paidTax('US', 'prior_year_overpayment') ?: '0'), 'federal_other_withholding' => $get('payments.federal_other_withholding'),
                'nj_estimated' => Dec::norm($paidTax('NJ', 'estimated') ?: '0'), 'nj_extension' => Dec::norm($paidTax('NJ', 'extension') ?: '0'),
                'prior_year_overpayment_nj' => Dec::norm($paidTax('NJ', 'prior_year_overpayment') ?: '0'), 'nj_other_withholding' => $get('payments.nj_other_withholding')],
            'prior_year' => ['federal_total_tax' => $kv['prior_year.federal_total_tax'] ?? ($priorSummary['federal']['total_tax'] ?? null),
                'federal_agi' => $kv['prior_year.federal_agi'] ?? ($priorSummary['federal']['agi'] ?? null), 'nj_total_tax' => $kv['prior_year.nj_total_tax'] ?? ($priorSummary['nj']['total_tax'] ?? null)],
            'carryforwards' => ['capital_loss_st' => Dec::norm($cf('capital_loss_st') ?: '0'), 'capital_loss_lt' => Dec::norm($cf('capital_loss_lt') ?: '0'),
                'amt_credit' => Dec::norm($cf('amt_credit') ?: '0'), 'charitable' => Dec::norm($cf('charitable') ?: '0'), 'passive' => $passive ?: new \stdClass],
            'amt' => ['iso_bargain_element' => $this->isoBargain($year, $personIds), 'private_activity_bond_interest' => $get('amt.private_activity_bond_interest'), 'other_adjustments' => $get('amt.other')],
            'nj' => ['property_tax_paid' => Dec::norm($propertyTax ?: '0'), 'rent_paid' => $get('nj.rent_paid'), 'homeowner' => ($kv['nj.homeowner'] ?? true) !== false,
                'nj_529_contributions' => $get('nj.529_contributions'), 'credit_other_jurisdictions' => $get('nj.credit_other_jurisdictions'), 'veterans' => (int) ($kv['nj.veterans'] ?? 0)],
            'packs' => $packs,
            'mfs_lived_apart' => (bool) ($kv['mfs_lived_apart'] ?? false),
        ];
        foreach ((array) ($kv['other_income'] ?? []) as $o) {
            $input['income']['other'][] = ['label' => (string) ($o['label'] ?? 'Other'), 'amount' => (string) ($o['amount'] ?? '0'), 'nj_amount' => isset($o['nj_amount']) ? (string) $o['nj_amount'] : null];
        }
        if (! $input['prior_year']['federal_total_tax']) {
            $this->missing[] = 'Prior-year federal tax is unknown; safe-harbor checks use 90% of current-year tax only.';
        }
        $input = array_replace_recursive($input, $overrides);
        $input['missing_inputs'] = array_values(array_unique($this->missing));
        $input['assumptions'] = array_values(array_unique(array_merge($this->assumptions, $rentalRows['assumptions'], (array) ($overrides['assumptions'] ?? []))));

        return $input;
    }

    private function scheduleC(int $year, array $personIds): array
    {
        $out = [];
        $entities = $this->ctx->constrain(DB::table('legal_entities'), 'governance', 'read')->whereNull('archived_at')
            ->where(fn ($q) => $q->where('kind', 'sole_prop')->orWhere(fn ($w) => $w->where('kind', 'llc')->where('tax_classification', 'disregarded')))->get();
        foreach ($entities as $e) {
            $owner = DB::table('ownership_interests')->where('subject_type', 'entity')->where('subject_id', $e->id)->where('owner_type', 'person')
                ->whereIn('owner_id', $personIds)->whereNull('archived_at')->value('owner_id');
            if (! $owner) {
                continue;
            }
            $txs = $this->ctx->constrain(DB::table('transactions'), 'ledger', 'read')->where('entity_id', $e->id)->whereNull('archived_at')
                ->whereNull('transfer_group')->whereBetween('posted_on', ["{$year}-01-01", "{$year}-12-31"])->get(['id', 'amount', 'tax_category', 'kind']);
            if ($txs->isEmpty()) {
                continue;
            }
            $gross = '0';
            $expenses = [];
            foreach ($txs as $t) {
                $a = Dec::norm($t->amount);
                if (in_array($t->kind, ['transfer', 'contribution', 'withdrawal'], true)) {
                    continue;
                }
                if (Dec::cmp($a, '0') > 0) {
                    $gross = Dec::add($gross, $a);
                } else {
                    $cat = $t->tax_category ?: 'uncategorized';
                    $expenses[$cat] = Dec::add($expenses[$cat] ?? '0', Dec::abs($a));
                }
            }
            if (isset($expenses['uncategorized'])) {
                $this->missing[] = "{$e->name}: business expenses without a tax category need review before calculation.";
            }
            $out[] = ['person' => $owner, 'name' => $e->name, 'gross' => $gross, 'expenses' => $expenses ?: new \stdClass, 'facts' => $txs->pluck('id')->map(fn ($i) => 'transaction:'.$i)->take(200)->all()];
        }

        return $out;
    }

    private function rentals(int $year): array
    {
        $out = [];
        $assumptions = [];
        $props = $this->ctx->constrain(DB::table('properties'), 'real_estate', 'read')->whereNull('archived_at')->whereIn('kind', ['rental_ltr', 'rental_str', 'mixed', 'second_home'])->get();
        foreach ($props as $p) {
            $stays = DB::table('property_stays')->where('property_id', $p->id)->whereNull('archived_at')->where('cancelled', false)
                ->where('check_out', '>', "{$year}-01-01")->where('check_in', '<=', "{$year}-12-31")->get();
            $rentalNights = 0;
            $personal = 0;
            $guestStays = 0;
            $rents = '0';
            $fees = '0';
            foreach ($stays as $s) {
                $in = max(strtotime($s->check_in), strtotime("{$year}-01-01"));
                $outT = min(strtotime($s->check_out), strtotime(($year + 1).'-01-01'));
                $nights = max(0, (int) round(($outT - $in) / 86400));
                if (in_array($s->kind, ['guest', 'family_fair_rent'], true)) {
                    $rentalNights += $nights;
                    $guestStays++;
                    $rents = Dec::add($rents, Dec::norm($s->gross_rent ?? '0'), Dec::norm($s->cleaning_fee ?? '0'));
                    $rents = Dec::sub($rents, Dec::norm($s->refund ?? '0'));
                    $fees = Dec::add($fees, Dec::norm($s->platform_fee ?? '0'));
                } elseif (in_array($s->kind, ['personal', 'family_free', 'donated'], true)) {
                    $personal += $nights;
                }
            }
            $logPersonal = DB::table('property_use_logs')->where('property_id', $p->id)->where('kind', 'personal')->whereNull('archived_at')
                ->whereBetween('log_date', ["{$year}-01-01", "{$year}-12-31"])->count();
            $personal += $logPersonal;
            $available = DB::table('property_use_logs')->where('property_id', $p->id)->where('kind', 'available')->whereNull('archived_at')
                ->whereBetween('log_date', ["{$year}-01-01", "{$year}-12-31"])->count();
            $hours = (float) DB::table('property_use_logs')->where('property_id', $p->id)->whereIn('kind', ['management', 'maintenance', 'rental'])->whereNull('archived_at')
                ->whereBetween('log_date', ["{$year}-01-01", "{$year}-12-31"])->sum('hours');
            $exp = ['platform_fees' => $fees];
            foreach (DB::table('property_expenses')->where('property_id', $p->id)->whereNull('archived_at')->where('treatment', 'expense')
                ->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])->get() as $e) {
                $exp[$e->category] = Dec::add($exp[$e->category] ?? '0', Dec::norm($e->amount));
            }
            if (DB::table('property_expenses')->where('property_id', $p->id)->where('treatment', 'undetermined')->whereNull('archived_at')
                ->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])->exists()) {
                $this->missing[] = "{$p->name}: some expenses are not yet classified as repair or improvement.";
            }
            $interest = (string) DB::table('liability_payments as lp')->join('liabilities as l', 'l.id', '=', 'lp.liability_id')
                ->where('l.collateral_type', 'property')->where('l.collateral_id', $p->id)->whereNull('lp.archived_at')
                ->whereBetween('lp.paid_on', ["{$year}-01-01", "{$year}-12-31"])->sum('lp.interest');
            if ($interest !== '' && (float) $interest > 0) {
                $exp['mortgage_interest'] = Dec::add($exp['mortgage_interest'] ?? '0', Dec::norm($interest));
            }
            $mp = DB::table('tax_inputs')->where('workspace_id', $this->ctx->workspace->id)->where('key', 'material_participation:'.$p->id)->value('value');
            $material = $mp !== null ? (bool) json_decode((string) $mp, true) : $hours > 500;
            if ($mp === null && $hours >= 100 && $hours <= 500) {
                $assumptions[] = "{$p->name}: {$hours} participation hours logged; material participation is not assumed (tests 3-7 need facts about others' hours).";
            }
            if ($rentalNights === 0 && $personal === 0 && $stays->isEmpty()) {
                continue;
            }
            $out[] = ['property_id' => $p->id, 'name' => $p->name, 'rents' => $rents, 'expenses' => $exp, 'depreciation' => $this->depreciation($p, $year),
                'fair_rental_days' => $rentalNights, 'personal_days' => $personal, 'available_days' => $available, 'guest_stays' => $guestStays,
                'rental_nights' => $rentalNights, 'material_participation' => $material, 'active_participation' => true,
                'facts' => array_merge(['property:'.$p->id], $stays->pluck('id')->map(fn ($i) => 'property_stay:'.$i)->take(150)->all())];
        }

        return ['rentals' => $out, 'assumptions' => $assumptions];
    }

    /** Straight-line, mid-month residential (27.5y) or nonresidential (39y) depreciation for building and improvements. */
    public function depreciation(object $p, int $year): string
    {
        $total = '0';
        $pieces = [];
        if ($p->placed_in_service_on && $p->building_value) {
            $basis = $p->converted_to_rental_on && $p->fmv_at_conversion !== null ? min((float) $p->building_value, (float) $p->fmv_at_conversion) : (float) $p->building_value;
            $pieces[] = [$p->placed_in_service_on, (string) $basis, (float) ($p->recovery_years ?: 27.5)];
        }
        foreach (DB::table('property_improvements')->where('property_id', $p->id)->whereNull('archived_at')->get() as $i) {
            $pieces[] = [$i->placed_in_service_on, Dec::norm($i->cost), (float) $i->recovery_years];
        }
        foreach ($pieces as [$pis, $basis, $life]) {
            $startYear = (int) substr($pis, 0, 4);
            $month = (int) substr($pis, 5, 2);
            if ($year < $startYear) {
                continue;
            }
            $annual = (float) $basis / $life;
            $firstYearMonths = 12 - $month + 0.5;
            $taken = $firstYearMonths / 12 * $annual + max(0, $year - $startYear - 1) * $annual;
            $amount = $year === $startYear ? $firstYearMonths / 12 * $annual : $annual;
            $amount = max(0, min($amount, (float) $basis - ($year === $startYear ? 0 : $taken)));
            $total = Dec::add($total, Dec::norm(round($amount, 2)));
        }

        return $total;
    }

    private function isoBargain(int $year, array $personIds): string
    {
        $sum = '0';
        $events = DB::table('equity_events as e')->join('equity_grants as g', 'g.id', '=', 'e.grant_id')->where('g.grant_type', 'iso')
            ->whereIn('g.person_id', $personIds)->where('e.kind', 'exercise')->whereNull('e.archived_at')->whereBetween('e.event_date', ["{$year}-01-01", "{$year}-12-31"])
            ->get(['e.units', 'e.fmv', 'g.strike_price']);
        foreach ($events as $e) {
            if ($e->fmv !== null) {
                $sum = Dec::add($sum, Dec::mul(Dec::norm($e->units, 10), Dec::sub(Dec::norm($e->fmv, 8), Dec::norm($e->strike_price ?? '0', 8))));
            }
        }

        return $sum;
    }
}
