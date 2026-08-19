<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\I18n\Locales;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $phone,
        public readonly Role $role,
        public readonly string $locale,
        public readonly UserStatus $status,
        public readonly ?string $passwordHash = null,
        public readonly ?string $emailVerifiedAt = null,
        public readonly ?string $lastLoginAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) ($row['first_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
            (string) ($row['phone'] ?? ''),
            Role::fromString((string) ($row['role'] ?? 'customer')),
            Locales::normalise((string) ($row['locale'] ?? 'fr')) ?? Locales::FALLBACK,
            UserStatus::fromString((string) ($row['status'] ?? 'pending')),
            $row['password_hash'] === null ? null : (string) $row['password_hash'],
            $row['email_verified_at'] === null ? null : (string) $row['email_verified_at'],
            $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
        );
    }

    public function displayName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);

        return $name === '' ? $this->email : $name;
    }

    public function isAdministrator(): bool
    {
        return $this->role->isAdministrator();
    }

    public function isOperational(): bool
    {
        return $this->role->isOperational();
    }

    /**
     * Représentation sûre : jamais de hash de mot de passe.
     *
     * @return array{id: int, email: string, name: string, role: string, locale: string, status: string}
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->displayName(),
            'role' => $this->role->value,
            'locale' => $this->locale,
            'status' => $this->status->value,
        ];
    }
}
