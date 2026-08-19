<?php

declare(strict_types=1);

namespace SecondStay\Notification;

use SecondStay\Database\Database;

/**
 * Journal des notifications : une ligne par canal et par tentative
 * (ARCHITECTURE.md §14). Le contenu du message n'y figure pas.
 */
final class NotificationRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(
        NotificationEvent $event,
        NotificationChannel $channel,
        string $status,
        ?int $userId,
        string $locale,
        string $subject = '',
        string $reference = '',
        string $error = '',
        string $correlationId = '',
    ): int {
        return $this->database->insert('notification', [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'event' => $event->value,
            'channel' => $channel->value,
            'status' => $status,
            'user_id' => $userId,
            'locale' => $locale,
            'subject' => mb_substr($subject, 0, 255),
            'reference' => mb_substr($reference, 0, 190),
            'error' => mb_substr($error, 0, 255),
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `notification` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEvent(NotificationEvent $event): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `notification` WHERE `event` = :event ORDER BY `id`',
            ['event' => $event->value]
        );
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->database->execute(
            'DELETE FROM `notification` WHERE `created_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        )->rowCount();
    }
}
