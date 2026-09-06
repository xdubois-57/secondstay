<?php

declare(strict_types=1);

namespace SecondStay\Incident;

use SecondStay\Database\Database;

/**
 * Persistance des incidents et de leur historique.
 */
final class IncidentRepository
{
    /**
     * Colonnes jointes systématiquement : un incident sans sa référence de
     * séjour ni le nom de sa zone oblige l'affichage à re-interroger la base
     * ligne par ligne.
     */
    private const SELECT = 'SELECT i.*, b.`reference` AS `booking_reference`, z.`code` AS `zone_code`, '
        . 't.`name` AS `zone_name` '
        . 'FROM `incident` i '
        . 'LEFT JOIN `booking` b ON b.`id` = i.`booking_id` '
        . 'LEFT JOIN `inspection_zone` z ON z.`id` = i.`zone_id` '
        . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale ';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->database->insert(
            'incident',
            $data + [
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function find(int $id, string $locale = 'fr'): ?Incident
    {
        $row = $this->database->fetchOne(self::SELECT . 'WHERE i.`id` = :id', ['id' => $id, 'locale' => $locale]);
        if ($row === null) {
            return null;
        }

        return Incident::fromRow($row, $this->eventsFor($id), $this->photosFor($id));
    }

    /**
     * Liste filtrée, la plus urgente d'abord puis la plus récente.
     *
     * @return list<Incident>
     */
    public function listing(
        ?IncidentStatus $status = null,
        ?int $bookingId = null,
        string $locale = 'fr',
        int $limit = 200
    ): array
    {
        $conditions = [];
        $parameters = ['locale' => $locale];

        if ($status !== null) {
            $conditions[] = 'i.`status` = :status';
            $parameters['status'] = $status->value;
        }

        if ($bookingId !== null) {
            $conditions[] = 'i.`booking_id` = :booking';
            $parameters['booking'] = $bookingId;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions) . ' ';

        $rows = $this->database->fetchAll(
            self::SELECT . $where
            . 'ORDER BY FIELD(i.`severity`, \'urgent\', \'normal\', \'low\'), i.`created_at` DESC, i.`id` DESC '
            . 'LIMIT ' . max(1, min(500, $limit)),
            $parameters
        );

        return array_map(static fn (array $row): Incident => Incident::fromRow($row), $rows);
    }

    /**
     * @return list<Incident>
     */
    public function forBooking(int $bookingId, string $locale = 'fr'): array
    {
        return $this->listing(null, $bookingId, $locale);
    }

    public function countOpen(): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `incident` WHERE `status` <> :resolved',
            ['resolved' => IncidentStatus::Resolved->value]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('incident', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function addEvent(int $incidentId, string $type, string $note, ?int $actorId, string $actorLabel): int
    {
        return $this->database->insert('incident_event', [
            'incident_id' => $incidentId,
            'type' => mb_substr($type, 0, 32),
            'note' => mb_substr($note, 0, 255),
            'actor_id' => $actorId,
            'actor_label' => mb_substr($actorLabel, 0, 190),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function addPhoto(int $incidentId, int $documentId): void
    {
        $this->database->insert('incident_photo', [
            'incident_id' => $incidentId,
            'document_id' => $documentId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<IncidentEvent>
     */
    public function eventsFor(int $incidentId): array
    {
        return array_map(
            static fn (array $row): IncidentEvent => IncidentEvent::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `incident_event` WHERE `incident_id` = :incident ORDER BY `id`',
                ['incident' => $incidentId]
            )
        );
    }

    /**
     * @return list<int>
     */
    public function photosFor(int $incidentId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['document_id'],
            $this->database->fetchAll(
                'SELECT `document_id` FROM `incident_photo` WHERE `incident_id` = :incident ORDER BY `id`',
                ['incident' => $incidentId]
            )
        );
    }
}
