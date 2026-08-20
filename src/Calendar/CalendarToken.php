<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

/**
 * Jeton d'accès à un flux ICS.
 *
 * Le jeton en clair n'existe qu'au moment de sa création : seule son empreinte
 * est conservée, exactement comme un mot de passe. Une fuite de la base ne
 * donne donc accès à aucun calendrier.
 */
final class CalendarToken
{
    public function __construct(
        public readonly int $id,
        public readonly CalendarScope $scope,
        public readonly string $label,
        public readonly ?int $userId,
        public readonly ?int $bookingId,
        public readonly string $createdAt,
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
            CalendarScope::fromString((string) $row['scope']),
            (string) $row['label'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            $row['booking_id'] === null ? null : (int) $row['booking_id'],
            (string) $row['created_at'],
            $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function wasUsed(): bool
    {
        return $this->lastUsedAt !== null;
    }
}
