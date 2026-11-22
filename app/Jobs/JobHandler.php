<?php

namespace App\Jobs;

use App\Models\ComputeJob;
use App\Support\AccessContext;

/** A bounded unit of background work. Runner-backed handlers return a request for the Python runner. */
interface JobHandler
{
    /** Domain + action the initiator must still hold when the job runs. */
    public function authorization(ComputeJob $job): array;

    /** Python job type, or null if the handler runs entirely in PHP. */
    public function runnerType(): ?string;

    /** Build the runner input (or perform PHP-only work and return ['_done' => summary]). */
    public function prepare(ComputeJob $job, AccessContext $ctx): array;

    /** Validate and persist the runner result. Must be idempotent per job id. Returns a small summary. */
    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array;
}
