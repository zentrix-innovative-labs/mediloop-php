<?php

declare(strict_types=1);

namespace Mediloop;

class ApiException extends MediloopException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly mixed $body = null,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }
}
