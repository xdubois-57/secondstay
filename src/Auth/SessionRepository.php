<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Database\Database;

/**
 * Sessions persistées : liste des appareils, révocation individuelle et
 * révocation globale (SPECIFICATIONS.md §10).
 */
final class SessionRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        string $tokenHash,
        int $userId,
        int $lifetimeMinutes,
        string $ip,
        string $userAgent,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->insert('user_session', [
            'id' => $tokenHash,
            'user_id' => $userId,
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($lifetimeMinutes * 60)),
            'ip' => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 255),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $tokenHash): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `user_session` WHERE `id` = :id AND `revoked_at` IS NULL AND `expires_at` > :now',
            ['id' => $tokenHash, 'now' => gmdate('Y-m-d H:i:s')]
        );
    }

    public function touch(string $tokenHash, int $lifetimeMinutes): void
    {
        $this->database->update(
            'user_session',
            [
                'last_seen_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + ($lifetimeMinutes * 60)),
            ],
            ['id' => $tokenHash]
        );
    }

    public function revoke(string $tokenHash): void
    {
        $this->database->update('user_session', ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $tokenHash]);
    }

    public function revokeAllForUser(int $userId, ?string $exceptTokenHash = null): int
    {
        $sql = 'UPDATE `user_session` SET `revoked_at` = :now WHERE `user_id` = :user_id AND `revoked_at` IS NULL';
        $parameters = ['now' => gmdate('Y-m-d H:i:s'), 'user_id' => $userId];

        if ($exceptTokenHash !== null) {
            $sql .= ' AND `id` <> :except';
            $parameters['except'] = $exceptTokenHash;
        }

        return $this->database->execute($sql, $parameters)->rowCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `user_session` WHERE `user_id` = :user_id AND `revoked_at` IS NULL '
            . 'AND `expires_at` > :now ORDER BY `last_seen_at` DESC',
            ['user_id' => $userId, 'now' => gmdate('Y-m-d H:i:s')]
        );
    }

    public function purgeExpired(): int
    {
        return $this->database->execute(
            'DELETE FROM `user_session` WHERE `expires_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - 86400 * 30)]
        )->rowCount();
    }
}
