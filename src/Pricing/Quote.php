<?php

declare(strict_types=1);

namespace SecondStay\Pricing;

/**
 * Devis d'un séjour.
 *
 * Tous les montants sont des entiers de centimes : la logique financière est
 * canonique et indépendante de la locale, seul l'affichage est localisé
 * (SPECIFICATIONS.md §21).
 */
final class Quote
{
    /**
     * @param list<array{day: string, price_cents: int, is_override: bool}> $nights
     */
    public function __construct(
        public readonly DateRange $range,
        public readonly array $nights,
        public readonly int $accommodationCents,
        public readonly int $cleaningCents,
        public readonly int $totalCents,
        public readonly int $depositCents,
        public readonly int $securityDepositCents,
        public readonly string $currency = 'EUR',
    ) {
    }

    public function nightCount(): int
    {
        return count($this->nights);
    }

    /**
     * Prix moyen par nuit, utile à l'affichage d'un séjour traversant
     * plusieurs tarifs. Arrondi au centime, jamais utilisé pour facturer.
     */
    public function averageNightCents(): int
    {
        $count = $this->nightCount();

        return $count === 0 ? 0 : (int) round($this->accommodationCents / $count);
    }

    public function crossesSeveralRates(): bool
    {
        $prices = array_unique(array_column($this->nights, 'price_cents'));

        return count($prices) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'arrival' => $this->range->arrivalKey(),
            'departure' => $this->range->departureKey(),
            'nights' => $this->nights,
            'night_count' => $this->nightCount(),
            'accommodation_cents' => $this->accommodationCents,
            'cleaning_cents' => $this->cleaningCents,
            'total_cents' => $this->totalCents,
            'deposit_cents' => $this->depositCents,
            'security_deposit_cents' => $this->securityDepositCents,
            'average_night_cents' => $this->averageNightCents(),
            'currency' => $this->currency,
        ];
    }
}
