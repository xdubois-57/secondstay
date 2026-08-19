<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\I18n\Locales;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Security\Csrf;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;

/**
 * Parcours complets sur une installation terminée.
 */
final class InstalledAppTest extends InstalledAppTestCase
{
    // ---------------------------------------------------------------- Site --

    /**
     * @return list<array{string, string}>
     */
    public static function homeExpectations(): array
    {
        return [
            ['fr', 'Bienvenue'],
            ['en', 'Welcome'],
            ['nl', 'Welkom'],
            ['de', 'Willkommen'],
        ];
    }

    #[DataProvider('homeExpectations')]
    public function testPublicHomeIsServedInEveryLocale(string $locale, string $expected): void
    {
        $response = $this->request('/' . $locale . '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString($expected, $response->content());
        self::assertStringContainsString('<html lang="' . $locale . '"', $response->content());
    }

    public function testInstallationRouteDisappearsOnceInstalled(): void
    {
        self::assertSame(404, $this->request('/fr/install')->status());
    }

    public function testUnknownRouteIsLocalised(): void
    {
        self::assertStringContainsString('Page introuvable', $this->request('/fr/inexistant')->content());
        self::assertStringContainsString('Seite nicht gefunden', $this->request('/de/inexistant')->content());
        self::assertStringContainsString('Pagina niet gevonden', $this->request('/nl/inexistant')->content());
        self::assertStringContainsString('Page not found', $this->request('/en/inexistant')->content());
    }

    // ------------------------------------------------------ Authentification --

    public function testLoginAndLogout(): void
    {
        $login = $this->request('/fr/login', 'POST', $this->withCsrf([
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]));

        self::assertSame(302, $login->status());
        self::assertSame('/fr/admin', $login->headers()['location'] ?? null);

        $dashboard = $this->request('/fr/admin');
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('data-testid="todo-list"', $dashboard->content());

        $logout = $this->request('/fr/logout', 'POST', $this->withCsrf([]));
        self::assertSame(302, $logout->status());
        self::assertSame(403, $this->request('/fr/admin')->status());
    }

    public function testLoginWithWrongPasswordShowsALocalisedError(): void
    {
        $response = $this->request('/de/login', 'POST', $this->withCsrf([
            'email' => self::ADMIN_EMAIL,
            'password' => 'mauvais',
        ]));

        self::assertSame(401, $response->status());
        self::assertStringContainsString('E-Mail-Adresse oder Passwort ist falsch.', $response->content());
    }

    public function testAdminAreaRequiresAuthentication(): void
    {
        foreach (['/fr/admin', '/fr/admin/settings', '/fr/admin/users', '/fr/admin/backups', '/fr/admin/logs'] as $path) {
            self::assertSame(403, $this->request($path)->status(), $path);
        }
    }

    public function testLocalManagerCannotReachAdministratorOnlyPages(): void
    {
        $this->createUser(self::MANAGER_EMAIL, Role::LocalManager);
        $this->loginAs(self::MANAGER_EMAIL);

        self::assertSame(200, $this->request('/fr/admin')->status());
        self::assertSame(403, $this->request('/fr/admin/settings')->status());
        self::assertSame(403, $this->request('/fr/admin/users')->status());
        self::assertSame(403, $this->request('/fr/admin/backups')->status());
    }

    public function testCustomerCannotReachTheAdminArea(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL, Role::Customer);
        $this->loginAs(self::CUSTOMER_EMAIL);

        self::assertSame(403, $this->request('/fr/admin')->status());
    }

    public function testAccountLocaleDrivesTheInterface(): void
    {
        $this->createUser('nl@example.test', Role::Administrator, 'nl');
        $this->loginAs('nl@example.test');

        $response = $this->request('/admin');
        self::assertStringContainsString('<html lang="nl"', $response->content());
    }

    // ---------------------------------------------------------------- CSRF --

    public function testMutationWithoutCsrfIsRefused(): void
    {
        $response = $this->request('/fr/login', 'POST', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        self::assertSame(403, $response->status());
    }

    public function testMutationWithAWrongCsrfTokenIsRefused(): void
    {
        $response = $this->request('/fr/login', 'POST', [
            Csrf::FIELD => 'jeton-invalide',
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        self::assertSame(403, $response->status());
    }

    // ------------------------------------------------------------ Réglages --

    public function testSettingsCanBeSavedAndAreTyped(): void
    {
        $this->loginAs();

        $response = $this->request('/fr/admin/settings', 'POST', $this->withCsrf([
            'module' => 'pricing',
            'setting_pricing__default_night_price' => '145,50',
            'setting_pricing__cleaning_mode' => 'mandatory',
            'setting_pricing__cleaning_price' => '100.00',
            'setting_pricing__deposit_percent' => '30',
            'setting_pricing__security_deposit' => '500',
        ]));

        self::assertSame(302, $response->status());

        $settings = $this->container->get(SettingsService::class);
        $settings->refresh();
        self::assertSame(14550, $settings->money('pricing.default_night_price'));
        self::assertSame(10000, $settings->money('pricing.cleaning_price'));
        self::assertSame('mandatory', $settings->string('pricing.cleaning_mode'));
    }

    public function testInvalidSettingIsReportedInTheForm(): void
    {
        $this->loginAs();

        $response = $this->request('/fr/admin/settings', 'POST', $this->withCsrf([
            'module' => 'booking',
            'setting_booking__max_guests' => 'beaucoup',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('data-error-for="booking.max_guests"', $response->content());
    }

    public function testDefaultCleaningPolicyIsMandatoryAt100Euro(): void
    {
        $settings = $this->container->get(SettingsService::class);

        self::assertSame('mandatory', $settings->string('pricing.cleaning_mode'));
        self::assertSame(10000, $settings->money('pricing.cleaning_price'));
    }

    // ------------------------------------------------------------- Comptes --

    public function testAdministratorCanCreateALocalManager(): void
    {
        $this->loginAs();

        $response = $this->request('/fr/admin/users', 'POST', $this->withCsrf([
            'email' => self::MANAGER_EMAIL,
            'password' => 'Marée-Haute-2026!',
            'first_name' => 'Jean',
            'last_name' => 'Martin',
            'role' => Role::LocalManager->value,
            'locale' => 'nl',
        ]));

        self::assertSame(302, $response->status());

        $created = (new UserRepository($this->database))->findByEmail(self::MANAGER_EMAIL);
        self::assertNotNull($created);
        self::assertSame(Role::LocalManager, $created->role);
        self::assertSame('nl', $created->locale);
    }

    public function testTheLastAdministratorCannotBeDemoted(): void
    {
        $this->loginAs();
        $users = new UserRepository($this->database);
        $admin = $users->findByEmail(self::ADMIN_EMAIL);
        self::assertNotNull($admin);

        $this->request('/fr/admin/users/' . $admin->id . '/role', 'POST', $this->withCsrf([
            'role' => Role::Customer->value,
        ]));

        self::assertSame(Role::Administrator, $users->findById($admin->id)?->role);
    }

    public function testAdministratorCannotDeleteTheirOwnAccount(): void
    {
        $this->loginAs();
        $users = new UserRepository($this->database);
        $admin = $users->findByEmail(self::ADMIN_EMAIL);
        self::assertNotNull($admin);

        $this->request('/fr/admin/users/' . $admin->id . '/delete', 'POST', $this->withCsrf([]));

        self::assertNotNull($users->findById($admin->id));
    }

    public function testSeveralAdministratorsAreSupported(): void
    {
        $this->loginAs();
        $this->request('/fr/admin/users', 'POST', $this->withCsrf([
            'email' => 'second-admin@example.test',
            'password' => 'Marée-Haute-2026!',
            'first_name' => 'Alice',
            'last_name' => 'Durand',
            'role' => Role::Administrator->value,
            'locale' => 'fr',
        ]));

        self::assertSame(2, (new UserRepository($this->database))->countAdministrators());
    }

    // --------------------------------------------------------- Maintenance --

    public function testMaintenanceClosesThePublicSiteButNotTheAdminArea(): void
    {
        $this->loginAs();

        $this->request('/fr/admin/maintenance', 'POST', $this->withCsrf(['enable' => '1']));

        $maintenance = $this->container->get(MaintenanceMode::class);
        self::assertTrue($maintenance->isActive());

        // L'administrateur garde l'accès.
        self::assertSame(200, $this->request('/fr/admin')->status());
        self::assertSame(200, $this->request('/fr/')->status());

        $this->request('/fr/admin/maintenance', 'POST', $this->withCsrf(['enable' => '0']));
        self::assertFalse($maintenance->isActive());
    }

    public function testMaintenanceReturns503ForVisitors(): void
    {
        $this->container->get(MaintenanceMode::class)->enable('maintenance.reason.manual');

        $response = $this->request('/fr/');

        self::assertSame(503, $response->status());
        self::assertStringContainsString('Maintenance en cours', $response->content());
        self::assertSame('600', $response->headers()['retry-after'] ?? null);
    }

    public function testHealthEndpointStaysAvailableDuringMaintenance(): void
    {
        $this->container->get(MaintenanceMode::class)->enable('maintenance.reason.update');

        self::assertSame(200, $this->request('/api/health')->status());
    }

    // ------------------------------------------------------------ Journaux --

    public function testAdministrativeActionsAreAudited(): void
    {
        $this->loginAs();
        $this->request('/fr/admin/settings', 'POST', $this->withCsrf([
            'module' => 'pricing',
            'setting_pricing__cleaning_price' => '150.00',
        ]));

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');

        self::assertContains('auth.login', $actions);
        self::assertContains('settings.updated', $actions);
    }

    public function testDiagnosticsPageIsRendered(): void
    {
        $this->loginAs();
        $response = $this->request('/fr/admin/diagnostics');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-diagnostic="database_connection"', $response->content());
        self::assertStringContainsString('data-testid="schema-version"', $response->content());
    }

    public function testBackupCanBeCreatedAndListedFromTheAdminArea(): void
    {
        $this->loginAs();

        self::assertStringContainsString('data-testid="no-backup"', $this->request('/fr/admin/backups')->content());

        $response = $this->request('/fr/admin/backups/create', 'POST', $this->withCsrf([]));
        self::assertSame(302, $response->status());

        $list = $this->request('/fr/admin/backups')->content();
        self::assertStringContainsString('data-backup-id=', $list);
        self::assertStringNotContainsString('data-testid="no-backup"', $list);
    }

    public function testBackupDownloadRequiresAdministrator(): void
    {
        $this->createUser(self::MANAGER_EMAIL, Role::LocalManager);
        $this->loginAs(self::MANAGER_EMAIL);

        self::assertSame(403, $this->request('/fr/admin/backups/20260704-120000-abcdef/download')->status());
    }

    public function testLocaleSwitchIsPersistedForVisitors(): void
    {
        $response = $this->request('/nl/');
        $cookies = $response->cookies();

        self::assertCount(1, $cookies);
        self::assertSame('ss_locale', $cookies[0]['name']);
        self::assertSame('nl', $cookies[0]['value']);
    }

    /**
     * SECURITY.md §5 — une panne de base ne doit jamais rouvrir l'assistant
     * d'installation d'une instance déjà déployée.
     */
    public function testDatabaseOutageNeverReopensTheInstaller(): void
    {
        $config = (string) file_get_contents($this->appRoot . '/config/local.php');
        file_put_contents(
            $this->appRoot . '/config/local.php',
            str_replace("'" . self::databaseConfig()?->name . "'", "'base_absente_secondstay'", $config)
        );

        $kernel = new \SecondStay\Core\Kernel($this->appRoot);

        $home = $kernel->handle(new \SecondStay\Core\Http\Request('GET', '/fr/'));
        self::assertSame(503, $home->status());
        self::assertSame('120', $home->headers()['retry-after'] ?? null);

        $installer = $kernel->handle(new \SecondStay\Core\Http\Request('GET', '/fr/install'));
        self::assertSame(404, $installer->status());

        $installPost = $kernel->handle(new \SecondStay\Core\Http\Request('POST', '/fr/install'));
        self::assertSame(404, $installPost->status());

        // Les endpoints techniques restent disponibles pour le diagnostic.
        self::assertSame(200, $kernel->handle(new \SecondStay\Core\Http\Request('GET', '/api/health'))->status());
    }

    public function testEveryFirstClassLocaleIsAdvertised(): void
    {
        $content = $this->request('/fr/')->content();

        foreach (Locales::ALL as $locale) {
            self::assertStringContainsString('hreflang="' . $locale . '"', $content);
        }
    }

    /**
     * Un message flash appartient à la page réellement affichée.
     *
     * Le service worker précharge des pages avec les cookies de la session :
     * si ces requêtes consommaient les messages, une confirmation pourrait
     * disparaître avant d'avoir été lue.
     */
    public function testAFlashMessageSurvivesAConcurrentNonNavigationRequest(): void
    {
        $this->loginAs();
        $this->session()->flash('success', 'account.profile.saved');

        // Requête émise par un service worker : rien ne doit être consommé.
        $prefetch = $this->request('/fr/', 'GET', [], [
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_MODE' => 'no-cors',
        ]);
        self::assertSame(200, $prefetch->status());
        self::assertStringNotContainsString('data-flash-type="success"', $prefetch->content());

        // La navigation suivante affiche bien le message, une seule fois —
        // y compris relayée par un service worker, où la destination devient
        // « empty » alors que le mode reste « navigate ».
        $page = $this->request('/fr/', 'GET', [], [
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
        ]);
        self::assertStringContainsString('data-flash-type="success"', $page->content());

        $again = $this->request('/fr/', 'GET', [], ['HTTP_SEC_FETCH_DEST' => 'document']);
        self::assertStringNotContainsString('data-flash-type="success"', $again->content());
    }
}
