<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

enum DiagnosticStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case NotApplicable = 'not_applicable';

    public function isBlocking(): bool
    {
        return $this === self::Error;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok => 'text-bg-success',
            self::Warning => 'text-bg-warning',
            self::Error => 'text-bg-danger',
            self::NotApplicable => 'text-bg-secondary',
        };
    }
}
