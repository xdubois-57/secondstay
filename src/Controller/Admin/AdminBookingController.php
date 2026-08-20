<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\PromoCode;
use SecondStay\Booking\PromoCodeRepository;
use SecondStay\Contract\ContractService;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Payment\PaymentService;
use SecondStay\Support\Money;

/**
 * Suivi des réservations : validation, refus, annulation et codes promo.
 */
final class AdminBookingController extends AdminController
{
    protected function section(): string
    {
        return 'bookings';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $filter = (string) ($context->request->query('status') ?? '');
        $statuses = $filter === '' ? [] : [BookingStatus::fromString($filter)];

        return $this->renderAdmin('admin/bookings.html.twig', [
            'meta_title' => $this->trans('booking.admin.title'),
            'bookings' => $this->container->get(BookingRepository::class)->listing($statuses),
            'statuses' => BookingStatus::cases(),
            'filter' => $filter,
            'promos' => $this->container->get(PromoCodeRepository::class)->all(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $payments = $this->container->get(PaymentService::class)->schedule($booking);

        $contracts = $this->container->get(ContractService::class);
        $acceptance = $contracts->acceptanceFor($booking);

        $paid = 0;
        $due = 0;
        foreach ($payments as $payment) {
            $paid += $payment->netCents();
            $due += $payment->outstandingCents();
        }

        return $this->renderAdmin('admin/booking-detail.html.twig', [
            'meta_title' => $booking->reference,
            'booking' => $booking,
            'timeline' => $this->container->get(BookingEventRepository::class)->forBooking($booking->id),
            'transitions' => $booking->status->allowedTransitions(),
            'payments' => $payments,
            'paid_cents' => $paid,
            'due_cents' => $due,
            'documents' => $this->container->get(DocumentRepository::class)->forBooking($booking->id),
            'document_kinds' => DocumentKind::cases(),
            'contract_acceptance' => $acceptance,
            'contract_intact' => $acceptance !== null && $contracts->acceptanceIsIntact($acceptance),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function transition(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $target = BookingStatus::fromString((string) $context->request->input('status', ''));

        $result = $this->container->get(BookingService::class)->transition(
            $booking,
            $target,
            $user,
            (string) $context->request->input('reason', ''),
        );

        $result['ok'] ? $this->flashSuccess('booking.status.' . $target->value) : $this->flashError($result['errors'][0]);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function createPromo(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $request = $context->request;

        $kind = (string) $request->input('kind', PromoCode::KIND_PERCENT);
        $rawValue = (string) $request->input('value', '');

        // Un pourcentage est un entier ; un montant fixe se saisit en euros.
        $value = $kind === PromoCode::KIND_FIXED ? Money::parse($rawValue) : (int) $rawValue;

        if ($value === null || $value <= 0 || PromoCode::normalise((string) $request->input('code', '')) === '') {
            $this->flashError('admin.pricing.error.price');

            return $this->redirectToRoute($context, 'admin.bookings');
        }

        $maxUses = (int) $request->input('max_uses', '0');

        $this->container->get(PromoCodeRepository::class)->create(
            (string) $request->input('code', ''),
            $kind,
            $value,
            $this->dateOrNull((string) $request->input('starts_on', '')),
            $this->dateOrNull((string) $request->input('ends_on', '')),
            $maxUses > 0 ? $maxUses : null,
            (string) $request->input('label', ''),
        );

        $this->audit()->record('promo.created', 'promo_code', PromoCode::normalise((string) $request->input('code', '')), null, [
            'kind' => $kind,
        ], $user->id, $user->email);

        $this->flashSuccess('booking.promo.applied');

        return $this->redirectToRoute($context, 'admin.bookings');
    }

    /**
     * @param array<string, string> $params
     */
    public function deletePromo(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $id = (int) ($params['id'] ?? 0);
        $removed = $this->container->get(PromoCodeRepository::class)->delete($id);

        if ($removed) {
            $this->audit()->record('promo.deleted', 'promo_code', (string) $id, null, null, $user->id, $user->email);
        }

        return $this->redirectToRoute($context, 'admin.bookings');
    }

    private function dateOrNull(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }
}
