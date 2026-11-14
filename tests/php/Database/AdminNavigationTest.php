<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Controller\Admin\AdminNavigation;
use SecondStay\Core\Router;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;

/**
 * Menu de l'espace d'administration et lien client vers ses séjours.
 *
 * Le menu montrait vingt-cinq entrées à tout le monde : un responsable local
 * cliquait sur « Réglages » et recevait un 403, et un module éteint
 * s'affichait comme s'il tournait.
 */
final class AdminNavigationTest extends InstalledAppTestCase
{

    /**
     * Le filtrage n'a de sens que s'il reflète exactement l'autorisation des
     * contrôleurs : une entrée cachée qui serait accessible prive d'une
     * page, une entrée montrée qui ne l'est pas renvoie un 403.
     */
    public function testALocalManagerSeesExactlyThePagesTheyMayOpen(): void
    {
        $this->container->get(SettingsService::class)->setMany([
            'imap.enabled' => '1',
            'llm.enabled' => '1',
            'tax.tourist_enabled' => '1',
            'compliance.police_record_enabled' => '1',
        ]);
        $this->createUser(self::MANAGER_EMAIL, Role::LocalManager);
        $this->loginAs(self::MANAGER_EMAIL);

        $page = $this->request('/fr/admin/operations');
        self::assertSame(200, $page->status());
        $shown = $this->sections($page->content());

        $router = $this->container->get(Router::class);
        foreach (AdminNavigation::sectionIds() as $section) {
            $status = $this->request($router->path(AdminNavigation::routeOf($section), [], 'fr'))->status();
            self::assertSame(
                in_array($section, $shown, true),
                $status === 200,
                sprintf('%s : affiché=%s, statut=%d', $section, in_array($section, $shown, true) ? 'oui' : 'non', $status)
            );
        }
    }

    public function testADisabledModuleIsHiddenFromTheLocalManagerAndMarkedForTheAdministrator(): void
    {
        $this->container->get(SettingsService::class)->setMany(['imap.enabled' => '0']);

        $this->loginAs();
        $admin = $this->request('/fr/admin/operations')->content();
        self::assertContains('mailbox', $this->sections($admin));
        self::assertMatchesRegularExpression('/data-admin-section="mailbox"\s+data-disabled="true"/', $admin);

        $this->createUser(self::MANAGER_EMAIL, Role::LocalManager);
        $this->loginAs(self::MANAGER_EMAIL);
        $manager = $this->request('/fr/admin/operations')->content();
        self::assertNotContains('mailbox', $this->sections($manager));
    }

    public function testTheMenuIsGroupedAndTranslated(): void
    {
        $this->loginAs();

        $content = $this->request('/de/admin/operations')->content();

        foreach (['activity', 'stays', 'compliance', 'site', 'system'] as $group) {
            self::assertStringContainsString('data-admin-group="' . $group . '"', $content);
        }
        self::assertStringContainsString('Aufenthalte', $content);
        self::assertStringNotContainsString('admin.nav.group', $content);
    }

    public function testAClientHasAVisibleLinkToTheirStays(): void
    {
        $this->createUser('client@example.test', Role::Customer);
        $this->loginAs('client@example.test');

        $content = $this->request('/nl/')->content();

        self::assertStringContainsString('data-testid="my-bookings-link"', $content);
        self::assertStringContainsString('Mijn verblijven', $content);
        self::assertStringNotContainsString('data-testid="admin-link"', $content);
    }

    public function testStaffGetTheAdministrationLinkInstead(): void
    {
        $this->loginAs();

        $content = $this->request('/fr/')->content();

        self::assertStringContainsString('data-testid="admin-link"', $content);
        self::assertStringNotContainsString('data-testid="my-bookings-link"', $content);
    }

    /**
     * @return list<string>
     */
    private function sections(string $html): array
    {
        preg_match_all('/data-admin-section="([a-z]+)"/', $html, $matches);

        return $matches[1];
    }
}
