<?php

namespace App\Jobs;

class RunnerUnavailable extends RunnerFailed
{
    public function __construct(string $message)
    {
        parent::__construct($message, true);
    }
}
