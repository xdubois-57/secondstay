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

    public function create(DateRange $range, string $kind, string $label, ?int $userId = null): int
    {
        return $this->database->insert('availability_block', [
            'start_day' => $range->arrivalKey(),
            'end_day' => $range->lastNightKey(),
            'kind' => in_array($kind, self::KINDS, true) ? $kind : self::KIND_OWNER,
            'label' => mb_substr($label, 0, 190),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
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
