<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Calendar\CalendarService;
use SecondStay\I18n\Locales;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;

/**
 * Construit « Mon séjour aujourd'hui » et gère les liens invité.
 *
 * Deux règles gouvernent ce service :
 *
 * 1. les **codes d'accès** ne sortent que pendant la fenêtre du séjour. Un
 *    code de boîte à clés publié un mois à l'avance, ou laissé accessible
 *    après le départ, n'est plus un code d'accès ;
 * 2. un **lien invité** ne donne jamais accès à autre chose qu'aux
 *    informations pratiques : ni montants, ni documents, ni compte
 *    (SPECIFICATIONS.md §46).
 */
final class StayService
{
    /** Durée de validité par défaut d'un lien invité, en jours après le départ. */
    public const GUEST_LINK_GRACE_DAYS = 2;

    public function __construct(
        private readonly StayInfoRepository $blocks,
        private readonly StaySecretRepository $secrets,
        private readonly GuestLinkRepository $links,
        private readonly BookingRepository $bookings,
        private readonly CalendarService $calendar,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Vue du séjour pour son titulaire.
     */
    public function forBooking(Booking $booking, ?string $locale = null, ?string $today = null): StayView
    {
        return $this->build($booking, $locale ?? $booking->locale, false, $today);
    }

    /**
     * Vue du séjour derrière un lien invité, ou `null` si le lien ne vaut plus.
     */
    public function forGuestToken(string $token, ?string $locale = null, ?string $today = null): ?StayView
    {
        $link = $this->links->findUsable($token);
        if ($link === null) {
            return null;
        }

        $booking = $this->bookings->find($link->bookingId);
        if ($booking === null) {
            return null;
        }

        $this->links->touch($link->id);

        return $this->build($booking, $locale ?? $link->locale, true, $today);
    }

    /**
     * Délivre un lien invité et renvoie son jeton, une seule fois.
     *
     * @return array{ok: bool, token: string, error: string}
     */
    public function issueGuestLink(
        Booking $booking,
        string $locale,
        string $label = '',
        ?User $actor = null,
    ): array {
        if (!$booking->status->occupiesNights()) {
            // Un séjour annulé n'a pas d'invités.
            return ['ok' => false, 'token' => '', 'error' => 'stay.error.not_active'];
        }

        $locale = Locales::isSupported($locale) ? $locale : $booking->locale;

        $result = $this->links->issue(
            $booking->id,
            $this->guestLinkExpiry($booking),
            $locale,
            $label,
            $actor?->id,
        );

        $this->audit?->record('stay.guest_link_issued', 'booking', (string) $booking->id, null, [
            'link' => $result['id'],
            'locale' => $locale,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        $this->logger->info('stay', 'Lien invité délivré', ['booking' => $booking->id]);

        return ['ok' => true, 'token' => $result['token'], 'error' => ''];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function revokeGuestLink(int $linkId, ?User $actor = null): array
    {
        $link = $this->links->find($linkId);
        if ($link === null || $link->isRevoked()) {
            return ['ok' => false, 'error' => 'stay.error.link_not_found'];
        }

        $this->links->revoke($link->id);

        $this->audit?->record('stay.guest_link_revoked', 'booking', (string) $link->bookingId, null, [
            'link' => $link->id,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Expiration d'un lien : la fin du séjour, plus un délai de grâce.
     *
     * Un lien qui ne finirait jamais serait un lien que l'on oublie de
     * révoquer.
     */
    public function guestLinkExpiry(Booking $booking): string
    {
        return $booking->range->departure
            ->modify('+' . self::GUEST_LINK_GRACE_DAYS . ' days')
            ->format('Y-m-d 23:59:59');
    }

    // --- Construction ------------------------------------------------------------

    private function build(Booking $booking, string $locale, bool $isGuest, ?string $today): StayView
    {
        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;
        $timezone = $this->timezone();
        $phase = StayPhase::of($booking, $timezone, $today);

        return new StayView(
            $booking,
            $phase,
            $locale,
            $this->blocksFor($locale),
            // Les codes d'accès ne sortent que pendant le séjour.
            $phase->isOnSite() ? $this->secrets->all() : [],
            $this->calendar->managerOf($booking),
            $this->settings->string('booking.checkin_time'),
            $this->settings->string('booking.checkout_time'),
            $isGuest,
            $this->nightsUntilArrival($booking, $timezone, $today),
        );
    }

    /**
     * Blocs de la langue demandée, complétés par ceux de la langue par défaut.
     *
     * Un livret partiellement traduit vaut mieux qu'un livret vide : le
     * voyageur doit trouver l'information, même si elle n'est pas encore dans
     * sa langue.
     *
     * @return list<StayInfoBlock>
     */
    private function blocksFor(string $locale): array
    {
        $blocks = [];
        foreach ($this->blocks->published($locale) as $block) {
            if (!$block->isEmpty()) {
                $blocks[$block->code] = $block;
            }
        }

        $fallback = $this->settings->string('site.default_locale');
        if ($fallback !== '' && $fallback !== $locale) {
            foreach ($this->blocks->published($fallback) as $block) {
                if (!isset($blocks[$block->code]) && !$block->isEmpty()) {
                    $blocks[$block->code] = $block;
                }
            }
        }

        $ordered = array_values($blocks);
        usort(
            $ordered,
            static fn (StayInfoBlock $a, StayInfoBlock $b): int => [$a->position, $a->code] <=> [$b->position, $b->code]
        );

        return $ordered;
    }

    private function nightsUntilArrival(Booking $booking, string $timezone, ?string $today): int
    {
        try {
            $zone = new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }

        $now = $today === null
            ? new DateTimeImmutable('today', $zone)
            : new DateTimeImmutable($today . ' 00:00:00', $zone);

        $arrival = new DateTimeImmutable($booking->range->arrival->format('Y-m-d') . ' 00:00:00', $zone);

        return (int) $now->diff($arrival)->format('%r%a');
    }

    private function timezone(): string
    {
        $timezone = $this->settings->string('site.timezone');

        return $timezone === '' ? 'Europe/Paris' : $timezone;
    }
}
