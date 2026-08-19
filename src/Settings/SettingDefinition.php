<?php

declare(strict_types=1);

namespace SecondStay\Settings;

/**
 * Déclaration typée d'un réglage : validation, aide et applicabilité.
 */
final class SettingDefinition
{
    /**
     * @param list<string> $enumValues
     */
    public function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly mixed $default = null,
        public readonly string $module = 'core',
        public readonly string $helpKey = '',
        public readonly array $enumValues = [],
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly bool $required = false,
        public readonly ?string $appliesWhen = null,
    ) {
    }

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }

    public function labelKey(): string
    {
        return 'settings.' . $this->key . '.label';
    }

    public function helpTranslationKey(): string
    {
        return $this->helpKey !== '' ? $this->helpKey : 'settings.' . $this->key . '.help';
    }
}
