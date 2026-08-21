<?php

declare(strict_types=1);

namespace SecondStay\Pwa;

use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use SecondStay\Settings\SettingsService;

/**
 * Manifeste d'application installable (SPECIFICATIONS.md §43).
 *
 * Le manifeste est **localisé** : le nom, la description et les raccourcis
 * suivent la langue demandée, et l'URL de démarrage porte le préfixe de
 * langue correspondant. Aucun contenu propre au logement n'est figé dans le
 * dépôt : tout provient des réglages de l'installation.
 */
final class ManifestBuilder
{
    public const ICON_SIZES = [192, 512];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Translator $translator,
        private readonly string $basePath = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $locale): array
    {
        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;
        $name = $this->settings->string('property.name');
        if ($name === '') {
            $name = 'SecondStay';
        }

        $base = $this->basePath;

        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            $icons[] = [
                'src' => $base . '/icon-' . $size . '.png',
                'sizes' => $size . 'x' . $size,
                'type' => 'image/png',
                'purpose' => 'any',
            ];
            $icons[] = [
                'src' => $base . '/icon-maskable-' . $size . '.png',
                'sizes' => $size . 'x' . $size,
                'type' => 'image/png',
                'purpose' => 'maskable',
            ];
        }

        return [
            'id' => $base . '/' . $locale . '/',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => $this->translator->trans('pwa.description', ['property' => $name], $locale),
            'lang' => $locale,
            'dir' => 'ltr',
            'start_url' => $base . '/' . $locale . '/',
            'scope' => $base . '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => $this->colour('pwa.background_color', '#f8f9fa'),
            'theme_color' => $this->colour('pwa.theme_color', '#0d6efd'),
            'icons' => $icons,
            'shortcuts' => [
                [
                    'name' => $this->translator->trans('pwa.shortcut.account', [], $locale),
                    'url' => $base . '/' . $locale . '/account',
                ],
                [
                    'name' => $this->translator->trans('pwa.shortcut.gallery', [], $locale),
                    'url' => $base . '/' . $locale . '/gallery',
                ],
            ],
        ];
    }

    public function toJson(string $locale): string
    {
        return (string) json_encode(
            $this->build($locale),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    /**
     * Couleur du manifeste, ramenée à une valeur que le navigateur accepte.
     *
     * Un réglage vide ou mal recopié doit donner la teinte d'origine plutôt
     * qu'un manifeste invalide : l'application resterait installable, mais
     * la barre système redeviendrait blanche sans explication.
     */
    private function colour(string $key, string $fallback): string
    {
        $value = strtolower(trim($this->settings->string($key)));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : $fallback;
    }
}
