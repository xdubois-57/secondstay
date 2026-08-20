<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Settings\SettingsService;

/**
 * Flux ICS privés (SPECIFICATIONS.md §51).
 *
 * Chaque portée ne montre que ce dont son destinataire a besoin : un flux
 * abonné dans un agenda tiers finit souvent par être partagé sans y penser,
 * et un calendrier n'a pas à porter des montants ni des coordonnées qui n'ont
 * rien à y faire.
 */
final class CalendarService
{
    public function __construct(
        private readonly CalendarTokenRepository $tokens,
        private readonly BookingRepository $bookings,
        private readonly UserRepository $users,
        private readonly SettingsService $settings,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
    ) {
    }

    /**
     * Construit le flux correspondant à un jeton.
     *
     * Renvoie `null` si le jeton est inconnu ou révoqué : révoquer doit
     * couper l'accès immédiatement, sans période de grâce.
     */
    public function feedFor(string $token): ?string
    {
        $entry = $this->tokens->findActive($token);
        if ($entry === null) {
            return null;
        }

        $this->tokens->touch($entry->id);

        return $this->render($entry);
    }

    public function render(CalendarToken $entry): string
    {
        $locale = $this->localeFor($entry);
        $formatter = $this->formatter->withLocale($locale);
        $property = $this->propertyName();

        $calendar = new IcsCalendar(
            $this->trans('calendar.feed.' . $entry->scope->value, $locale, ['property' => $property]),
            $property,
        );

        foreach ($this->bookingsFor($entry) as $booking) {
            $calendar->addAllDayEvent(
                $this->uid($booking),
                $booking->range->arrival,
                // La date de fin d'un événement « toute la journée » est
                // exclusive : le jour du départ reste libre.
                $booking->range->departure,
                $this->summary($booking, $entry->scope, $locale),
                $this->description($booking, $entry->scope, $locale, $formatter),
                $this->location(),
                ['status' => $booking->status === BookingStatus::Confirmed ? 'CONFIRMED' : 'TENTATIVE'],
            );
        }

        return $calendar->render();
    }

    /**
     * Jeton actif d'un compte pour une portée, créé au besoin.
     */
    public function tokenFor(CalendarScope $scope, ?User $user, ?Booking $booking = null): string
    {
        $existing = $this->tokens->activeFor($scope, $user?->id, $booking?->id);
        if ($existing !== null) {
            // Le jeton en clair n'est plus connu : on en délivre un nouveau
            // et l'ancien cesse aussitôt de fonctionner.
            $this->tokens->revoke($existing->id);
        }

        return $this->tokens->issue(
            $scope,
            $booking === null ? ($user === null ? '' : $user->email) : $booking->reference,
            $user?->id,
            $booking?->id,
        )['token'];
    }

    // --- Contenu ---------------------------------------------------------------

    /**
     * @return list<Booking>
     */
    private function bookingsFor(CalendarToken $entry): array
    {
        if ($entry->scope->isSingleBooking()) {
            if ($entry->bookingId === null) {
                return [];
            }

            $booking = $this->bookings->find($entry->bookingId);

            return $booking === null ? [] : [$booking];
        }

        // Les séjours annulés ou refusés n'occupent rien : les publier
        // encombrerait l'agenda de dates en réalité libres.
        return $this->bookings->listing([
            BookingStatus::Hold,
            BookingStatus::Request,
            BookingStatus::ToConfirm,
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
        ]);
    }

    private function summary(Booking $booking, CalendarScope $scope, string $locale): string
    {
        if (!$scope->showsGuest()) {
            return $this->trans('calendar.event.stay', $locale, ['reference' => $booking->reference]);
        }

        return sprintf('%s — %s', $booking->guestName, $booking->reference);
    }

    private function description(
        Booking $booking,
        CalendarScope $scope,
        string $locale,
        Formatter $formatter,
    ): string {
        $lines = [
            $this->trans('booking.journey.reference', $locale) . ' : ' . $booking->reference,
            $this->trans('booking.admin.status', $locale) . ' : '
                . $this->trans($booking->status->labelKey(), $locale),
            $this->trans('booking.journey.guests', $locale) . ' : '
                . $booking->adults . ' / ' . $booking->children . ' / ' . $booking->infants,
            $this->trans('contract.field.arrival', $locale) . ' : '
                . $this->settings->string('booking.checkin_time'),
            $this->trans('contract.field.departure', $locale) . ' : '
                . $this->settings->string('booking.checkout_time'),
        ];

        if ($scope->showsGuest()) {
            $lines[] = $this->trans('booking.admin.guest', $locale) . ' : ' . $booking->guestName;
            if ($booking->guestPhone !== '') {
                $lines[] = $this->trans('contract.field.guest_phone', $locale) . ' : ' . $booking->guestPhone;
            }
        }

        if ($scope->showsAmounts()) {
            $lines[] = $this->trans('booking.quote.total', $locale) . ' : '
                . $formatter->money($booking->totalCents);
        }

        // Le flux du voyageur porte le contact du responsable local
        // (SPECIFICATIONS.md §51).
        if ($scope === CalendarScope::Customer) {
            foreach ($this->managerLines($booking, $locale) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function managerLines(Booking $booking, string $locale): array
    {
        $manager = $this->managerOf($booking);
        if ($manager === null) {
            return [];
        }

        $lines = [$this->trans('operations.manager.contact', $locale) . ' : ' . $manager->displayName()];

        if ($manager->phone !== '') {
            $lines[] = $manager->phone;
        }
        $lines[] = $manager->email;

        return $lines;
    }

    /**
     * Responsable du séjour : celui qui lui est affecté, sinon celui par
     * défaut de l'installation.
     */
    public function managerOf(Booking $booking): ?User
    {
        if ($booking->managerId !== null) {
            $manager = $this->users->findById($booking->managerId);
            if ($manager !== null) {
                return $manager;
            }
        }

        $default = $this->settings->int('operations.default_manager');

        return $default > 0 ? $this->users->findById($default) : null;
    }

    private function uid(Booking $booking): string
    {
        $host = (string) (parse_url($this->settings->string('site.public_url'), PHP_URL_HOST) ?: 'secondstay.local');

        return sprintf('booking-%d-%s@%s', $booking->id, $booking->reference, $host);
    }

    private function location(): string
    {
        $parts = array_filter([
            $this->settings->string('property.address_line1'),
            trim($this->settings->string('property.postal_code') . ' ' . $this->settings->string('property.city')),
        ], static fn (string $part): bool => trim($part) !== '');

        return implode(', ', $parts);
    }

    private function propertyName(): string
    {
        $name = $this->settings->string('property.name');

        return $name === '' ? 'SecondStay' : $name;
    }

    private function localeFor(CalendarToken $entry): string
    {
        if ($entry->scope->isSingleBooking() && $entry->bookingId !== null) {
            $booking = $this->bookings->find($entry->bookingId);
            if ($booking !== null) {
                return $booking->locale;
            }
        }

        if ($entry->userId !== null) {
            $user = $this->users->findById($entry->userId);
            if ($user !== null) {
                return $user->locale;
            }
        }

        return $this->settings->string('site.default_locale');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, $locale);
    }
}
