<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\RealEstate\CountyRecordsService;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/** PHP-only: low-volume fetch of one property's county tax card and clerk index entries. */
class CountyRecordsHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['real_estate', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return null;
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $ctx->constrain(DB::table('properties'), 'real_estate', 'write')->where('id', $job->params['property_id'] ?? '')->whereNull('archived_at')->first();
        if (! $p) {
            throw new ApiError('not_found', 'Property not found.', 404);
        }

        return ['_done' => (new CountyRecordsService($ctx))->sync($p)];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        return $result;
    }
}
