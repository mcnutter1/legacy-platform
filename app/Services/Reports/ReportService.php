<?php

namespace App\Services\Reports;

use App\Jobs\JobService;
use App\Services\NetWorthService;
use App\Services\VaultService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Domains;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/** Report packages: CSV tables (formula-escaped), JSON manifest and a PDF summary, stored in the vault. */
final class ReportService
{
    public const KINDS = ['net_worth', 'cpa_package', 'insurer_package', 'emergency_binder', 'counsel_binder', 'wealth_bridge', 'retirement_report', 'beneficiary_report'];
    public const REDACTIONS = ['account_numbers', 'identity', 'addresses', 'beneficiaries', 'tax'];

    public function request(AccessContext $ctx, string $kind, array $params): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new ApiError('validation_failed', 'Unknown report type.');
        }
        $scopeId = (string) ($params['scope_id'] ?? '');
        if (! $ctx->can('reports', 'write', $scopeId)) {
            throw ApiError::forbidden('You cannot create reports in that scope.');
        }
        foreach (Domains::REPORT_DEPENDENCIES[$kind] ?? [] as $d) {
            if (! $ctx->canAny($d)) {
                throw ApiError::forbidden('This report depends on '.Domains::LABELS[$d].' data you cannot access.');
            }
        }
        $redactions = array_values(array_intersect((array) ($params['redactions'] ?? ($kind === 'insurer_package' ? ['tax', 'beneficiaries', 'identity', 'account_numbers'] : ['account_numbers'])), self::REDACTIONS));
        $id = (string) Str::ulid();
        DB::table('report_manifests')->insert([
            'id' => $id, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $scopeId, 'kind' => $kind,
            'title' => mb_substr((string) ($params['title'] ?? ucwords(str_replace('_', ' ', $kind)).' — '.now()->toDateString()), 0, 255),
            'params' => json_encode(array_diff_key($params, ['scope_id' => 1, 'redactions' => 1])), 'as_of' => $params['as_of'] ?? now()->toDateString(),
            'currency' => $ctx->workspace->base_currency, 'redactions' => json_encode($redactions), 'status' => 'pending',
            'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        $job = app(JobService::class)->enqueue($ctx, 'report.build', ['manifest_id' => $id], 'report_manifest', $id, $scopeId);
        DB::table('report_manifests')->where('id', $id)->update(['job_id' => $job->id]);
        Audit::log('report.requested', 'report_manifest', $id, ['kind' => $kind, 'redactions' => $redactions], scopeId: $scopeId);

        return ['manifest_id' => $id, 'job' => $job->present()];
    }

    /** Build the package for a manifest (runs in the worker with the initiator's re-checked context). */
    public function build(AccessContext $ctx, string $manifestId): array
    {
        $m = DB::table('report_manifests')->find($manifestId);
        $params = json_decode((string) $m->params, true) ?: [];
        $red = json_decode((string) $m->redactions, true) ?: [];
        $tables = $this->tables($ctx, $m->kind, $params, $m->as_of, $red);
        $manifest = [
            'report_id' => $m->id, 'kind' => $m->kind, 'title' => $m->title, 'workspace' => $ctx->workspace->name, 'as_of' => $m->as_of,
            'currency' => $m->currency, 'generated_at' => now()->toIso8601String(), 'generated_by' => $ctx->user->name, 'redactions' => $red,
            'source_scope' => ['scope_ids' => array_values(array_unique(array_merge(...array_map(fn ($d) => $ctx->scopeIds($d), Domains::REPORT_DEPENDENCIES[$m->kind] ?? ['reports']))))],
            'run_ids' => $tables['_runs'] ?? [], 'tables' => [], 'methodology' => $tables['_methodology'] ?? null, 'engine_release' => config('legacy.release'),
        ];
        unset($tables['_runs'], $tables['_methodology']);
        $zipPath = tempnam(sys_get_temp_dir(), 'rpt');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        foreach ($tables as $name => $rows) {
            $csv = $this->csv($rows);
            $zip->addFromString($name.'.csv', $csv);
            $manifest['tables'][] = ['name' => $name, 'rows' => count($rows), 'sha256' => hash('sha256', $csv)];
        }
        $html = view('reports.summary', ['manifest' => $manifest, 'tables' => $tables])->render();
        $zip->addFromString('summary.html', $html);
        $zip->addFromString('summary.pdf', $this->pdf($html));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        $bytes = file_get_contents($zipPath);
        @unlink($zipPath);
        $stored = app(VaultService::class)->store($ctx, $bytes, ['scope_id' => $m->scope_id, 'filename' => Str::slug($m->title).'.zip', 'title' => $m->title,
            'doc_type' => 'report', 'origin' => 'generated', 'allow_duplicate' => true]);
        // Generated by the application itself: no external content, so it skips quarantine.
        DB::table('documents')->where('id', $stored['document_id'])->update(['status' => 'ready']);
        DB::table('document_versions')->where('id', $stored['version_id'])->update(['scan_status' => 'clean', 'scan_engine' => 'generated']);
        DB::table('report_manifests')->where('id', $m->id)->update(['status' => 'ready', 'document_id' => $stored['document_id'], 'sha256' => $stored['sha256'],
            'run_ids' => json_encode($manifest['run_ids']), 'source_scope' => json_encode($manifest['source_scope']), 'updated_at' => now()]);

        return ['manifest_id' => $m->id, 'document_id' => $stored['document_id'], 'tables' => count($manifest['tables'])];
    }

    public function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        if ($rows) {
            fputcsv($fh, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($fh, array_map([$this, 'cell'], array_values($r)), ',', '"', '');
            }
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Neutralize spreadsheet formula injection while keeping numeric cells numeric. */
    public function cell(mixed $v): string
    {
        if (is_array($v)) {
            $v = json_encode($v);
        }
        $s = (string) ($v ?? '');
        if ($s !== '' && ! preg_match('/^-?\d+(\.\d+)?$/', $s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$s;
        }

        return $s;
    }

    private function pdf(string $html): string
    {
        $opts = new Options;
        $opts->set('isRemoteEnabled', false);
        $opts->set('isPhpEnabled', false);
        $opts->set('chroot', resource_path('views/reports'));
        $d = new Dompdf($opts);
        $d->loadHtml($html);
        $d->setPaper('letter');
        $d->render();

        return (string) $d->output();
    }

    private function mask(?string $v, array $red, string $kind): ?string
    {
        return in_array($kind, $red, true) && $v !== null ? '•••' : $v;
    }

    private function tables(AccessContext $ctx, string $kind, array $p, string $asOf, array $red): array
    {
        $year = (int) ($p['year'] ?? substr($asOf, 0, 4));
        switch ($kind) {
            case 'net_worth':
                $nw = app(NetWorthService::class)->compute($ctx, $asOf, $p['person_id'] ?? null);
                $rows = [];
                foreach ($nw['categories'] as $c) {
                    foreach ($c['items'] as $i) {
                        $rows[] = ['section' => $i['section'], 'category' => $i['category'], 'item' => $i['label'], 'value' => $i['value'], 'ownership_share' => $i['share'],
                            'method' => $i['method'], 'as_of' => $i['as_of']];
                    }
                }

                return ['net_worth' => $rows, 'summary' => [['total_assets' => $nw['total_assets'], 'total_liabilities' => $nw['total_liabilities'], 'net_worth' => $nw['net_worth'],
                    'liquid_assets' => $nw['liquid_assets'], 'unvalued_items' => count($nw['unvalued'])]], 'unvalued' => $nw['unvalued'], '_methodology' => $nw['methodology']];

            case 'cpa_package':
                $unitId = (string) ($p['tax_unit_id'] ?? '');
                $run = $ctx->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('tax_unit_id', $unitId)->where('tax_year', $year)->where('kind', 'return')
                    ->whereNotIn('status', ['pending', 'failed'])->orderByDesc('created_at')->first();
                $lines = $run ? array_map(fn ($l) => ['jurisdiction' => $l['jurisdiction'], 'form' => $l['form'], 'line' => $l['line'], 'label' => $l['label'], 'amount' => $l['value'], 'rule' => $l['rule']], json_decode((string) $run->lines, true) ?: []) : [];
                $payroll = $ctx->constrain(DB::table('payroll_snapshots'), 'compensation', 'read')->where('status', 'approved')->whereBetween('pay_date', ["{$year}-01-01", "{$year}-12-31"])
                    ->orderBy('pay_date')->get()->map(fn ($s) => ['pay_date' => $s->pay_date, 'employment_id' => $s->employment_id, 'run_kind' => $s->run_kind, 'gross' => $s->gross, 'net' => $s->net] + (json_decode((string) $s->current, true) ?: []))->all();
                $stays = $ctx->constrain(DB::table('property_stays'), 'real_estate', 'read')->whereNull('archived_at')->whereBetween('check_in', ["{$year}-01-01", "{$year}-12-31"])
                    ->orderBy('check_in')->get(['property_id', 'check_in', 'check_out', 'kind', 'gross_rent', 'cleaning_fee', 'platform_fee', 'payout', 'cancelled'])->map(fn ($r) => (array) $r)->all();
                $uselog = $ctx->constrain(DB::table('property_use_logs'), 'real_estate', 'read')->whereNull('archived_at')->whereBetween('log_date', ["{$year}-01-01", "{$year}-12-31"])
                    ->orderBy('log_date')->get(['property_id', 'log_date', 'kind', 'hours', 'participant_person_id', 'activity'])->map(fn ($r) => (array) $r)->all();
                $deductions = $ctx->constrain(DB::table('transactions'), 'ledger', 'read')->whereNull('archived_at')->where('tax_candidate', true)->whereBetween('posted_on', ["{$year}-01-01", "{$year}-12-31"])
                    ->orderBy('posted_on')->get(['posted_on', 'description', 'amount', 'tax_category', 'business_purpose', 'entity_id'])->map(fn ($r) => (array) $r)->all();
                $payments = $ctx->constrain(DB::table('tax_payments'), 'tax', 'read')->where('tax_unit_id', $unitId)->whereNull('archived_at')
                    ->get(['jurisdiction', 'kind', 'installment', 'due_on', 'paid_on', 'amount', 'status'])->map(fn ($r) => (array) $r)->all();
                $equity = $ctx->constrain(DB::table('equity_events'), 'compensation', 'read')->whereNull('archived_at')->whereBetween('event_date', ["{$year}-01-01", "{$year}-12-31"])
                    ->get(['grant_id', 'kind', 'event_date', 'units', 'fmv', 'price', 'ordinary_income', 'tax_withheld', 'proceeds', 'reconciliation'])->map(fn ($r) => (array) $r)->all();
                $docs = $ctx->constrain(DB::table('tax_documents'), 'tax', 'read', 'tax_year')->where('tax_year', $year)->whereNull('archived_at')
                    ->get(['form', 'payer', 'status', 'corrected'])->map(fn ($r) => (array) $r)->all();
                $issues = $run ? array_map(fn ($i) => ['severity' => $i['severity'] ?? '', 'code' => $i['code'] ?? '', 'message' => $i['message'] ?? ''], json_decode((string) $run->issues, true) ?: []) : [];

                return ['return_line_crosswalk' => $lines, 'payroll' => $payroll, 'equity_events' => $equity, 'rental_stays' => $stays, 'rental_use_log' => $uselog,
                    'deduction_candidates' => $deductions, 'tax_payments' => $payments, 'tax_documents' => $docs, 'open_issues' => $issues,
                    '_runs' => $run ? ['tax_run' => $run->id, 'status' => $run->status, 'input_hash' => $run->input_hash] : [],
                    '_methodology' => $run ? 'Tax run '.$run->id.' ('.$run->status.'), engine '.$run->engine_version.'. Lines trace to rule IDs and source facts in the application.' : 'No completed tax run for this year.'];

            case 'insurer_package':
                $pol = $ctx->constrain(DB::table('insurance_policies'), 'insurance', 'read')->whereNull('archived_at')->where('status', 'active')->get();
                $cov = DB::table('policy_coverages')->whereIn('policy_id', $pol->pluck('id'))->whereNull('archived_at')->get();
                $assets = $ctx->canAny('assets') ? $ctx->constrain(DB::table('assets'), 'assets', 'read')->whereNull('archived_at')->where('status', 'owned')->get() : collect();
                $props = $ctx->canAny('real_estate') ? $ctx->constrain(DB::table('properties'), 'real_estate', 'read')->whereNull('archived_at')->where('status', 'owned')->get() : collect();
                $nw = app(NetWorthService::class);

                return [
                    'policies' => $pol->map(fn ($x) => ['policy' => $x->name, 'kind' => $x->kind, 'carrier' => $x->carrier, 'term_start' => $x->term_start, 'term_end' => $x->term_end,
                        'premium' => $x->premium, 'deductible' => $x->deductible, 'policy_number' => $this->mask($x->policy_number_last4, $red, 'account_numbers')])->all(),
                    'coverages' => $cov->map(fn ($c) => ['policy_id' => $c->policy_id, 'coverage' => $c->coverage, 'limit' => $c->limit_amount, 'sublimit' => $c->sublimit,
                        'deductible' => $c->deductible, 'valuation' => $c->valuation_basis, 'subject' => $c->subject_type])->all(),
                    'scheduled_items' => $assets->map(fn ($a) => ['item' => $a->name, 'kind' => $a->kind, 'serial' => $this->mask($a->serial_number, $red, 'identity'),
                        'replacement_value' => $nw->valuation('asset', $a->id, $asOf, 'replacement')[0] ?? $nw->valuation('asset', $a->id, $asOf)[0], 'appraisal_date' => $nw->valuation('asset', $a->id, $asOf, 'insurance')[2]])->all(),
                    'properties' => $props->map(fn ($p) => ['property' => $p->name, 'kind' => $p->kind, 'city' => $p->city, 'state' => $p->state,
                        'address' => $this->mask($p->address_line, $red, 'addresses'), 'replacement_value' => $nw->valuation('property', $p->id, $asOf, 'replacement')[0]])->all(),
                    '_methodology' => 'Prepared for insurance review. Tax, beneficiary and identity data are excluded by default.',
                ];

            case 'emergency_binder':
                $binder = $ctx->constrain(DB::table('emergency_binders'), 'governance', 'read')->where('id', $p['binder_id'] ?? '')->first();
                if (! $binder) {
                    throw ApiError::notFound('Binder not found.');
                }
                $c = json_decode((string) $binder->contents, true) ?: [];
                $contacts = $ctx->constrain(DB::table('contacts'), 'governance', 'read')->whereIn('id', (array) ($c['contact_ids'] ?? []))->get(['name', 'kind', 'organization', 'email', 'phone'])->map(fn ($r) => (array) $r)->all();
                $policies = $ctx->canAny('insurance') ? $ctx->constrain(DB::table('insurance_policies'), 'insurance', 'read')->whereIn('id', (array) ($c['policy_ids'] ?? []))
                    ->get(['name', 'kind', 'carrier', 'agent', 'term_end', 'face_amount'])->map(fn ($r) => (array) $r)->all() : [];
                $accounts = $ctx->canAny('ledger') ? $ctx->constrain(DB::table('accounts'), 'ledger', 'read')->whereIn('id', (array) ($c['account_ids'] ?? []))
                    ->get(['name', 'institution', 'kind', 'mask'])->map(fn ($r) => ['name' => $r->name, 'institution' => $r->institution, 'kind' => $r->kind, 'last4' => $this->mask($r->mask, $red, 'account_numbers')])->all() : [];
                $estate = $ctx->canAny('estate') ? $ctx->constrain(DB::table('estate_documents'), 'estate', 'read')->whereIn('id', (array) ($c['estate_document_ids'] ?? []))
                    ->get(['title', 'kind', 'executed_on', 'original_location'])->map(fn ($r) => (array) $r)->all() : [];
                $docs = $ctx->constrain(DB::table('documents'), 'vault', 'read')->whereIn('id', (array) ($c['document_ids'] ?? []))->get(['title', 'doc_type', 'original_location'])->map(fn ($r) => (array) $r)->all();

                return ['instructions' => [['instructions' => (string) $binder->instructions]], 'contacts' => $contacts, 'policies' => $policies, 'accounts' => $accounts,
                    'estate_documents' => $estate, 'document_locations' => $docs, '_methodology' => 'Emergency binder "'.$binder->name.'". No balances or full account numbers are included.'];

            case 'counsel_binder':
                $entities = $ctx->constrain(DB::table('legal_entities'), 'governance', 'read')->whereNull('archived_at')->get(['id', 'name', 'kind', 'jurisdiction', 'formed_on', 'tax_classification', 'status'])->map(fn ($r) => (array) $r)->all();
                $estateDocs = $ctx->constrain(DB::table('estate_documents'), 'estate', 'read')->whereNull('archived_at')->get(['title', 'kind', 'person_id', 'executed_on', 'review_due_on', 'original_location', 'witness_status', 'notary_status', 'status'])->map(fn ($r) => (array) $r)->all();
                $bens = in_array('beneficiaries', $red, true) ? [] : $ctx->constrain(DB::table('beneficiary_designations'), 'estate', 'read')->whereNull('archived_at')
                    ->get(['subject_type', 'subject_id', 'beneficiary_type', 'beneficiary_id', 'beneficiary_label', 'tier', 'share_fraction', 'per_stirpes', 'verified_on'])->map(fn ($r) => (array) $r)->all();
                $title = $ctx->constrain(DB::table('ownership_interests'), 'household', 'read')->whereNull('archived_at')->where('basis', 'legal_title')
                    ->get(['owner_type', 'owner_id', 'subject_type', 'subject_id', 'fraction', 'title_form', 'capacity', 'effective_from'])->map(fn ($r) => (array) $r)->all();
                $run = $ctx->constrain(DB::table('estate_runs'), 'estate', 'read')->where('status', 'completed')->orderByDesc('created_at')->first();
                $unresolved = $run ? array_map(fn ($u) => ['code' => $u['code'] ?? '', 'message' => $u['message'] ?? ''], json_decode((string) $run->unresolved, true) ?: []) : [];

                return ['entities' => $entities, 'estate_documents' => $estateDocs, 'beneficiary_designations' => $bens, 'title_and_ownership' => $title, 'unresolved_items' => $unresolved,
                    '_runs' => $run ? ['estate_run' => $run->id] : [], '_methodology' => 'Workpapers for counsel. The application does not determine enforceability or construe documents.'];

            case 'wealth_bridge':
                $snap = $ctx->constrain(DB::table('annual_snapshots'), 'ledger', 'read')->where('year', $year)->whereIn('status', ['closed', 'draft'])->orderByDesc('version')->first();
                if (! $snap) {
                    throw new ApiError('not_ready', "Build the {$year} year-close snapshot first.");
                }
                $bridge = json_decode((string) $snap->bridge, true) ?: [];
                $data = json_decode((string) $snap->data, true) ?: [];

                return ['bridge' => array_map(fn ($k, $v) => ['component' => $k, 'amount' => $v], array_keys($bridge), $bridge),
                    'income' => array_map(fn ($k, $v) => ['category' => $k, 'amount' => $v], array_keys($data['income'] ?? []), $data['income'] ?? []),
                    'summary' => [array_diff_key($data, ['income' => 1, 'by_category' => 1])], '_runs' => ['annual_snapshot' => $snap->id, 'version' => $snap->version, 'status' => $snap->status]];

            case 'retirement_report':
                $sc = $ctx->constrain(DB::table('plan_scenarios'), 'planning', 'read')->where('id', $p['scenario_id'] ?? '')->first();
                if (! $sc) {
                    throw ApiError::notFound('Scenario not found.');
                }
                $runs = DB::table('plan_runs')->where('scenario_id', $sc->id)->where('status', 'completed')->orderByDesc('created_at')->get()->unique('kind');
                $det = $runs->firstWhere('kind', 'deterministic');
                $years = $det ? array_map(fn ($y) => ['year' => $y['year'], 'income' => round(($y['wages'] ?? 0) + ($y['social_security'] ?? 0), 2), 'spending' => round(($y['essential_spending'] ?? 0) + ($y['discretionary_spending'] ?? 0) + ($y['healthcare'] ?? 0), 2),
                    'taxes' => $y['total_tax'] ?? null, 'withdrawals' => round(array_sum($y['withdrawals'] ?? []), 2), 'ending_liquid' => $y['ending_liquid'] ?? null, 'net_worth' => $y['net_worth'] ?? null,
                    'shortfall' => $y['shortfall'] ?? null], json_decode((string) $det->years, true) ?: []) : [];
                $mc = $runs->firstWhere('kind', 'montecarlo');
                $mcs = $mc ? (json_decode((string) $mc->summary, true) ?: []) : [];

                return ['projection' => $years, 'monte_carlo' => $mc ? [['probability_success' => $mcs['probability_success'] ?? null,
                    'probability_essential_covered' => $mcs['probability_essential_covered'] ?? null, 'paths' => $mc->paths, 'seed' => $mc->seed]] : [],
                    '_runs' => $runs->pluck('id', 'kind')->all(), '_methodology' => 'Projection results are conditional on the scenario assumptions recorded in each run manifest.'];

            case 'beneficiary_report':
                $rows = $ctx->constrain(DB::table('beneficiary_designations'), 'estate', 'read')->whereNull('archived_at')->get();

                return ['designations' => $rows->map(fn ($b) => ['subject_type' => $b->subject_type, 'subject_id' => $b->subject_id, 'beneficiary' => $b->beneficiary_label ?: $b->beneficiary_id,
                    'type' => $b->beneficiary_type, 'tier' => $b->tier, 'share' => $b->share_fraction, 'per_stirpes' => $b->per_stirpes ? 'yes' : 'no',
                    'verified_on' => $b->verified_on, 'stale' => ! $b->verified_on || $b->verified_on < now()->subYears(3)->toDateString() ? 'review' : ''])->all()];
        }

        return [];
    }
}
