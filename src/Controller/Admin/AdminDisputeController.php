<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Dispute\Dispute;
use SecondStay\Dispute\DisputeRepository;
use SecondStay\Dispute\DisputeService;
use SecondStay\Dispute\DisputeStatus;
use SecondStay\Support\Money;

/**
 * Litiges, côté exploitation.
 */
final class AdminDisputeController extends AdminController
{
    protected function section(): string
    {
        return 'disputes';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $requested = (string) ($context->request->query('status') ?? '');
        $filter = $requested === '' ? null : DisputeStatus::tryFrom($requested);

        return $this->renderAdmin('admin/disputes.html.twig', [
            'meta_title' => $this->trans('dispute.title'),
            'disputes' => $this->container->get(DisputeRepository::class)->listing($filter),
            'statuses' => DisputeStatus::cases(),
            'current_status' => $filter,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $dispute = $this->dispute($params);
        $booking = $this->container->get(BookingRepository::class)->find($dispute->bookingId);
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        return $this->renderAdmin('admin/dispute.html.twig', [
            'meta_title' => $dispute->summary,
            'dispute' => $dispute,
            'booking' => $booking,
            'transitions' => $dispute->status->allowedTransitions(),
            'evidence' => $this->container->get(DisputeService::class)->evidenceFor($booking),
        ]);
    }

    /**
     * Ouvre un litige sur un séjour.
     *
     * @param array<string, string> $params
     */
    public function open(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->booking($params);
        $claimed = Money::parse((string) $context->request->input('claimed', '0'));

        if ($claimed === null || $claimed < 0) {
            $this->flashError('dispute.error.amount');

            return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
        }

        $result = $this->container->get(DisputeService::class)->open(
            $booking,
            (string) $context->request->input('kind', 'deposit'),
            $claimed,
            (string) $context->request->input('summary', ''),
            $context->locale,
            $user,
        );

        if ($result['ok'] === false || $result['dispute'] === null) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
        }

        $this->flashSuccess('dispute.opened');

        return $this->redirectToRoute($context, 'admin.disputes.show', ['id' => $result['dispute']->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function transition(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $dispute = $this->dispute($params);

        $target = DisputeStatus::tryFrom((string) $context->request->input('status', ''));
        if ($target === null) {
            $this->flashError('dispute.error.transition');

            return $this->redirectToRoute($context, 'admin.disputes.show', ['id' => $dispute->id]);
        }

        $settled = Money::parse((string) $context->request->input('settled', '0'));

        $result = $this->container->get(DisputeService::class)->transition(
            $dispute,
            $target,
            $settled ?? -1,
            (string) $context->request->input('resolution', ''),
            $user,
        );

        $result['ok'] ? $this->flashSuccess('dispute.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.disputes.show', ['id' => $dispute->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function comment(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $dispute = $this->dispute($params);

        $result = $this->container->get(DisputeService::class)
            ->comment($dispute, (string) $context->request->input('note', ''), $user);

        $result['ok'] ? $this->flashSuccess('dispute.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.disputes.show', ['id' => $dispute->id]);
    }

    /**
     * @param array<string, string> $params
     */
    private function dispute(array $params): Dispute
    {
        $dispute = $this->container->get(DisputeRepository::class)->find((int) ($params['id'] ?? 0));
        if ($dispute === null) {
            throw new NotFoundException('Litige introuvable.');
        }

        return $dispute;
    }

    /**
     * @param array<string, string> $params
     */
    private function booking(array $params): Booking
    {
        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        return $booking;
    }
}
