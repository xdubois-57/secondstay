<?php

declare(strict_types=1);

namespace SecondStay\Auth;

/**
 * Rôles simples (AGENTS.md §7). L'administrateur hérite des capacités du
 * responsable local. Aucun moteur générique de permissions.
 */
enum Role: string
{
    case Customer = 'customer';
    case LocalManager = 'local_manager';
    case Administrator = 'administrator';

    public function level(): int
    {
        return match ($this) {
            self::Customer => 10,
            self::LocalManager => 50,
            self::Administrator => 100,
        };
    }

    public function includes(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    public function isAdministrator(): bool
    {
        return $this === self::Administrator;
    }

    public function isOperational(): bool
    {
        return $this->includes(self::LocalManager);
    }

    public function labelKey(): string
    {
        return 'auth.role.' . $this->value;
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Customer;
    }
}
