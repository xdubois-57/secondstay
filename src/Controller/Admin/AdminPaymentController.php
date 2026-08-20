<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\Payment;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentService;
use SecondStay\Payment\WebhookRepository;
use SecondStay\Support\Money;

/**
 * Suivi financier : échéancier, encaissements manuels, remboursements et
 * cycle de la caution (SPECIFICATIONS.md §29 à §32).
 */
final class AdminPaymentController extends AdminController
{
    protected function section(): string
    {
        return 'payments';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $payments = $this->container->get(PaymentRepository::class);
        $provider = $this->container->get(PaymentProvider::class);

        return $this->renderAdmin('admin/payments.html.twig', [
            'meta_title' => $this->trans('payment.admin.title'),
            'outstanding' => $payments->outstanding(),
            'held_deposits' => $payments->heldDeposits(),
            'today' => gmdate('Y-m-d'),
            'webhooks' => $this->container->get(WebhookRepository::class)->recent(25),
            'provider_name' => $provider->name(),
            'provider_ready' => $provider->isConfigured(),
        ]);
    }

    /**
     * (Re)construit l'échéancier d'un séjour. L'opération est idempotente.
     *
     * @param array<string, string> $params
     */
    public function schedule(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $this->container->get(PaymentService::class)->schedule($booking);
        $this->flashSuccess('payment.admin.scheduled');

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    /**
     * Encaissement constaté hors fournisseur (virement, espèces).
     *
     * @param array<string, string> $params
     */
    public function record(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $payment = $this->payment($params);

        $result = $this->container->get(PaymentService::class)->recordManualPayment(
            $payment,
            $user,
            $context->request->input('confirm_booking') !== null,
        );

        $result['ok'] ? $this->flashSuccess('payment.admin.recorded') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $payment->bookingId]);
    }

    /**
     * @param array<string, string> $params
     */
    public function refund(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $payment = $this->payment($params);

        $amount = Money::parse((string) $context->request->input('amount', ''));
        if ($amount === null) {
            $this->flashError('payment.error.refund_amount');

            return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $payment->bookingId]);
        }

        $result = $this->container->get(PaymentService::class)->refund(
            $payment,
            $amount,
            $user,
            (string) $context->request->input('reason', ''),
        );

        $result['ok'] ? $this->flashSuccess('payment.admin.refunded') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $payment->bookingId]);
    }

    /**
     * Fait avancer le cycle de la caution (SPECIFICATIONS.md §32).
     *
     * @param array<string, string> $params
     */
    public function hold(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $payment = $this->payment($params);

        $target = HoldStatus::fromString((string) $context->request->input('hold_status', ''));

        $result = $target === HoldStatus::ToReturn
            ? $this->container->get(PaymentService::class)->markDepositToReturn($payment, $user)
            : ['ok' => false, 'error' => 'payment.error.hold_transition'];

        $result['ok'] ? $this->flashSuccess('payment.admin.hold_updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $payment->bookingId]);
    }

    /**
     * @param array<string, string> $params
     */
    private function payment(array $params): Payment
    {
        $payment = $this->container->get(PaymentRepository::class)->find((int) ($params['id'] ?? 0));
        if ($payment === null) {
            throw new NotFoundException('Paiement introuvable.');
        }

        return $payment;
    }
}
