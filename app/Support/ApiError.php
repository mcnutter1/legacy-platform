<?php

namespace App\Support;

use RuntimeException;

/** Business-rule failure rendered as a structured API error. */
class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'You do not have access to this resource.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self('conflict', $message, 409, $details);
    }

    public static function unsupported(string $message, array $details = []): self
    {
        return new self('unsupported', $message, 422, $details);
    }

    public function toArray(): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage(), 'details' => $this->details ?: null,
            'correlation_id' => Correlation::id()]];
    }
}
