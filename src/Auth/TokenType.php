<?php

declare(strict_types=1);

namespace SecondStay\Auth;

enum TokenType: string
{
    case EmailConfirmation = 'email_confirmation';
    case PasswordReset = 'password_reset';
    case EmailChange = 'email_change';

    /**
     * Durée de validité en secondes.
     */
    public function lifetimeSeconds(): int
    {
        return match ($this) {
            self::EmailConfirmation => 7 * 86400,
            self::PasswordReset => 3600,
            self::EmailChange => 86400,
        };
    }
}
