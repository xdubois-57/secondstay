<?php

declare(strict_types=1);

namespace SecondStay\Notification;

use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Settings\SettingsService;

/**
 * Rappels de séjour, arrivées et départs (SPECIFICATIONS.md §42).
 *
 * Ces trois événements existaient depuis l'itération notifications mais rien ne
 * les déclenchait : le produit savait les mettre en forme dans les quatre
 * langues sans jamais les envoyer. C'est le planificateur qui les déclenche
 * désormais (ARCHITECTURE.md §23).
 *
 * La règle d'envoi est volontairement stricte :
 *
 * - **un rappel par séjour et par événement**, garanti par la trace d'envoi et
 *   non par un drapeau de plus sur la réservation. Un cron qui repasse toutes
 *   les heures ne doit pas produire une rafale ;
 * - **rien n'est envoyé rétroactivement.** Une installation dont le cron n'a
 *   pas tourné pendant une semaine ne doit pas réveiller sept jours de rappels
 *   d'un coup : seuls le jour même et la date de rappel exacte comptent.
 */
final class StayReminderService
{
    private const SENT = 'sent';
    private const ALREADY_SENT = 'already_sent';
    private const NO_RECIPIENT = 'no_recipient';

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly NotificationRepository $log,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Nombre de jours entre le rappel et l'arrivée.
     */
    public function reminderDays(): int
    {
        return max(1, min(60, $this->settings->int('notification.reminder_days')));
    }

    /**
     * @return array{reminders: int, arrivals: int, departures: int, skipped: int}
     */
    public function dispatch(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');

        $reminderDay = gmdate('Y-m-d', strtotime($today . ' UTC') + $this->reminderDays() * 86400);

        $reminders = 0;
        $arrivals = 0;
        $departures = 0;
        $skipped = 0;

        foreach ($this->bookings->arrivingBetween($reminderDay, $reminderDay) as $booking) {
            match ($this->send($booking, NotificationEvent::StayReminder)) {
                self::SENT => $reminders++,
                self::NO_RECIPIENT => $skipped++,
                default => null,
            };
        }

        foreach ($this->bookings->startingOn($today) as $booking) {
            match ($this->send($booking, NotificationEvent::Arrival)) {
                self::SENT => $arrivals++,
                self::NO_RECIPIENT => $skipped++,
                default => null,
            };
        }

        foreach ($this->bookings->endingOn($today) as $booking) {
            match ($this->send($booking, NotificationEvent::Departure)) {
                self::SENT => $departures++,
                self::NO_RECIPIENT => $skipped++,
                default => null,
            };
        }

        return [
            'reminders' => $reminders,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return self::SENT|self::ALREADY_SENT|self::NO_RECIPIENT
     */
    private function send(Booking $booking, NotificationEvent $event): string
    {
        $reference = $event->value . ':' . $booking->id;

        if ($this->log->hasBeenSent($event, $reference)) {
            return self::ALREADY_SENT;
        }

        $user = $this->recipient($booking);
        if ($user === null) {
            // Un séjour ouvert sans compte — saisi depuis l'administration —
            // n'a pas de destinataire dont on connaisse les préférences de
            // canal. Le compter comme envoyé serait faux.
            return self::NO_RECIPIENT;
        }

        $this->notifications->notify($event, $user, [
            'reference' => $booking->reference,
            'booking_id' => $booking->id,
            'arrival' => $booking->range->arrivalKey(),
            'departure' => $booking->range->departureKey(),
            'action_path' => '/' . $booking->locale . '/stay/' . $booking->reference,
        ], $reference);

        return self::SENT;
    }

    private function recipient(Booking $booking): ?User
    {
        if ($booking->userId !== null) {
            return $this->users->findById($booking->userId);
        }

        return $booking->guestEmail === '' ? null : $this->users->findByEmail($booking->guestEmail);
    }
}
