<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use SecondStay\Database\Database;

final class MailRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): int
    {
        return $this->database->insert('mail_message', $data + ['created_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function markSent(int $id, string $messageId): void
    {
        $this->database->update('mail_message', [
            'status' => 'sent',
            'message_id' => $messageId,
            'sent_at' => gmdate('Y-m-d H:i:s'),
            'error' => null,
        ], ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->database->update('mail_message', [
            'status' => 'failed',
            'error' => mb_substr($error, 0, 2000),
        ], ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));

        return $this->database->fetchAll(
            sprintf('SELECT * FROM `mail_message` ORDER BY `id` DESC LIMIT %d', $limit)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `mail_message` WHERE `user_id` = :user ORDER BY `id` DESC',
            ['user' => $userId]
        );
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `mail_message` WHERE `status` = :status',
            ['status' => $status]
        );
    }
}
