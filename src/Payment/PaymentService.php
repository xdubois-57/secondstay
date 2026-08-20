<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\BookingStatus;
use SecondStay\Logging\Logger;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationService;
use SecondStay\Settings\SettingsService;
use SecondStay\Tax\TouristTaxCalculator;

/**
 * Échéancier, encaissements et remboursements (SPECIFICATIONS.md §29 à §34).
 *
 * Deux règles gouvernent tout le module :
 *
 * 1. l'application ne croit jamais le corps d'un webhook ; elle y lit un
 *    identifiant, puis relit l'état chez le fournisseur ;
 * 2. seul un paiement **confirmé par le fournisseur** confirme une
 *    réservation ; un virement classique ne le fait pas, sauf validation
 *    manuelle explicite (SPECIFICATIONS.md §30).
 */
final class PaymentService
{
    public const METHOD_PROVIDER = 'provider';
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_MANUAL = 'manual';

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly WebhookRepository $webhooks,
        private readonly BookingRepository $bookings,
        private readonly BookingEventRepository $bookingEvents,
        private readonly BookingService $bookingService,
        private readonly PaymentProvider $provider,
        private readonly SettingsService $settings,
        private readonly TouristTaxCalculator $tax,
        private readonly Logger $logger,
        private readonly ?UserRepository $users = null,
        private readonly ?NotificationService $notifications = null,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Construit l'échéancier d'un séjour : acompte, solde, caution et taxe.
     *
     * L'appel est idempotent : rejouer la planification ne duplique aucun
     * composant.
     *
     * @return list<Payment>
     */
    public function schedule(Booking $booking): array
    {
        // Un séjour annulé ou refusé ne doit plus rien : la consultation de
        // sa fiche ne doit surtout pas lui recréer un échéancier. Un verrou
        // non finalisé n'en a pas encore.
        if (!$booking->status->occupiesNights() || $booking->status === BookingStatus::Hold) {
            return $this->payments->forBooking($booking->id);
        }

        $balanceDue = $this->balanceDueDate($booking);

        $components = [];

        if ($booking->depositCents > 0) {
            $components[] = [PaymentKind::Deposit, $booking->depositCents, gmdate('Y-m-d'), 'booking.journey.deposit'];
        }

        $balance = $booking->balanceCents();
        if ($balance > 0) {
            $components[] = [PaymentKind::Balance, $balance, $balanceDue, 'booking.journey.balance'];
        }

        if ($booking->securityDepositCents > 0) {
            $components[] = [
                PaymentKind::SecurityDeposit,
                $booking->securityDepositCents,
                $balanceDue,
                'booking.rates.security_deposit',
            ];
        }

        $taxCents = $this->tax->forBooking($booking);
        if ($taxCents > 0) {
            $components[] = [PaymentKind::TouristTax, $taxCents, $balanceDue, 'payment.kind.tourist_tax'];
        }

        foreach ($components as [$kind, $amount, $due, $description]) {
            if ($this->payments->findKind($booking->id, $kind) !== null) {
                continue;
            }

            $id = $this->payments->create([
                'booking_id' => $booking->id,
                'kind' => $kind->value,
                'status' => PaymentStatus::Pending->value,
                'amount_cents' => $amount,
                'currency' => $booking->currency,
                'method' => self::METHOD_PROVIDER,
                'due_on' => $due,
                'description' => $description,
                'hold_status' => $kind === PaymentKind::SecurityDeposit
                    ? HoldStatus::ToPay->value
                    : HoldStatus::None->value,
            ]);

            $this->payments->recordEvent($id, 'scheduled', ['amount_cents' => $amount, 'due_on' => $due]);
        }

        return $this->payments->forBooking($booking->id);
    }

    /**
     * Ouvre un paiement chez le fournisseur et renvoie l'URL de redirection.
     *
     * @return array{ok: bool, redirect_url: string, error: string}
     */
    public function start(Payment $payment, string $returnUrl, string $webhookUrl): array
    {
        if ($payment->status->isSettled()) {
            return ['ok' => false, 'redirect_url' => '', 'error' => 'payment.error.already_settled'];
        }

        if (!$this->provider->isConfigured()) {
            return ['ok' => false, 'redirect_url' => '', 'error' => 'payment.error.not_configured'];
        }

        $booking = $this->bookings->find($payment->bookingId);
        if ($booking === null) {
            return ['ok' => false, 'redirect_url' => '', 'error' => 'booking.error.not_found'];
        }

        $result = $this->provider->create(
            $payment->amountCents,
            $payment->currency,
            $booking->reference . ' — ' . $payment->kind->value,
            $returnUrl,
            $webhookUrl,
            ['booking' => $booking->reference, 'payment' => (string) $payment->id],
        );

        if ($result['ok'] === false) {
            $this->payments->recordEvent($payment->id, 'start_failed', ['error' => $result['error']]);

            return ['ok' => false, 'redirect_url' => '', 'error' => $result['error']];
        }

        $this->payments->update($payment->id, [
            'provider' => $this->provider->name(),
            'provider_reference' => $result['reference'],
            'method' => self::METHOD_PROVIDER,
        ]);

        $this->payments->recordEvent($payment->id, 'started', ['provider' => $this->provider->name()]);

        return ['ok' => true, 'redirect_url' => $result['redirect_url'], 'error' => ''];
    }

    /**
     * Traite une notification de paiement.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, status: string, error: string}
     */
    public function handleWebhook(array $payload, string $rawBody): array
    {
        $reference = $this->provider->referenceFromWebhook($payload, $rawBody);
        if ($reference === null) {
            return ['ok' => false, 'status' => 'invalid', 'error' => 'payment.error.invalid_webhook'];
        }

        // Idempotence : un même événement rejoué ne produit rien de plus.
        $event = $this->webhooks->receive($this->provider->name(), $reference, $rawBody);
        if ($event['first'] === false) {
            $this->logger->info('payment', 'Webhook déjà traité', ['reference' => $reference]);

            return ['ok' => true, 'status' => 'duplicate', 'error' => ''];
        }

        $payment = $this->payments->findByReference($this->provider->name(), $reference);
        if ($payment === null) {
            $this->webhooks->markIgnored($event['id'], 'unknown_payment');

            return ['ok' => true, 'status' => 'unknown', 'error' => ''];
        }

        // On ne croit pas le corps de la notification : on relit l'état.
        $remote = $this->provider->fetch($reference);
        if ($remote['ok'] === false) {
            $this->webhooks->markFailed($event['id'], $remote['error']);

            return ['ok' => false, 'status' => 'unreachable', 'error' => $remote['error']];
        }

        $applied = $this->applyStatus($payment, $remote['status'], $remote['amount_cents']);
        $this->webhooks->markProcessed($event['id']);

        return ['ok' => true, 'status' => $applied ? 'applied' : 'ignored', 'error' => ''];
    }

    /**
     * Applique un état constaté chez le fournisseur.
     *
     * Les notifications peuvent arriver dans le désordre : un état définitif
     * n'est jamais remplacé par un état antérieur.
     */
    public function applyStatus(Payment $payment, PaymentStatus $status, int $amountCents = 0): bool
    {
        if ($payment->status === $status) {
            return false;
        }

        if (!$payment->status->canBeReplacedBy($status)) {
            $this->payments->recordEvent($payment->id, 'status_ignored', [
                'current' => $payment->status->value,
                'received' => $status->value,
            ]);

            return false;
        }

        // Un montant différent de celui attendu n'est jamais accepté
        // silencieusement.
        if ($status === PaymentStatus::Paid && $amountCents > 0 && $amountCents !== $payment->amountCents) {
            $this->payments->recordEvent($payment->id, 'amount_mismatch', [
                'expected' => $payment->amountCents,
                'received' => $amountCents,
            ]);
            $this->logger->warning('payment', 'Montant inattendu sur un paiement', [
                'payment' => $payment->id,
            ]);

            return false;
        }

        $data = ['status' => $status->value];
        if ($status === PaymentStatus::Paid) {
            $data['paid_at'] = gmdate('Y-m-d H:i:s');
            if ($payment->kind === PaymentKind::SecurityDeposit) {
                $data['hold_status'] = HoldStatus::Received->value;
            }
        }

        $this->payments->update($payment->id, $data);
        $this->payments->recordEvent($payment->id, 'status_' . $status->value, [
            'from' => $payment->status->value,
        ]);

        if ($status === PaymentStatus::Paid) {
            $this->afterPayment($payment);
        }

        return true;
    }

    /**
     * Encaissement constaté hors fournisseur (virement, espèces).
     *
     * Il ne confirme jamais un séjour tout seul : c'est une validation
     * manuelle explicite (SPECIFICATIONS.md §30).
     *
     * @return array{ok: bool, error: string}
     */
    public function recordManualPayment(Payment $payment, User $actor, bool $confirmBooking = false): array
    {
        if ($payment->status->isSettled()) {
            return ['ok' => false, 'error' => 'payment.error.already_settled'];
        }

        $data = [
            'status' => PaymentStatus::Paid->value,
            'method' => self::METHOD_TRANSFER,
            'paid_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($payment->kind === PaymentKind::SecurityDeposit) {
            $data['hold_status'] = HoldStatus::Received->value;
        }

        $this->payments->update($payment->id, $data);
        $this->payments->recordEvent($payment->id, 'manual_payment', ['actor' => $actor->email]);

        $this->audit?->record('payment.manual', 'payment', (string) $payment->id, null, [
            'amount_cents' => $payment->amountCents,
            'confirm_booking' => $confirmBooking,
        ], $actor->id, $actor->email);

        if ($confirmBooking) {
            $this->confirmBookingFor($payment, $actor);
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function refund(Payment $payment, int $amountCents, User $actor, string $reason = ''): array
    {
        if (!$payment->status->isSettled()) {
            return ['ok' => false, 'error' => 'payment.error.not_settled'];
        }

        $remaining = $payment->amountCents - $payment->refundedCents;
        if ($amountCents <= 0 || $amountCents > $remaining) {
            return ['ok' => false, 'error' => 'payment.error.refund_amount'];
        }

        if ($payment->method === self::METHOD_PROVIDER && $payment->providerReference !== '') {
            $result = $this->provider->refund($payment->providerReference, $amountCents, $reason);
            if ($result['ok'] === false) {
                return ['ok' => false, 'error' => $result['error']];
            }
        }

        $refunded = $payment->refundedCents + $amountCents;
        $status = $refunded >= $payment->amountCents
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        $data = [
            'refunded_cents' => $refunded,
            'status' => $status->value,
            'refunded_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($payment->kind === PaymentKind::SecurityDeposit) {
            $data['hold_status'] = $refunded >= $payment->amountCents
                ? HoldStatus::Returned->value
                : HoldStatus::PartiallyRetained->value;
        }

        $this->payments->update($payment->id, $data);
        $this->payments->recordEvent($payment->id, 'refunded', [
            'amount_cents' => $amountCents,
            'reason' => mb_substr($reason, 0, 190),
        ]);

        $this->audit?->record('payment.refunded', 'payment', (string) $payment->id, null, [
            'amount_cents' => $amountCents,
        ], $actor->id, $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Fait passer une caution reçue à « à restituer ».
     *
     * @return array{ok: bool, error: string}
     */
    public function markDepositToReturn(Payment $payment, User $actor): array
    {
        if (!$payment->holdStatus->canTransitionTo(HoldStatus::ToReturn)) {
            return ['ok' => false, 'error' => 'payment.error.hold_transition'];
        }

        $this->payments->update($payment->id, ['hold_status' => HoldStatus::ToReturn->value]);
        $this->payments->recordEvent($payment->id, 'hold_to_return', ['actor' => $actor->email]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Date d'échéance du solde (SPECIFICATIONS.md §31).
     *
     * Règle par défaut : un mois avant l'arrivée, immédiat si la réservation
     * est plus tardive que cela.
     */
    public function balanceDueDate(Booking $booking, ?string $today = null): string
    {
        $today ??= gmdate('Y-m-d');
        $days = max(0, $this->settings->int('payment.balance_days_before'));

        $due = $booking->range->arrival->modify('-' . $days . ' days')->format('Y-m-d');

        return $due < $today ? $today : $due;
    }

    // --- Internes -------------------------------------------------------------

    private function afterPayment(Payment $payment): void
    {
        $booking = $this->bookings->find($payment->bookingId);
        if ($booking === null) {
            return;
        }

        $this->bookingEvents->record($booking->id, 'payment_' . $payment->kind->value, [
            'amount_cents' => $payment->amountCents,
        ]);

        // Seul un acompte confirmé par le fournisseur confirme le séjour.
        if ($payment->kind->confirmsBooking() && $payment->method === self::METHOD_PROVIDER) {
            $this->confirmBooking($booking);
        }

        $this->notifyPayment($booking, $payment);
    }

    private function confirmBookingFor(Payment $payment, User $actor): void
    {
        $booking = $this->bookings->find($payment->bookingId);
        if ($booking !== null) {
            $this->confirmBooking($booking, $actor);
        }
    }

    private function confirmBooking(Booking $booking, ?User $actor = null): void
    {
        if (!$booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            return;
        }

        $this->bookingService->transition($booking, BookingStatus::Confirmed, $actor, 'payment');
        $this->logger->info('payment', 'Séjour confirmé par le paiement', ['booking' => $booking->id]);
    }

    private function notifyPayment(Booking $booking, Payment $payment): void
    {
        if ($this->notifications === null || $booking->userId === null) {
            return;
        }

        $user = $this->userFor($booking->userId);
        if ($user === null) {
            return;
        }

        $this->notifications->notify(NotificationEvent::PaymentReceived, $user, [
            'reference' => $booking->reference,
            'booking_id' => $booking->id,
            'action_path' => '/' . $booking->locale . '/booking/' . $booking->reference,
        ], 'payment:' . $payment->id);
    }

    private function userFor(int $userId): ?User
    {
        return $this->users?->findById($userId);
    }
}
