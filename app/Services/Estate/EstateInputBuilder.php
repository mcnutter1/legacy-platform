<?php

namespace App\Services\Estate;

use App\Services\NetWorthService;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/** Builds the estate runner input from title, contract beneficiaries, trusts, wills and valuations. */
final class EstateInputBuilder
{
    public function __construct(private readonly AccessContext $ctx) {}

    public function build(string $scenarioId): array
    {
        $sc = $this->ctx->constrain(DB::table('estate_scenarios'), 'estate', 'read')->where('id', $scenarioId)->whereNull('archived_at')->first();
        if (! $sc) {
            throw ApiError::notFound();
        }
        $order = json_decode((string) $sc->death_order, true) ?: [];
        $assumptions = json_decode((string) $sc->assumptions, true) ?: [];
        $year = (int) $sc->assumed_year;
        $today = now()->toDateString();
        $wsId = $this->ctx->workspace->id;
        $nw = app(NetWorthService::class);
        $persons = DB::table('persons')->where('workspace_id', $wsId)->whereNull('archived_at')->get();
        $relations = DB::table('relationships')->where('workspace_id', $wsId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->get()
            ->map(fn ($r) => ['from_id' => $r->person_id, 'to_id' => $r->related_person_id, 'kind' => $r->kind])->all();

        $owners = function (string $type, string $id, ?object $row = null) use ($wsId) {
            $rows = DB::table('ownership_interests')->where('workspace_id', $wsId)->where('subject_type', $type)->where('subject_id', $id)
                ->where('basis', 'legal_title')->whereNull('archived_at')->get();
            if ($rows->isNotEmpty()) {
                return [$rows->map(fn ($r) => ['type' => $r->owner_type, 'id' => $r->owner_id, 'fraction' => (string) $r->fraction])->all(), $rows->first()->title_form ?: 'sole'];
            }
            if ($row && ($row->owner_person_id ?? null)) {
                return [[['type' => 'person', 'id' => $row->owner_person_id, 'fraction' => '1']], 'sole'];
            }
            if ($row && ($row->owner_entity_id ?? $row->entity_id ?? null)) {
                return [[['type' => 'entity', 'id' => $row->owner_entity_id ?? $row->entity_id, 'fraction' => '1']], 'entity'];
            }

            return [[], 'sole'];
        };
        $bens = function (string $type, string $id) use ($wsId) {
            return DB::table('beneficiary_designations')->where('workspace_id', $wsId)->where('subject_type', $type)->where('subject_id', $id)->whereNull('archived_at')->get()
                ->map(fn ($b) => ['tier' => $b->tier === 'remainder' ? 'contingent' : $b->tier, 'type' => $b->beneficiary_type, 'id' => $b->beneficiary_id,
                    'label' => (string) $b->beneficiary_label, 'share' => (string) $b->share_fraction, 'per_stirpes' => (bool) $b->per_stirpes])->all();
        };

        $assets = [];
        $unvisible = 0;
        $push = function (string $type, string $id, string $label, string $kind, ?string $value, object $row, array $extra = []) use (&$assets, $owners, $bens) {
            if ($value === null) {
                return;
            }
            [$own, $form] = $owners($type, $id, $row);
            $form = in_array($form, ['sole', 'jtwros', 'tenancy_by_entirety', 'tenants_in_common', 'community', 'trust', 'entity'], true) ? $form : 'sole';
            $assets[] = ['id' => $id, 'label' => $label, 'kind' => $kind, 'value' => $value, 'title_form' => $form, 'owners' => $own, 'beneficiaries' => $bens($type, $id)] + $extra;
        };
        foreach (['ledger' => 'accounts', 'real_estate' => 'properties', 'assets' => 'assets', 'insurance' => 'insurance_policies', 'debt' => 'liabilities'] as $domain => $table) {
            if (! $this->ctx->canAny($domain)) {
                $unvisible++;
            }
        }
        if ($this->ctx->canAny('ledger')) {
            foreach ($this->ctx->constrain(DB::table('accounts'), 'ledger', 'read')->whereNull('archived_at')->where('status', 'open')->where('visibility', 'visible')->where('is_liability', false)->get() as $a) {
                [$v] = $nw->accountValue($a->id, $today);
                $push('account', $a->id, $a->name, 'account', $v, $a, ['retirement' => in_array($a->tax_bucket, ['pretax', 'roth'], true), 'liquid' => $a->liquidity === 'daily',
                    'basis' => (string) (DB::table('lots')->where('account_id', $a->id)->whereNull('archived_at')->sum('cost_basis') ?: '0')]);
            }
        }
        if ($this->ctx->canAny('real_estate')) {
            foreach ($this->ctx->constrain(DB::table('properties'), 'real_estate', 'read')->whereNull('archived_at')->where('status', 'owned')->get() as $p) {
                [$v] = $nw->valuation('property', $p->id, $today, 'estate');
                if ($v === null) {
                    [$v] = $nw->valuation('property', $p->id, $today);
                }
                $debt = (string) (DB::table('liabilities')->where('collateral_type', 'property')->where('collateral_id', $p->id)->whereNull('archived_at')->sum('current_balance') ?: '0');
                $push('property', $p->id, $p->name, 'property', $v ?? ($p->purchase_price !== null ? (string) $p->purchase_price : null), $p,
                    ['basis' => (string) (($p->purchase_price ?? 0) + ($p->closing_costs ?? 0)), 'debt' => $debt, 'liquid' => false]);
            }
        }
        if ($this->ctx->canAny('assets')) {
            foreach ($this->ctx->constrain(DB::table('assets'), 'assets', 'read')->whereNull('archived_at')->where('status', 'owned')->get() as $x) {
                [$v] = $nw->valuation('asset', $x->id, $today, 'estate');
                [$v2] = $v === null ? $nw->valuation('asset', $x->id, $today) : [$v];
                $push('asset', $x->id, $x->name, $x->kind, $v2 ?? ($x->cost !== null ? (string) $x->cost : null), $x, ['liquid' => false, 'basis' => (string) ($x->cost ?? '0')]);
            }
        }
        if ($this->ctx->canAny('insurance')) {
            foreach ($this->ctx->constrain(DB::table('insurance_policies'), 'insurance', 'read')->whereNull('archived_at')->where('status', 'active')
                ->whereIn('kind', ['term_life', 'whole_life', 'universal_life', 'variable_life'])->get() as $pol) {
                $insured = json_decode((string) $pol->insured, true);
                $insuredId = is_array($insured) ? ($insured[0]['person_id'] ?? ($insured['person_id'] ?? null)) : null;
                if (! $pol->face_amount || ! $insuredId) {
                    continue;
                }
                $isTrust = $pol->owner_type === 'entity';
                $push('policy', $pol->id, $pol->name, 'policy', '0', $pol, ['life_insurance' => ['insured' => $insuredId, 'owner' => $pol->owner_id,
                    'owner_type' => $isTrust ? 'trust' : 'person', 'death_benefit' => (string) $pol->face_amount], 'liquid' => true]);
            }
        }
        $trusts = [];
        foreach ($this->ctx->constrain(DB::table('trust_details as t'), 'estate', 'read', null, 't')->join('legal_entities as e', 'e.id', '=', 't.entity_id')->whereNull('t.archived_at')->get(['t.*', 'e.name']) as $t) {
            $terms = json_decode((string) $t->distribution_terms, true) ?: [];
            $trusts[] = ['id' => $t->entity_id, 'name' => $t->name, 'settlor_ids' => json_decode((string) $t->settlor_person_ids, true) ?: [], 'revocable' => (bool) $t->revocable,
                'beneficiaries' => $bens('trust', $t->entity_id), 'age_schedule' => $terms['age_schedule'] ?? [], 'marital' => (bool) ($terms['marital'] ?? false)];
        }
        $wills = [];
        foreach ($this->ctx->constrain(DB::table('estate_documents'), 'estate', 'read')->where('kind', 'will')->where('status', 'executed')->whereNull('archived_at')->get() as $w) {
            $prov = json_decode((string) $w->provisions, true) ?: [];
            if (! $w->person_id) {
                continue;
            }
            $wills[] = ['person_id' => $w->person_id, 'residuary' => array_map(fn ($b) => ['tier' => $b['tier'] ?? 'primary', 'type' => $b['type'] ?? 'person',
                'id' => $b['id'] ?? null, 'label' => $b['label'] ?? '', 'share' => (string) ($b['share'] ?? '1')], $prov['residuary'] ?? []),
                'pour_over_trust_id' => $prov['pour_over_trust_id'] ?? null];
            foreach ((array) ($prov['specific_gifts'] ?? []) as $g) {
                foreach ($assets as &$as) {
                    if ($as['id'] === ($g['asset_id'] ?? null)) {
                        $as['will_specific_gift_to'] = $g['person_id'] ?? null;
                    }
                }
                unset($as);
            }
        }
        $debts = [];
        if ($this->ctx->canAny('debt')) {
            foreach ($this->ctx->constrain(DB::table('liabilities'), 'debt', 'read')->whereNull('archived_at')->where('status', 'active')->whereNull('collateral_id')->get() as $l) {
                $owner = DB::table('ownership_interests')->where('subject_type', 'liability')->where('subject_id', $l->id)->where('owner_type', 'person')->value('owner_id') ?? ($order[0] ?? null);
                if ($owner) {
                    $debts[$owner] = (string) (($debts[$owner] ?? 0) + (float) $l->current_balance);
                }
            }
        }
        $pack = DB::table('tax_rule_packs')->where('jurisdiction', 'US')->where('tax_year', '<=', $year)->orderByDesc('tax_year')->first();
        $exemption = $assumptions['exemption'] ?? ($year >= 2026 ? '15000000' : '13990000');

        return [
            'year' => $year, 'death_order' => $order, 'simultaneous' => (bool) ($assumptions['simultaneous'] ?? false),
            'persons' => $persons->map(fn ($p) => ['id' => $p->id, 'name' => $p->display_name, 'is_us_citizen' => (bool) $p->is_us_citizen, 'domicile' => 'NJ',
                'age' => $p->birth_date ? (int) floor((time() - strtotime($p->birth_date)) / (365.2425 * 86400)) : null])->all(),
            'relations' => $relations, 'assets' => $assets, 'trusts' => $trusts, 'wills' => $wills, 'debts' => $debts,
            'funeral' => (string) ($assumptions['funeral'] ?? '15000'), 'admin_pct' => (string) ($assumptions['admin_pct'] ?? '0.03'),
            'lifetime_taxable_gifts' => $assumptions['lifetime_taxable_gifts'] ?? new \stdClass, 'exemption' => (string) $exemption,
            'elect_portability' => (bool) ($assumptions['elect_portability'] ?? true), 'nj_inheritance' => true,
            '_scope_note' => $unvisible ? 'Some domains were outside your access and are excluded from this run.' : null,
            '_pack' => $pack?->version,
        ];
    }
}
