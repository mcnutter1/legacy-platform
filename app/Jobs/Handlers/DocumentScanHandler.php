<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\VaultService;
use App\Support\AccessContext;

/** PHP-only: malware scan runs from the CLI worker (FPM never spawns processes). */
class DocumentScanHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['vault', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return null;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        return ['_done' => ['scan_status' => app(VaultService::class)->scan($job->params['version_id'])]];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }
}
