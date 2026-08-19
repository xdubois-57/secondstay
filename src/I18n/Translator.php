<?php

declare(strict_types=1);

namespace SecondStay\I18n;

use RuntimeException;

/**
 * Catalogue de traductions systeme, charge depuis translations/<locale>/<domain>.php.
 *
 * Les cles sont de la forme `domaine.section.cle`. Le premier segment designe
 * le fichier de catalogue.
 */
final class Translator
{
    /** @var array<string, array<string, array<string, string>>> locale => domaine => cles aplaties */
    private array $catalogues = [];

    private string $locale;

    /** @var list<string> */
    private array $missingKeys = [];

    public function __construct(
        private readonly string $translationsPath,
        string $locale = Locales::FALLBACK,
        private readonly string $fallbackLocale = Locales::FALLBACK,
        private readonly bool $collectMissing = false,
    ) {
        $this->locale = Locales::isSupported($locale) ? $locale : $fallbackLocale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = Locales::isSupported($locale) ? $locale : $this->fallbackLocale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function fallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        $target = $locale !== null && Locales::isSupported($locale) ? $locale : $this->locale;

        $value = $this->lookup($key, $target);
        if ($value === null) {
            $value = $this->lookup($key, $this->fallbackLocale);
        }

        if ($value === null) {
            if ($this->collectMissing && !in_array($key, $this->missingKeys, true)) {
                $this->missingKeys[] = $key;
            }

            // Ne jamais afficher une cle brute : le dernier segment lisible est
            // toujours preferable a `booking.status.confirmed` dans l'UI.
            return $this->humanise($key);
        }

        return $this->interpolate($value, $parameters);
    }

    /**
     * Pluriel simple : `key` doit contenir des variantes separees par `|`
     * dans l'ordre zero|un|autre, ou un|autre.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function transChoice(string $key, int $count, array $parameters = [], ?string $locale = null): string
    {
        $raw = $this->trans($key, [], $locale);
        $variants = explode('|', $raw);
        $parameters['count'] = $count;

        if (count($variants) === 3) {
            $variant = $count === 0 ? $variants[0] : ($count === 1 ? $variants[1] : $variants[2]);
        } elseif (count($variants) === 2) {
            $variant = $count <= 1 ? $variants[0] : $variants[1];
        } else {
            $variant = $variants[0];
        }

        return $this->interpolate($variant, $parameters);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        $target = $locale ?? $this->locale;

        return $this->lookup($key, $target) !== null;
    }

    /**
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return $this->missingKeys;
    }

    /**
     * @return array<string, string> cles aplaties du catalogue complet d'une locale
     */
    public function catalogue(string $locale): array
    {
        $this->loadLocale($locale);
        $result = [];
        foreach ($this->catalogues[$locale] ?? [] as $domain => $entries) {
            foreach ($entries as $key => $value) {
                $result[$domain . '.' . $key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function domains(): array
    {
        $path = $this->translationsPath . '/' . Locales::FALLBACK;
        $files = glob($path . '/*.php');
        if ($files === false) {
            return [];
        }

        return array_values(array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files
        ));
    }

    private function lookup(string $key, string $locale): ?string
    {
        $position = strpos($key, '.');
        if ($position === false) {
            return null;
        }

        $domain = substr($key, 0, $position);
        $rest = substr($key, $position + 1);

        $this->loadLocale($locale);

        $value = $this->catalogues[$locale][$domain][$rest] ?? null;

        return is_string($value) ? $value : null;
    }

    private function loadLocale(string $locale): void
    {
        if (isset($this->catalogues[$locale])) {
            return;
        }

        $this->catalogues[$locale] = [];
        $directory = $this->translationsPath . '/' . $locale;
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $domain = basename($file, '.php');
            /** @var mixed $data */
            $data = require $file;
            if (!is_array($data)) {
                throw new RuntimeException('Catalogue de traduction invalide : ' . $file);
            }
            /** @var array<string, mixed> $data */
            $this->catalogues[$locale][$domain] = $this->flatten($data);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $composed = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result += $this->flatten($value, $composed);
            } elseif (is_scalar($value)) {
                $result[$composed] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function interpolate(string $value, array $parameters): string
    {
        if ($parameters === []) {
            return $value;
        }

        $replacements = [];
        foreach ($parameters as $key => $parameter) {
            $replacements['{' . $key . '}'] = (string) $parameter;
        }

        return strtr($value, $replacements);
    }

    private function humanise(string $key): string
    {
        $segments = explode('.', $key);
        $last = end($segments);
        if ($last === false) {
            return $key;
        }

        return ucfirst(str_replace('_', ' ', $last));
    }
}
