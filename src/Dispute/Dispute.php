<?php

declare(strict_types=1);

namespace SecondStay\Dispute;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Un litige rattaché à un séjour.
 *
 * Le cas courant est la caution : le propriétaire estime une retenue, le
 * voyageur conteste, et la discussion s'appuie sur ce que le produit a déjà
 * collecté — état des lieux de départ, incidents, contrat accepté.
 */
final class Dispute
{
    /** Natures reconnues : au-delà, l'écran ne saurait pas quoi rattacher. */
    public const KINDS = ['deposit', 'damage', 'payment', 'other'];

    /**
     * @param list<DisputeEvent> $events
     */
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly string $kind,
        public readonly DisputeStatus $status,
        public readonly int $claimedCents,
        public readonly int $settledCents,
        public readonly string $currency,
        public readonly string $summary,
        public readonly string $resolution,
        public readonly string $locale,
        public readonly ?int $openedBy,
        public readonly string $openedAt,
        public readonly string $updatedAt,
        public readonly ?string $resolvedAt,
        public readonly string $bookingReference = '',
        public readonly array $events = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<DisputeEvent>   $events
     */
    public static function fromRow(array $row, array $events = []): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            (string) $row['kind'],
            DisputeStatus::fromString((string) $row['status']),
            (int) $row['claimed_cents'],
            (int) $row['settled_cents'],
            (string) $row['currency'],
            (string) $row['summary'],
            (string) ($row['resolution'] ?? ''),
            (string) $row['locale'],
            $row['opened_by'] === null ? null : (int) $row['opened_by'],
            (string) $row['opened_at'],
            (string) $row['updated_at'],
            $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
            (string) ($row['booking_reference'] ?? ''),
            $events,
        );
    }

    public function isOpen(): bool
    {
        return !$this->status->isResolved();
    }

    /**
     * Ce qui a été abandonné par rapport à la réclamation initiale.
     */
    public function waivedCents(): int
    {
        return max(0, $this->claimedCents - $this->settledCents);
    }

    public function openedDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->openedAt, new DateTimeZone('UTC'));
    }

    public function resolvedDate(): ?DateTimeImmutable
    {
        return $this->resolvedAt === null
            ? null
            : new DateTimeImmutable($this->resolvedAt, new DateTimeZone('UTC'));
    }

    public function kindLabelKey(): string
    {
        return 'dispute.kind.' . $this->kind;
    }
}
