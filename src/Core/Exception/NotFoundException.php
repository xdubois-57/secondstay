<?php

declare(strict_types=1);

namespace SecondStay\Core\Exception;

use Throwable;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?Throwable $previous = null)
    {
        parent::__construct(404, $message, 'error.404.title', $previous);
    }
}
