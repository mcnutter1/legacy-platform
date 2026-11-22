<?php

namespace App\Jobs;

class RunnerTimeout extends RunnerFailed
{
    public function __construct(string $message)
    {
        parent::__construct($message, true);
    }
}
