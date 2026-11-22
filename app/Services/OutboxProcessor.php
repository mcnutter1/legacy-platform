<?php

namespace App\Services;

use App\Jobs\JobService;
use App\Models\ComputeJob;
use App\Models\Membership;
use App\Services\Notifications\NotificationService;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Retry-safe outbox dispatcher. Each (event, handler) pair runs at most once via outbox_handled. */
final class OutboxProcessor
{
    public function __construct(private readonly NotificationService $notify, private readonly JobService $jobs) {}

    public function handlers(): array
    {
        return [
            'payroll.approved' => ['forecastTax'],
            'document.uploaded' => ['scanDocument'],
            'job.failed' => ['notifyJobFailed'],
            'job.succeeded' => ['notifyJobSucceeded'],
            'membership.invited' => [],
            'export.send' => ['sendExport'],
            'breakglass.requested' => ['notifyBreakGlass'],
            'breakglass.released' => ['notifyBreakGlassReleased'],
            'import.committed' => [],
            'access.changed' => [],
        ];
    }

    public function run(int $limit = 100): array
    {
        $done = 0;
        $failed = 0;
        $events = DB::table('outbox_events')->whereNull('dispatched_at')->whereNull('failed_at')->where('available_at', '<=', now())->orderBy('created_at')->limit($limit)->get();
        foreach ($events as $e) {
            $payload = json_decode((string) $e->payload, true) ?: [];
            $ok = true;
            foreach ($this->handlers()[$e->type] ?? [] as $h) {
                if (DB::table('outbox_handled')->where('event_id', $e->id)->where('handler', $h)->exists()) {
                    continue;
                }
                try {
                    DB::transaction(function () use ($h, $e, $payload) {
                        $this->{$h}($e, $payload);
                        DB::table('outbox_handled')->insert(['event_id' => $e->id, 'handler' => $h, 'handled_at' => now()]);
                    });
                } catch (Throwable $ex) {
                    $ok = false;
                    Log::warning('outbox.handler_failed', ['event' => $e->id, 'type' => $e->type, 'handler' => $h, 'error' => class_basename($ex).': '.mb_substr($ex->getMessage(), 0, 200)]);
                }
            }
            if ($ok) {
                DB::table('outbox_events')->where('id', $e->id)->update(['dispatched_at' => now()]);
                $done++;
            } else {
                $attempts = $e->attempts + 1;
                DB::table('outbox_events')->where('id', $e->id)->update(['attempts' => $attempts, 'available_at' => now()->addSeconds(min(3600, 30 * 2 ** $attempts)),
                    'failed_at' => $attempts >= 10 ? now() : null, 'last_error' => 'handler failure']);
                $failed++;
            }
        }
        DB::table('outbox_events')->whereNotNull('dispatched_at')->where('dispatched_at', '<', now()->subDays(30))->delete();

        return ['dispatched' => $done, 'failed' => $failed];
    }

    private function ctxFor(string $workspaceId, ?string $membershipId): ?AccessContext
    {
        $m = $membershipId ? Membership::where('workspace_id', $workspaceId)->find($membershipId) : null;
        if (! $m || ! $m->isActive() || ! $m->user) {
            return null;
        }

        return new AccessContext($m->user, \App\Models\Workspace::find($workspaceId), $m);
    }

    /** New approved payroll → fresh federal/NJ forecast for every tax unit of that person for the year. */
    private function forecastTax(object $e, array $p): void
    {
        $ctx = $this->ctxFor($e->workspace_id, $p['initiator_membership_id'] ?? null);
        if (! $ctx) {
            return;
        }
        $units = $ctx->constrain(DB::table('tax_units'), 'tax', 'write', 'tax_year')->where('tax_year', $p['year'])
            ->where(fn ($q) => $q->where('primary_person_id', $p['person_id'])->orWhere('spouse_person_id', $p['person_id']))->whereNull('archived_at')->get();
        foreach ($units as $u) {
            $runId = (string) Str::ulid();
            DB::table('tax_runs')->insert(['id' => $runId, 'workspace_id' => $u->workspace_id, 'scope_id' => $u->scope_id, 'tax_unit_id' => $u->id, 'tax_year' => $u->tax_year,
                'scenario' => 'payroll-forecast', 'kind' => 'forecast', 'status' => 'pending', 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            $job = $this->jobs->enqueue($ctx, 'tax.compute', ['tax_unit_id' => $u->id, 'run_id' => $runId], 'tax_run', $runId, $u->scope_id, 50);
            DB::table('tax_runs')->where('id', $runId)->update(['job_id' => $job->id]);
        }
    }

    private function scanDocument(object $e, array $p): void
    {
        $ctx = $this->ctxFor($e->workspace_id, $p['initiator_membership_id'] ?? null);
        $doc = DB::table('documents')->find($p['document_id']);
        if (! $ctx || ! $doc) {
            DB::table('documents')->where('id', $p['document_id'])->where('status', 'quarantined')->update(['status' => config('legacy.vault.require_scan') ? 'quarantined' : 'clean']);

            return;
        }
        $this->jobs->enqueue($ctx, 'document.scan', ['version_id' => $p['version_id']], 'document', $doc->id, $doc->scope_id, 10);
    }

    private function notifyJobFailed(object $e, array $p): void
    {
        $job = ComputeJob::find($p['job_id']);
        if ($job?->initiator_membership_id) {
            $this->notify->notify($job->workspace_id, [$job->initiator_membership_id], 'job_failed', 'A background task could not finish', 'Open the activity panel to retry or review details.', '/jobs', 'jobfail:'.$job->id, 'high');
        }
    }

    private function notifyJobSucceeded(object $e, array $p): void
    {
        $job = ComputeJob::find($p['job_id']);
        if ($job && in_array($job->type, ['report.build', 'plan.montecarlo', 'import.normalize', 'yearclose.build', 'tax.strategies'], true)) {
            $labels = ['report.build' => 'Your report is ready', 'plan.montecarlo' => 'Monte Carlo simulation finished', 'import.normalize' => 'Import ready for review',
                'yearclose.build' => 'Year-close snapshot ready', 'tax.strategies' => 'Tax strategy ideas are ready'];
            $this->notify->notify($job->workspace_id, [$job->initiator_membership_id], 'job_done', $labels[$job->type], null, '/jobs', 'jobdone:'.$job->id);
        }
    }

    private function sendExport(object $e, array $p): void
    {
        $g = DB::table('export_grants')->find($p['grant_id']);
        if (! $g || $g->revoked_at) {
            return;
        }
        $link = decrypt($p['link']);
        $html = view('emails.export', ['name' => $g->recipient_name, 'from' => $p['from_name'] ?? 'A McNutt Legacy member', 'link' => $link, 'expires' => $g->expires_at])->render();
        $driver = config('legacy.notifications.driver');
        if ($driver === 'mcnutt' && config('legacy.notifications.api_token')) {
            $res = \Illuminate\Support\Facades\Http::withToken((string) config('legacy.notifications.api_token'))->timeout(10)->acceptJson()
                ->post(rtrim((string) config('legacy.notifications.base_url'), '/').'/api/send.php', array_filter(['channel' => 'email', 'to' => $g->recipient_email,
                    'from' => config('legacy.notifications.from'), 'subject' => 'Documents shared with you', 'body' => $html, 'provider' => config('legacy.notifications.provider')]));
            if ($res->serverError() || $res->status() === 429) {
                throw new \RuntimeException('Notification service unavailable');
            }
        } else {
            Log::info('export.email', ['grant' => $g->id]);
        }
    }

    private function notifyBreakGlass(object $e, array $p): void
    {
        $r = DB::table('break_glass_requests')->find($p['request_id']);
        $stewards = DB::table('scope_stewards')->where('scope_id', $r->scope_id)->pluck('membership_id')->all();
        $this->notify->notify($r->workspace_id, $stewards, 'breakglass', 'Emergency access requested', 'A family member requested emergency binder access. Verify before it is released.', '/governance', 'bg:'.$r->id, 'high');
    }

    private function notifyBreakGlassReleased(object $e, array $p): void
    {
        $r = DB::table('break_glass_requests')->find($p['request_id']);
        $this->notify->notify($r->workspace_id, array_merge([$r->requester_membership_id], DB::table('scope_stewards')->where('scope_id', $r->scope_id)->pluck('membership_id')->all()),
            'breakglass_released', 'Emergency binder access released', 'Access is time-limited and every view is recorded.', '/governance', 'bgr:'.$r->id, 'high');
    }
}
