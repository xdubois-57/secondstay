<?php

declare(strict_types=1);

namespace SecondStay\Settings;

use SecondStay\Database\Database;

final class SettingsRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array<string, array{value: ?string, is_secret: bool}>
     */
    public function all(): array
    {
        $rows = $this->database->fetchAll('SELECT `key`, `value`, `is_secret` FROM `setting`');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['key']] = [
                'value' => $row['value'] === null ? null : (string) $row['value'],
                'is_secret' => (bool) $row['is_secret'],
            ];
        }

        return $result;
    }

    public function set(string $key, ?string $value, bool $isSecret): void
    {
        $this->database->execute(
            'INSERT INTO `setting` (`key`, `value`, `is_secret`, `updated_at`) '
            . 'VALUES (:key, :value, :is_secret, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), '
            . '`is_secret` = VALUES(`is_secret`), `updated_at` = VALUES(`updated_at`)',
            [
                'key' => $key,
                'value' => $value,
                'is_secret' => $isSecret ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function delete(string $key): void
    {
        $this->database->delete('setting', ['key' => $key]);
    }
}
