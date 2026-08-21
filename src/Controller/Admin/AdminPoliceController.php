<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Police\PoliceRecordService;
use SecondStay\Privacy\RetentionService;

/**
 * Fiches de police et rétention (SPECIFICATIONS.md §64 et §65).
 *
 * L'écran n'existe que si l'obligation a été activée : tant qu'elle ne l'est
 * pas, le produit ne propose pas de collecter une identité, une date de
 * naissance et un domicile.
 */
final class AdminPoliceController extends AdminController
{
    protected function section(): string
    {
        return 'police';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $police = $this->container->get(PoliceRecordService::class);
        $retention = $this->container->get(RetentionService::class);

        return $this->renderAdmin('admin/police.html.twig', [
            'meta_title' => $this->trans('police.title'),
            'enabled' => $police->isEnabled(),
            'retention_days' => $police->retentionDays(),
            'records' => $police->isEnabled() ? $police->all() : [],
            'fields' => PoliceRecordService::FIELDS,
            'policy' => $retention->policy(),
            'today' => gmdate('Y-m-d'),
        ]);
    }

    /**
     * Fiche d'un séjour.
     *
     * @param array<string, string> $params
     */
    public function edit(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $booking = $this->booking($params);
        $police = $this->container->get(PoliceRecordService::class);

        if (!$police->isEnabled()) {
            throw new NotFoundException('Fiche de police désactivée.');
        }

        return $this->renderAdmin('admin/police-record.html.twig', [
            'meta_title' => $this->trans('police.record'),
            'booking' => $booking,
            'record' => $police->forBooking($booking),
            'fields' => PoliceRecordService::FIELDS,
            'purge_after' => $police->purgeDateFor($booking),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->booking($params);

        $input = [];
        foreach (PoliceRecordService::FIELDS as $field) {
            $input[$field] = $context->request->input($field, '');
        }

        $result = $this->container->get(PoliceRecordService::class)
            ->save($booking, $input, $context->locale, $user);

        $result['ok'] ? $this->flashSuccess('police.saved') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.police.edit', ['id' => $booking->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $booking = $this->booking($params);
        $this->container->get(PoliceRecordService::class)->delete($booking, $user);

        $this->flashSuccess('police.deleted');

        return $this->redirectToRoute($context, 'admin.police');
    }

    /**
     * Applique les durées de conservation, à la demande.
     *
     * @param array<string, string> $params
     */
    public function purge(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $removed = $this->container->get(RetentionService::class)->purge();

        array_sum($removed) > 0
            ? $this->flashSuccess('privacy.purged')
            : $this->flashWarning('privacy.nothing_to_purge');

        return $this->redirectToRoute($context, 'admin.police');
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
