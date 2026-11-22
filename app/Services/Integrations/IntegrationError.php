<?php

namespace App\Services\Integrations;

use RuntimeException;

final class IntegrationError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
