<?php

declare(strict_types=1);

namespace SecondStay\Operations;

use PDOException;
use SecondStay\Database\Database;

/**
 * Tâches d'exploitation cochées par un humain.
 */
final class TaskRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Crée la tâche si elle n'existe pas encore, et renvoie son identifiant.
     *
     * L'unicité (séjour, code) est portée par la base : deux requêtes
     * simultanées ne peuvent pas créer deux fois la même tâche.
     */
    public function ensure(int $bookingId, string $code, TaskPhase $phase, string $label = ''): int
    {
        try {
            return $this->database->insert('booking_task', [
                'booking_id' => $bookingId,
                'phase' => $phase->value,
                'code' => mb_substr($code, 0, 32),
                'label' => mb_substr($label, 0, 190),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            $existing = $this->find($bookingId, $code);

            return $existing === null ? 0 : (int) $existing['id'];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $bookingId, string $code): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `booking_task` WHERE `booking_id` = :booking AND `code` = :code',
            ['booking' => $bookingId, 'code' => $code]
        );
    }

    /**
     * @return array<string, array<string, mixed>> indexé par code
     */
    public function forBooking(int $bookingId): array
    {
        $tasks = [];

        foreach ($this->database->fetchAll(
            'SELECT * FROM `booking_task` WHERE `booking_id` = :booking ORDER BY `id`',
            ['booking' => $bookingId]
        ) as $row) {
            $tasks[(string) $row['code']] = $row;
        }

        return $tasks;
    }

    public function setDone(int $bookingId, string $code, bool $done, ?int $actorId, string $note = ''): void
    {
        $this->database->update(
            'booking_task',
            [
                'done_at' => $done ? gmdate('Y-m-d H:i:s') : null,
                'done_by' => $done ? $actorId : null,
                'note' => mb_substr($note, 0, 255),
            ],
            ['booking_id' => $bookingId, 'code' => $code]
        );
    }
}
