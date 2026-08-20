<?php

declare(strict_types=1);

namespace SecondStay\Incident;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Un incident : ticket rattaché à un séjour et à une zone
 * (SPECIFICATIONS.md §54).
 */
final class Incident
{
    /**
     * @param list<IncidentEvent> $events
     * @param list<int>           $photoIds
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $bookingId,
        public readonly ?int $zoneId,
        public readonly IncidentSeverity $severity,
        public readonly IncidentStatus $status,
        public readonly string $title,
        public readonly string $description,
        public readonly string $locale,
        public readonly ?int $reportedBy,
        public readonly ?int $assignedTo,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $resolvedAt,
        public readonly string $bookingReference = '',
        public readonly string $zoneCode = '',
        public readonly string $zoneName = '',
        public readonly array $events = [],
        public readonly array $photoIds = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<IncidentEvent>  $events
     * @param list<int>            $photoIds
     */
    public static function fromRow(array $row, array $events = [], array $photoIds = []): self
    {
        return new self(
            (int) $row['id'],
            $row['booking_id'] === null ? null : (int) $row['booking_id'],
            $row['zone_id'] === null ? null : (int) $row['zone_id'],
            IncidentSeverity::fromString((string) $row['severity']),
            IncidentStatus::fromString((string) $row['status']),
            (string) $row['title'],
            (string) ($row['description'] ?? ''),
            (string) $row['locale'],
            $row['reported_by'] === null ? null : (int) $row['reported_by'],
            $row['assigned_to'] === null ? null : (int) $row['assigned_to'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
            (string) ($row['booking_reference'] ?? ''),
            (string) ($row['zone_code'] ?? ''),
            (string) ($row['zone_name'] ?? ''),
            $events,
            $photoIds,
        );
    }

    public function createdDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->createdAt, new DateTimeZone('UTC'));
    }

    public function updatedDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->updatedAt, new DateTimeZone('UTC'));
    }

    public function resolvedDate(): ?DateTimeImmutable
    {
        return $this->resolvedAt === null
            ? null
            : new DateTimeImmutable($this->resolvedAt, new DateTimeZone('UTC'));
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Clé de traduction de la zone, quand elle n'a pas de libellé propre.
     */
    public function zoneLabelKey(): string
    {
        return $this->zoneCode === '' ? '' : 'inspection.zone.' . $this->zoneCode;
    }
}
