<?php

namespace App\Console\Commands;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Bounded worker started by cron. Claims leased jobs until its time budget is spent; no standing daemon. */
class WorkCommand extends Command
{
    protected $signature = 'legacy:work {queue=interactive : interactive|bulk} {--max-seconds=} {--once}';

    protected $description = 'Process queued compute jobs for a bounded time';

    public function handle(JobService $jobs, RunnerClient $runner): int
    {
        $queue = in_array($this->argument('queue'), ['interactive', 'bulk'], true) ? $this->argument('queue') : 'interactive';
        $budget = (int) ($this->option('max-seconds') ?: config('legacy.runner.worker_seconds')[$queue]);
        $timeout = (int) config('legacy.runner.timeout_seconds')[$queue];
        $deadline = microtime(true) + $budget;
        $processed = 0;
        Cache::put('worker-heartbeat:'.$queue, time(), 600);
        while (microtime(true) + $timeout + 3 < $deadline || ($this->option('once') && $processed === 0)) {
            $job = $jobs->claim($queue);
            if (! $job) {
                if ($this->option('once')) {
                    break;
                }
                usleep(750_000);
                Cache::put('worker-heartbeat:'.$queue, time(), 600);
                continue;
            }
            $jobs->execute($job, $runner);
            $processed++;
            Cache::put('worker-heartbeat:'.$queue, time(), 600);
            if ($this->option('once')) {
                break;
            }
        }
        if ($processed) {
            $this->line("Processed {$processed} {$queue} job(s).");
        }

        return self::SUCCESS;
    }
}
