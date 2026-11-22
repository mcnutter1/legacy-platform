<?php

namespace App\Http\Controllers\Api;

use App\Models\ComputeJob;
use App\Support\ApiError;
use App\Support\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Every automated puller in one place: scheduled data-source syncs, on-demand public-record pulls, system tasks and run history. */
class ScheduleController extends ApiController
{
    /** job type => [subject table, domain] used to decide who may see a run. */
    private const JOB_SUBJECTS = ['integration.sync' => ['source_connections', 'ledger'], 'property.county_records' => ['properties', 'real_estate']];

    public function index(): JsonResponse
    {
        $ctx = $this->ctx();
        $conns = $ctx->constrain(DB::table('source_connections'), 'ledger')->whereNull('archived_at')->whereIn('mode', ['mcp', 'api'])->orderBy('provider')->orderBy('label')->get();
        $props = $ctx->constrain(DB::table('properties'), 'real_estate')->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'scope_id', 'county', 'block', 'lot']);
        $jobs = $this->visibleJobs($conns->pluck('id'), $props->pluck('id'), 200);
        $byConn = $jobs->where('type', 'integration.sync')->groupBy('subject_id');
        $byProp = $jobs->where('type', 'property.county_records')->groupBy('subject_id');
        $beats = DB::table('scheduler_heartbeats')->get()->keyBy('task');

        return $this->ok([
            'now' => now()->toIso8601String(),
            'sources' => $conns->map(function ($c) use ($ctx, $byConn) {
                $runs = $byConn[$c->id] ?? collect();
                $scheduled = $c->approved_at && $c->credential_encrypted && in_array($c->status, ['active', 'error'], true);

                return ['id' => $c->id, 'provider' => $c->provider, 'label' => $c->label, 'status' => $c->status, 'approved' => (bool) $c->approved_at,
                    'authorized' => (bool) $c->credential_encrypted, 'interval_hours' => (int) $c->sync_interval_hours, 'scheduled' => $scheduled,
                    'next_run_at' => $scheduled ? $this->iso($c->next_sync_at ?? now()) : null, 'last_success_at' => $this->iso($c->last_success_at),
                    'last_attempt_at' => $this->iso($c->last_sync_at), 'last_error' => $c->last_error, 'edit_version' => (int) $c->edit_version,
                    'can_run' => $ctx->can('ledger', 'write', $c->scope_id) && (bool) $c->approved_at && (bool) $c->credential_encrypted,
                    'blocked_reason' => match (true) {
                        ! $c->credential_encrypted => 'Not authorized',
                        $c->status === 'needs_auth' => 'Authorization expired; reconnect',
                        ! $c->approved_at => 'Preview and approve on the Integrations page',
                        default => null,
                    },
                    'runs' => $runs->take(10)->map(fn ($j) => $this->summary($j))->values()];
            })->values(),
            'on_demand' => $props->map(fn ($p) => [
                'kind' => 'county_records', 'label' => 'County tax card & clerk records', 'subject_id' => $p->id, 'subject' => $p->name, 'county' => $p->county,
                'can_run' => $ctx->can('real_estate', 'write', $p->scope_id) && strtoupper((string) $p->county) === 'MONMOUTH' && $p->block && $p->lot,
                'blocked_reason' => strtoupper((string) $p->county) !== 'MONMOUTH' ? 'Only Monmouth County is integrated' : (! $p->block || ! $p->lot ? 'Look up public records first (block and lot)' : null),
                'runs' => ($byProp[$p->id] ?? collect())->take(10)->map(fn ($j) => $this->summary($j))->values(),
            ])->values(),
            'system' => collect(Heartbeat::TASKS)->map(fn ($t, $k) => ['task' => $k, 'label' => $t[0], 'cadence' => $t[1], 'description' => $t[2],
                'last_started_at' => $this->iso($beats[$k]->last_started_at ?? null), 'last_finished_at' => $this->iso($beats[$k]->last_finished_at ?? null),
                'last_status' => $beats[$k]->last_status ?? null, 'last_duration_ms' => $beats[$k]->last_duration_ms ?? null, 'failures' => (int) ($beats[$k]->failures ?? 0)])->values(),
            'queue' => [
                'queued' => ComputeJob::where('workspace_id', $ctx->workspace->id)->whereIn('status', ['queued', 'retryable'])->count(),
                'running' => ComputeJob::where('workspace_id', $ctx->workspace->id)->where('status', 'leased')->count(),
            ],
            'history' => $jobs->take(50)->map(fn ($j) => $this->summary($j) + ['subject' => $j->type === 'integration.sync'
                ? ($conns->firstWhere('id', $j->subject_id)->label ?? null) : ($props->firstWhere('id', $j->subject_id)->name ?? null)])->values(),
        ]);
    }

    /** Full status output of one run: parameters, timing, attempts, result or error, metrics. */
    public function job(string $job): JsonResponse
    {
        $j = Str::isUlid($job) ? ComputeJob::where('workspace_id', $this->ctx()->workspace->id)->find($job) : null;
        if (! $j || ! $this->canSee($j)) {
            throw ApiError::notFound();
        }

        return $this->ok($this->summary($j) + [
            'params' => array_filter((array) $j->params, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
            'result' => $j->result, 'error' => $j->error, 'metrics' => $j->metrics, 'max_attempts' => $j->max_attempts,
            'available_at' => $this->iso($j->available_at), 'cancel_requested_at' => $this->iso($j->cancel_requested_at),
            'initiated_by_you' => $j->initiator_membership_id === $this->ctx()->membership->id,
        ]);
    }

    private function visibleJobs(Collection $connIds, Collection $propIds, int $limit): Collection
    {
        $ctx = $this->ctx();

        return ComputeJob::where('workspace_id', $ctx->workspace->id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('type', 'integration.sync')->whereIn('subject_id', $connIds))
                ->orWhere(fn ($w) => $w->where('type', 'property.county_records')->whereIn('subject_id', $propIds)))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function canSee(ComputeJob $j): bool
    {
        if ($j->initiator_membership_id === $this->ctx()->membership->id) {
            return true;
        }
        [$table, $domain] = self::JOB_SUBJECTS[$j->type] ?? [null, null];

        return $table && $j->subject_id && $this->ctx()->constrain(DB::table($table), $domain)->where('id', $j->subject_id)->exists();
    }

    private function summary(ComputeJob $j): array
    {
        $done = $j->finished_at ?? null;

        return ['id' => $j->id, 'type' => $j->type, 'subject_id' => $j->subject_id, 'status' => $j->status, 'attempts' => (int) $j->attempts,
            'trigger' => ! empty($j->params['scheduled']) ? 'scheduled' : 'manual', 'dry_run' => ! empty($j->params['dry_run']),
            'created_at' => $this->iso($j->created_at), 'started_at' => $this->iso($j->started_at), 'finished_at' => $this->iso($done),
            'duration_s' => $j->started_at && $done ? round($done->getTimestamp() - $j->started_at->getTimestamp(), 1) : null,
            'error' => $j->error ? mb_substr((string) $j->error, 0, 300) : null];
    }

    private function iso(mixed $v): ?string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;
    }
}
