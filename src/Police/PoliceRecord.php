<?php

declare(strict_types=1);

namespace SecondStay\Police;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fiche individuelle de police (SPECIFICATIONS.md §64).
 *
 * Le contenu ne vit jamais en clair en base : cet objet ne le porte qu'une
 * fois déchiffré, le temps de l'afficher ou de l'exporter.
 */
final class PoliceRecord
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly array $fields,
        public readonly string $locale,
        public readonly string $createdAt,
        public readonly ?int $createdBy,
        public readonly string $purgeAfter,
    ) {
    }

    public function purgeDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->purgeAfter . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function createdDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->createdAt, new DateTimeZone('UTC'));
    }

    public function field(string $name): string
    {
        return $this->fields[$name] ?? '';
    }

    /**
     * La fiche a-t-elle dépassé sa durée de conservation ?
     */
    public function isExpired(?string $today = null): bool
    {
        return $this->purgeAfter < ($today ?? gmdate('Y-m-d'));
    }
}
