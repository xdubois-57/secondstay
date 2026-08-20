<?php

declare(strict_types=1);

namespace SecondStay\Settings;

use SecondStay\Support\Money;

/**
 * Validation et normalisation des valeurs de réglages.
 *
 * Retourne toujours soit la valeur normalisée, soit une clé de traduction
 * d'erreur : jamais un message en dur (I18N.md §2).
 */
final class SettingValidator
{
    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    public function validate(SettingDefinition $definition, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            if ($definition->required) {
                return ['ok' => false, 'error' => 'settings.error.required'];
            }

            return ['ok' => true, 'value' => $definition->type === SettingType::Bool ? false : null];
        }

        return match ($definition->type) {
            SettingType::Bool => ['ok' => true, 'value' => $this->toBool($raw)],
            SettingType::Integer => $this->validateInteger($definition, $raw),
            SettingType::Decimal => $this->validateDecimal($definition, $raw),
            SettingType::Money => $this->validateMoney($definition, $raw),
            SettingType::Enum => $this->validateEnum($definition, $raw),
            SettingType::Email => $this->validateEmail($raw),
            SettingType::Url => $this->validateUrl($raw),
            SettingType::Date => $this->validatePattern($raw, '/^\d{4}-\d{2}-\d{2}$/', 'settings.error.date'),
            SettingType::Time => $this->validatePattern($raw, '/^([01]\d|2[0-3]):[0-5]\d$/', 'settings.error.time'),
            SettingType::Duration => $this->validateDuration($definition, $raw),
            SettingType::Json => $this->validateJson($raw),
            default => $this->validateString($definition, $raw),
        };
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateInteger(SettingDefinition $definition, mixed $raw): array
    {
        $string = is_scalar($raw) ? trim((string) $raw) : '';
        if (preg_match('/^-?\d+$/', $string) !== 1) {
            return ['ok' => false, 'error' => 'settings.error.integer'];
        }

        $value = (int) $string;

        return $this->checkRange($definition, (float) $value, $value);
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateDecimal(SettingDefinition $definition, mixed $raw): array
    {
        $string = is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        if (!is_numeric($string)) {
            return ['ok' => false, 'error' => 'settings.error.decimal'];
        }

        $value = (float) $string;

        return $this->checkRange($definition, $value, $value);
    }

    /**
     * Les montants sont saisis en euros et stockés en centimes entiers
     * (I18N.md §7 : logique financière canonique).
     *
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateMoney(SettingDefinition $definition, mixed $raw): array
    {
        $cents = is_scalar($raw) ? Money::parse((string) $raw) : null;
        if ($cents === null) {
            return ['ok' => false, 'error' => 'settings.error.money'];
        }

        return $this->checkRange($definition, (float) $cents, $cents);
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateEnum(SettingDefinition $definition, mixed $raw): array
    {
        $string = is_scalar($raw) ? (string) $raw : '';
        if (!in_array($string, $definition->enumValues, true)) {
            return ['ok' => false, 'error' => 'settings.error.enum'];
        }

        return ['ok' => true, 'value' => $string];
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateEmail(mixed $raw): array
    {
        $string = is_scalar($raw) ? trim((string) $raw) : '';
        if (filter_var($string, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'settings.error.email'];
        }

        return ['ok' => true, 'value' => $string];
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateUrl(mixed $raw): array
    {
        $string = is_scalar($raw) ? trim((string) $raw) : '';
        if (filter_var($string, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'error' => 'settings.error.url'];
        }

        $scheme = strtolower((string) parse_url($string, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'settings.error.url_scheme'];
        }

        return ['ok' => true, 'value' => $string];
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validatePattern(mixed $raw, string $pattern, string $error): array
    {
        $string = is_scalar($raw) ? trim((string) $raw) : '';
        if (preg_match($pattern, $string) !== 1) {
            return ['ok' => false, 'error' => $error];
        }

        return ['ok' => true, 'value' => $string];
    }

    /**
     * Durée exprimée en minutes.
     *
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateDuration(SettingDefinition $definition, mixed $raw): array
    {
        $string = is_scalar($raw) ? trim((string) $raw) : '';
        if (preg_match('/^\d+$/', $string) !== 1) {
            return ['ok' => false, 'error' => 'settings.error.duration'];
        }

        $value = (int) $string;

        return $this->checkRange($definition, (float) $value, $value);
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return ['ok' => true, 'value' => $raw];
        }

        $string = is_scalar($raw) ? (string) $raw : '';
        /** @var mixed $decoded */
        $decoded = json_decode($string, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return ['ok' => false, 'error' => 'settings.error.json'];
        }

        return ['ok' => true, 'value' => $decoded];
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function validateString(SettingDefinition $definition, mixed $raw): array
    {
        $string = is_scalar($raw) ? (string) $raw : '';
        $string = $definition->type === SettingType::Text ? $string : trim($string);

        if ($definition->max !== null && mb_strlen($string) > (int) $definition->max) {
            return ['ok' => false, 'error' => 'settings.error.too_long'];
        }

        return ['ok' => true, 'value' => $string];
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, error: string}
     */
    private function checkRange(SettingDefinition $definition, float $numeric, mixed $value): array
    {
        if ($definition->min !== null && $numeric < $definition->min) {
            return ['ok' => false, 'error' => 'settings.error.too_small'];
        }
        if ($definition->max !== null && $numeric > $definition->max) {
            return ['ok' => false, 'error' => 'settings.error.too_large'];
        }

        return ['ok' => true, 'value' => $value];
    }
}
