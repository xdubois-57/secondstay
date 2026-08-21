<?php

declare(strict_types=1);

namespace SecondStay\Tax;

/**
 * Une règle de taxe de séjour, valable sur une période donnée
 * (SPECIFICATIONS.md §63).
 *
 * Un barème change : il est voté, il prend effet à une date, il est remplacé.
 * Une règle est donc bornée dans le temps, et une réservation passée reste
 * calculée avec celle qui s'appliquait le jour de son arrivée.
 */
final class TouristTaxRule
{
    public function __construct(
        public readonly int $id,
        public readonly string $territory,
        public readonly string $classification,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
        public readonly int $perAdultNightCents,
        public readonly int $capPerStayCents,
        public readonly int $taxableFromAge,
        public readonly string $sourceUrl,
        public readonly string $notes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['territory'],
            (string) $row['classification'],
            (string) $row['effective_from'],
            $row['effective_to'] === null ? null : (string) $row['effective_to'],
            (int) $row['per_adult_night_cents'],
            (int) $row['cap_per_stay_cents'],
            (int) $row['taxable_from_age'],
            (string) $row['source_url'],
            (string) $row['notes'],
        );
    }

    /**
     * La règle s'applique-t-elle à cette date ?
     *
     * La borne de fin est **inclusive** : une règle qui court jusqu'au
     * 31 décembre s'applique bien le 31 décembre.
     */
    public function appliesOn(string $day): bool
    {
        if ($day < $this->effectiveFrom) {
            return false;
        }

        return $this->effectiveTo === null || $day <= $this->effectiveTo;
    }

    /**
     * Montant dû, en centimes, pour un nombre de personnes taxables et de
     * nuits.
     */
    public function compute(int $taxablePersons, int $nights): int
    {
        if ($taxablePersons <= 0 || $nights <= 0 || $this->perAdultNightCents <= 0) {
            return 0;
        }

        $total = $this->perAdultNightCents * $taxablePersons * $nights;

        return $this->capPerStayCents > 0 ? min($this->capPerStayCents, $total) : $total;
    }
}
