<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Incident\IncidentSeverity;
use SecondStay\Inspection\EntryState;
use SecondStay\Inspection\InspectionKind;
use SecondStay\Inspection\InspectionService;
use SecondStay\Stay\StayPhase;

/**
 * État des lieux rempli sur place, depuis un téléphone
 * (SPECIFICATIONS.md §53).
 *
 * L'écran est pensé pour un pouce et une main : une zone après l'autre, un
 * bouton par état, l'appareil photo directement accessible. Il n'est jamais
 * mis en cache : il écrit, et une photo prise hors ligne qui ne partirait
 * jamais serait pire qu'une photo manquante.
 */
final class InspectionController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        [$user, $booking, $kind] = $this->resolve($params);

        $inspections = $this->container->get(InspectionService::class);
        $inspection = $inspections->prepare($booking, $kind, $context->locale);

        if ($inspection === null) {
            throw new NotFoundException('État des lieux indisponible.');
        }

        return $this->render('inspection/show.html.twig', [
            'meta_title' => $this->trans($kind->labelKey()),
            'meta_robots' => 'noindex, nofollow',
            'booking' => $booking,
            'kind' => $kind,
            'inspection' => $inspection,
            'other' => $this->otherKind($kind),
            'phase' => StayPhase::of($booking, $this->timezone()),
            'report_window_hours' => $inspections->reportWindowHours(),
            'severities' => IncidentSeverity::cases(),
            'is_operational' => $user->isOperational(),
        ]);
    }

    /**
     * Enregistre le constat d'une zone, photo comprise.
     *
     * @param array<string, string> $params
     */
    public function saveEntry(RequestContext $context, array $params = []): Response
    {
        [$user, $booking, $kind] = $this->resolve($params);

        $inspections = $this->container->get(InspectionService::class);
        $inspection = $inspections->find($booking, $kind, $context->locale);

        if ($inspection === null) {
            throw new NotFoundException('État des lieux introuvable.');
        }

        $zoneId = (int) $context->request->input('zone', '0');

        $result = $inspections->recordEntry(
            $inspection,
            $zoneId,
            EntryState::fromString((string) $context->request->input('state', 'pending')),
            (string) $context->request->input('note', ''),
            $user,
        );

        if ($result['ok'] === false) {
            $this->flashError($result['error']);

            return $this->backToInspection($context, $booking, $kind);
        }

        $photo = $this->uploadedPhoto($context);
        if ($photo !== null) {
            $stored = $inspections->addPhoto($inspection, $zoneId, $photo['contents'], $photo['name'], $user);
            if ($stored['ok'] === false) {
                $this->flashError($stored['error']);

                return $this->backToInspection($context, $booking, $kind);
            }
        }

        $this->flashSuccess('inspection.saved');

        return $this->backToInspection($context, $booking, $kind);
    }

    /**
     * @param array<string, string> $params
     */
    public function complete(RequestContext $context, array $params = []): Response
    {
        [$user, $booking, $kind] = $this->resolve($params);

        $inspections = $this->container->get(InspectionService::class);
        $inspection = $inspections->find($booking, $kind, $context->locale);

        if ($inspection === null) {
            throw new NotFoundException('État des lieux introuvable.');
        }

        $result = $inspections->complete($booking, $inspection, $user);
        $result['ok'] ? $this->flashSuccess('inspection.completed') : $this->flashError($result['error']);

        return $this->backToInspection($context, $booking, $kind);
    }

    /**
     * Ouvre un incident depuis une anomalie constatée.
     *
     * @param array<string, string> $params
     */
    public function raiseIncident(RequestContext $context, array $params = []): Response
    {
        [$user, $booking, $kind] = $this->resolve($params);

        $inspections = $this->container->get(InspectionService::class);
        $inspection = $inspections->find($booking, $kind, $context->locale);

        if ($inspection === null) {
            throw new NotFoundException('État des lieux introuvable.');
        }

        $result = $inspections->raiseIncident(
            $booking,
            $inspection,
            (int) $context->request->input('zone', '0'),
            IncidentSeverity::fromString((string) $context->request->input('severity', 'normal')),
            (string) $context->request->input('description', ''),
            $user,
        );

        $result['ok'] ? $this->flashSuccess('incident.reported') : $this->flashError($result['error']);

        return $this->backToInspection($context, $booking, $kind);
    }

    // --- Interne ------------------------------------------------------------------

    /**
     * Résout séjour et type, autorisation comprise.
     *
     * @param array<string, string> $params
     *
     * @return array{0: User, 1: Booking, 2: InspectionKind}
     */
    private function resolve(array $params): array
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null || ($booking->userId !== $user->id && !$user->isOperational())) {
            throw new NotFoundException('Séjour introuvable.');
        }

        // Le propriétaire peut préférer remplir lui-même les états des lieux :
        // dans ce cas le voyageur n'y accède pas, et le masquage du lien ne
        // suffirait pas à l'en empêcher.
        if (!$user->isOperational() && !$this->settings()->bool('inspection.guest_enabled')) {
            throw new NotFoundException('État des lieux indisponible.');
        }

        if (!$booking->status->occupiesNights()) {
            // Un séjour annulé n'a pas d'état des lieux.
            throw new NotFoundException('Séjour introuvable.');
        }

        $requested = (string) ($params['kind'] ?? '');
        $kind = InspectionKind::tryFrom($requested);
        if ($kind === null) {
            throw new NotFoundException('État des lieux introuvable.');
        }

        return [$user, $booking, $kind];
    }

    private function otherKind(InspectionKind $kind): InspectionKind
    {
        return $kind === InspectionKind::Checkin ? InspectionKind::Checkout : InspectionKind::Checkin;
    }

    /**
     * Photo envoyée avec le formulaire, si elle est bien arrivée.
     *
     * @return array{contents: string, name: string}|null
     */
    private function uploadedPhoto(RequestContext $context): ?array
    {
        /** @var array<string, mixed> $file */
        $file = $context->request->files['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $contents = file_get_contents((string) $file['tmp_name']);
        if ($contents === false || $contents === '') {
            return null;
        }

        return ['contents' => $contents, 'name' => (string) ($file['name'] ?? 'photo.jpg')];
    }

    private function backToInspection(RequestContext $context, Booking $booking, InspectionKind $kind): Response
    {
        return $this->redirectToRoute($context, 'inspection.show', [
            'reference' => $booking->reference,
            'kind' => $kind->value,
        ]);
    }

    private function timezone(): string
    {
        $timezone = $this->settings()->string('site.timezone');

        return $timezone === '' ? 'UTC' : $timezone;
    }
}
