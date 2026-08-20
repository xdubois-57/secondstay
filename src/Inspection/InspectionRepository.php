<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

use PDOException;
use SecondStay\Database\Database;

final class InspectionRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(
        private readonly Database $database,
        private readonly ZoneRepository $zones,
    ) {
    }

    /**
     * Ouvre l'état des lieux s'il n'existe pas, et renvoie son identifiant.
     *
     * L'unicité (séjour, type) est portée par la base : deux ouvertures
     * simultanées — le voyageur sur son téléphone, le responsable sur le sien
     * — ne peuvent pas produire deux états des lieux.
     */
    public function open(int $bookingId, InspectionKind $kind, string $locale): int
    {
        try {
            return $this->database->insert('inspection', [
                'booking_id' => $bookingId,
                'kind' => $kind->value,
                'status' => InspectionStatus::Open->value,
                'locale' => $locale,
                'started_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            $row = $this->database->fetchOne(
                'SELECT `id` FROM `inspection` WHERE `booking_id` = :booking AND `kind` = :kind',
                ['booking' => $bookingId, 'kind' => $kind->value]
            );

            return $row === null ? 0 : (int) $row['id'];
        }
    }

    /**
     * État des lieux complet, zones et photos comprises.
     */
    public function find(int $id, ?string $locale = null): ?Inspection
    {
        $row = $this->database->fetchOne('SELECT * FROM `inspection` WHERE `id` = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }

        return Inspection::fromRow($row, $this->entriesFor((int) $row['id'], $locale ?? (string) $row['locale']));
    }

    public function findFor(int $bookingId, InspectionKind $kind, ?string $locale = null): ?Inspection
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `inspection` WHERE `booking_id` = :booking AND `kind` = :kind',
            ['booking' => $bookingId, 'kind' => $kind->value]
        );

        if ($row === null) {
            return null;
        }

        return Inspection::fromRow($row, $this->entriesFor((int) $row['id'], $locale ?? (string) $row['locale']));
    }

    /**
     * @return list<Inspection>
     */
    public function forBooking(int $bookingId, ?string $locale = null): array
    {
        return array_map(
            fn (array $row): Inspection => Inspection::fromRow(
                $row,
                $this->entriesFor((int) $row['id'], $locale ?? (string) $row['locale'])
            ),
            $this->database->fetchAll(
                'SELECT * FROM `inspection` WHERE `booking_id` = :booking ORDER BY `id`',
                ['booking' => $bookingId]
            )
        );
    }

    /**
     * Crée les constats manquants pour les zones actives.
     *
     * Une zone ajoutée après l'ouverture apparaît donc au prochain affichage,
     * plutôt que d'être silencieusement absente de l'état des lieux.
     */
    public function ensureEntries(int $inspectionId, string $locale): void
    {
        $existing = [];
        foreach ($this->database->fetchAll(
            'SELECT `zone_id` FROM `inspection_entry` WHERE `inspection_id` = :inspection',
            ['inspection' => $inspectionId]
        ) as $row) {
            $existing[(int) $row['zone_id']] = true;
        }

        foreach ($this->zones->active($locale) as $zone) {
            if (isset($existing[$zone->id])) {
                continue;
            }

            try {
                $this->database->insert('inspection_entry', [
                    'inspection_id' => $inspectionId,
                    'zone_id' => $zone->id,
                    'state' => EntryState::Pending->value,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                    throw $exception;
                }
            }
        }
    }

    public function entry(int $inspectionId, int $zoneId): ?int
    {
        $row = $this->database->fetchOne(
            'SELECT `id` FROM `inspection_entry` WHERE `inspection_id` = :inspection AND `zone_id` = :zone',
            ['inspection' => $inspectionId, 'zone' => $zoneId]
        );

        return $row === null ? null : (int) $row['id'];
    }

    public function updateEntry(int $entryId, EntryState $state, string $note): void
    {
        $this->database->update('inspection_entry', [
            'state' => $state->value,
            'note' => $note === '' ? null : mb_substr($note, 0, 4000),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $entryId]);
    }

    public function addPhoto(int $entryId, int $documentId): void
    {
        $this->database->insert('inspection_photo', [
            'entry_id' => $entryId,
            'document_id' => $documentId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function complete(int $inspectionId, ?int $userId, string $summary): void
    {
        $this->database->update('inspection', [
            'status' => InspectionStatus::Completed->value,
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'completed_by' => $userId,
            'summary' => mb_substr($summary, 0, 255),
        ], ['id' => $inspectionId]);
    }

    /**
     * @return list<InspectionEntry>
     */
    private function entriesFor(int $inspectionId, string $locale): array
    {
        $photos = [];
        foreach ($this->database->fetchAll(
            'SELECT p.`entry_id`, p.`document_id` FROM `inspection_photo` p '
            . 'INNER JOIN `inspection_entry` e ON e.`id` = p.`entry_id` '
            . 'WHERE e.`inspection_id` = :inspection ORDER BY p.`id`',
            ['inspection' => $inspectionId]
        ) as $row) {
            $photos[(int) $row['entry_id']][] = (int) $row['document_id'];
        }

        $entries = [];
        foreach ($this->database->fetchAll(
            'SELECT e.*, z.`code`, z.`position`, z.`photo_required`, z.`active`, z.`reference_note`, '
            . 't.`name`, t.`instructions` '
            . 'FROM `inspection_entry` e '
            . 'INNER JOIN `inspection_zone` z ON z.`id` = e.`zone_id` '
            . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale '
            . 'WHERE e.`inspection_id` = :inspection ORDER BY z.`position`, z.`id`',
            ['inspection' => $inspectionId, 'locale' => $locale]
        ) as $row) {
            $entries[] = new InspectionEntry(
                (int) $row['id'],
                $inspectionId,
                // `$row` porte déjà un `id` — celui du constat : la zone est
                // reconstruite explicitement, sans risque de confusion.
                Zone::fromRow([
                    'id' => (int) $row['zone_id'],
                    'code' => $row['code'],
                    'position' => $row['position'],
                    'photo_required' => $row['photo_required'],
                    'active' => $row['active'],
                    'reference_note' => $row['reference_note'],
                    'name' => $row['name'],
                    'instructions' => $row['instructions'],
                ]),
                EntryState::fromString((string) $row['state']),
                (string) ($row['note'] ?? ''),
                $photos[(int) $row['id']] ?? [],
                (string) $row['updated_at'],
            );
        }

        return $entries;
    }
}
