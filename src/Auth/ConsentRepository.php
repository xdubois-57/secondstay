<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Database\Database;

/**
 * Consentements versionnés (RGPD, SECURITY.md §21).
 *
 * Un consentement n'est jamais mis à jour : chaque acceptation est une ligne
 * nouvelle, ce qui préserve l'historique.
 */
final class ConsentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(int $userId, string $type, string $version, string $locale, string $ip = ''): int
    {
        return $this->database->insert('consent', [
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 48),
            'version' => mb_substr($version, 0, 32),
            'locale' => $locale,
            'accepted_at' => gmdate('Y-m-d H:i:s'),
            'ip' => mb_substr($ip, 0, 45),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `consent` WHERE `user_id` = :user ORDER BY `id` DESC',
            ['user' => $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(int $userId, string $type): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `consent` WHERE `user_id` = :user AND `type` = :type ORDER BY `id` DESC LIMIT 1',
            ['user' => $userId, 'type' => $type]
        );
    }

    public function has(int $userId, string $type): bool
    {
        return $this->latest($userId, $type) !== null;
    }
}
