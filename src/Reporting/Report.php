<?php

declare(strict_types=1);

namespace SecondStay\Reporting;

/**
 * Résultat d'un reporting sur une période (SPECIFICATIONS.md §66).
 *
 * Tous les montants sont en centimes. Le rapport ne porte **aucun conseil
 * fiscal** : il compte ce qui a été encaissé, ce qui est attendu, ce qui est
 * détenu, et laisse la lecture comptable à qui en a la charge.
 */
final class Report
{
    /**
     * @param list<array{
     *     reference: string,
     *     arrival: string,
     *     departure: string,
     *     nights: int,
     *     nights_in_period: int,
     *     status: string,
     *     received_cents: int,
     *     expected_cents: int,
     *     tax_cents: int,
     *     deposit_held_cents: int
     * }> $stays
     */
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly int $receivedCents,
        public readonly int $expectedCents,
        public readonly int $refundedCents,
        public readonly int $depositsHeldCents,
        public readonly int $touristTaxCents,
        public readonly int $nightsSold,
        public readonly int $nightsAvailable,
        public readonly int $staysCount,
        public readonly string $currency,
        public readonly array $stays = [],
    ) {
    }

    /**
     * Taux d'occupation, en pourcentage, arrondi à une décimale.
     */
    public function occupancyPercent(): float
    {
        if ($this->nightsAvailable <= 0) {
            return 0.0;
        }

        return round(($this->nightsSold / $this->nightsAvailable) * 100, 1);
    }

    /**
     * Prix moyen de la nuit, en centimes.
     *
     * Il porte sur l'hébergement encaissé rapporté aux nuits vendues : y mêler
     * ménage, taxe ou caution donnerait un chiffre que rien ne permet de
     * comparer d'un mois à l'autre.
     */
    public function averageNightCents(): int
    {
        if ($this->nightsSold <= 0) {
            return 0;
        }

        return (int) round($this->receivedCents / $this->nightsSold);
    }

    /**
     * Ce qui reste à encaisser sur la période.
     */
    public function outstandingCents(): int
    {
        return max(0, $this->expectedCents - $this->receivedCents);
    }

    public function isEmpty(): bool
    {
        return $this->staysCount === 0;
    }
}
