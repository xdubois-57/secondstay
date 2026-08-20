<?php

declare(strict_types=1);

namespace SecondStay\Stay;

/**
 * Lien invité (SPECIFICATIONS.md §46).
 *
 * Il donne accès aux informations pratiques d'un séjour — et à rien d'autre :
 * ni finances, ni documents, ni compte. Il expire, et se révoque.
 */
final class GuestLink
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly string $label,
        public readonly string $locale,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $expiresAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            (string) $row['label'],
            (string) $row['locale'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            (string) $row['created_at'],
            (string) $row['expires_at'],
            $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(?string $now = null): bool
    {
        return $this->expiresAt < ($now ?? gmdate('Y-m-d H:i:s'));
    }

    public function isUsable(?string $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
