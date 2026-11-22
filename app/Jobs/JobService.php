<?php

namespace App\Jobs;

use App\Models\ComputeJob;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Durable, leased compute jobs executed by bounded cron-started workers (no standing daemon). */
final class JobService
{
    /** @var array<string, class-string<JobHandler>> */
    public const HANDLERS = [
        'tax.compute' => Handlers\TaxComputeHandler::class,
        'tax.strategies' => Handlers\TaxStrategiesHandler::class,
        'plan.project' => Handlers\PlanRunHandler::class,
        'plan.montecarlo' => Handlers\PlanRunHandler::class,
        'plan.stress' => Handlers\PlanRunHandler::class,
        'debt.compare' => Handlers\DebtCompareHandler::class,
        'debt.amortize' => Handlers\DebtAmortizeHandler::class,
        'equity.model' => Handlers\EquityModelHandler::class,
        'estate.distribute' => Handlers\EstateRunHandler::class,
        'import.normalize' => Handlers\ImportNormalizeHandler::class,
        'document.extract' => Handlers\DocumentExtractHandler::class,
        'document.scan' => Handlers\DocumentScanHandler::class,
        'report.build' => Handlers\ReportBuildHandler::class,
        'yearclose.build' => Handlers\YearCloseHandler::class,
    ];

    public const BULK = ['plan.montecarlo', 'plan.stress', 'import.normalize', 'document.extract', 'document.scan', 'report.build', 'yearclose.build', 'tax.strategies'];

    /** Job-local directory for the job being executed (runner file paths must stay inside it). */
    public static ?string $currentWorkDir = null;

    public function enqueue(AccessContext $ctx, string $type, array $params, ?string $subjectType = null, ?string $subjectId = null, ?string $scopeId = null, int $priority = 100): ComputeJob
    {
        if (! isset(self::HANDLERS[$type])) {
            throw new ApiError('invalid_job', 'Unknown job type.');
        }
        $active = ComputeJob::where('workspace_id', $ctx->workspace->id)->whereIn('status', ['queued', 'leased'])->count();
        if ($active >= 25) {
            throw new ApiError('queue_saturated', 'Too many jobs are pending for this workspace. Try again shortly.', 429);
        }
        $job = ComputeJob::create([
            'workspace_id' => $ctx->workspace->id,
            'scope_id' => $scopeId,
            'type' => $type,
            'queue' => in_array($type, self::BULK, true) ? 'bulk' : 'interactive',
            'priority' => $priority,
            'initiator_user_id' => $ctx->user->id,
            'initiator_membership_id' => $ctx->membership->id,
            'initiator_kind' => $ctx->actorType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'schema_version' => RunnerClient::SCHEMA_VERSION,
            'params' => $params + ['_token_domains' => $ctx->tokenDomains, '_token_actions' => $ctx->tokenActions, '_client_id' => $ctx->clientId],
            'available_at' => now(),
            'status' => 'queued',
        ]);
        Audit::log('job.enqueued', 'compute_job', $job->id, ['type' => $type]);

        return $job;
    }

    public function cancel(AccessContext $ctx, string $jobId): ComputeJob
    {
        $job = $this->visible($ctx, $jobId);
        if ($job->status === 'queued' || $job->status === 'retryable') {
            $job->forceFill(['status' => 'cancelled', 'finished_at' => now(), 'cancel_requested_at' => now()])->save();
        } elseif ($job->status === 'leased') {
            $job->forceFill(['cancel_requested_at' => now()])->save();
        }

        return $job;
    }

    public function retry(AccessContext $ctx, string $jobId): ComputeJob
    {
        $job = $this->visible($ctx, $jobId);
        if (! in_array($job->status, ['failed', 'timed_out', 'cancelled'], true)) {
            throw ApiError::conflict('Only failed, timed out or cancelled jobs can be retried.');
        }
        $job->forceFill(['status' => 'queued', 'attempts' => 0, 'error' => null, 'available_at' => now(), 'cancel_requested_at' => null,
            'initiator_user_id' => $ctx->user->id, 'initiator_membership_id' => $ctx->membership->id])->save();

        return $job;
    }

    public function visible(AccessContext $ctx, string $jobId): ComputeJob
    {
        $job = ComputeJob::where('workspace_id', $ctx->workspace->id)->find($jobId);
        // Jobs are visible to their initiator only; results are re-read through scoped resources.
        if (! $job || $job->initiator_membership_id !== $ctx->membership->id) {
            throw ApiError::notFound();
        }

        return $job;
    }

    /** Claim the next job with a renewable lease. */
    public function claim(string $queue): ?ComputeJob
    {
        $this->recoverExpiredLeases();
        $max = (int) config('legacy.runner.max_per_workspace');
        $busy = ComputeJob::where('status', 'leased')->select('workspace_id', DB::raw('count(*) as c'))->groupBy('workspace_id')
            ->get()->filter(fn ($r) => $r->c >= $max)->pluck('workspace_id')->all();
        $candidates = ComputeJob::where('queue', $queue)->whereIn('status', ['queued', 'retryable'])
            ->where('available_at', '<=', now())->when($busy, fn ($q) => $q->whereNotIn('workspace_id', $busy))
            ->orderBy('priority')->orderBy('available_at')->limit(5)->get();
        foreach ($candidates as $c) {
            $token = Str::random(40);
            $n = ComputeJob::where('id', $c->id)->whereIn('status', ['queued', 'retryable'])->update([
                'status' => 'leased', 'lease_token' => $token, 'lease_expires_at' => now()->addSeconds((int) config('legacy.runner.lease_seconds') + 30),
                'attempts' => DB::raw('attempts + 1'), 'started_at' => now(), 'updated_at' => now(),
            ]);
            if ($n === 1) {
                return ComputeJob::find($c->id);
            }
        }

        return null;
    }

    public function recoverExpiredLeases(): void
    {
        ComputeJob::where('status', 'leased')->where('lease_expires_at', '<', now())->get()->each(function (ComputeJob $j) {
            $final = $j->attempts >= $j->max_attempts;
            $j->forceFill([
                'status' => $final ? 'timed_out' : 'retryable', 'lease_token' => null, 'error' => 'Worker lease expired',
                'available_at' => now()->addSeconds(30 * (2 ** min($j->attempts, 5))), 'finished_at' => $final ? now() : null,
            ])->save();
            if ($final) {
                $this->afterFailure($j);
            }
        });
    }

    /** Execute a claimed job end-to-end. A runner crash leaves financial records unchanged. */
    public function execute(ComputeJob $job, RunnerClient $runner): void
    {
        $token = $job->lease_token;
        $workDir = null;
        try {
            $ctx = $this->initiatorContext($job);
            $handler = app(self::HANDLERS[$job->type]);
            foreach ($handler->authorization($job) as [$domain, $action, $scopeId]) {
                if ($scopeId ? ! $ctx->can($domain, $action, $scopeId) : ! $ctx->canAny($domain, $action)) {
                    throw new JobDenied('Initiator no longer authorized');
                }
            }
            app()->instance(AccessContext::class, $ctx);
            $workDir = $this->jobDir($job);
            self::$currentWorkDir = $workDir;
            $input = $handler->prepare($job, $ctx);
            if (array_key_exists('_done', $input)) {
                $this->complete($job, $token, $input['_done'], ['php_only' => true]);

                return;
            }
            $started = microtime(true);
            $out = $runner->run($handler->runnerType(), $job->id, $input, $job->queue, $workDir);
            $this->assertLease($job, $token);
            $summary = DB::transaction(function () use ($handler, $job, $ctx, $out, $token) {
                $locked = ComputeJob::where('id', $job->id)->lockForUpdate()->first();
                if ($locked->lease_token !== $token || $locked->status !== 'leased') {
                    throw new JobLeaseLost('Lease lost before commit');
                }

                return $handler->commit($job, $ctx, $out['result'] + ['_engine' => $out['engine']]);
            });
            $this->complete($job, $token, $summary, $out['metrics'] + ['total_s' => round(microtime(true) - $started, 3)]);
        } catch (JobLeaseLost $e) {
            Log::warning('job.lease_lost', ['job' => $job->id]);
        } catch (JobDenied $e) {
            $this->fail($job, $token, 'failed', 'Not authorized at execution time');
        } catch (RunnerTimeout $e) {
            $this->fail($job, $token, $job->attempts < $job->max_attempts ? 'retryable' : 'timed_out', $e->getMessage());
        } catch (RunnerFailed $e) {
            $this->fail($job, $token, $e->retryable && $job->attempts < $job->max_attempts ? 'retryable' : 'failed', $e->getMessage());
        } catch (ApiError $e) {
            $this->fail($job, $token, 'failed', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('job.crash', ['job' => $job->id, 'type' => $job->type, 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]);
            $this->fail($job, $token, $job->attempts < $job->max_attempts ? 'retryable' : 'failed', 'Internal error ('.class_basename($e).')');
        } finally {
            if ($workDir) {
                File::deleteDirectory($workDir);
            }
            self::$currentWorkDir = null;
            app()->forgetInstance(AccessContext::class);
        }
    }

    public function initiatorContext(ComputeJob $job): AccessContext
    {
        $m = Membership::where('workspace_id', $job->workspace_id)->find($job->initiator_membership_id);
        $u = User::find($job->initiator_user_id);
        if (! $m || ! $u || ! $m->isActive() || $m->user_id !== $u->id || $u->disabled_at) {
            throw new JobDenied('Initiator inactive');
        }
        $p = $job->params ?? [];

        return new AccessContext($u, Workspace::findOrFail($job->workspace_id), $m, $job->initiator_kind === 'oauth' ? 'oauth' : 'user',
            $p['_token_domains'] ?? null, $p['_token_actions'] ?? null, $p['_client_id'] ?? null);
    }

    private function assertLease(ComputeJob $job, ?string $token): void
    {
        $fresh = ComputeJob::find($job->id);
        if (! $fresh || $fresh->lease_token !== $token || $fresh->status !== 'leased') {
            throw new JobLeaseLost('Lease lost');
        }
        if ($fresh->cancel_requested_at) {
            $fresh->forceFill(['status' => 'cancelled', 'finished_at' => now(), 'lease_token' => null])->save();
            throw new JobLeaseLost('Cancelled');
        }
    }

    private function complete(ComputeJob $job, ?string $token, array $summary, array $metrics): void
    {
        $n = ComputeJob::where('id', $job->id)->where('lease_token', $token)->update([
            'status' => 'succeeded', 'result' => json_encode($summary), 'metrics' => json_encode($metrics),
            'finished_at' => now(), 'lease_token' => null, 'error' => null, 'updated_at' => now(),
        ]);
        if ($n === 1) {
            Outbox::publish('job.succeeded', ['job_id' => $job->id, 'type' => $job->type], 'compute_job', $job->id, $job->workspace_id);
        }
    }

    private function fail(ComputeJob $job, ?string $token, string $status, string $error): void
    {
        $attempts = (int) ComputeJob::where('id', $job->id)->value('attempts');
        ComputeJob::where('id', $job->id)->where('lease_token', $token)->update([
            'status' => $status, 'error' => mb_substr($error, 0, 500), 'lease_token' => null, 'updated_at' => now(),
            'available_at' => now()->addSeconds(min(3600, 20 * (2 ** min($attempts, 6)) + random_int(0, 15))),
            'finished_at' => $status === 'retryable' ? null : now(),
        ]);
        if ($status !== 'retryable') {
            $this->afterFailure(ComputeJob::find($job->id));
        }
    }

    private function afterFailure(ComputeJob $job): void
    {
        $handler = self::HANDLERS[$job->type] ?? null;
        if ($handler && method_exists($handler, 'onFailure')) {
            try {
                app($handler)->onFailure($job);
            } catch (Throwable) {
            }
        }
        Outbox::publish('job.failed', ['job_id' => $job->id, 'type' => $job->type], 'compute_job', $job->id, $job->workspace_id);
    }

    private function jobDir(ComputeJob $job): string
    {
        $dir = rtrim((string) config('legacy.vault.job_dir'), '/').'/'.$job->id.'-'.Str::random(8);
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }
}
