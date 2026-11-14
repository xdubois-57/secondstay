<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\User;
use SecondStay\Settings\SettingsService;

/**
 * Menu de l'espace d'administration.
 *
 * Vingt-cinq entrées à plat, identiques pour tous, faisaient voir à un
 * responsable local neuf pages qui lui répondent « accès refusé », et à tout
 * le monde des modules éteints comme s'ils tournaient. Le menu est donc :
 *
 *  - **groupé** par usage, pour qu'on trouve sans lire toute la liste ;
 *  - **filtré par rôle** : une entrée n'apparaît que si son contrôleur
 *    l'autorise. Ce filtrage est un confort, jamais une protection — chaque
 *    contrôleur vérifie le rôle côté serveur (SECURITY.md §5) ;
 *  - **filtré par fonction activée** : un module éteint disparaît pour le
 *    responsable local, qui ne peut pas l'allumer. L'administrateur le voit
 *    encore, marqué « désactivé », parce que c'est en y allant qu'il apprend
 *    où l'allumer — et que certaines de ces pages servent avant l'activation
 *    (barèmes de taxe, sources du contenu local, durées de conservation).
 */
final class AdminNavigation
{
    private const ADMINISTRATOR = 'administrator';
    private const OPERATIONAL = 'operational';

    /**
     * Groupe → entrées. Le rôle d'une entrée est celui qu'exige l'action
     * `index` de son contrôleur ; la fonction, le réglage qui l'allume.
     *
     * @var array<string, list<array{id: string, route: string, role: string, feature?: string}>>
     */
    private const GROUPS = [
        'activity' => [
            ['id' => 'dashboard', 'route' => 'admin.dashboard', 'role' => self::OPERATIONAL],
            ['id' => 'operations', 'route' => 'admin.operations', 'role' => self::OPERATIONAL],
            ['id' => 'bookings', 'route' => 'admin.bookings', 'role' => self::OPERATIONAL],
            ['id' => 'pricing', 'route' => 'admin.pricing', 'role' => self::OPERATIONAL],
            ['id' => 'payments', 'route' => 'admin.payments', 'role' => self::OPERATIONAL],
            ['id' => 'reports', 'route' => 'admin.reports', 'role' => self::OPERATIONAL],
        ],
        'stays' => [
            ['id' => 'stay', 'route' => 'admin.stay', 'role' => self::OPERATIONAL],
            ['id' => 'local', 'route' => 'admin.local', 'role' => self::OPERATIONAL, 'feature' => 'llm.enabled'],
            ['id' => 'inspections', 'route' => 'admin.inspections', 'role' => self::OPERATIONAL],
            ['id' => 'incidents', 'route' => 'admin.incidents', 'role' => self::OPERATIONAL],
            ['id' => 'disputes', 'route' => 'admin.disputes', 'role' => self::OPERATIONAL],
            ['id' => 'documents', 'route' => 'admin.documents', 'role' => self::OPERATIONAL],
            ['id' => 'mailbox', 'route' => 'admin.mailbox', 'role' => self::OPERATIONAL, 'feature' => 'imap.enabled'],
        ],
        'compliance' => [
            ['id' => 'compliance', 'route' => 'admin.compliance', 'role' => self::OPERATIONAL],
            ['id' => 'tax', 'route' => 'admin.tax', 'role' => self::OPERATIONAL, 'feature' => 'tax.tourist_enabled'],
            [
                'id' => 'police',
                'route' => 'admin.police',
                'role' => self::OPERATIONAL,
                'feature' => 'compliance.police_record_enabled',
            ],
        ],
        'site' => [
            ['id' => 'content', 'route' => 'admin.content', 'role' => self::ADMINISTRATOR],
            ['id' => 'media', 'route' => 'admin.media', 'role' => self::ADMINISTRATOR],
        ],
        'system' => [
            ['id' => 'settings', 'route' => 'admin.settings', 'role' => self::ADMINISTRATOR],
            ['id' => 'users', 'route' => 'admin.users', 'role' => self::ADMINISTRATOR],
            ['id' => 'backups', 'route' => 'admin.backups', 'role' => self::ADMINISTRATOR],
            ['id' => 'updates', 'route' => 'admin.updates', 'role' => self::ADMINISTRATOR],
            ['id' => 'diagnostics', 'route' => 'admin.diagnostics', 'role' => self::ADMINISTRATOR],
            ['id' => 'logs', 'route' => 'admin.logs', 'role' => self::ADMINISTRATOR],
            ['id' => 'audit', 'route' => 'admin.audit', 'role' => self::ADMINISTRATOR],
        ],
    ];

    public function __construct(private readonly ?SettingsService $settings = null)
    {
    }

    /**
     * @return list<array{id: string, label: string, items: list<array{id: string, route: string, label: string,
     *     disabled: bool}>}>
     */
    public function forUser(?User $user): array
    {
        if ($user === null || !$user->isOperational()) {
            return [];
        }

        $groups = [];
        foreach (self::GROUPS as $groupId => $entries) {
            $items = [];
            foreach ($entries as $entry) {
                if ($entry['role'] === self::ADMINISTRATOR && !$user->isAdministrator()) {
                    continue;
                }

                $disabled = isset($entry['feature']) && !$this->featureEnabled($entry['feature']);
                if ($disabled && !$user->isAdministrator()) {
                    continue;
                }

                $items[] = [
                    'id' => $entry['id'],
                    'route' => $entry['route'],
                    'label' => 'admin.nav.' . $entry['id'],
                    'disabled' => $disabled,
                ];
            }

            if ($items !== []) {
                $groups[] = ['id' => $groupId, 'label' => 'admin.nav.group.' . $groupId, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @return list<string> identifiants de toutes les sections, tous groupes confondus
     */
    public static function sectionIds(): array
    {
        $ids = [];
        foreach (self::GROUPS as $entries) {
            foreach ($entries as $entry) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }

    public static function routeOf(string $sectionId): string
    {
        foreach (self::GROUPS as $entries) {
            foreach ($entries as $entry) {
                if ($entry['id'] === $sectionId) {
                    return $entry['route'];
                }
            }
        }

        throw new \InvalidArgumentException('Section inconnue : ' . $sectionId);
    }

    private function featureEnabled(string $key): bool
    {
        if ($this->settings === null) {
            return true;
        }

        try {
            return $this->settings->bool($key);
        } catch (\Throwable) {
            // Réglage illisible : mieux vaut montrer l'entrée que la perdre.
            return true;
        }
    }
}
