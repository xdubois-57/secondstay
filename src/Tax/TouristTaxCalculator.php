<?php

declare(strict_types=1);

namespace SecondStay\Tax;

use SecondStay\Booking\Booking;
use SecondStay\Settings\SettingsService;

/**
 * Taxe de séjour, volet financier (SPECIFICATIONS.md §29).
 *
 * Le calcul appliqué ici est celui du cas courant en France : un montant par
 * adulte et par nuit, plafonné, les mineurs étant exonérés. Le moteur versionné
 * complet — territoire, classification, exemptions, historisation du contexte
 * de calcul (SPECIFICATIONS.md §63) — arrive à l'itération « France et
 * conformité » et prendra la place de ce calcul sans changer les appelants :
 * le montant reste un composant de paiement comme un autre.
 */
final class TouristTaxCalculator
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('tax.tourist_enabled');
    }

    /**
     * Montant dû pour un séjour, en centimes.
     */
    public function forBooking(Booking $booking): int
    {
        return $this->compute($booking->adults, $booking->nights());
    }

    /**
     * Les mineurs sont exonérés : seuls les adultes sont comptés
     * (article L. 2333-31 du code général des collectivités territoriales).
     */
    public function compute(int $adults, int $nights): int
    {
        if (!$this->isEnabled() || $adults <= 0 || $nights <= 0) {
            return 0;
        }

        $perNight = max(0, $this->settings->money('tax.tourist_per_adult_night'));
        if ($perNight === 0) {
            return 0;
        }

        $total = $perNight * $adults * $nights;

        $cap = max(0, $this->settings->money('tax.tourist_cap_per_stay'));

        return $cap > 0 ? min($cap, $total) : $total;
    }

    /**
     * Détail du calcul, conservé avec le séjour pour rester explicable.
     *
     * @return array<string, mixed>
     */
    public function explain(Booking $booking): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'adults' => $booking->adults,
            'exempt' => $booking->children + $booking->infants,
            'nights' => $booking->nights(),
            'per_adult_night_cents' => $this->settings->money('tax.tourist_per_adult_night'),
            'cap_cents' => $this->settings->money('tax.tourist_cap_per_stay'),
            'total_cents' => $this->forBooking($booking),
        ];
    }
}
