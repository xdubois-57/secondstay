<?php

declare(strict_types=1);

namespace SecondStay\Navigation;

use SecondStay\Auth\User;
use SecondStay\Core\Router;

/**
 * Menus du site, tels que les dessinent la barre de bureau et le panneau
 * latéral mobile (`templates/partials/nav.html.twig`).
 *
 * Un menu est un onglet de la barre ; il ouvre un panneau de colonnes
 * titrées (« mega-menu ») sur ordinateur, une section d'accordéon sur
 * téléphone. Une colonne sans titre est la colonne « par défaut » du menu.
 *
 *  - **Le logement**, pour tout le monde : l'accueil et les pages publiées
 *    sans enfant dans une colonne sans titre, puis une colonne par page qui a
 *    des sous-pages (la page elle-même en tête, ses sous-pages ensuite).
 *  - **Mon espace**, pour un client identifié : ses réservations et son
 *    compte. L'équipe y accède par son avatar, comme tout le monde.
 *  - **Administration**, pour l'équipe : les groupes de
 *    {@see \SecondStay\Controller\Admin\AdminNavigation}, un par colonne.
 *
 * La page courante est la plus longue correspondance avec le chemin courant :
 * `/admin/bookings/12` sélectionne « Réservations », jamais aussi « Tableau de
 * bord » parce qu'il vit sous `/admin`. Le menu qui la contient est le menu
 * actif — son onglet reste souligné quel que soit le panneau ouvert.
 *
 * Ce filtrage est un confort de lecture, jamais une protection : chaque
 * contrôleur vérifie le rôle côté serveur (SECURITY.md §5).
 */
final class SiteNavigation
{
    /**
     * Icône de chaque section d'administration (Bootstrap Icons embarqué).
     */
    public const ADMIN_ICONS = [
        'dashboard' => 'bi-speedometer2',
        'operations' => 'bi-list-check',
        'bookings' => 'bi-calendar-check',
        'pricing' => 'bi-tag',
        'payments' => 'bi-credit-card',
        'reports' => 'bi-bar-chart',
        'stay' => 'bi-book',
        'local' => 'bi-geo-alt',
        'inspections' => 'bi-clipboard-check',
        'incidents' => 'bi-exclamation-triangle',
        'disputes' => 'bi-chat-square-text',
        'documents' => 'bi-folder2-open',
        'mailbox' => 'bi-envelope',
        'compliance' => 'bi-patch-check',
        'tax' => 'bi-receipt',
        'police' => 'bi-person-vcard',
        'content' => 'bi-file-earmark-text',
        'media' => 'bi-images',
        'settings' => 'bi-sliders',
        'users' => 'bi-people',
        'backups' => 'bi-archive',
        'updates' => 'bi-arrow-repeat',
        'diagnostics' => 'bi-heart-pulse',
        'logs' => 'bi-journal-text',
        'audit' => 'bi-shield-check',
    ];

    public function __construct(private readonly Router $router)
    {
    }

    /**
     * @param list<array{slug: string, label: string, children: list<mixed>}> $menuTree
     * @param list<array{id: string, label: string, items: list<array{id: string, route: string, label: string,
     *     disabled: bool}>}> $adminGroups
     *
     * @return array{menus: list<array<string, mixed>>, active_menu_id: string, active_page_path: string}
     */
    public function build(array $menuTree, ?User $user, array $adminGroups, string $currentPath): array
    {
        $menus = [$this->housingMenu($menuTree)];

        if ($user !== null && !$user->isOperational()) {
            $menus[] = $this->clientMenu();
        }

        if ($adminGroups !== []) {
            $menus[] = $this->adminMenu($adminGroups);
        }

        $activePath = $this->activePath($menus, $currentPath);
        $activeMenu = '';
        foreach ($menus as $menu) {
            foreach ($menu['groups'] as $group) {
                foreach ($group['pages'] as $page) {
                    if ($activePath !== '' && $page['path'] === $activePath) {
                        $activeMenu = $activeMenu === '' ? $menu['id'] : $activeMenu;
                    }
                }
            }
        }

        return ['menus' => $menus, 'active_menu_id' => $activeMenu, 'active_page_path' => $activePath];
    }

    /**
     * @param list<array{slug: string, label: string, children: list<mixed>}> $menuTree
     *
     * @return array<string, mixed>
     */
    private function housingMenu(array $menuTree): array
    {
        $main = [$this->page('home', [], 'bi-house', null, 'nav.home')];
        $columns = [];

        foreach ($menuTree as $item) {
            $entry = $this->page('page.show', ['slug' => $item['slug']], 'bi-file-earmark-text', $item['label']);
            $children = [];
            foreach ($item['children'] as $child) {
                if (!is_array($child) || !isset($child['slug'], $child['label'])) {
                    continue;
                }
                $children[] = $this->page(
                    'page.show',
                    ['slug' => (string) $child['slug']],
                    'bi-file-earmark-text',
                    (string) $child['label']
                );
            }

            if ($children === []) {
                $main[] = $entry;
                continue;
            }

            $columns[] = ['label' => $item['label'], 'label_key' => null, 'pages' => [$entry, ...$children]];
        }

        return [
            'id' => 'housing',
            'label_key' => 'nav.housing',
            'icon' => 'bi-house-door',
            'groups' => [['label' => null, 'label_key' => null, 'pages' => $main], ...$columns],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientMenu(): array
    {
        $bookings = $this->page('account.profile', [], 'bi-calendar-check', null, 'booking.journey.my_bookings');
        $bookings['fragment'] = 'my-bookings';
        // Une ancre n'est pas une page : elle ne doit jamais voler la
        // sélection à « Mon compte », qui partage le même chemin.
        $bookings['path'] = '';

        return [
            'id' => 'account',
            'label_key' => 'nav.my_space',
            'icon' => 'bi-person-circle',
            'groups' => [[
                'label' => null,
                'label_key' => null,
                'pages' => [
                    $bookings,
                    $this->page('account.profile', [], 'bi-person', null, 'account.profile.title'),
                ],
            ]],
        ];
    }

    /**
     * @param list<array{id: string, label: string, items: list<array{id: string, route: string, label: string,
     *     disabled: bool}>}> $adminGroups
     *
     * @return array<string, mixed>
     */
    private function adminMenu(array $adminGroups): array
    {
        $groups = [];
        foreach ($adminGroups as $group) {
            $pages = [];
            foreach ($group['items'] as $item) {
                $page = $this->page($item['route'], [], self::ADMIN_ICONS[$item['id']] ?? 'bi-dot', null, $item['label']);
                $page['id'] = $item['id'];
                $page['disabled'] = $item['disabled'];
                $pages[] = $page;
            }
            $groups[] = ['id' => $group['id'], 'label' => null, 'label_key' => $group['label'], 'pages' => $pages];
        }

        return [
            'id' => 'admin',
            'label_key' => 'admin.nav.administration',
            'icon' => 'bi-gear',
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function page(
        string $route,
        array $params,
        string $icon,
        ?string $label,
        ?string $labelKey = null
    ): array {
        return [
            'id' => $route === 'page.show' ? ($params['slug'] ?? '') : $route,
            'route' => $route,
            'params' => $params,
            'fragment' => '',
            'label' => $label,
            'label_key' => $labelKey,
            'icon' => $icon,
            'path' => $this->router->path($route, $params),
            'disabled' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $menus
     */
    private function activePath(array $menus, string $currentPath): string
    {
        $best = '';
        foreach ($menus as $menu) {
            foreach ($menu['groups'] as $group) {
                foreach ($group['pages'] as $page) {
                    $path = (string) $page['path'];
                    if ($path === '') {
                        continue;
                    }
                    $matches = $currentPath === $path
                        || ($path !== '/' && str_starts_with($currentPath, $path . '/'));
                    if ($matches && strlen($path) > strlen($best)) {
                        $best = $path;
                    }
                }
            }
        }

        return $best;
    }
}
