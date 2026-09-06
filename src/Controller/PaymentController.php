<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use InvalidArgumentException;
use SecondStay\Auth\Role;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Payment\EpcQrBuilder;
use SecondStay\Payment\Payment;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentService;

/**
 * Parcours de paiement côté voyageur (SPECIFICATIONS.md §29 à §33).
 *
 * Le voyageur choisit entre le paiement en ligne — qui confirme le séjour dès
 * que le fournisseur l'a constaté — et le virement, présenté avec un QR code
 * EPC et qui n'engage rien tant qu'un humain ne l'a pas rapproché.
 */
final class PaymentController extends AbstractController
{
    /**
     * Ouvre le paiement chez le fournisseur et redirige le voyageur.
     *
     * @param array<string, string> $params
     */
    public function start(RequestContext $context, array $params = []): Response
    {
        [$payment, $booking] = $this->ownedPayment($params);

        $service = $this->container->get(PaymentService::class);
        $result = $service->start(
            $payment,
            $this->absoluteUrl($context, 'payment.return', ['id' => (string) $payment->id]),
            $this->absoluteUrl($context, 'payment.webhook', [], false),
        );

        if ($result['ok'] === false) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'booking.show', ['reference' => $booking->reference]);
        }

        return $this->redirect($result['redirect_url']);
    }

    /**
     * Retour depuis le fournisseur.
     *
     * Le voyageur peut revenir avant la notification, ou ne jamais revenir :
     * cette page relit donc l'état réel plutôt que de croire la redirection,
     * et le webhook reste la source d'autorité.
     *
     * @param array<string, string> $params
     */
    public function returnFromProvider(RequestContext $context, array $params = []): Response
    {
        [$payment, $booking] = $this->ownedPayment($params);

        $payment = $this->synchronise($payment);

        return $this->render('payment/return.html.twig', [
            'meta_title' => $this->trans('payment.return.title'),
            'booking' => $booking,
            'payment' => $payment,
        ]);
    }

    /**
     * Instructions de virement et QR code EPC.
     *
     * @param array<string, string> $params
     */
    public function transfer(RequestContext $context, array $params = []): Response
    {
        [$payment, $booking] = $this->ownedPayment($params);

        $transfer = $this->transferDetails($payment, $booking);
        if ($transfer === null) {
            throw new NotFoundException('Virement non proposé.');
        }

        return $this->render('payment/transfer.html.twig', [
            'meta_title' => $this->trans('payment.transfer.title'),
            'booking' => $booking,
            'payment' => $payment,
            'transfer' => $transfer,
        ]);
    }

    /**
     * QR code EPC en SVG, servi séparément pour rester cachable et léger.
     *
     * @param array<string, string> $params
     */
    public function epcQr(RequestContext $context, array $params = []): Response
    {
        [$payment, $booking] = $this->ownedPayment($params);

        $transfer = $this->transferDetails($payment, $booking);
        if ($transfer === null) {
            throw new NotFoundException('Virement non proposé.');
        }

        return new Response($transfer['svg'], 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // --- Internes -------------------------------------------------------------

    /**
     * Charge le paiement en vérifiant qu'il appartient bien au demandeur.
     *
     * @param array<string, string> $params
     *
     * @return array{Payment, Booking}
     */
    private function ownedPayment(array $params): array
    {
        $user = $this->requireRole(Role::Customer);

        $payment = $this->container->get(PaymentRepository::class)->find((int) ($params['id'] ?? 0));
        if ($payment === null) {
            throw new NotFoundException('Paiement introuvable.');
        }

        $booking = $this->container->get(BookingRepository::class)->find($payment->bookingId);
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        // Un identifiant de paiement n'est pas un secret : l'appartenance est
        // vérifiée à chaque accès.
        if ($booking->userId !== $user->id && !$user->isOperational()) {
            throw new NotFoundException('Paiement introuvable.');
        }

        return [$payment, $booking];
    }

    /**
     * Relit l'état chez le fournisseur si un paiement y est ouvert.
     */
    private function synchronise(Payment $payment): Payment
    {
        if ($payment->providerReference === '' || $payment->status->isFinal()) {
            return $payment;
        }

        $provider = $this->container->get(PaymentProvider::class);
        $remote = $provider->fetch($payment->providerReference);

        if ($remote['ok'] === true) {
            $this->container->get(PaymentService::class)
                ->applyStatus($payment, $remote['status'], $remote['amount_cents']);
        }

        return $this->container->get(PaymentRepository::class)->find($payment->id) ?? $payment;
    }

    /**
     * Coordonnées de virement et QR code, ou `null` si le virement est fermé.
     *
     * @return array{iban: string, bic: string, beneficiary: string, reference: string, svg: string}|null
     */
    private function transferDetails(Payment $payment, Booking $booking): ?array
    {
        $settings = $this->settings();

        if (!$settings->bool('payment.transfer_enabled')) {
            return null;
        }

        $iban = $settings->string('payment.iban');
        $beneficiary = $settings->string('payment.beneficiary_name');
        if ($beneficiary === '') {
            $beneficiary = $settings->string('property.name');
        }

        if ($iban === '' || $beneficiary === '' || $payment->status->isSettled()) {
            return null;
        }

        $reference = $booking->reference . ' ' . $this->trans($payment->kind->labelKey());

        try {
            $svg = EpcQrBuilder::svg(
                $beneficiary,
                $iban,
                $payment->amountCents,
                $reference,
                $payment->currency,
                $settings->string('payment.bic'),
            );
        } catch (InvalidArgumentException) {
            // Un IBAN devenu invalide ne doit pas casser la page : le
            // virement est simplement retiré des moyens proposés.
            $this->logger()->warning('payment', 'Coordonnées de virement inutilisables');

            return null;
        }

        return [
            'iban' => EpcQrBuilder::formatIban($iban),
            'bic' => $settings->string('payment.bic'),
            'beneficiary' => $beneficiary,
            'reference' => $reference,
            'svg' => $svg,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function absoluteUrl(
        RequestContext $context,
        string $route,
        array $parameters = [],
        bool $localised = true
    ): string
    {
        $base = rtrim($this->settings()->string('site.public_url'), '/');
        if ($base === '') {
            $base = $context->request->baseUrl();
        }
        $base = rtrim($base, '/');

        return $base . $this->router()->path($route, $parameters, $localised ? $context->locale : null);
    }
}
