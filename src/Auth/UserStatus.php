<?php

declare(strict_types=1);

namespace SecondStay\Auth;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Pending;
    }
}
