<?php

declare(strict_types=1);

namespace SecondStay\Content;

/**
 * Arborescence de contenu créée à l'installation.
 *
 * Les textes sont fournis via des clés de traduction : l'installation produit
 * donc immédiatement un site complet dans les quatre langues, que le
 * propriétaire peut ensuite réécrire.
 */
final class DefaultContent
{
    /**
     * @return list<array{
     *     slug: string,
     *     kind: PageKind,
     *     season: Season,
     *     parent: ?string,
     *     show_in_menu: bool,
     *     is_system: bool,
     *     key: string
     * }>
     */
    public static function pages(): array
    {
        return [
            self::page('home', PageKind::Home, 'home', showInMenu: true, system: true),
            self::page('property', PageKind::Page, 'property', system: true),
            self::page('availability', PageKind::Availability, 'availability', system: true),
            self::page('rates', PageKind::Rates, 'rates', system: true),
            self::page('gallery', PageKind::Gallery, 'gallery', system: true),
            self::page('activities', PageKind::Page, 'activities'),
            self::page('access', PageKind::Page, 'access'),
            self::page('contact', PageKind::Contact, 'contact', system: true),
            self::page('legal-notice', PageKind::Legal, 'legal_notice', showInMenu: false, system: true),
            self::page('privacy', PageKind::Legal, 'privacy', showInMenu: false, system: true),
            self::page('terms', PageKind::Legal, 'terms', showInMenu: false, system: true),
        ];
    }

    /**
     * Pages légales listées dans le pied de page.
     *
     * @return list<string>
     */
    public static function legalSlugs(): array
    {
        return ['legal-notice', 'privacy', 'terms'];
    }

    /**
     * @return array{
     *     slug: string,
     *     kind: PageKind,
     *     season: Season,
     *     parent: ?string,
     *     show_in_menu: bool,
     *     is_system: bool,
     *     key: string
     * }
     */
    private static function page(
        string $slug,
        PageKind $kind,
        string $key,
        ?string $parent = null,
        bool $showInMenu = true,
        bool $system = false,
        Season $season = Season::All,
    ): array {
        return [
            'slug' => $slug,
            'kind' => $kind,
            'season' => $season,
            'parent' => $parent,
            'show_in_menu' => $showInMenu,
            'is_system' => $system,
            'key' => $key,
        ];
    }
}
