<?php

declare(strict_types=1);

namespace SecondStay\Incident;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\I18n\Locales;
use SecondStay\Logging\Logger;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationService;
use SecondStay\Settings\SettingsService;

/**
 * Cycle de vie d'un incident (SPECIFICATIONS.md §54).
 *
 * Trois règles gouvernent ce service :
 *
 * 1. tout changement d'état passe par `transition()`, qui refuse ce que
 *    `IncidentStatus::canMoveTo()` n'autorise pas : l'historique reste alors
 *    une suite de faits, pas une suite de valeurs écrites au hasard ;
 * 2. chaque action écrit une ligne d'historique — c'est ce qui rend l'incident
 *    opposable au moment de discuter d'une caution ;
 * 3. un incident **urgent** prévient les rôles opérationnels tout de suite ;
 *    les autres attendent la consultation du tableau, parce qu'une alerte qui
 *    sonne pour tout ne fait plus réagir à rien.
 */
final class IncidentService
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly DocumentService $documents,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?BookingEventRepository $bookingEvents = null,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Ouvre un incident.
     *
     * @return array{ok: bool, incident: Incident|null, error: string}
     */
    public function report(
        string $title,
        string $description,
        IncidentSeverity $severity,
        ?int $bookingId,
        ?int $zoneId,
        string $locale,
        ?User $reporter = null,
    ): array {
        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'incident' => null, 'error' => 'incident.error.title_required'];
        }

        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;

        $id = $this->incidents->create([
            'booking_id' => $bookingId,
            'zone_id' => $zoneId,
            'severity' => $severity->value,
            'status' => IncidentStatus::Reported->value,
            'title' => mb_substr($title, 0, 190),
            'description' => trim($description) === '' ? null : mb_substr(trim($description), 0, 8000),
            'locale' => $locale,
            'reported_by' => $reporter?->id,
        ]);

        $this->incidents->addEvent(
            $id,
            'reported',
            $severity->value,
            $reporter?->id,
            $reporter === null ? '' : $reporter->displayName(),
        );

        // La chronologie du séjour n'existe que s'il y a un séjour : un
        // incident hors réservation (panne du portail en intersaison) reste un
        // incident, il n'a simplement rien à inscrire ailleurs.
        if ($bookingId !== null) {
            $this->bookingEvents?->record(
                $bookingId,
                'incident_reported',
                ['incident' => $id, 'severity' => $severity->value],
                $reporter?->id,
                $reporter === null ? '' : $reporter->displayName(),
            );
        }

        $this->audit?->record('incident.reported', 'incident', (string) $id, null, [
            'severity' => $severity->value,
            'booking' => $bookingId,
        ], $reporter?->id, $reporter === null ? '' : $reporter->email);

        $this->logger->info('incident', 'Incident signalé', ['incident' => $id, 'severity' => $severity->value]);

        $incident = $this->incidents->find($id, $locale);

        if ($severity->isUrgent() && $incident !== null) {
            $this->alertOperational($incident);
        }

        return ['ok' => true, 'incident' => $incident, 'error' => ''];
    }

    /**
     * Change l'état d'un incident.
     *
     * @return array{ok: bool, error: string}
     */
    public function transition(Incident $incident, IncidentStatus $target, string $note, ?User $actor = null): array
    {
        if (!$incident->status->canMoveTo($target)) {
            return ['ok' => false, 'error' => 'incident.error.transition'];
        }

        $data = ['status' => $target->value];
        // Rouvrir efface la date de résolution : la garder ferait croire à un
        // incident clos alors qu'il ne l'est plus.
        $data['resolved_at'] = $target->isResolved() ? gmdate('Y-m-d H:i:s') : null;

        $this->incidents->update($incident->id, $data);
        $this->incidents->addEvent(
            $incident->id,
            $target->value,
            $note,
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        $this->audit?->record('incident.' . $target->value, 'incident', (string) $incident->id, [
            'status' => $incident->status->value,
        ], ['status' => $target->value], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Confie l'incident à un rôle opérationnel.
     *
     * @return array{ok: bool, error: string}
     */
    public function assign(Incident $incident, ?int $userId, ?User $actor = null): array
    {
        $assignee = $userId === null ? null : $this->users->findById($userId);

        if ($userId !== null && ($assignee === null || !$assignee->isOperational())) {
            // Confier un incident à un client n'aurait aucun sens : il n'a
            // accès à rien de ce qu'il faudrait pour le traiter.
            return ['ok' => false, 'error' => 'incident.error.assignee'];
        }

        $this->incidents->update($incident->id, ['assigned_to' => $assignee?->id]);
        $this->incidents->addEvent(
            $incident->id,
            'assigned',
            $assignee === null ? '' : $assignee->displayName(),
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        if ($assignee !== null) {
            $this->notifications->notify(NotificationEvent::TaskAssigned, $assignee, [
                'property' => $this->settings->string('property.name'),
                'title' => $incident->title,
            ], 'incident-' . $incident->id);
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Ajoute un commentaire à l'historique.
     *
     * @return array{ok: bool, error: string}
     */
    public function comment(Incident $incident, string $note, ?User $actor = null): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'error' => 'incident.error.note_required'];
        }

        $this->incidents->addEvent(
            $incident->id,
            'comment',
            $note,
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );
        $this->incidents->update($incident->id, []);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Attache une photo à l'incident.
     *
     * Le fichier suit le circuit ordinaire des documents : même stockage hors
     * document root, même détection de type par le contenu, même contrôle
     * d'accès au téléchargement.
     *
     * @return array{ok: bool, error: string}
     */
    public function attachPhoto(Incident $incident, string $contents, string $filename, ?User $actor = null): array
    {
        $result = $this->documents->store(
            $contents,
            $filename,
            DocumentKind::Incident,
            DocumentSource::Upload,
            $incident->bookingId,
            null,
            $actor?->id,
            '',
            $incident->locale,
        );

        if ($result['ok'] === false || $result['document'] === null) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $this->incidents->addPhoto($incident->id, $result['document']->id);
        $this->incidents->addEvent(
            $incident->id,
            'photo',
            $result['document']->filename,
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        return ['ok' => true, 'error' => ''];
    }

    // --- Interne ----------------------------------------------------------------

    /**
     * Prévient les rôles opérationnels d'un incident urgent.
     */
    private function alertOperational(Incident $incident): void
    {
        $recipients = $incident->assignedTo === null
            ? $this->users->operational()
            : array_values(array_filter([$this->users->findById($incident->assignedTo)]));

        foreach ($recipients as $user) {
            $this->notifications->notify(NotificationEvent::Incident, $user, [
                'property' => $this->settings->string('property.name'),
                'title' => $incident->title,
                'reference' => $incident->bookingReference,
            ], 'incident-' . $incident->id);
        }
    }
}
