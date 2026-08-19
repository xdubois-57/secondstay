<?php

declare(strict_types=1);

namespace SecondStay\Core;

final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public static function load(string $projectRoot): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $projectRoot . '/config/app.php';

        $localFile = $projectRoot . '/config/local.php';
        if (is_file($localFile)) {
            /** @var array<string, mixed> $local */
            $local = require $localFile;
            $defaults = self::mergeDeep($defaults, $local);
        }

        $config = new self($defaults);
        $config->applyEnvironmentOverrides();

        return $config;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $existing */
                $existing = $base[$key];
                /** @var array<string, mixed> $value */
                $base[$key] = self::mergeDeep($existing, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function applyEnvironmentOverrides(): void
    {
        $map = [
            'SECONDSTAY_ENV' => 'app.env',
            'SECONDSTAY_DEBUG' => 'app.debug',
            'SECONDSTAY_DB_DSN' => 'database.dsn',
            'SECONDSTAY_DB_HOST' => 'database.host',
            'SECONDSTAY_DB_PORT' => 'database.port',
            'SECONDSTAY_DB_NAME' => 'database.name',
            'SECONDSTAY_DB_USER' => 'database.user',
            'SECONDSTAY_DB_PASSWORD' => 'database.password',
            'SECONDSTAY_ENCRYPTION_KEY' => 'security.encryption_key',
            'SECONDSTAY_STORAGE_PATH' => 'paths.storage',
            'SECONDSTAY_DEFAULT_LOCALE' => 'i18n.default_locale',
        ];

        foreach ($map as $env => $key) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                continue;
            }

            if (in_array($value, ['true', 'false'], true)) {
                $this->set($key, $value === 'true');
                continue;
            }

            if (ctype_digit($value)) {
                $this->set($key, (int) $value);
                continue;
            }

            $this->set($key, $value);
        }
    }

    private const MISSING = "\0secondstay-missing";

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor = $this->cursorFor($segments);

        return $cursor === self::MISSING ? $default : $cursor;
    }

    /**
     * @param list<string> $segments
     */
    private function cursorFor(array $segments): mixed
    {
        $cursor = $this->wrap($this->values);

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return self::MISSING;
            }
            $cursor = $this->wrap($cursor[$segment]);
        }

        return $cursor;
    }

    /**
     * Neutralise l'inférence de type sur une valeur de configuration
     * volontairement hétérogène.
     */
    private function wrap(mixed $value): mixed
    {
        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    public function listOfStrings(string $key): array
    {
        $result = [];
        foreach ($this->array($key) as $value) {
            if (is_string($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->values;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            /** @var array<string, mixed> $next */
            $next = &$cursor[$segment];
            $cursor = &$next;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
