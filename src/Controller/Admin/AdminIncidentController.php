<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\UserRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Incident\Incident;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Incident\IncidentService;
use SecondStay\Incident\IncidentSeverity;
use SecondStay\Incident\IncidentStatus;
use SecondStay\Inspection\ZoneRepository;

/**
 * Incidents, côté exploitation (SPECIFICATIONS.md §54).
 */
final class AdminIncidentController extends AdminController
{
    protected function section(): string
    {
        return 'incidents';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $requested = (string) ($context->request->query('status') ?? '');
        $filter = $requested === '' ? null : IncidentStatus::tryFrom($requested);

        return $this->renderAdmin('admin/incidents.html.twig', [
            'meta_title' => $this->trans('incident.title'),
            'incidents' => $this->container->get(IncidentRepository::class)->listing($filter, null, $context->locale),
            'statuses' => IncidentStatus::cases(),
            'severities' => IncidentSeverity::cases(),
            'current_status' => $filter,
            'zones' => $this->container->get(ZoneRepository::class)->active($context->locale),
            'bookings' => $this->container->get(BookingRepository::class)->listing([], 60),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $incident = $this->incident($context, $params);

        $booking = $incident->bookingId === null
            ? null
            : $this->container->get(BookingRepository::class)->find($incident->bookingId);

        return $this->renderAdmin('admin/incident.html.twig', [
            'meta_title' => $incident->title,
            'incident' => $incident,
            'booking' => $booking,
            'transitions' => $incident->status->allowedTransitions(),
            'assignees' => $this->container->get(UserRepository::class)->operational(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $bookingId = (int) $context->request->input('booking', '0');
        $zoneId = (int) $context->request->input('zone', '0');

        $result = $this->container->get(IncidentService::class)->report(
            (string) $context->request->input('title', ''),
            (string) $context->request->input('description', ''),
            IncidentSeverity::fromString((string) $context->request->input('severity', 'normal')),
            $bookingId > 0 ? $bookingId : null,
            $zoneId > 0 ? $zoneId : null,
            $context->locale,
            $user,
        );

        if ($result['ok'] === false || $result['incident'] === null) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'admin.incidents');
        }

        $this->flashSuccess('incident.reported');

        return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $result['incident']->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function transition(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $incident = $this->incident($context, $params);

        $target = IncidentStatus::tryFrom((string) $context->request->input('status', ''));
        if ($target === null) {
            $this->flashError('incident.error.transition');

            return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
        }

        $result = $this->container->get(IncidentService::class)->transition(
            $incident,
            $target,
            (string) $context->request->input('note', ''),
            $user,
        );

        $result['ok'] ? $this->flashSuccess('incident.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function assign(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $incident = $this->incident($context, $params);

        $assignee = (int) $context->request->input('assignee', '0');

        $result = $this->container->get(IncidentService::class)
            ->assign($incident, $assignee > 0 ? $assignee : null, $user);

        $result['ok'] ? $this->flashSuccess('incident.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function comment(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $incident = $this->incident($context, $params);

        $result = $this->container->get(IncidentService::class)
            ->comment($incident, (string) $context->request->input('note', ''), $user);

        $result['ok'] ? $this->flashSuccess('incident.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function uploadPhoto(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $incident = $this->incident($context, $params);

        /** @var array<string, mixed> $file */
        $file = $context->request->files['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $contents = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ? file_get_contents((string) $file['tmp_name'])
            : false;

        if ($contents === false || $contents === '') {
            $this->flashError('document.error.upload_failed');

            return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
        }

        $result = $this->container->get(IncidentService::class)->attachPhoto(
            $incident,
            $contents,
            (string) ($file['name'] ?? 'photo.jpg'),
            $user,
        );

        $result['ok'] ? $this->flashSuccess('incident.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.incidents.show', ['id' => $incident->id]);
    }

    /**
     * @param array<string, string> $params
     */
    private function incident(RequestContext $context, array $params): Incident
    {
        $incident = $this->container->get(IncidentRepository::class)
            ->find((int) ($params['id'] ?? 0), $context->locale);

        if ($incident === null) {
            throw new NotFoundException('Incident introuvable.');
        }

        return $incident;
    }
}
