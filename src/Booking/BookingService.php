<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use InvalidArgumentException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Availability\AvailabilityService;
use SecondStay\I18n\Locales;
use SecondStay\Logging\Logger;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailService;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationService;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Settings\SettingsService;

/**
 * Parcours de réservation (SPECIFICATIONS.md §25 à §28).
 *
 * Le service tient trois promesses :
 * 1. deux clients concurrents ne peuvent pas obtenir les mêmes nuits ;
 * 2. le montant enregistré est celui calculé par le serveur, jamais celui
 *    envoyé par le formulaire ;
 * 3. chaque étape importante laisse une trace dans la timeline.
 */
final class BookingService
{
    public const REFERENCE_ATTEMPTS = 8;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly BookingEventRepository $events,
        private readonly PromoCodeRepository $promos,
        private readonly WaitlistRepository $waitlist,
        private readonly StayRules $rules,
        private readonly AvailabilityService $availability,
        private readonly PriceCalculator $prices,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?NotificationService $notifications = null,
        private readonly ?MailService $mail = null,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Pose un verrou temporaire sur les nuits demandées.
     *
     * C'est la seule opération qui décide qui obtient le séjour : tout le
     * reste du parcours se déroule ensuite sur des nuits déjà tenues.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: true, booking: Booking}|array{ok: false, errors: list<string>, conflicts: list<string>}
     */
    public function hold(array $input, ?User $user = null, string $locale = Locales::FALLBACK): array
    {
        try {
            $range = DateRange::fromStrings(
                (string) ($input['arrival'] ?? ''),
                (string) ($input['departure'] ?? '')
            );
        } catch (InvalidArgumentException $exception) {
            return ['ok' => false, 'errors' => [$exception->getMessage()], 'conflicts' => []];
        }

        $adults = max(0, (int) ($input['adults'] ?? 0));
        $children = max(0, (int) ($input['children'] ?? 0));
        $infants = max(0, (int) ($input['infants'] ?? 0));

        $errors = array_merge(
            $this->rules->validateRange($range),
            $this->rules->validateGuests($adults, $children, $infants),
        );

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'conflicts' => []];
        }

        // Les nuits bloquées par l'exploitation ne sont pas dans
        // `booking_night` : elles se vérifient à part.
        $conflicts = $this->availability->conflictingNights($range);
        if ($conflicts !== []) {
            return ['ok' => false, 'errors' => ['booking.error.unavailable'], 'conflicts' => $conflicts];
        }

        $cleaning = $this->resolveCleaning($input);
        $quote = $this->prices->quote($range, $cleaning);

        $promo = $this->resolvePromo((string) ($input['promo_code'] ?? ''), $quote->accommodationCents);
        $total = max(0, $quote->totalCents - $promo['discount_cents']);

        $data = [
            'reference' => $this->uniqueReference(),
            'user_id' => $user?->id,
            'status' => BookingStatus::Hold->value,
            'arrival' => $range->arrivalKey(),
            'departure' => $range->departureKey(),
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'locale' => Locales::isSupported($locale) ? $locale : Locales::FALLBACK,
            'guest_email' => $user === null ? '' : $user->email,
            'guest_name' => $user === null ? '' : $user->displayName(),
            'guest_phone' => $user === null ? '' : $user->phone,
            'cleaning' => $cleaning ? 1 : 0,
            'promo_code' => $promo['code'],
            'accommodation_cents' => $quote->accommodationCents,
            'cleaning_cents' => $quote->cleaningCents,
            'discount_cents' => $promo['discount_cents'],
            'total_cents' => $total,
            'deposit_cents' => $this->prices->depositCents($total),
            'security_deposit_cents' => $quote->securityDepositCents,
            'currency' => $quote->currency,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($this->holdMinutes() * 60)),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $result = $this->bookings->insertWithNights($data, $range);

        if ($result['ok'] === false) {
            // Une autre transaction a gagné la course : c'est le cas nominal
            // de l'anti-double-réservation, pas une erreur technique.
            $this->logger->info('booking', 'Nuits déjà prises pendant la réservation', [
                'range' => (string) $range,
            ]);

            return [
                'ok' => false,
                'errors' => [$result['error']],
                'conflicts' => $this->bookings->occupiedNights($range->arrivalKey(), $range->lastNightKey()),
            ];
        }

        $booking = $this->bookings->find($result['id']);
        if ($booking === null) {
            return ['ok' => false, 'errors' => ['booking.error.unavailable'], 'conflicts' => []];
        }

        $this->events->record($booking->id, 'hold_created', [
            'nights' => $range->nights(),
            'expires_at' => $data['expires_at'],
        ], $user?->id, $user === null ? '' : $user->email);

        return ['ok' => true, 'booking' => $booking];
    }

    /**
     * Transforme un verrou en demande de réservation.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: true, booking: Booking}|array{ok: false, errors: list<string>}
     */
    public function submit(Booking $booking, array $input, User $user): array
    {
        if ($booking->status !== BookingStatus::Hold) {
            return ['ok' => false, 'errors' => ['booking.error.not_open']];
        }
        if ($booking->isExpired()) {
            return ['ok' => false, 'errors' => ['booking.error.hold_expired']];
        }

        $errors = [];
        $phone = trim((string) ($input['phone'] ?? $user->phone));
        if ($phone !== '' && preg_match('/^[+0-9 ().-]{6,40}$/', $phone) !== 1) {
            $errors['phone'] = 'account.error.phone_invalid';
        }
        if (($input['accept_rules'] ?? null) === null) {
            $errors['accept_rules'] = 'booking.error.rules_required';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values($errors)];
        }

        $target = $this->requiresApproval() ? BookingStatus::ToConfirm : BookingStatus::Confirmed;

        $this->bookings->update($booking->id, [
            'status' => $target->value,
            'user_id' => $user->id,
            'guest_email' => $user->email,
            'guest_name' => $user->displayName(),
            'guest_phone' => $phone,
            'message' => mb_substr(trim((string) ($input['message'] ?? '')), 0, 2000),
            // La demande n'expire plus : seul le verrou avait une durée de vie.
            'expires_at' => null,
            'confirmed_at' => $target === BookingStatus::Confirmed ? gmdate('Y-m-d H:i:s') : null,
        ]);

        $this->consumePromo($booking);

        $this->events->record($booking->id, 'requested', [
            'status' => $target->value,
        ], $user->id, $user->email);

        $updated = $this->bookings->find($booking->id);
        if ($updated === null) {
            return ['ok' => false, 'errors' => ['booking.error.not_found']];
        }

        $this->notify($updated, $user, $target === BookingStatus::Confirmed
            ? NotificationEvent::BookingConfirmed
            : NotificationEvent::BookingCreated);

        $this->audit?->record('booking.requested', 'booking', (string) $booking->id, null, [
            'reference' => $booking->reference,
            'status' => $target->value,
        ], $user->id, $user->email);

        return ['ok' => true, 'booking' => $updated];
    }

    /**
     * Change l'état principal d'un séjour, en respectant le workflow.
     *
     * @return array{ok: true, booking: Booking}|array{ok: false, errors: list<string>}
     */
    public function transition(
        Booking $booking,
        BookingStatus $target,
        ?User $actor = null,
        string $reason = '',
    ): array {
        if (!$booking->status->canTransitionTo($target)) {
            return ['ok' => false, 'errors' => ['booking.error.transition']];
        }

        $data = ['status' => $target->value];

        if ($target === BookingStatus::Confirmed) {
            $data['confirmed_at'] = gmdate('Y-m-d H:i:s');
            $data['expires_at'] = null;
        }

        $freed = [];
        if (!$target->occupiesNights()) {
            $data['cancelled_at'] = gmdate('Y-m-d H:i:s');
            $freed = $booking->range->nightKeys();
        }

        $this->bookings->update($booking->id, $data);

        if ($freed !== []) {
            $this->bookings->releaseNights($booking->id);
            $this->releasePromo($booking);
        }

        $this->events->record($booking->id, 'status_' . $target->value, [
            'from' => $booking->status->value,
            'reason' => mb_substr($reason, 0, 190),
        ], $actor?->id, $actor === null ? '' : $actor->email);

        $this->audit?->record('booking.' . $target->value, 'booking', (string) $booking->id, [
            'status' => $booking->status->value,
        ], ['status' => $target->value], $actor?->id, $actor === null ? '' : $actor->email);

        $updated = $this->bookings->find($booking->id);
        if ($updated === null) {
            return ['ok' => false, 'errors' => ['booking.error.not_found']];
        }

        // Des nuits redeviennent libres : la liste d'attente est prévenue.
        if ($freed !== []) {
            $this->notifyWaitlist($freed);
        }

        return ['ok' => true, 'booking' => $updated];
    }

    /**
     * Libère les verrous expirés.
     *
     * @return int nombre de verrous libérés
     */
    public function releaseExpiredHolds(): int
    {
        $released = 0;

        foreach ($this->bookings->expiredHolds() as $hold) {
            $this->bookings->update($hold->id, [
                'status' => BookingStatus::Cancelled->value,
                'cancelled_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => null,
            ]);
            $this->bookings->releaseNights($hold->id);
            $this->events->record($hold->id, 'hold_expired');
            $released++;
        }

        if ($released > 0) {
            $this->logger->info('booking', 'Verrous de réservation expirés libérés', ['count' => $released]);
        }

        return $released;
    }

    /**
     * Inscription à la liste d'attente sur des dates indisponibles.
     *
     * @return array{ok: bool, error: string}
     */
    public function joinWaitlist(DateRange $range, string $email, string $locale, ?User $user = null): array
    {
        if (!$range->isValid()) {
            return ['ok' => false, 'error' => 'booking.error.invalid_range'];
        }

        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'account.error.email_invalid'];
        }

        $this->waitlist->add($email, $range, $locale, $user?->id);
        $this->logger->info('booking', 'Inscription en liste d’attente', ['range' => (string) $range]);

        return ['ok' => true, 'error' => ''];
    }

    public function holdMinutes(): int
    {
        return max(5, $this->settings->int('booking.hold_minutes'));
    }

    public function requiresApproval(): bool
    {
        return $this->settings->bool('booking.requires_approval');
    }

    // --- Internes -----------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     */
    private function resolveCleaning(array $input): bool
    {
        return match ($this->prices->cleaningMode()) {
            PriceCalculator::CLEANING_NONE => false,
            PriceCalculator::CLEANING_OPTIONAL => ($input['cleaning'] ?? null) !== null
                && (bool) $input['cleaning'],
            default => true,
        };
    }

    /**
     * @return array{code: string, discount_cents: int, error: string}
     */
    private function resolvePromo(string $code, int $accommodationCents): array
    {
        $normalised = PromoCode::normalise($code);
        if ($normalised === '') {
            return ['code' => '', 'discount_cents' => 0, 'error' => ''];
        }

        $promo = $this->promos->find($normalised);
        if ($promo === null) {
            return ['code' => '', 'discount_cents' => 0, 'error' => 'booking.promo.unknown'];
        }

        $refusal = $promo->refusalReason(gmdate('Y-m-d'));
        if ($refusal !== null) {
            return ['code' => '', 'discount_cents' => 0, 'error' => $refusal];
        }

        return [
            'code' => $promo->code,
            'discount_cents' => $promo->discountFor($accommodationCents),
            'error' => '',
        ];
    }

    private function consumePromo(Booking $booking): void
    {
        if ($booking->promoCode === '') {
            return;
        }

        $promo = $this->promos->find($booking->promoCode);
        if ($promo !== null && !$this->promos->consume($promo->id)) {
            // La limite d'usage vient d'être atteinte par quelqu'un d'autre :
            // le séjour reste valide, la remise est retirée.
            $this->bookings->update($booking->id, [
                'promo_code' => '',
                'discount_cents' => 0,
                'total_cents' => $booking->totalCents + $booking->discountCents,
                'deposit_cents' => $this->prices->depositCents($booking->totalCents + $booking->discountCents),
            ]);
            $this->events->record($booking->id, 'promo_exhausted', ['code' => $booking->promoCode]);
        }
    }

    private function releasePromo(Booking $booking): void
    {
        if ($booking->promoCode === '' || $booking->status === BookingStatus::Hold) {
            return;
        }

        $promo = $this->promos->find($booking->promoCode);
        if ($promo !== null) {
            $this->promos->release($promo->id);
        }
    }

    private function uniqueReference(): string
    {
        for ($attempt = 0; $attempt < self::REFERENCE_ATTEMPTS; $attempt++) {
            $reference = BookingReference::generate();
            if (!$this->bookings->referenceExists($reference)) {
                return $reference;
            }
        }

        // Improbable, mais on ne renvoie jamais une référence en double :
        // la contrainte d'unicité ferait échouer la réservation.
        return BookingReference::generate();
    }

    private function notify(Booking $booking, User $user, NotificationEvent $event): void
    {
        $this->notifications?->notify($event, $user, [
            'reference' => $booking->reference,
            'action_path' => '/' . $booking->locale . '/account',
        ], 'booking:' . $booking->id);
    }

    /**
     * Prévient la liste d'attente que des nuits se sont libérées
     * (SPECIFICATIONS.md §28).
     *
     * L'e-mail part dans la langue choisie à l'inscription. Une inscription
     * n'est prévenue qu'une fois : elle est marquée avant l'envoi, de sorte
     * qu'un échec d'envoi ne produise pas une rafale de rappels.
     *
     * @param list<string> $freedNights
     *
     * @return int inscriptions prévenues
     */
    private function notifyWaitlist(array $freedNights): int
    {
        $notified = 0;

        foreach ($this->waitlist->matching($freedNights) as $entry) {
            $this->waitlist->markNotified((int) $entry['id']);

            $email = (string) $entry['email'];
            $locale = (string) $entry['locale'];

            if ($this->mail !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $this->mail->send('waitlist_available', new MailAddress($email), $locale, [
                    'arrival' => (string) $entry['arrival'],
                    'departure' => (string) $entry['departure'],
                    'availability_path' => '/' . $locale . '/availability?month='
                        . substr((string) $entry['arrival'], 0, 7),
                ]);
            }

            $notified++;
        }

        if ($notified > 0) {
            $this->logger->info('booking', 'Liste d’attente prévenue', ['count' => $notified]);
        }

        return $notified;
    }
}
