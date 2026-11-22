<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\ExportService;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\ReportService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Reports, export grants, review inbox, jobs and notifications. */
class WorkflowController extends ApiController
{
    public function createReport(Request $r, ReportService $svc): JsonResponse
    {
        $res = $svc->request($this->ctx(), (string) $r->input('kind'), $r->except('kind'));

        return response()->json(['data' => $res], 202);
    }

    public function reportDownload(\App\Services\VaultService $vault, string $manifest): JsonResponse
    {
        $m = $this->ctx()->constrain(DB::table('report_manifests'), 'reports', 'read')->where('id', $manifest)->first();
        if (! $m || ! $m->document_id) {
            throw ApiError::notFound();
        }

        return $this->ok(['url' => $vault->issueDownload($this->ctx(), $m->document_id)]);
    }

    public function createExport(Request $r, ExportService $svc, string $manifest): JsonResponse
    {
        $this->privileged();

        return $this->ok($svc->create($this->ctx(), $manifest, $r->all()), 201);
    }

    public function sendExport(Request $r, ExportService $svc, string $grant): JsonResponse
    {
        $this->privileged();
        $svc->send($this->ctx(), $grant, (string) $r->input('link'));

        return $this->ok(['sent' => true, 'note' => 'The recipient will receive a link by email. Share the access code separately.']);
    }

    public function revokeExport(ExportService $svc, string $grant): JsonResponse
    {
        $svc->revoke($this->ctx(), $grant);

        return $this->ok(['revoked' => true]);
    }

    public function exports(): JsonResponse
    {
        $rows = $this->ctx()->constrain(DB::table('export_grants'), 'reports', 'read')->orderByDesc('created_at')->limit(100)
            ->get(['id', 'report_manifest_id', 'recipient_name', 'recipient_email', 'expires_at', 'revoked_at', 'max_downloads', 'download_count', 'sent_at', 'created_at']);

        return $this->ok($rows);
    }

    public function resolveReview(Request $r, string $item): JsonResponse
    {
        $ctx = $this->ctx();
        $row = DB::table('review_items')->where('workspace_id', $ctx->workspace->id)->where('id', $item)->first();
        if (! $row || ! $ctx->can($row->domain, 'write', $row->scope_id)) {
            throw ApiError::notFound();
        }
        $status = $r->input('status') === 'dismissed' ? 'dismissed' : 'resolved';
        if ($row->kind === 'extraction_review') {
            // Values only reach payroll through the draft's Approve (checks + step-up); the inbox can only discard the draft.
            if ($status === 'resolved') {
                throw new ApiError('approve_on_draft', 'Open the draft (Review & approve) and use "Approve paycheck" — resolving here would not record the paycheck.', 409);
            }
            DB::table('document_extractions')->where('workspace_id', $ctx->workspace->id)->where('id', $row->subject_id)->where('status', 'needs_review')
                ->update(['status' => 'rejected', 'reviewed_by' => $ctx->user->id, 'updated_at' => now()]);
        }
        DB::table('review_items')->where('id', $row->id)->update(['status' => $status, 'resolved_by' => $ctx->user->id, 'resolved_at' => now(),
            'resolution' => json_encode(['note' => mb_substr((string) $r->input('note', ''), 0, 1000)]), 'updated_at' => now()]);
        if ($row->kind === 'carryforward_proposal' && $status === 'resolved') {
            $ev = json_decode((string) $row->evidence, true) ?: [];
            $run = DB::table('tax_runs')->find($ev['run_id'] ?? '');
            if ($run) {
                $kind = ['capital_loss_st' => 'capital_loss_st', 'capital_loss_lt' => 'capital_loss_lt', 'passive_suspended' => 'passive_loss'][$ev['kind']] ?? null;
                if ($kind) {
                    DB::table('tax_carryforwards')->insert(['id' => (string) \Illuminate\Support\Str::ulid(), 'workspace_id' => $run->workspace_id, 'scope_id' => $run->scope_id,
                        'tax_unit_id' => $run->tax_unit_id, 'jurisdiction' => 'US', 'kind' => $kind, 'origin_year' => $run->tax_year, 'amount' => $ev['amount'],
                        'source' => 'derived', 'source_ref' => $run->id, 'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
                }
            }
        }
        \App\Support\Audit::log('review.'.$status, 'review_item', $row->id, ['kind' => $row->kind], scopeId: $row->scope_id);

        return $this->ok(['status' => $status]);
    }

    public function job(JobService $jobs, string $job): JsonResponse
    {
        return $this->ok($jobs->visible($this->ctx(), $job)->present());
    }

    public function jobs(): JsonResponse
    {
        $ctx = $this->ctx();

        return $this->ok(\App\Models\ComputeJob::where('workspace_id', $ctx->workspace->id)->where('initiator_membership_id', $ctx->membership->id)
            ->orderByDesc('created_at')->limit(50)->get()->map->present());
    }

    public function cancelJob(JobService $jobs, string $job): JsonResponse
    {
        return $this->ok($jobs->cancel($this->ctx(), $job)->present());
    }

    public function retryJob(JobService $jobs, string $job): JsonResponse
    {
        return $this->ok($jobs->retry($this->ctx(), $job)->present());
    }

    public function notifications(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        $rows = DB::table('notifications')->where('workspace_id', $ctx->workspace->id)->where('membership_id', $ctx->membership->id)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<', now()))
            ->when($r->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->orderByDesc('created_at')->limit(100)->get();

        return $this->ok(['items' => $rows, 'unread' => DB::table('notifications')->where('membership_id', $ctx->membership->id)->whereNull('read_at')->count()]);
    }

    public function markNotification(Request $r, string $notification): JsonResponse
    {
        $ctx = $this->ctx();
        $update = $r->input('action') === 'snooze'
            ? ['snoozed_until' => now()->addDays(max(1, min(30, (int) $r->input('days', 1))))]
            : ['read_at' => now()];
        DB::table('notifications')->where('workspace_id', $ctx->workspace->id)->where('membership_id', $ctx->membership->id)
            ->when($notification !== 'all', fn ($q) => $q->where('id', $notification))->update($update + ['updated_at' => now()]);

        return $this->ok(['ok' => true]);
    }

    public function preferences(Request $r): JsonResponse
    {
        $ctx = $this->ctx();
        if ($r->isMethod('put')) {
            $d = $this->validated($r, ['email_enabled' => ['boolean'], 'email_digest' => ['in:immediate,daily,weekly,off'], 'quiet_start' => ['nullable', 'date_format:H:i'],
                'quiet_end' => ['nullable', 'date_format:H:i'], 'timezone' => ['timezone'], 'muted_kinds' => ['nullable', 'array']]);
            $vals = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $d) + ['updated_at' => now()];
            $q = DB::table('notification_preferences')->where('workspace_id', $ctx->workspace->id)->where('membership_id', $ctx->membership->id);
            if ((clone $q)->exists()) {
                $q->update($vals);
            } else {
                DB::table('notification_preferences')->insert($vals + ['id' => (string) \Illuminate\Support\Str::ulid(), 'workspace_id' => $ctx->workspace->id,
                    'membership_id' => $ctx->membership->id, 'created_at' => now()]);
            }
        }
        $p = DB::table('notification_preferences')->where('membership_id', $ctx->membership->id)->first();

        return $this->ok($p ? ['muted_kinds' => json_decode((string) $p->muted_kinds, true)] + (array) $p : ['email_enabled' => true, 'email_digest' => 'immediate', 'timezone' => $ctx->workspace->timezone]);
    }

    public function resendDelivery(NotificationService $svc, string $delivery): JsonResponse
    {
        if (! $this->ctx()->isAdministrator()) {
            throw ApiError::forbidden();
        }
        $svc->resend($this->ctx()->workspace->id, $delivery);

        return $this->ok(['queued' => true]);
    }

    public function operations(NotificationService $notify): JsonResponse
    {
        $ctx = $this->ctx();
        if (! $ctx->isAdministrator()) {
            throw ApiError::forbidden();
        }
        $ws = $ctx->workspace->id;

        return $this->ok([
            'jobs' => DB::table('compute_jobs')->where('workspace_id', $ws)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'oldest_queued_job' => DB::table('compute_jobs')->where('workspace_id', $ws)->where('status', 'queued')->min('created_at'),
            'outbox_pending' => DB::table('outbox_events')->where('workspace_id', $ws)->whereNull('dispatched_at')->count(),
            'deliveries' => DB::table('notification_deliveries')->where('workspace_id', $ws)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'delivery_attention' => DB::table('notification_deliveries')->where('workspace_id', $ws)->whereIn('status', ['delivery_unknown', 'failed'])->orderByDesc('updated_at')->limit(20)
                ->get(['id', 'status', 'attempts', 'last_error', 'updated_at']),
            'notifications_health' => $notify->health(),
            'auth_health' => app(\App\Services\Auth\AuthGateway::class)->health(),
            'backup' => ($f = config('legacy.backup_status_file')) && is_readable($f) ? json_decode((string) file_get_contents($f), true) : null,
            'release' => config('legacy.release'),
        ]);
    }
}
