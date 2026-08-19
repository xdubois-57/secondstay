<?php

declare(strict_types=1);

namespace SecondStay\Settings;

/**
 * Stockage des réglages.
 *
 * L'interface permet de tester la logique de réglages (typage, secrets,
 * rotation de clé) sans base de données, et laisse la porte ouverte à un
 * stockage différent sans toucher au service.
 */
interface SettingsStore
{
    /**
     * @return array<string, array{value: ?string, is_secret: bool}>
     */
    public function all(): array;

    public function set(string $key, ?string $value, bool $isSecret): void;

    public function delete(string $key): void;
}
