<?php

declare(strict_types=1);

namespace SecondStay\Availability;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\StayRules;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Pricing\RateRepository;

/**
 * Disponibilité et calendrier (ARCHITECTURE.md §12).
 *
 * La disponibilité combine les indisponibilités d'exploitation et les nuits
 * réellement tenues par une réservation — verrou temporaire compris. Les
 * imports ICS externes s'y ajouteront par la même porte, sans changer les
 * appelants.
 */
final class AvailabilityService
{
    public const STATE_FREE = 'free';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_PAST = 'past';
    public const STATE_CLOSED = 'closed';

    /** Motif générique d'une nuit déjà réservée : jamais l'identité du client. */
    public const REASON_BOOKED = 'booked';

    public function __construct(
        private readonly AvailabilityBlockRepository $blocks,
        private readonly RateRepository $rates,
        private readonly PriceCalculator $prices,
        private readonly StayRules $rules,
        private readonly string $timezone = 'Europe/Paris',
        private readonly ?BookingRepository $bookings = null,
    ) {
    }

    /**
     * Nuits indisponibles d'une plage, quelle qu'en soit la raison.
     *
     * @return array<string, string> nuit → motif (`blocked` ou `booked`)
     */
    private function unavailableNights(string $from, string $to): array
    {
        $unavailable = [];

        foreach ($this->blocks->blockedNights($from, $to) as $day => $block) {
            $unavailable[$day] = $block['kind'];
        }

        foreach ($this->bookings?->occupiedNights($from, $to) ?? [] as $day) {
            // Une nuit réservée reste « occupée » sans dire par qui.
            $unavailable[$day] = self::REASON_BOOKED;
        }

        return $unavailable;
    }

    public function today(): DateTimeImmutable
    {
        return DateRange::fromStrings(
            (new DateTimeImmutable('today', new DateTimeZone($this->timezone)))->format('Y-m-d'),
            (new DateTimeImmutable('today', new DateTimeZone($this->timezone)))->format('Y-m-d')
        )->arrival;
    }

    /**
     * Vrai si toutes les nuits de la plage sont libres.
     */
    public function isAvailable(DateRange $range): bool
    {
        return $this->conflictingNights($range) === [];
    }

    /**
     * @return list<string> nuits indisponibles
     */
    public function conflictingNights(DateRange $range): array
    {
        if (!$range->isValid()) {
            return [];
        }

        $unavailable = $this->unavailableNights($range->arrivalKey(), $range->lastNightKey());

        return array_values(array_intersect($range->nightKeys(), array_keys($unavailable)));
    }

    /**
     * État de chaque nuit d'une plage, pour le calendrier.
     *
     * @return array<string, array{state: string, price_cents: int, label: string, is_override: bool}>
     */
    public function nightStates(DateRange $range): array
    {
        if (!$range->isValid()) {
            return [];
        }

        $from = $range->arrivalKey();
        $to = $range->lastNightKey();

        $unavailable = $this->unavailableNights($from, $to);
        $overrides = $this->rates->forRange($from, $to);

        $today = $this->today();
        $earliest = $this->rules->earliestArrival($today);
        $latest = $this->rules->latestDeparture($today);

        $states = [];
        foreach ($range->nightsList() as $night) {
            $day = $night->format('Y-m-d');

            $state = self::STATE_FREE;
            $label = '';

            if ($night < $today) {
                $state = self::STATE_PAST;
            } elseif (isset($unavailable[$day])) {
                $state = self::STATE_BLOCKED;
                // Le motif détaillé n'est pas public : seul le type l'est.
                $label = $unavailable[$day];
            } elseif ($night < $earliest || $night >= $latest) {
                $state = self::STATE_CLOSED;
            }

            $states[$day] = [
                'state' => $state,
                'price_cents' => $this->prices->nightPrice($day, $overrides),
                'label' => $label,
                'is_override' => isset($overrides[$day]),
            ];
        }

        return $states;
    }

    /**
     * Grille d'un mois civil, complétée pour commencer un lundi et finir un
     * dimanche : le gabarit n'a plus aucun calcul à faire.
     *
     * @return array{
     *     month: string,
     *     previous: string,
     *     next: string,
     *     first_day: string,
     *     weeks: list<list<array{day: string, in_month: bool, state: string,
     *         price_cents: int, label: string, is_override: bool}>>
     * }
     */
    public function month(string $month): array
    {
        $first = DateRange::fromStrings($month . '-01', $month . '-01')->arrival;
        $last = $first->modify('last day of this month');

        $gridStart = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $gridEnd = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');

        $states = $this->nightStates(DateRange::fromNights(
            $gridStart->format('Y-m-d'),
            $gridEnd->format('Y-m-d')
        ));

        $weeks = [];
        $week = [];
        $cursor = $gridStart;

        while ($cursor <= $gridEnd) {
            $day = $cursor->format('Y-m-d');
            $state = $states[$day] ?? [
                'state' => self::STATE_CLOSED,
                'price_cents' => 0,
                'label' => '',
                'is_override' => false,
            ];

            $week[] = [
                'day' => $day,
                'in_month' => $cursor->format('Y-m') === $first->format('Y-m'),
            ] + $state;

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->modify('+1 day');
        }

        if ($week !== []) {
            $weeks[] = $week;
        }

        return [
            'month' => $first->format('Y-m'),
            'previous' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'first_day' => $first->format('Y-m-d'),
            'weeks' => $weeks,
        ];
    }

    /**
     * Mois valide et borné : une entrée aberrante ne doit ni planter ni
     * permettre de balayer des années entières.
     */
    public function normaliseMonth(?string $month): string
    {
        $today = $this->today();

        if ($month === null || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return $today->format('Y-m');
        }

        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if ($candidate === false) {
            return $today->format('Y-m');
        }

        $floor = $today->modify('-1 month');
        $ceiling = $this->rules->latestDeparture($today)->modify('+1 month');

        if ($candidate < $floor) {
            return $floor->format('Y-m');
        }
        if ($candidate > $ceiling) {
            return $ceiling->format('Y-m');
        }

        return $candidate->format('Y-m');
    }
}
