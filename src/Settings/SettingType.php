<?php

declare(strict_types=1);

namespace SecondStay\Settings;

/**
 * Types de réglages supportés (SPECIFICATIONS.md §14).
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Bool = 'bool';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Enum = 'enum';
    case Date = 'date';
    case Time = 'time';
    case Duration = 'duration';
    case Email = 'email';
    case Url = 'url';
    case Secret = 'secret';
    case Json = 'json';

    public function isSecret(): bool
    {
        return $this === self::Secret;
    }

    /**
     * Type d'entrée HTML recommandé pour l'UI d'administration.
     */
    public function inputType(): string
    {
        return match ($this) {
            self::Bool => 'checkbox',
            self::Integer, self::Decimal, self::Money => 'number',
            self::Date => 'date',
            self::Time => 'time',
            self::Email => 'email',
            self::Url => 'url',
            self::Secret => 'password',
            self::Text, self::Json => 'textarea',
            self::Enum => 'select',
            default => 'text',
        };
    }
}
