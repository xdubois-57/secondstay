<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserStatus;
use SecondStay\Controller\Admin\AdminNavigation;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Navigation\SiteNavigation;

final class SiteNavigationTest extends TestCase
{
    private const MENU_TREE = [
        ['slug' => 'property', 'label' => 'Le logement', 'children' => []],
        ['slug' => 'region', 'label' => 'La région', 'children' => [
            ['slug' => 'beaches', 'label' => 'Plages', 'children' => []],
        ]],
    ];

    private function navigation(): SiteNavigation
    {
        $router = new Router();
        Routes::register($router);

        return new SiteNavigation($router);
    }

    private function user(Role $role): User
    {
        return new User(1, 'a@b.test', 'Claire', 'Dubois', '', $role, 'fr', UserStatus::Active);
    }

    /**
     * @param array{menus: list<array<string, mixed>>} $result
     *
     * @return list<string>
     */
    private function menuIds(array $result): array
    {
        return array_map(static fn (array $menu): string => (string) $menu['id'], $result['menus']);
    }

    public function testVisitorSeesOnlyTheHousingMenu(): void
    {
        $result = $this->navigation()->build(self::MENU_TREE, null, [], '/');

        self::assertSame(['housing'], $this->menuIds($result));
        self::assertSame('housing', $result['active_menu_id']);
        self::assertSame('/', $result['active_page_path']);
    }

    public function testPagesWithChildrenBecomeTitledColumns(): void
    {
        $groups = $this->navigation()->build(self::MENU_TREE, null, [], '/')['menus'][0]['groups'];

        self::assertCount(2, $groups);
        self::assertNull($groups[0]['label']);
        self::assertSame(['home', 'property'], array_column($groups[0]['pages'], 'id'));
        self::assertSame('La région', $groups[1]['label']);
        self::assertSame(['region', 'beaches'], array_column($groups[1]['pages'], 'id'));
        self::assertSame('/beaches', $groups[1]['pages'][1]['path']);
    }

    public function testCustomerGetsTheirSpaceButNoAdministration(): void
    {
        $result = $this->navigation()->build(self::MENU_TREE, $this->user(Role::Customer), [], '/account');

        self::assertSame(['housing', 'account'], $this->menuIds($result));
        self::assertSame('account', $result['active_menu_id']);
        $pages = $result['menus'][1]['groups'][0]['pages'];
        self::assertSame('my-bookings', $pages[0]['fragment']);
        // L'ancre partage le chemin de « Mon compte » : elle ne doit pas
        // voler la sélection.
        self::assertSame('', $pages[0]['path']);
    }

    public function testDetailPageSelectsItsListByLongestPrefix(): void
    {
        $administrator = $this->user(Role::Administrator);
        $groups = (new AdminNavigation())->forUser($administrator);
        $result = $this->navigation()->build(self::MENU_TREE, $administrator, $groups, '/admin/bookings/12');

        self::assertSame(['housing', 'admin'], $this->menuIds($result));
        self::assertSame('admin', $result['active_menu_id']);
        self::assertSame('/admin/bookings', $result['active_page_path']);

        $admin = $result['menus'][1];
        self::assertSame(
            array_column($groups, 'id'),
            array_column($admin['groups'], 'id')
        );
        foreach ($admin['groups'] as $group) {
            foreach ($group['pages'] as $page) {
                self::assertArrayHasKey($page['id'], SiteNavigation::ADMIN_ICONS, 'icône manquante : ' . $page['id']);
            }
        }
    }

    public function testEveryAdminSectionHasAnIcon(): void
    {
        foreach (AdminNavigation::sectionIds() as $id) {
            self::assertArrayHasKey($id, SiteNavigation::ADMIN_ICONS, $id);
        }
    }

    public function testUnknownPathSelectsNothing(): void
    {
        $result = $this->navigation()->build(self::MENU_TREE, null, [], '/login');

        self::assertSame('', $result['active_menu_id']);
        self::assertSame('', $result['active_page_path']);
    }
}
