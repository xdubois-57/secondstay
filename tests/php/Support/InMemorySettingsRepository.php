<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use SecondStay\Settings\SettingsStore;

/**
 * Stockage de réglages en mémoire : permet de tester la logique de réglages
 * sans base de données.
 */
final class InMemorySettingsRepository implements SettingsStore
{
    /** @var array<string, array{value: ?string, is_secret: bool}> */
    private array $values = [];

    /**
     * @return array<string, array{value: ?string, is_secret: bool}>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function set(string $key, ?string $value, bool $isSecret): void
    {
        $this->values[$key] = ['value' => $value, 'is_secret' => $isSecret];
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}
