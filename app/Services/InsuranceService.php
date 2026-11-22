<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Coverage analysis, gap/duplicate/dependency flags, loss summaries and quote comparison. Never binds or sends. */
final class InsuranceService
{
    public function analysis(AccessContext $ctx): array
    {
        $policies = $ctx->constrain(DB::table('insurance_policies'), 'insurance', 'read')->whereNull('archived_at')->where('status', 'active')->get();
        $cov = DB::table('policy_coverages')->whereIn('policy_id', $policies->pluck('id'))->whereNull('archived_at')->get();
        $flags = [];
        $today = now()->toDateString();
        foreach ($policies as $p) {
            if ($p->term_end && $p->term_end < $today) {
                $flags[] = ['severity' => 'high', 'code' => 'lapsed', 'policy_id' => $p->id, 'message' => "{$p->name} term ended {$p->term_end}."];
            } elseif ($p->term_end && $p->term_end <= now()->addDays(45)->toDateString()) {
                $flags[] = ['severity' => 'normal', 'code' => 'renewal', 'policy_id' => $p->id, 'message' => "{$p->name} renews {$p->term_end}."];
            }
        }
        // Umbrella underlying minimums.
        foreach ($policies->where('kind', 'umbrella') as $u) {
            foreach (['auto' => 'required_underlying_auto', 'homeowners' => 'required_underlying_home'] as $kind => $field) {
                if ($u->{$field} === null) {
                    continue;
                }
                $under = $policies->where('kind', $kind);
                $liab = $cov->whereIn('policy_id', $under->pluck('id'))->filter(fn ($c) => str_contains(strtolower($c->coverage), 'liab'))->max(fn ($c) => (float) $c->limit_amount);
                if (! $under->count() || ($liab ?? 0) < (float) $u->{$field}) {
                    $flags[] = ['severity' => 'high', 'code' => 'umbrella_underlying', 'policy_id' => $u->id,
                        'message' => "{$u->name} requires {$kind} liability of at least $".number_format((float) $u->{$field}).'; recorded underlying limit is $'.number_format((float) ($liab ?? 0)).'.'];
                }
            }
        }
        // Uncovered assets / properties.
        if ($ctx->canAny('real_estate')) {
            foreach ($ctx->constrain(DB::table('properties'), 'real_estate', 'read')->whereNull('archived_at')->where('status', 'owned')->get() as $prop) {
                $has = $cov->where('subject_type', 'property')->where('subject_id', $prop->id)->count();
                if (! $has) {
                    $flags[] = ['severity' => 'high', 'code' => 'uncovered_property', 'subject_id' => $prop->id, 'message' => "{$prop->name} has no recorded property coverage."];
                }
                if (in_array($prop->kind, ['rental_str', 'rental_ltr'], true) && ! $policies->contains(fn ($p) => in_array($p->kind, ['business_owners', 'general_liability', 'umbrella'], true))) {
                    $flags[] = ['severity' => 'normal', 'code' => 'rental_liability', 'subject_id' => $prop->id, 'message' => "{$prop->name} is a rental; confirm landlord/short-term rental liability coverage."];
                }
            }
        }
        if ($ctx->canAny('assets')) {
            foreach ($ctx->constrain(DB::table('assets'), 'assets', 'read')->whereNull('archived_at')->where('status', 'owned')->whereIn('kind', ['jewelry', 'art', 'collectible', 'wine', 'vehicle', 'boat', 'aircraft'])->get() as $a) {
                if (! $cov->where('subject_type', 'asset')->where('subject_id', $a->id)->count()) {
                    $flags[] = ['severity' => 'normal', 'code' => 'unscheduled_asset', 'subject_id' => $a->id, 'message' => "{$a->name} is not scheduled on any policy."];
                }
                $val = DB::table('valuations')->where('subject_type', 'asset')->where('subject_id', $a->id)->whereIn('purpose', ['insurance', 'replacement'])->where('status', 'accepted')->max('as_of');
                if (! $val || $val < now()->subYears(3)->toDateString()) {
                    $flags[] = ['severity' => 'normal', 'code' => 'stale_appraisal', 'subject_id' => $a->id, 'message' => "{$a->name} has no replacement appraisal in the last three years."];
                }
            }
        }
        // Duplicate coverage (same subject + coverage across policies).
        foreach ($cov->whereNotNull('subject_id')->groupBy(fn ($c) => $c->subject_type.'|'.$c->subject_id.'|'.strtolower($c->coverage)) as $group) {
            if ($group->pluck('policy_id')->unique()->count() > 1) {
                $flags[] = ['severity' => 'info', 'code' => 'overlap', 'message' => 'Overlapping '.$group->first()->coverage.' coverage on the same item across policies; totals are not additive.'];
            }
        }
        $life = $policies->whereIn('kind', ['term_life', 'whole_life', 'universal_life', 'variable_life']);
        $premiums = $policies->sum(fn ($p) => (float) ($p->premium ?? 0) * (['annual' => 1, 'semiannual' => 2, 'quarterly' => 4, 'monthly' => 12, 'single' => 0][$p->premium_frequency] ?? 1));

        return ['policies' => $policies->count(), 'annual_premiums' => Dec::norm(round($premiums, 2)), 'life_death_benefit' => Dec::norm($life->sum(fn ($p) => (float) ($p->face_amount ?? 0))),
            'net_surrender_value' => Dec::norm(round($life->sum(fn ($p) => max(0, (float) ($p->cash_value ?? 0) - (float) ($p->loan_balance ?? 0) - (float) ($p->surrender_charge ?? 0))), 2)),
            'flags' => $flags, 'note' => 'Overlapping limits are never summed into an unconditional total.'];
    }

    /** Estimated recovery for a loss: deductible, sublimit, then excess from umbrella above the attachment point. */
    public function lossSummary(AccessContext $ctx, array $data): array
    {
        $loss = (float) Dec::norm($data['loss_amount'] ?? '0');
        $coverage = $ctx->constrain(DB::table('policy_coverages'), 'insurance', 'read')->where('id', $data['coverage_id'] ?? '')->first();
        if (! $coverage) {
            throw ApiError::notFound('Coverage not found.');
        }
        $policy = DB::table('insurance_policies')->find($coverage->policy_id);
        $unknown = [];
        $deductible = (float) ($coverage->deductible ?? $policy->deductible ?? 0);
        if ($coverage->deductible === null && $policy->deductible === null) {
            $unknown[] = 'deductible';
        }
        $limit = $coverage->sublimit !== null ? (float) $coverage->sublimit : ($coverage->limit_amount !== null ? (float) $coverage->limit_amount : null);
        if ($limit === null) {
            $unknown[] = 'limit';
        }
        $afterDeductible = max(0, $loss - $deductible);
        $primary = $limit === null ? $afterDeductible : min($afterDeductible, $limit);
        $excess = 0.0;
        $isLiability = str_contains(strtolower($coverage->coverage), 'liab');
        if ($isLiability && $limit !== null && $afterDeductible > $limit) {
            $umbrella = $ctx->constrain(DB::table('insurance_policies'), 'insurance', 'read')->where('kind', 'umbrella')->where('status', 'active')->first();
            if ($umbrella) {
                $uCov = DB::table('policy_coverages')->where('policy_id', $umbrella->id)->first();
                $attach = (float) ($uCov->attachment_point ?? $limit);
                $excess = min(max(0, $loss - max($attach, $limit + $deductible)), (float) ($uCov->limit_amount ?? $umbrella->face_amount ?? 0));
            }
        }
        $exclusions = json_decode((string) $coverage->exclusions, true) ?: [];

        return ['loss' => Dec::norm($loss), 'deductible' => Dec::norm($deductible), 'applicable_limit' => $limit === null ? null : Dec::norm($limit),
            'primary_recovery' => Dec::norm(round($primary, 2)), 'excess_recovery' => Dec::norm(round($excess, 2)),
            'uninsured' => Dec::norm(round(max(0, $loss - $primary - $excess), 2)), 'valuation_basis' => $coverage->valuation_basis,
            'exclusions_to_review' => $exclusions, 'unknown_terms' => $unknown,
            'note' => 'Estimate only; actual recovery depends on policy conditions, exclusions and adjuster determination.'];
    }

    public function compareQuotes(AccessContext $ctx, string $caseId): array
    {
        $case = $ctx->constrain(DB::table('insurance_shopping_cases'), 'insurance', 'read')->where('id', $caseId)->first();
        if (! $case) {
            throw ApiError::notFound();
        }
        $baseline = json_decode((string) $case->baseline, true) ?: [];
        $quotes = DB::table('insurance_quotes')->where('case_id', $case->id)->whereNull('archived_at')->orderBy('premium')->get();
        $keys = [];
        foreach ($quotes as $q) {
            $keys = array_unique(array_merge($keys, array_keys(json_decode((string) $q->limits, true) ?: []), array_keys(json_decode((string) $q->deductibles, true) ?: [])));
        }
        $rows = [];
        foreach ($quotes as $q) {
            $lim = json_decode((string) $q->limits, true) ?: [];
            $ded = json_decode((string) $q->deductibles, true) ?: [];
            $diffs = [];
            foreach (($baseline['limits'] ?? []) as $k => $v) {
                if (! isset($lim[$k])) {
                    $diffs[] = ['field' => "limit:{$k}", 'kind' => 'unknown', 'message' => "Quote does not state {$k}."];
                } elseif ((float) $lim[$k] < (float) $v) {
                    $diffs[] = ['field' => "limit:{$k}", 'kind' => 'lower', 'message' => "{$k} limit {$lim[$k]} is below the baseline {$v}."];
                }
            }
            foreach (($baseline['deductibles'] ?? []) as $k => $v) {
                if (isset($ded[$k]) && (float) $ded[$k] > (float) $v) {
                    $diffs[] = ['field' => "deductible:{$k}", 'kind' => 'higher', 'message' => "{$k} deductible {$ded[$k]} exceeds baseline {$v}."];
                }
            }
            $rows[] = ['quote_id' => $q->id, 'carrier' => $q->carrier, 'premium' => Dec::norm($q->premium), 'carrier_rating' => $q->carrier_rating,
                'limits' => $lim, 'deductibles' => $ded, 'endorsements' => json_decode((string) $q->endorsements, true) ?: [],
                'exclusions' => json_decode((string) $q->exclusions, true) ?: [], 'differences' => $diffs];
        }

        return ['case_id' => $case->id, 'baseline' => $baseline, 'normalized_fields' => array_values($keys), 'quotes' => $rows,
            'note' => 'Review differences and unknowns before choosing. Coverage is never bound from this comparison.'];
    }
}
