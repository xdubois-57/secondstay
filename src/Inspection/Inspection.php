<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

use DateTimeImmutable;
use DateTimeZone;

/**
 * État des lieux d'un séjour.
 */
final class Inspection
{
    /**
     * @param list<InspectionEntry> $entries
     */
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly InspectionKind $kind,
        public readonly InspectionStatus $status,
        public readonly string $locale,
        public readonly string $startedAt,
        public readonly ?string $completedAt,
        public readonly ?int $completedBy,
        public readonly string $summary,
        public readonly array $entries = [],
    ) {
    }

    /**
     * @param array<string, mixed>  $row
     * @param list<InspectionEntry> $entries
     */
    public static function fromRow(array $row, array $entries = []): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            InspectionKind::fromString((string) $row['kind']),
            InspectionStatus::fromString((string) $row['status']),
            (string) $row['locale'],
            (string) $row['started_at'],
            $row['completed_at'] === null ? null : (string) $row['completed_at'],
            $row['completed_by'] === null ? null : (int) $row['completed_by'],
            (string) $row['summary'],
            $entries,
        );
    }

    /**
     * Date de clôture, pour l'affichage localisé.
     *
     * Les horodatages sont stockés en UTC : la conversion est explicite ici
     * plutôt que laissée au fuseau du serveur.
     */
    public function completedDate(): ?DateTimeImmutable
    {
        return $this->completedAt === null
            ? null
            : new DateTimeImmutable($this->completedAt, new DateTimeZone('UTC'));
    }

    /**
     * Zones encore à traiter avant de pouvoir clore.
     *
     * @return list<InspectionEntry>
     */
    public function blocking(): array
    {
        if (!$this->kind->requiresPhotos()) {
            return array_values(array_filter(
                $this->entries,
                static fn (InspectionEntry $entry): bool => !$entry->state->isDecided()
            ));
        }

        return array_values(array_filter(
            $this->entries,
            static fn (InspectionEntry $entry): bool => !$entry->isReadyForCheckout()
        ));
    }

    public function isComplete(): bool
    {
        return $this->blocking() === [];
    }

    /**
     * @return list<InspectionEntry>
     */
    public function anomalies(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (InspectionEntry $entry): bool => $entry->state->isAnomaly()
        ));
    }

    /**
     * @return array{done: int, total: int}
     */
    public function progress(): array
    {
        $done = 0;
        foreach ($this->entries as $entry) {
            if ($this->kind->requiresPhotos() ? $entry->isReadyForCheckout() : $entry->state->isDecided()) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => count($this->entries)];
    }
}
