<?php

declare(strict_types=1);

namespace SecondStay\Notification;

use SecondStay\Database\Database;

/**
 * Préférences de canal. L'absence de ligne vaut « actif » : un compte neuf
 * reçoit ses notifications sans configuration préalable.
 */
final class NotificationPreferenceRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function isEnabled(int $userId, NotificationChannel $channel): bool
    {
        $value = $this->database->fetchValue(
            'SELECT `enabled` FROM `notification_preference` WHERE `user_id` = :user AND `channel` = :channel',
            ['user' => $userId, 'channel' => $channel->value]
        );

        return $value === null || (int) $value === 1;
    }

    public function set(int $userId, NotificationChannel $channel, bool $enabled): void
    {
        $this->database->execute(
            'INSERT INTO `notification_preference` (`user_id`, `channel`, `enabled`) '
            . 'VALUES (:user, :channel, :enabled) ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`)',
            ['user' => $userId, 'channel' => $channel->value, 'enabled' => $enabled ? 1 : 0]
        );
    }

    /**
     * @return array<string, bool>
     */
    public function forUser(int $userId): array
    {
        $preferences = [];
        foreach (NotificationChannel::cases() as $channel) {
            $preferences[$channel->value] = $this->isEnabled($userId, $channel);
        }

        return $preferences;
    }
}
