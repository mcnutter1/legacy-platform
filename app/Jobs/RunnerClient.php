<?php

namespace App\Jobs;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Invokes the allowlisted Python runner: absolute interpreter path, argument array (no shell),
 * minimal environment (no app/DB credentials), bounded time/memory/threads/output.
 */
final class RunnerClient
{
    public const ALLOWED = [
        'tax.compute', 'tax.strategies', 'plan.project', 'plan.montecarlo', 'plan.stress', 'debt.amortize', 'debt.compare',
        'estate.distribute', 'import.normalize', 'equity.model', 'document.extract', 'rental.classify', 'selfcheck',
    ];

    public const SCHEMA_VERSION = 1;

    public function run(string $jobType, string $jobId, array $input, string $queue = 'interactive', ?string $workDir = null): array
    {
        if (! in_array($jobType, self::ALLOWED, true)) {
            throw new RuntimeException('Runner job type not allowlisted');
        }
        $python = (string) config('legacy.runner.python');
        if (! is_file($python) || ! is_executable($python)) {
            throw new RunnerUnavailable('Python runner not installed at configured path');
        }
        $request = json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'job_id' => $jobId,
            'job_type' => $jobType,
            'input' => $input ?: new \stdClass,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        $timeout = (int) (config('legacy.runner.timeout_seconds')[$queue] ?? 40);
        $threads = (string) max(1, (int) config('legacy.runner.threads'));
        $env = [
            'PATH' => '/usr/bin:/bin',
            'LANG' => 'C.UTF-8',
            'PYTHONHASHSEED' => '0',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONNOUSERSITE' => '1',
            'OMP_NUM_THREADS' => $threads,
            'OPENBLAS_NUM_THREADS' => $threads,
            'MKL_NUM_THREADS' => $threads,
            'NUMEXPR_NUM_THREADS' => $threads,
            'LEGACY_RUNNER_MEMORY_MB' => (string) config('legacy.runner.memory_mb'),
            'LEGACY_RUNNER_CPU_SECONDS' => (string) ($timeout + 5),
            'LEGACY_JOB_DIR' => $workDir ?? '',
            'HOME' => $workDir ?: sys_get_temp_dir(),
        ];
        $cmd = [$python, '-I', '-m', 'legacy_runners', $jobType];
        if (config('legacy.runner.sandbox') === 'bwrap' && is_executable('/usr/bin/bwrap')) {
            $cmd = array_merge(['/usr/bin/bwrap', '--ro-bind', '/usr', '/usr', '--ro-bind', '/lib', '/lib', '--ro-bind-try', '/lib64', '/lib64',
                '--ro-bind', '/bin', '/bin', '--ro-bind', base_path('runners'), base_path('runners'), '--proc', '/proc', '--dev', '/dev',
                '--tmpfs', '/tmp', '--unshare-all', '--die-with-parent', '--new-session'],
                $workDir ? ['--bind', $workDir, $workDir] : [], ['--chdir', base_path('runners')], $cmd);
        }
        $proc = new Process($cmd, base_path('runners'), $env, $request, $timeout);
        $started = microtime(true);
        $max = (int) config('legacy.runner.max_output_bytes');
        $out = '';
        try {
            $proc->start();
            foreach ($proc as $type => $data) {
                if ($type === Process::OUT) {
                    $out .= $data;
                    if (strlen($out) > $max) {
                        $proc->stop(0);
                        throw new RunnerFailed('Runner output exceeded limit', false);
                    }
                }
            }
        } catch (ProcessTimedOutException) {
            throw new RunnerTimeout('Runner exceeded '.$timeout.'s');
        }
        $elapsed = round(microtime(true) - $started, 3);
        $decoded = json_decode($out, true);
        if (! is_array($decoded)) {
            $err = mb_substr(trim($proc->getErrorOutput()), -400);
            throw new RunnerFailed('Runner returned no valid JSON (exit '.$proc->getExitCode().'): '.$this->sanitize($err), $proc->getExitCode() !== 2);
        }
        if (($decoded['ok'] ?? false) !== true && ($decoded['job_id'] ?? '') === '') {
            $error = $decoded['error'] ?? [];
            throw new RunnerFailed($this->sanitize(($error['code'] ?? 'error').': '.($error['message'] ?? 'runner rejected the request')), false);
        }
        if (($decoded['job_id'] ?? null) !== $jobId || (int) ($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new RunnerFailed('Runner result does not match job/schema', false);
        }
        if (($decoded['ok'] ?? false) !== true) {
            $error = $decoded['error'] ?? ['code' => 'unknown', 'message' => 'Runner failed'];
            throw new RunnerFailed($this->sanitize(($error['code'] ?? 'error').': '.($error['message'] ?? '')), (bool) ($error['retryable'] ?? false));
        }

        return [
            'result' => $decoded['result'] ?? [],
            'engine' => $decoded['engine'] ?? null,
            'metrics' => ['elapsed_s' => $elapsed, 'bytes_out' => strlen($out)] + (array) ($decoded['metrics'] ?? []),
        ];
    }

    private function sanitize(string $s): string
    {
        return preg_replace('/\d{5,}/', '[n]', $s) ?? '';
    }
}
