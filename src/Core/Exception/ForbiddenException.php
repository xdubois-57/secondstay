<?php

declare(strict_types=1);

namespace SecondStay\Core\Exception;

use Throwable;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?Throwable $previous = null)
    {
        parent::__construct(403, $message, 'error.403.title', $previous);
    }
}
