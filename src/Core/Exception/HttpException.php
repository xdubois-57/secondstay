<?php

declare(strict_types=1);

namespace SecondStay\Core\Exception;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly string $translationKey = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }
}
