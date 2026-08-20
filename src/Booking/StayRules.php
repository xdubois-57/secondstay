<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Pricing\DateRange;
use SecondStay\Settings\SettingsService;

/**
 * Règles de séjour configurables (SPECIFICATIONS.md §20 et §22).
 *
 * Les règles sont vérifiées **côté serveur** : le calendrier les applique pour
 * guider la saisie, mais rien ne dépend du navigateur.
 */
final class StayRules
{
    public const WEEKDAYS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $timezone = 'Europe/Paris',
    ) {
    }

    public function minNights(): int
    {
        return max(1, $this->settings->int('booking.min_nights'));
    }

    public function maxNights(): int
    {
        return max($this->minNights(), $this->settings->int('booking.max_nights'));
    }

    public function maxGuests(): int
    {
        return max(1, $this->settings->int('booking.max_guests'));
    }

    public function nightMultiple(): int
    {
        return max(0, $this->settings->int('booking.night_multiple'));
    }

    public function checkinTime(): string
    {
        $time = $this->settings->string('booking.checkin_time');

        return $time !== '' ? $time : '16:00';
    }

    public function checkoutTime(): string
    {
        $time = $this->settings->string('booking.checkout_time');

        return $time !== '' ? $time : '10:00';
    }

    /**
     * Jour d'arrivée imposé, en numéro ISO (1 = lundi), ou null si libre.
     *
     * Le réglage historique « samedi-samedi » reste prioritaire : il exprime
     * la même contrainte d'une façon que les propriétaires connaissent.
     */
    public function arrivalWeekday(): ?int
    {
        if ($this->settings->bool('booking.saturday_to_saturday')) {
            return self::WEEKDAYS['saturday'];
        }

        $configured = $this->settings->string('booking.arrival_weekday');

        return self::WEEKDAYS[$configured] ?? null;
    }

    /**
     * Vrai lorsque le séjour doit commencer et finir le même jour de semaine.
     */
    public function isFixedWeek(): bool
    {
        return $this->settings->bool('booking.saturday_to_saturday');
    }

    /**
     * Première date réservable : aujourd'hui plus le délai de prévenance.
     */
    public function earliestArrival(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today ??= new DateTimeImmutable('today', new DateTimeZone($this->timezone));

        return DateRange::fromStrings(
            $today->format('Y-m-d'),
            $today->format('Y-m-d')
        )->arrival->modify('+' . max(0, $this->settings->int('booking.advance_days')) . ' days');
    }

    /**
     * Dernière date réservable : au-delà, le calendrier n'est pas ouvert.
     */
    public function latestDeparture(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today ??= new DateTimeImmutable('today', new DateTimeZone($this->timezone));

        return DateRange::fromStrings(
            $today->format('Y-m-d'),
            $today->format('Y-m-d')
        )->arrival->modify('+' . max(30, $this->settings->int('booking.horizon_days')) . ' days');
    }

    /**
     * Vérifie une plage de séjour.
     *
     * @return list<string> clés de traduction, vide si le séjour est conforme
     */
    public function validateRange(DateRange $range, ?DateTimeImmutable $today = null): array
    {
        $errors = [];

        if (!$range->isValid()) {
            return ['booking.error.invalid_range'];
        }

        $nights = $range->nights();

        if ($nights < $this->minNights()) {
            $errors[] = 'booking.error.min_nights';
        }
        if ($nights > $this->maxNights()) {
            $errors[] = 'booking.error.max_nights';
        }

        $multiple = $this->nightMultiple();
        if ($multiple > 1 && $nights % $multiple !== 0) {
            $errors[] = 'booking.error.night_multiple';
        }

        $weekday = $this->arrivalWeekday();
        if ($weekday !== null && (int) $range->arrival->format('N') !== $weekday) {
            $errors[] = 'booking.error.arrival_weekday';
        }
        if ($this->isFixedWeek() && (int) $range->departure->format('N') !== $weekday) {
            $errors[] = 'booking.error.departure_weekday';
        }

        if ($range->arrival < $this->earliestArrival($today)) {
            $errors[] = 'booking.error.too_early';
        }
        if ($range->departure > $this->latestDeparture($today)) {
            $errors[] = 'booking.error.too_far';
        }

        return $errors;
    }

    /**
     * Vérifie la composition du groupe.
     *
     * @return list<string> clés de traduction
     */
    public function validateGuests(int $adults, int $children = 0, int $infants = 0): array
    {
        $errors = [];

        if ($adults < max(1, $this->settings->int('booking.min_adults'))) {
            $errors[] = 'booking.error.min_adults';
        }
        if ($children < 0 || $children > $this->settings->int('booking.max_children')) {
            $errors[] = 'booking.error.max_children';
        }
        if ($infants < 0 || $infants > $this->settings->int('booking.max_infants')) {
            $errors[] = 'booking.error.max_infants';
        }

        // Les bébés ne comptent pas dans la capacité de couchage.
        if ($adults + $children > $this->maxGuests()) {
            $errors[] = 'booking.error.max_guests';
        }

        return $errors;
    }

    /**
     * Résumé destiné à l'affichage public des règles.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $weekday = $this->arrivalWeekday();

        return [
            'min_nights' => $this->minNights(),
            'max_nights' => $this->maxNights(),
            'max_guests' => $this->maxGuests(),
            'max_children' => $this->settings->int('booking.max_children'),
            'max_infants' => $this->settings->int('booking.max_infants'),
            'night_multiple' => $this->nightMultiple(),
            'checkin_time' => $this->checkinTime(),
            'checkout_time' => $this->checkoutTime(),
            'arrival_weekday' => $weekday,
            'fixed_week' => $this->isFixedWeek(),
            'advance_days' => max(0, $this->settings->int('booking.advance_days')),
        ];
    }
}
