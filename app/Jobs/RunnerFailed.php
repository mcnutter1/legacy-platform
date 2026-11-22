<?php

namespace App\Jobs;

use RuntimeException;

class RunnerFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
