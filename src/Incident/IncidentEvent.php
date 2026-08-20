<?php

declare(strict_types=1);

namespace SecondStay\Incident;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Une ligne d'historique d'incident.
 *
 * L'historique est **ajout seul** : il n'existe ni modification ni
 * suppression, sans quoi il ne prouverait plus rien.
 */
final class IncidentEvent
{
    public function __construct(
        public readonly int $id,
        public readonly int $incidentId,
        public readonly string $type,
        public readonly string $note,
        public readonly ?int $actorId,
        public readonly string $actorLabel,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['incident_id'],
            (string) $row['type'],
            (string) $row['note'],
            $row['actor_id'] === null ? null : (int) $row['actor_id'],
            (string) $row['actor_label'],
            (string) $row['created_at'],
        );
    }

    public function createdDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->createdAt, new DateTimeZone('UTC'));
    }

    public function labelKey(): string
    {
        return 'incident.event.' . $this->type;
    }
}
