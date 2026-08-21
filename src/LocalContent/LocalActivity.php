<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Une activité locale, avec ses dates exactes et sa source.
 *
 * La source et la date de vérification ne sont pas décoratives : la
 * spécification impose de les afficher (§58). Une suggestion sans provenance
 * n'est pas une suggestion, c'est une rumeur.
 */
final class LocalActivity
{
    public function __construct(
        public readonly int $id,
        public readonly int $generationId,
        public readonly ?int $bookingId,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $category,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly bool $bookingRequired,
        public readonly string $location,
        public readonly string $sourceUrl,
        public readonly string $verifiedOn,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['generation_id'],
            $row['booking_id'] === null ? null : (int) $row['booking_id'],
            (string) $row['locale'],
            (string) $row['title'],
            (string) ($row['summary'] ?? ''),
            (string) $row['category'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
            (bool) $row['booking_required'],
            (string) $row['location'],
            (string) $row['source_url'],
            (string) $row['verified_on'],
        );
    }

    public function startsDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->startsOn . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function endsDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->endsOn . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function verifiedDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->verifiedOn . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * L'activité tombe-t-elle dans la fenêtre demandée ?
     *
     * Les deux bornes sont incluses : un marché le jour du départ compte
     * encore, un festival de la semaine précédente non (SPECIFICATIONS.md §58).
     */
    public function overlaps(string $from, string $to): bool
    {
        return $this->startsOn <= $to && $this->endsOn >= $from;
    }

    /**
     * Groupe d'affichage : à réserver à l'avance, ou à faire cette semaine.
     */
    public function group(): string
    {
        return $this->bookingRequired ? 'book_ahead' : 'this_week';
    }

    public function categoryLabelKey(): string
    {
        return 'local.category.' . $this->category;
    }

    public function host(): string
    {
        $host = parse_url($this->sourceUrl, PHP_URL_HOST);

        return is_string($host) ? $host : $this->sourceUrl;
    }
}
