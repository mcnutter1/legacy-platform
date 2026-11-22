<?php

namespace App\Services;

use App\Resources\Registry;
use App\Resources\ResourceService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Home dashboard, permission-trimmed search and typed record links. */
final class InsightService
{
    public function __construct(private readonly AccessContext $ctx) {}

    public function home(): array
    {
        $ctx = $this->ctx;
        $nw = app(NetWorthService::class)->compute($ctx);
        $today = now()->toDateString();
        $due = [];
        foreach ([['tax_payments', 'tax', 'due_on', 'Estimated tax due', 'planned'], ['legal_obligations', 'governance', 'due_on', 'Filing due', 'open'],
            ['tasks', null, 'due_on', 'Task', 'open']] as [$table, $domain, $col, $label, $status]) {
            $q = DB::table($table)->whereNull('archived_at')->where('status', $status)->whereBetween($col, [$today, now()->addDays(45)->toDateString()]);
            if ($domain) {
                $ctx->constrain($q, $domain);
            } else {
                $def = Registry::get('tasks');
                $q = (new ResourceService($ctx))->query($def)->whereNull('archived_at')->where('status', 'open')->whereBetween('due_on', [$today, now()->addDays(45)->toDateString()]);
            }
            foreach ($q->limit(20)->get() as $r) {
                $due[] = ['kind' => $table, 'label' => $label.($r->title ?? '' ? ': '.$r->title : ($table === 'tax_payments' ? ' ('.$r->jurisdiction.')' : '')),
                    'due_on' => $r->{$col}, 'amount' => $table === 'tax_payments' ? Dec::norm($r->amount) : null, 'id' => $r->id];
            }
        }
        if ($ctx->canAny('insurance')) {
            foreach ($ctx->constrain(DB::table('insurance_policies'), 'insurance')->whereNull('archived_at')->where('status', 'active')->whereBetween('term_end', [$today, now()->addDays(45)->toDateString()])->get() as $p) {
                $due[] = ['kind' => 'policy_renewal', 'label' => "Renewal: {$p->name}", 'due_on' => $p->term_end, 'amount' => null, 'id' => $p->id];
            }
        }
        usort($due, fn ($a, $b) => strcmp($a['due_on'], $b['due_on']));
        $taxReserve = null;
        if ($ctx->canAny('tax')) {
            $run = $ctx->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('tax_year', now()->year)->whereNotIn('status', ['pending', 'failed'])->orderByDesc('created_at')->first();
            if ($run) {
                $s = json_decode((string) $run->summary, true) ?: [];
                $taxReserve = ['year' => (int) $run->tax_year, 'status' => $run->status, 'balance_due' => $s['combined']['balance_due'] ?? null,
                    'refund' => $s['combined']['refund'] ?? null, 'total_tax' => $s['combined']['total_tax'] ?? null, 'run_id' => $run->id, 'as_of' => substr((string) $run->created_at, 0, 10)];
            }
        }
        $review = (new ResourceService($ctx))->query(Registry::get('review_items'))->where('status', 'open')->count();
        $recent = [];
        foreach (['transactions' => 'ledger', 'documents' => 'vault', 'payroll_snapshots' => 'compensation', 'tax_runs' => 'tax', 'plan_runs' => 'planning'] as $table => $domain) {
            if (! $ctx->canAny($domain)) {
                continue;
            }
            foreach ($ctx->constrain(DB::table($table), $domain)->orderByDesc('updated_at')->limit(4)->get() as $r) {
                $recent[] = ['type' => $table, 'id' => $r->id, 'label' => $r->title ?? $r->description ?? $r->name ?? $r->scenario ?? $r->kind ?? $table, 'at' => (string) $r->updated_at];
            }
        }
        usort($recent, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $sources = $ctx->canAny('ledger') ? $ctx->constrain(DB::table('source_connections'), 'ledger')->whereNull('archived_at')->get(['id', 'label', 'provider', 'status', 'last_success_at', 'freshness_hours'])
            ->map(fn ($s) => (array) $s + ['stale' => ! $s->last_success_at || now()->diffInHours($s->last_success_at, true) > $s->freshness_hours])->all() : [];

        return ['as_of' => $nw['as_of'], 'net_worth' => $nw['net_worth'], 'total_assets' => $nw['total_assets'], 'total_liabilities' => $nw['total_liabilities'],
            'liquid_assets' => $nw['liquid_assets'], 'categories' => array_map(fn ($c) => array_diff_key($c, ['items' => 1]), $nw['categories']),
            'unvalued' => count($nw['unvalued']), 'issues' => $nw['issues'], 'tax' => $taxReserve, 'due' => array_slice($due, 0, 12), 'review_open' => $review,
            'recent' => array_slice($recent, 0, 10), 'sources' => $sources, 'methodology' => $nw['methodology'],
            'prompts' => array_slice(app(GovernanceService::class)->prompts($ctx), 0, 6)];
    }

    public function search(string $q): array
    {
        $q = trim(mb_substr($q, 0, 80));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $svc = new ResourceService($this->ctx);
        $out = [];
        foreach (Registry::all() as $type => $def) {
            if (empty($def['search'])) {
                continue;
            }
            $def['type'] = $type;
            $term = '%'.addcslashes($q, '%_\\').'%';
            $rows = $svc->query($def)->whereNull('archived_at')->where(function ($w) use ($def, $term, $type) {
                foreach ($def['search'] as $c) {
                    $w->orWhere($type.'.'.$c, 'like', $term);
                }
            })->limit(5)->get();
            foreach ($rows as $r) {
                $out[] = ['type' => $type, 'label' => $def['label'], 'id' => $r->id, 'title' => (string) ($r->{$def['title'] ?? 'id'} ?? $r->id)];
            }
        }

        return array_slice($out, 0, 40);
    }

    /** Typed edges; both endpoints must be visible to create or view a link. */
    public function link(array $data): string
    {
        $from = $this->resolve((string) ($data['from_type'] ?? ''), (string) ($data['from_id'] ?? ''), 'write');
        $to = $this->resolve((string) ($data['to_type'] ?? ''), (string) ($data['to_id'] ?? ''), 'read');
        $relation = (string) ($data['relation'] ?? 'related');
        if (! preg_match('/^[a-z_]{2,32}$/', $relation)) {
            throw new ApiError('validation_failed', 'Invalid relation.');
        }
        $id = (string) Str::ulid();
        DB::table('record_links')->insert(['id' => $id, 'workspace_id' => $this->ctx->workspace->id, 'from_type' => $from[0], 'from_id' => $from[1], 'to_type' => $to[0], 'to_id' => $to[1],
            'relation' => $relation, 'effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null,
            'provenance' => json_encode(['created_via' => 'ui', 'note' => mb_substr((string) ($data['note'] ?? ''), 0, 200)]), 'created_by' => $this->ctx->user->id,
            'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        Audit::log('link.created', $from[0], $from[1], ['to_type' => $to[0], 'relation' => $relation]);

        return $id;
    }

    public function links(string $type, string $id): array
    {
        $this->resolve($type, $id, 'read');
        $rows = DB::table('record_links')->where('workspace_id', $this->ctx->workspace->id)->whereNull('archived_at')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('from_type', $type)->where('from_id', $id))->orWhere(fn ($b) => $b->where('to_type', $type)->where('to_id', $id)))->get();
        $out = [];
        foreach ($rows as $r) {
            $otherType = $r->from_type === $type && $r->from_id === $id ? $r->to_type : $r->from_type;
            $otherId = $r->from_type === $type && $r->from_id === $id ? $r->to_id : $r->from_id;
            try {
                $other = $this->resolve($otherType, $otherId, 'read');
            } catch (ApiError) {
                continue; // invisible endpoint: the link itself is not disclosed
            }
            $out[] = ['id' => $r->id, 'relation' => $r->relation, 'direction' => $r->from_id === $id ? 'out' : 'in', 'type' => $otherType, 'record_id' => $otherId, 'title' => $other[2]];
        }

        return $out;
    }

    private function resolve(string $type, string $id, string $action): array
    {
        $def = Registry::get($type);
        if (! $def) {
            throw ApiError::notFound();
        }
        $row = (new ResourceService($this->ctx))->find($type, $id, $action);

        return [$type, $row->id, (string) ($row->{$def['title'] ?? 'id'} ?? $row->id)];
    }
}
