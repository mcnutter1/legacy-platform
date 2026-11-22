<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\Auth\StepUp;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxController extends ApiController
{
    public function run(Request $r, JobService $jobs, string $unit): JsonResponse
    {
        $ctx = $this->ctx();
        $u = $ctx->constrain(DB::table('tax_units'), 'tax', 'write', 'tax_year')->where('id', $unit)->whereNull('archived_at')->first();
        if (! $u) {
            throw ApiError::notFound();
        }
        $kind = in_array($r->input('kind'), ['return', 'forecast', 'strategy'], true) ? $r->input('kind') : 'return';
        $overrides = (array) $r->input('overrides', []);
        // Overrides are hypothetical: they mark the run as a scenario and never alter canonical facts.
        if ($overrides && $kind === 'return') {
            $kind = 'strategy';
        }
        $runId = (string) Str::ulid();
        DB::table('tax_runs')->insert(['id' => $runId, 'workspace_id' => $u->workspace_id, 'scope_id' => $u->scope_id, 'tax_unit_id' => $u->id, 'tax_year' => $u->tax_year,
            'scenario' => mb_substr((string) $r->input('scenario', $kind === 'return' ? 'baseline' : 'what-if'), 0, 64), 'kind' => $kind, 'status' => 'pending',
            'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        $job = $jobs->enqueue($ctx, 'tax.compute', ['tax_unit_id' => $u->id, 'run_id' => $runId, 'overrides' => $overrides + ($overrides ? ['assumptions' => ['Hypothetical what-if overrides applied']] : [])],
            'tax_run', $runId, $u->scope_id);
        DB::table('tax_runs')->where('id', $runId)->update(['job_id' => $job->id]);

        return response()->json(['data' => ['run_id' => $runId, 'job' => $job->present()]], 202);
    }

    public function show(string $run): JsonResponse
    {
        $r = $this->ctx()->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('id', $run)->first();
        if (! $r) {
            throw ApiError::notFound();
        }
        $dec = fn ($c) => json_decode((string) $c, true);

        return $this->ok(['id' => $r->id, 'tax_unit_id' => $r->tax_unit_id, 'tax_year' => $r->tax_year, 'scenario' => $r->scenario, 'kind' => $r->kind, 'status' => $r->status,
            'summary' => $dec($r->summary), 'lines' => $dec($r->lines), 'issues' => $dec($r->issues), 'rule_packs' => $dec($r->rule_packs), 'engine_version' => $r->engine_version,
            'input_hash' => $r->input_hash, 'reconciliation' => $dec($r->reconciliation), 'reviewer_name' => $r->reviewer_name, 'reviewed_at' => $r->reviewed_at,
            'immutable' => (bool) $r->immutable, 'created_at' => $r->created_at]);
    }

    public function trace(Request $r, string $run): JsonResponse
    {
        $row = $this->ctx()->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('id', $run)->first(['trace', 'input_manifest']);
        if (! $row) {
            throw ApiError::notFound();
        }
        $trace = json_decode((string) $row->trace, true) ?: [];
        if ($node = $r->query('node')) {
            $byNode = collect($trace)->keyBy('node');
            $out = [];
            $queue = [(string) $node];
            while ($queue && count($out) < 60) {
                $n = array_shift($queue);
                if (isset($out[$n]) || ! $byNode->has($n)) {
                    continue;
                }
                $out[$n] = $byNode[$n];
                foreach (array_keys($byNode[$n]['inputs'] ?? []) as $i) {
                    $queue[] = $i;
                }
            }
            $trace = array_values($out);
        }

        return $this->ok(['trace' => $trace, 'inputs' => $r->boolean('with_inputs') ? json_decode((string) $row->input_manifest, true) : null]);
    }

    /** Named professional sign-off makes the run immutable. */
    public function review(Request $r, string $run): JsonResponse
    {
        $this->privileged();
        $ctx = $this->ctx();
        $row = $ctx->constrain(DB::table('tax_runs'), 'tax', 'approve', 'tax_year')->where('id', $run)->first();
        if (! $row || ! in_array($row->status, ['calculated', 'reconciled', 'estimate'], true)) {
            throw ApiError::conflict('Only completed runs can be reviewed.');
        }
        $name = trim((string) $r->input('reviewer_name'));
        if (mb_strlen($name) < 3) {
            throw new ApiError('validation_failed', 'Enter the reviewer\'s full name.');
        }
        DB::table('tax_runs')->where('id', $row->id)->update(['status' => $row->status === 'estimate' ? 'estimate' : 'cpa_reviewed', 'reviewer_name' => $name,
            'reviewed_by' => $ctx->user->id, 'reviewed_at' => now(), 'immutable' => true, 'updated_at' => now()]);
        Audit::log('tax.run_reviewed', 'tax_run', $row->id, ['reviewer' => $name, 'status' => $row->status], scopeId: $row->scope_id);

        return $this->ok(['status' => $row->status === 'estimate' ? 'estimate' : 'cpa_reviewed', 'immutable' => true]);
    }

    /** Compare a run with the signed filed return; neither result is overwritten. */
    public function reconcile(Request $r, string $run): JsonResponse
    {
        $ctx = $this->ctx();
        $row = $ctx->constrain(DB::table('tax_runs'), 'tax', 'write', 'tax_year')->where('id', $run)->first();
        if (! $row) {
            throw ApiError::notFound();
        }
        $filed = (array) $r->input('filed_lines', []);
        $lines = collect(json_decode((string) $row->lines, true) ?: [])->keyBy(fn ($l) => $l['jurisdiction'].'|'.$l['form'].'|'.$l['line']);
        $out = [];
        $allMatch = true;
        foreach ($filed as $key => $value) {
            $calc = $lines[$key]['value'] ?? null;
            $diff = $calc === null ? null : round((float) $value - (float) $calc, 2);
            $match = $diff !== null && abs($diff) <= 1.0;
            $allMatch = $allMatch && $match;
            $out[] = ['line' => $key, 'filed' => (string) $value, 'calculated' => $calc, 'difference' => $diff, 'match' => $match];
        }
        $status = $row->status;
        if ($out && $allMatch && $row->status === 'calculated') {
            $status = 'reconciled';
        }
        DB::table('tax_runs')->where('id', $row->id)->update(['reconciliation' => json_encode(['compared_at' => now()->toIso8601String(), 'lines' => $out, 'all_match' => $allMatch]),
            'status' => $row->immutable ? $row->status : $status, 'updated_at' => now()]);
        Audit::log('tax.run_reconciled', 'tax_run', $row->id, ['all_match' => $allMatch], scopeId: $row->scope_id);

        return $this->ok(['lines' => $out, 'all_match' => $allMatch, 'status' => $status]);
    }

    public function compare(Request $r): JsonResponse
    {
        $ids = array_slice((array) $r->query('runs', []), 0, 4);
        $runs = $this->ctx()->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->whereIn('id', $ids)->get();
        $all = [];
        foreach ($runs as $run) {
            foreach (json_decode((string) $run->lines, true) ?: [] as $l) {
                $k = $l['jurisdiction'].'|'.$l['form'].'|'.$l['line'];
                $all[$k] ??= ['key' => $k, 'label' => $l['label'], 'values' => []];
                $all[$k]['values'][$run->id] = $l['value'];
            }
        }
        $diff = array_values(array_filter($all, fn ($x) => count(array_unique($x['values'])) > 1 || count($x['values']) < $runs->count()));

        return $this->ok(['runs' => $runs->map(fn ($x) => ['id' => $x->id, 'scenario' => $x->scenario, 'status' => $x->status, 'tax_year' => $x->tax_year]), 'differences' => $diff]);
    }

    public function strategies(Request $r, JobService $jobs, string $unit): JsonResponse
    {
        $u = $this->ctx()->constrain(DB::table('tax_units'), 'tax', 'write', 'tax_year')->where('id', $unit)->first();
        if (! $u) {
            throw ApiError::notFound();
        }

        return $this->accepted($jobs->enqueue($this->ctx(), 'tax.strategies', ['tax_unit_id' => $u->id, 'rank_by' => $r->input('rank_by'),
            'hdhp_covered' => $r->boolean('hdhp_covered'), 'planned_business_purchase' => (string) $r->input('planned_business_purchase', '0')], 'tax_unit', $u->id, $u->scope_id)->present());
    }

    /** Safe-harbor estimated payment plan from the latest forecast. Persists planned installments on request. */
    public function estimates(Request $r, string $unit): JsonResponse
    {
        $ctx = $this->ctx();
        $u = $ctx->constrain(DB::table('tax_units'), 'tax', 'read', 'tax_year')->where('id', $unit)->first();
        if (! $u) {
            throw ApiError::notFound();
        }
        $run = DB::table('tax_runs')->where('tax_unit_id', $u->id)->whereIn('status', ['estimate', 'calculated', 'reconciled', 'cpa_reviewed'])->orderByDesc('created_at')->first();
        if (! $run) {
            throw new ApiError('not_ready', 'Run a tax forecast first.');
        }
        $s = json_decode((string) $run->summary, true);
        $dates = ['US' => [($u->tax_year).'-04-15', ($u->tax_year).'-06-15', ($u->tax_year).'-09-15', ($u->tax_year + 1).'-01-15']];
        $dates['NJ'] = $dates['US'];
        $plan = [];
        foreach (['US' => 'federal', 'NJ' => 'nj'] as $j => $k) {
            if (! isset($s[$k])) {
                continue;
            }
            $required = (float) ($s[$k]['required_annual_payment'] ?? 0);
            $withheld = (float) ($s[$k]['withholding'] ?? 0);
            $paid = (float) DB::table('tax_payments')->where('tax_unit_id', $u->id)->where('jurisdiction', $j)->where('kind', 'estimated')->where('status', 'paid')->sum('amount');
            $remaining = max(0, $required - $withheld - $paid);
            $future = array_values(array_filter($dates[$j], fn ($d) => $d >= now()->toDateString()));
            foreach ($future as $i => $d) {
                $plan[] = ['jurisdiction' => $j, 'due_on' => $d, 'installment' => array_search($d, $dates[$j], true) + 1,
                    'amount' => number_format($future ? $remaining / count($future) : 0, 2, '.', '')];
            }
        }
        if ($r->isMethod('post')) {
            $this->privileged();
            foreach ($plan as $p) {
                $key = ['tax_unit_id' => $u->id, 'jurisdiction' => $p['jurisdiction'], 'kind' => 'estimated', 'installment' => $p['installment'], 'status' => 'planned'];
                $vals = ['due_on' => $p['due_on'], 'amount' => $p['amount'], 'source' => 'derived', 'source_ref' => $run->id, 'value_state' => 'projected', 'updated_at' => now()];
                if (DB::table('tax_payments')->where($key)->exists()) {
                    DB::table('tax_payments')->where($key)->update($vals);
                } else {
                    DB::table('tax_payments')->insert($key + $vals + ['id' => (string) Str::ulid(), 'workspace_id' => $u->workspace_id, 'scope_id' => $u->scope_id,
                        'created_at' => now(), 'edit_version' => 1]);
                }
            }
        }

        return $this->ok(['run_id' => $run->id, 'run_status' => $run->status, 'plan' => $plan,
            'note' => 'Installments meet the lower of 90% current-year or 100/110% prior-year tax (federal) and NJ safe harbors, net of projected withholding.']);
    }

    public function packs(): JsonResponse
    {
        return $this->ok(DB::table('tax_rule_packs')->orderByDesc('tax_year')->orderBy('jurisdiction')->get()->map(fn ($p) => ['summary' => json_decode((string) $p->summary, true)] + (array) $p));
    }

    /** Named reviewer approval of a rule pack release (enables "calculated" status). Platform administrators only. */
    public function approvePack(Request $r, string $pack): JsonResponse
    {
        $this->privileged();
        $ctx = $this->ctx();
        if (! $ctx->isAdministrator() || ! $ctx->canAny('tax', 'approve')) {
            throw ApiError::forbidden('Rule pack approval requires an administrator with tax approval rights.');
        }
        $name = trim((string) $r->input('reviewer_name'));
        $note = trim((string) $r->input('note'));
        if (mb_strlen($name) < 3 || mb_strlen($note) < 10) {
            throw new ApiError('validation_failed', 'Record the reviewer name and what was verified (parity fixtures, form lines).');
        }
        $p = DB::table('tax_rule_packs')->find($pack);
        if (! $p) {
            throw ApiError::notFound();
        }
        DB::table('tax_rule_packs')->where('jurisdiction', $p->jurisdiction)->where('tax_year', $p->tax_year)->where('status', 'approved')->update(['status' => 'superseded']);
        DB::table('tax_rule_packs')->where('id', $p->id)->update(['status' => 'approved', 'reviewer_name' => $name, 'approval_note' => $note, 'approved_by' => $ctx->user->id, 'approved_at' => now()]);
        Audit::log('tax.pack_approved', 'tax_rule_pack', $p->id, ['jurisdiction' => $p->jurisdiction, 'year' => $p->tax_year, 'version' => $p->version, 'hash' => substr($p->content_hash, 0, 16)]);

        return $this->ok(['approved' => true]);
    }

    public function decideStrategy(Request $r, string $strategy): JsonResponse
    {
        $ctx = $this->ctx();
        $s = $ctx->constrain(DB::table('tax_strategies'), 'tax', 'write', 'tax_year')->where('id', $strategy)->first();
        if (! $s) {
            throw ApiError::notFound();
        }
        $status = (string) $r->input('status');
        if (! in_array($status, ['reviewing', 'accepted', 'declined', 'implemented'], true)) {
            throw new ApiError('validation_failed', 'Invalid decision.');
        }
        DB::table('tax_strategies')->where('id', $s->id)->update(['status' => $status, 'professional_feedback' => $r->input('professional_feedback', $s->professional_feedback),
            'updated_by' => $ctx->user->id, 'updated_at' => now(), 'edit_version' => $s->edit_version + 1]);
        Audit::log('tax.strategy_decided', 'tax_strategy', $s->id, ['status' => $status], scopeId: $s->scope_id);

        return $this->ok(['status' => $status]);
    }
}
