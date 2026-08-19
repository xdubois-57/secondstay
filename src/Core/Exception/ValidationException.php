<?php

declare(strict_types=1);

namespace SecondStay\Core\Exception;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors cle de champ => cle de traduction
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
