<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\YearCloseService;
use App\Support\AccessContext;

class YearCloseHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['ledger', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return null;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        return ['_done' => app(YearCloseService::class)->build($ctx, (int) $job->params['year'], (string) $job->params['scope_id'])];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }
}
