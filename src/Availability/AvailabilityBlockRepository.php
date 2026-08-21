<?php

declare(strict_types=1);

namespace SecondStay\Availability;

use SecondStay\Database\Database;
use SecondStay\Pricing\DateRange;

/**
 * Indisponibilités décidées côté exploitation.
 *
 * `end_day` est la **dernière nuit occupée** : un blocage du 12 au 18 libère
 * le 19 pour une arrivée, exactement comme un séjour.
 */
final class AvailabilityBlockRepository
{
    public const KIND_OWNER = 'owner';
    public const KIND_MAINTENANCE = 'maintenance';
    public const KIND_EXTERNAL = 'external';

    public const KINDS = [self::KIND_OWNER, self::KIND_MAINTENANCE, self::KIND_EXTERNAL];

    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        DateRange $range,
        string $kind,
        string $label,
        ?int $userId = null,
        ?int $sourceId = null,
        string $externalUid = '',
    ): int {
        return $this->database->insert('availability_block', [
            'start_day' => $range->arrivalKey(),
            'end_day' => $range->lastNightKey(),
            'kind' => in_array($kind, self::KINDS, true) ? $kind : self::KIND_OWNER,
            'source_id' => $sourceId,
            'external_uid' => mb_substr($externalUid, 0, 190),
            'label' => mb_substr($label, 0, 190),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    /**
     * Remplace les blocages issus d'une source par ceux qu'elle publie
     * aujourd'hui.
     *
     * Une synchronisation ne touche **que** ses propres lignes : ce que le
     * propriétaire a bloqué à la main ne peut pas disparaître parce qu'un flux
     * distant a changé d'avis (SPECIFICATIONS.md §52).
     *
     * @param list<array{uid: string, start: string, end: string, summary: string}> $events
     */
    public function replaceForSource(int $sourceId, array $events): int
    {
        return (int) $this->database->transaction(function () use ($sourceId, $events): int {
            $this->database->execute(
                'DELETE FROM `availability_block` WHERE `source_id` = :source',
                ['source' => $sourceId]
            );

            $written = 0;
            foreach ($events as $event) {
                // `DTEND` est exclusif : la dernière nuit occupée est la
                // veille, comme pour un séjour.
                $range = DateRange::fromStrings($event['start'], $event['end']);
                if ($range->nights() < 1) {
                    continue;
                }

                $this->create($range, self::KIND_EXTERNAL, $event['summary'], null, $sourceId, $event['uid']);
                $written++;
            }

            return $written;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forSource(int $sourceId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `availability_block` WHERE `source_id` = :source ORDER BY `start_day`',
            ['source' => $sourceId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function overlapping(string $from, string $to): array
    {
        // Deux intervalles de nuits se chevauchent dès que chacun commence
        // avant la fin de l'autre.
        return $this->database->fetchAll(
            'SELECT * FROM `availability_block` WHERE `start_day` <= :to AND `end_day` >= :from '
            . 'ORDER BY `start_day`',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Nuits bloquées d'une plage, indexées par date ISO.
     *
     * @return array<string, array{kind: string, label: string, id: int}>
     */
    public function blockedNights(string $from, string $to): array
    {
        $blocked = [];

        foreach ($this->overlapping($from, $to) as $block) {
            $range = DateRange::fromNights((string) $block['start_day'], (string) $block['end_day']);

            foreach ($range->nightKeys() as $day) {
                if ($day < $from || $day > $to) {
                    continue;
                }
                $blocked[$day] = [
                    'kind' => (string) $block['kind'],
                    'label' => (string) $block['label'],
                    'id' => (int) $block['id'],
                ];
            }
        }

        return $blocked;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $from, int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `availability_block` WHERE `end_day` >= :from ORDER BY `start_day` LIMIT '
            . max(1, min(500, $limit)),
            ['from' => $from]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM `availability_block` WHERE `id` = :id', ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->database->delete('availability_block', ['id' => $id]) > 0;
    }

    public function purgeBefore(string $day): int
    {
        return $this->database->execute(
            'DELETE FROM `availability_block` WHERE `end_day` < :day',
            ['day' => $day]
        )->rowCount();
    }
}
