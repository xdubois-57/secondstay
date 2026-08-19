<?php

declare(strict_types=1);

namespace SecondStay\Core\Exception;

use Throwable;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, 'error.400.title', $previous);
    }
}
