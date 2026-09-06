<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\SubStatus;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\I18n\Locales;
use SecondStay\Incident\IncidentService;
use SecondStay\Incident\IncidentSeverity;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;

/**
 * États des lieux d'arrivée et de départ (SPECIFICATIONS.md §53).
 *
 * La spécification distingue nettement les deux moments :
 *
 * - **arrivée** : le voyageur *signale* ce qui ne va pas, dans un délai
 *   configuré. Ne rien signaler vaut « conforme » : on ne bloque donc pas un
 *   voyageur qui arrive à 23 h sur l'absence de photos ;
 * - **départ** : les photos des zones marquées comme telles sont
 *   **obligatoires**. C'est la seule preuve dont disposeront les deux parties
 *   au moment de discuter de la caution.
 *
 * Une anomalie constatée peut devenir un incident : le lien est fait ici, une
 * fois, plutôt que recopié dans chaque contrôleur.
 */
final class InspectionService
{
    /** Délai par défaut de signalement après l'arrivée, en heures. */
    public const DEFAULT_REPORT_WINDOW_HOURS = 24;

    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly ZoneRepository $zones,
        private readonly DocumentService $documents,
        private readonly BookingRepository $bookings,
        private readonly IncidentService $incidents,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?BookingEventRepository $bookingEvents = null,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * État des lieux prêt à être rempli : ouvert si besoin, zones à jour.
     *
     * L'appel est **idempotent** : ouvrir deux fois n'ouvre qu'une fois, et
     * une zone ajoutée entre-temps apparaît au passage suivant.
     */
    public function prepare(Booking $booking, InspectionKind $kind, ?string $locale = null): ?Inspection
    {
        $locale = $this->resolveLocale($locale ?? $booking->locale);

        $existing = $this->inspections->findFor($booking->id, $kind, $locale);
        $id = $existing === null
            ? $this->inspections->open($booking->id, $kind, $locale)
            : $existing->id;

        if ($id === 0) {
            return null;
        }

        // Les zones ne sont complétées que tant que l'état des lieux est
        // ouvert : ajouter une zone après coup ne doit pas rouvrir un constat
        // signé.
        if ($existing === null || !$existing->status->isCompleted()) {
            $this->inspections->ensureEntries($id, $locale);
        }

        return $this->inspections->find($id, $locale);
    }

    /**
     * État des lieux déjà ouvert, sans en créer.
     */
    public function find(Booking $booking, InspectionKind $kind, ?string $locale = null): ?Inspection
    {
        return $this->inspections->findFor($booking->id, $kind, $this->resolveLocale($locale ?? $booking->locale));
    }

    /**
     * @return list<Inspection>
     */
    public function forBooking(Booking $booking, ?string $locale = null): array
    {
        return $this->inspections->forBooking($booking->id, $this->resolveLocale($locale ?? $booking->locale));
    }

    /**
     * Enregistre le constat d'une zone.
     *
     * @return array{ok: bool, error: string}
     */
    public function recordEntry(
        Inspection $inspection,
        int $zoneId,
        EntryState $state,
        string $note,
        ?User $actor = null,
    ): array {
        if ($inspection->status->isCompleted()) {
            // Un état des lieux clos est une preuve : on n'y revient pas.
            return ['ok' => false, 'error' => 'inspection.error.completed'];
        }

        $entryId = $this->inspections->entry($inspection->id, $zoneId);
        if ($entryId === null) {
            return ['ok' => false, 'error' => 'inspection.error.unknown_zone'];
        }

        $this->inspections->updateEntry($entryId, $state, trim($note));

        $this->audit?->record('inspection.entry', 'inspection', (string) $inspection->id, null, [
            'zone' => $zoneId,
            'state' => $state->value,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Ajoute une photo à une zone.
     *
     * @return array{ok: bool, error: string}
     */
    public function addPhoto(
        Inspection $inspection,
        int $zoneId,
        string $contents,
        string $filename,
        ?User $actor = null,
    ): array {
        if ($inspection->status->isCompleted()) {
            return ['ok' => false, 'error' => 'inspection.error.completed'];
        }

        $entryId = $this->inspections->entry($inspection->id, $zoneId);
        if ($entryId === null) {
            return ['ok' => false, 'error' => 'inspection.error.unknown_zone'];
        }

        if (!$this->isImage($contents)) {
            // Un PDF ne prouve pas l'état d'une pièce : seule une photo le
            // fait, et l'exigence de la spécification porte bien sur des
            // photos.
            return ['ok' => false, 'error' => 'inspection.error.not_a_photo'];
        }

        $result = $this->documents->store(
            $contents,
            $filename,
            DocumentKind::Inventory,
            DocumentSource::Upload,
            $inspection->bookingId,
            null,
            $actor?->id,
            '',
            $inspection->locale,
        );

        if ($result['ok'] === false || $result['document'] === null) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $this->inspections->addPhoto($entryId, $result['document']->id);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Clôt l'état des lieux, si rien ne manque.
     *
     * @return array{ok: bool, error: string, blocking: list<InspectionEntry>}
     */
    public function complete(Booking $booking, Inspection $inspection, ?User $actor = null): array
    {
        if ($inspection->status->isCompleted()) {
            return ['ok' => false, 'error' => 'inspection.error.completed', 'blocking' => []];
        }

        $blocking = $inspection->blocking();
        if ($blocking !== []) {
            return [
                'ok' => false,
                // Au départ le blocage vient des photos manquantes, à
                // l'arrivée des zones non renseignées : deux messages, parce
                // que ce ne sont pas les mêmes gestes à faire.
                'error' => $inspection->kind->requiresPhotos()
                    ? 'inspection.error.photos_required'
                    : 'inspection.error.incomplete',
                'blocking' => $blocking,
            ];
        }

        $anomalies = $inspection->anomalies();
        $summary = $this->summarise($inspection);

        $this->inspections->complete($inspection->id, $actor?->id, $summary);

        // Un état des lieux clos avec anomalies reste un départ effectué : ce
        // qui reste à traiter vit dans les incidents, pas dans un sous-état
        // laissé en suspens.
        $this->bookings->update($booking->id, [$inspection->kind->bookingColumn() => SubStatus::Done->value]);

        $this->bookingEvents?->record(
            $booking->id,
            'inspection_' . $inspection->kind->value,
            ['anomalies' => count($anomalies)],
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        $this->audit?->record('inspection.completed', 'inspection', (string) $inspection->id, null, [
            'kind' => $inspection->kind->value,
            'anomalies' => count($anomalies),
        ], $actor?->id, $actor === null ? '' : $actor->email);

        $this->logger->info('inspection', 'État des lieux clos', [
            'booking' => $booking->id,
            'kind' => $inspection->kind->value,
            'anomalies' => count($anomalies),
        ]);

        return ['ok' => true, 'error' => '', 'blocking' => []];
    }

    /**
     * Transforme une anomalie constatée en incident.
     *
     * @return array{ok: bool, error: string}
     */
    public function raiseIncident(
        Booking $booking,
        Inspection $inspection,
        int $zoneId,
        IncidentSeverity $severity,
        string $description,
        ?User $actor = null,
    ): array {
        $entry = null;
        foreach ($inspection->entries as $candidate) {
            if ($candidate->zone->id === $zoneId) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            return ['ok' => false, 'error' => 'inspection.error.unknown_zone'];
        }

        if (!$entry->state->isAnomaly()) {
            // Ouvrir un incident sur une zone déclarée conforme rendrait les
            // deux informations contradictoires.
            return ['ok' => false, 'error' => 'inspection.error.not_an_anomaly'];
        }

        $label = $entry->zone->label();
        $title = ($label === '' ? $entry->zone->code : $label)
            . ' — ' . $inspection->kind->value;

        $result = $this->incidents->report(
            $title,
            trim($description) === '' ? $entry->note : $description,
            $severity,
            $booking->id,
            $zoneId,
            $inspection->locale,
            $actor,
        );

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Délai de signalement après l'arrivée, en heures.
     */
    public function reportWindowHours(): int
    {
        $configured = $this->settings->int('inspection.report_window_hours');

        return $configured > 0 ? $configured : self::DEFAULT_REPORT_WINDOW_HOURS;
    }

    /**
     * Zones du logement, dans la langue demandée.
     *
     * @return list<Zone>
     */
    public function activeZones(string $locale): array
    {
        return $this->zones->active($this->resolveLocale($locale));
    }

    // --- Interne ----------------------------------------------------------------

    /**
     * Résumé court, stocké avec l'état des lieux clos.
     */
    private function summarise(Inspection $inspection): string
    {
        $anomalies = $inspection->anomalies();
        if ($anomalies === []) {
            return 'ok';
        }

        return 'anomalies:' . implode(
            ',',
            array_map(
                static fn (InspectionEntry $entry): string => $entry->zone->code,
                $anomalies
            )
        );
    }

    /**
     * Le contenu est-il réellement une image ?
     *
     * La question est posée au contenu, jamais au nom du fichier ni à ce que
     * le navigateur annonce.
     */
    private function isImage(string $contents): bool
    {
        $mime = $this->documents->detectMime($contents);

        return $mime !== null && str_starts_with($mime, 'image/');
    }

    private function resolveLocale(string $locale): string
    {
        return Locales::isSupported($locale) ? $locale : Locales::FALLBACK;
    }
}
