<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use SecondStay\Database\Database;

/**
 * Timeline d'un séjour (SPECIFICATIONS.md §25).
 *
 * Chaque étape importante y laisse une trace horodatée et attribuée. C'est ce
 * que le client et le propriétaire lisent pour comprendre l'historique sans
 * ouvrir le journal technique.
 */
final class BookingEventRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(
        int $bookingId,
        string $type,
        array $data = [],
        ?int $actorUserId = null,
        string $actorLabel = '',
    ): int {
        return $this->database->insert('booking_event', [
            'booking_id' => $bookingId,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'type' => mb_substr($type, 0, 48),
            'actor_user_id' => $actorUserId,
            'actor_label' => mb_substr($actorLabel, 0, 190),
            'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return list<array{type: string, created_at: string, actor_label: string, data: array<string, mixed>}>
     */
    public function forBooking(int $bookingId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM `booking_event` WHERE `booking_id` = :booking ORDER BY `id`',
            ['booking' => $bookingId]
        );

        return array_map(
            static function (array $row): array {
                /** @var array<string, mixed>|null $data */
                $data = $row['data'] === null ? null : json_decode((string) $row['data'], true);

                return [
                    'type' => (string) $row['type'],
                    'created_at' => (string) $row['created_at'],
                    'actor_label' => (string) $row['actor_label'],
                    'data' => is_array($data) ? $data : [],
                ];
            },
            $rows
        );
    }
}
