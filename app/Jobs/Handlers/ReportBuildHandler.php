<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\Reports\ReportService;
use App\Support\AccessContext;
use App\Support\Domains;
use Illuminate\Support\Facades\DB;

class ReportBuildHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        $kind = DB::table('report_manifests')->where('id', $job->params['manifest_id'] ?? '')->value('kind');
        $checks = [['reports', 'write', $job->scope_id]];
        foreach (Domains::REPORT_DEPENDENCIES[$kind] ?? [] as $d) {
            $checks[] = [$d, 'read', null];
        }

        return $checks;
    }

    public function runnerType(): ?string
    {
        return null;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        return ['_done' => app(ReportService::class)->build($ctx, $job->params['manifest_id'])];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }

    public function onFailure(ComputeJob $job): void
    {
        DB::table('report_manifests')->where('id', $job->params['manifest_id'] ?? '')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
