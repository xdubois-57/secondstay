<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

use SecondStay\Database\Database;

/**
 * Sources, exécutions et activités produites.
 */
final class LocalContentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    // --- Sources -------------------------------------------------------------------

    /**
     * @return list<LocalSource>
     */
    public function sources(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM `local_source` ' . ($onlyActive ? 'WHERE `active` = 1 ' : '') . 'ORDER BY `id`';

        return array_map(
            static fn (array $row): LocalSource => LocalSource::fromRow($row),
            $this->database->fetchAll($sql)
        );
    }

    public function findSource(int $id): ?LocalSource
    {
        $row = $this->database->fetchOne('SELECT * FROM `local_source` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : LocalSource::fromRow($row);
    }

    public function addSource(string $url, string $label): int
    {
        return $this->database->insert('local_source', [
            'url' => mb_substr($url, 0, 500),
            'label' => mb_substr($label, 0, 190),
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setSourceActive(int $id, bool $active): void
    {
        $this->database->update('local_source', ['active' => $active ? 1 : 0], ['id' => $id]);
    }

    public function deleteSource(int $id): void
    {
        $this->database->delete('local_source', ['id' => $id]);
    }

    public function recordFetch(int $id, string $status): void
    {
        $this->database->update('local_source', [
            'last_fetch_at' => gmdate('Y-m-d H:i:s'),
            'last_status' => mb_substr($status, 0, 48),
        ], ['id' => $id]);
    }

    // --- Exécutions ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function startGeneration(array $data): int
    {
        return $this->database->insert('local_generation', $data + ['created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function finishGeneration(int $id, array $data): void
    {
        $this->database->update('local_generation', $data, ['id' => $id]);
    }

    /**
     * Dernière exécution d'un séjour, quelle qu'en soit l'issue.
     *
     * @return array<string, mixed>|null
     */
    public function lastGeneration(?int $bookingId): ?array
    {
        if ($bookingId === null) {
            return $this->database->fetchOne(
                'SELECT * FROM `local_generation` WHERE `booking_id` IS NULL ORDER BY `id` DESC LIMIT 1'
            );
        }

        return $this->database->fetchOne(
            'SELECT * FROM `local_generation` WHERE `booking_id` = :booking ORDER BY `id` DESC LIMIT 1',
            ['booking' => $bookingId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentGenerations(int $limit = 20): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `local_generation` ORDER BY `id` DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    // --- Activités --------------------------------------------------------------------

    /**
     * Remplace les activités d'un séjour dans une langue.
     *
     * Une génération remplace la précédente : accumuler produirait des
     * doublons à chaque rafraîchissement hebdomadaire.
     *
     * @param list<array<string, mixed>> $activities
     */
    public function replaceActivities(int $generationId, ?int $bookingId, string $locale, array $activities): int
    {
        $this->database->transaction(function () use ($generationId, $bookingId, $locale, $activities): void {
            if ($bookingId === null) {
                $this->database->execute(
                    'DELETE FROM `local_activity` WHERE `booking_id` IS NULL AND `locale` = :locale',
                    ['locale' => $locale]
                );
            } else {
                $this->database->execute(
                    'DELETE FROM `local_activity` WHERE `booking_id` = :booking AND `locale` = :locale',
                    ['booking' => $bookingId, 'locale' => $locale]
                );
            }

            foreach ($activities as $activity) {
                $this->database->insert(
                    'local_activity',
                    $activity + [
                        'generation_id' => $generationId,
                        'booking_id' => $bookingId,
                        'locale' => $locale,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]
                );
            }
        });

        return count($activities);
    }

    /**
     * Activités d'un séjour recouvrant une fenêtre de dates.
     *
     * @return list<LocalActivity>
     */
    public function activitiesFor(int $bookingId, string $locale, string $from, string $to): array
    {
        return array_map(
            static fn (array $row): LocalActivity => LocalActivity::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `local_activity` '
                . 'WHERE `booking_id` = :booking AND `locale` = :locale '
                . 'AND `starts_on` <= :window_end AND `ends_on` >= :window_start '
                . 'ORDER BY `starts_on`, `title`',
                [
                    'booking' => $bookingId,
                    'locale' => $locale,
                    'window_start' => $from,
                    'window_end' => $to,
                ]
            )
        );
    }

    /**
     * Toutes les activités d'un séjour, filtre de dates compris ou non.
     *
     * @return list<LocalActivity>
     */
    public function allActivitiesFor(int $bookingId, string $locale): array
    {
        return array_map(
            static fn (array $row): LocalActivity => LocalActivity::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `local_activity` WHERE `booking_id` = :booking AND `locale` = :locale '
                . 'ORDER BY `starts_on`, `title`',
                ['booking' => $bookingId, 'locale' => $locale]
            )
        );
    }
}
