<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use InvalidArgumentException;
use SecondStay\Auth\Role;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\QuoteService;
use SecondStay\Booking\StayRules;
use SecondStay\Calendar\CalendarScope;
use SecondStay\Calendar\CalendarService;
use SecondStay\Contract\ContractService;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentRepository;
use SecondStay\Imap\InboundMailService;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Payment\PaymentService;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;

/**
 * Parcours public de réservation (SPECIFICATIONS.md §25).
 *
 * Dates → voyageurs → prix → authentification → informations → règles →
 * confirmation. Le verrou est posé dès le récapitulatif : sans lui, deux
 * visiteurs pourraient remplir le même formulaire en parallèle et n'apprendre
 * qu'à la fin qu'un seul obtient le séjour.
 */
final class BookingController extends AbstractController
{
    /**
     * Récapitulatif : dates, voyageurs et prix, avant authentification.
     *
     * @param array<string, string> $params
     */
    public function summary(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;

        $input = [
            'arrival' => (string) ($request->input('arrival') ?? $request->query('arrival') ?? ''),
            'departure' => (string) ($request->input('departure') ?? $request->query('departure') ?? ''),
            'adults' => (int) ($request->input('adults') ?? $request->query('adults') ?? 2),
            'children' => (int) ($request->input('children') ?? $request->query('children') ?? 0),
            'infants' => (int) ($request->input('infants') ?? $request->query('infants') ?? 0),
            'promo_code' => (string) ($request->input('promo_code') ?? ''),
        ];

        if ($this->cleaningIsOptional()) {
            $input['cleaning'] = $request->input('cleaning') !== null;
        }

        $evaluation = $this->container->get(QuoteService::class)->evaluate($input);

        return $this->renderSummary($context, $input, $evaluation);
    }

    /**
     * Pose le verrou et ouvre le formulaire de finalisation.
     *
     * @param array<string, string> $params
     */
    public function hold(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;

        $input = [
            'arrival' => (string) $request->input('arrival', ''),
            'departure' => (string) $request->input('departure', ''),
            'adults' => (int) $request->input('adults', '2'),
            'children' => (int) $request->input('children', '0'),
            'infants' => (int) $request->input('infants', '0'),
            'promo_code' => (string) $request->input('promo_code', ''),
        ];

        if ($this->cleaningIsOptional()) {
            $input['cleaning'] = $request->input('cleaning') !== null;
        }

        $result = $this->container->get(BookingService::class)->hold(
            $input,
            $this->auth()->user(),
            $context->locale,
        );

        if ($result['ok'] === false) {
            $evaluation = $this->container->get(QuoteService::class)->evaluate($input);
            $evaluation['errors'] = $result['errors'];
            $evaluation['conflicts'] = $result['conflicts'];
            $evaluation['ok'] = false;

            return $this->renderSummary($context, $input, $evaluation, 422);
        }

        $this->session()->set(self::HOLD_KEY, $result['booking']->reference);

        return $this->redirectToRoute($context, 'booking.finalise');
    }

    public const HOLD_KEY = '_booking_hold';

    /**
     * Formulaire de finalisation : informations et acceptation des règles.
     *
     * @param array<string, string> $params
     */
    public function finalise(RequestContext $context, array $params = []): Response
    {
        $booking = $this->currentHold();
        if ($booking === null) {
            $this->flashError('booking.error.hold_expired');

            return $this->redirectToRoute($context, 'page.show', ['slug' => 'availability']);
        }

        // L'authentification est exigée ici, pas plus tôt : le visiteur voit
        // d'abord son prix.
        if (!$this->auth()->isAuthenticated()) {
            $this->flashError('booking.error.sign_in');

            return $this->redirectToRoute($context, 'login');
        }

        return $this->renderFinalise($context, $booking, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function submit(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->currentHold();
        if ($booking === null) {
            $this->flashError('booking.error.hold_expired');

            return $this->redirectToRoute($context, 'page.show', ['slug' => 'availability']);
        }

        $result = $this->container->get(BookingService::class)->submit($booking, [
            'accept_rules' => $context->request->input('accept_rules'),
            'phone' => $context->request->input('phone'),
            'message' => $context->request->input('message'),
        ], $user);

        if ($result['ok'] === false) {
            return $this->renderFinalise($context, $booking, $result['errors'], 422);
        }

        $this->session()->remove(self::HOLD_KEY);
        $this->flashSuccess('booking.journey.submitted');

        return $this->redirectToRoute($context, 'booking.show', ['reference' => $result['booking']->reference]);
    }

    /**
     * Détail d'un séjour, réservé à son titulaire.
     *
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        // Une référence n'est pas un secret : elle ne remplace jamais
        // l'authentification.
        if ($booking === null || ($booking->userId !== $user->id && !$user->isOperational())) {
            throw new NotFoundException('Réservation introuvable.');
        }

        // L'échéancier est construit à la volée : un séjour créé avant
        // l'activation des paiements doit tout de même en afficher un.
        $payments = $this->container->get(PaymentService::class)->schedule($booking);

        $paid = 0;
        $due = 0;
        foreach ($payments as $payment) {
            $paid += $payment->netCents();
            $due += $payment->outstandingCents();
        }

        $settings = $this->settings();
        $calendarToken = $this->takeIssuedToken();

        // Le contrat est produit dès la première consultation : le voyageur
        // doit pouvoir le lire sans attendre une action de l'administration.
        $contracts = $this->container->get(ContractService::class);
        $contracts->contractFor($booking);

        return $this->render('booking/show.html.twig', [
            'meta_title' => $this->trans('booking.journey.title'),
            'booking' => $booking,
            'timeline' => $this->container->get(BookingEventRepository::class)
                ->forBooking($booking->id),
            'payments' => $payments,
            'paid_cents' => $paid,
            'due_cents' => $due,
            'contract_acceptance' => $contracts->acceptanceFor($booking),
            'documents' => $this->container->get(DocumentRepository::class)->forBooking($booking->id),
            'reply_address' => $settings->bool('imap.enabled')
                ? $this->container->get(InboundMailService::class)->replyAddressFor($booking)
                : '',
            'calendar_enabled' => $settings->bool('operations.calendar_enabled'),
            'calendar_token' => $calendarToken,
            'calendar_url' => $calendarToken === '' ? '' : $this->feedUrl($context, $calendarToken),
            'manager' => $this->container->get(CalendarService::class)->managerOf($booking),
            'provider_ready' => $this->container->get(PaymentProvider::class)->isConfigured(),
            'transfer_available' => $settings->bool('payment.transfer_enabled')
                && $settings->string('payment.iban') !== '',
        ]);
    }

    /**
     * Délivre au voyageur un lien de calendrier pour son séjour.
     *
     * Le jeton n'est montré qu'une fois : le régénérer révoque le précédent,
     * ce qui est exactement ce qu'on attend d'un lien partagé par erreur
     * (SPECIFICATIONS.md §51).
     *
     * @param array<string, string> $params
     */
    public function calendarLink(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null || $booking->userId !== $user->id) {
            throw new NotFoundException('Réservation introuvable.');
        }

        if (!$this->settings()->bool('operations.calendar_enabled')) {
            $this->flashError('calendar.error.disabled');

            return $this->redirectToRoute($context, 'booking.show', ['reference' => $booking->reference]);
        }

        $token = $this->container->get(CalendarService::class)
            ->tokenFor(CalendarScope::Customer, $user, $booking);

        $this->session()->set('calendar_token', $token);
        $this->flashSuccess('calendar.created');

        return $this->redirectToRoute($context, 'booking.show', ['reference' => $booking->reference]);
    }

    /**
     * Inscription à la liste d'attente sur des dates indisponibles.
     *
     * @param array<string, string> $params
     */
    public function joinWaitlist(RequestContext $context, array $params = []): Response
    {
        if (!$this->settings()->bool('booking.allow_waitlist')) {
            throw new NotFoundException('Liste d’attente désactivée.');
        }

        $user = $this->auth()->user();
        $email = $user === null ? (string) $context->request->input('email', '') : $user->email;

        try {
            $range = DateRange::fromStrings(
                (string) $context->request->input('arrival', ''),
                (string) $context->request->input('departure', ''),
            );
        } catch (InvalidArgumentException) {
            $this->flashError('booking.error.invalid_date');

            return $this->redirectToRoute($context, 'page.show', ['slug' => 'availability']);
        }

        $result = $this->container->get(BookingService::class)
            ->joinWaitlist($range, $email, $context->locale, $user);

        $result['ok'] ? $this->flashSuccess('booking.waitlist.joined') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'page.show', ['slug' => 'availability']);
    }

    /**
     * Adresse complète du flux, telle qu'on la colle dans un agenda.
     */
    private function feedUrl(RequestContext $context, string $token): string
    {
        $base = rtrim($this->settings()->string('site.public_url'), '/');
        if ($base === '') {
            $base = rtrim($context->request->baseUrl(), '/');
        }

        return $base . $this->router()->path('calendar.feed', ['token' => $token]);
    }

    /**
     * Jeton de calendrier fraîchement délivré, affiché une seule fois.
     */
    private function takeIssuedToken(): string
    {
        $token = $this->session()->string('calendar_token');
        if ($token !== '') {
            $this->session()->remove('calendar_token');
        }

        return $token;
    }

    // --- Rendu ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $evaluation
     */
    private function renderSummary(
        RequestContext $context,
        array $input,
        array $evaluation,
        int $status = 200,
    ): Response {
        /** @var list<string> $errorKeys */
        $errorKeys = $evaluation['errors'];

        return $this->render('booking/summary.html.twig', [
            'meta_title' => $this->trans('booking.journey.title'),
            'input' => $input,
            'quote' => $evaluation['quote'],
            'rules' => $evaluation['rules'],
            'conflicts' => $evaluation['conflicts'],
            'errors' => array_map(fn (string $key): string => $this->trans($key), $errorKeys),
            'available' => $evaluation['ok'],
            'cleaning_optional' => $this->cleaningIsOptional(),
            'hold_minutes' => $this->container->get(BookingService::class)->holdMinutes(),
            'waitlist_enabled' => $this->settings()->bool('booking.allow_waitlist'),
            'is_authenticated' => $this->auth()->isAuthenticated(),
        ], $status);
    }

    /**
     * @param list<string> $errors
     */
    private function renderFinalise(
        RequestContext $context,
        \SecondStay\Booking\Booking $booking,
        array $errors,
        int $status = 200,
    ): Response {
        return $this->render('booking/finalise.html.twig', [
            'meta_title' => $this->trans('booking.journey.title'),
            'booking' => $booking,
            'rules' => $this->container->get(StayRules::class)->summary(),
            'errors' => array_map(fn (string $key): string => $this->trans($key), $errors),
            'hold_minutes' => $this->container->get(BookingService::class)->holdMinutes(),
        ], $status);
    }

    private function cleaningIsOptional(): bool
    {
        return $this->container->get(PriceCalculator::class)->cleaningMode()
            === PriceCalculator::CLEANING_OPTIONAL;
    }

    /**
     * Verrou en cours pour cette session, s'il est toujours valide.
     */
    private function currentHold(): ?\SecondStay\Booking\Booking
    {
        $reference = $this->session()->string(self::HOLD_KEY);
        if ($reference === '') {
            return null;
        }

        $booking = $this->container->get(BookingRepository::class)->findByReference($reference);

        if ($booking === null || $booking->status !== BookingStatus::Hold || $booking->isExpired()) {
            $this->session()->remove(self::HOLD_KEY);

            return null;
        }

        return $booking;
    }
}
