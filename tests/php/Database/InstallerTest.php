<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Paths;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Installer\InstallationState;
use SecondStay\Installer\InstallationStatus;
use SecondStay\Installer\Installer;
use SecondStay\Installer\LocalConfigWriter;
use SecondStay\Installer\RequirementChecker;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

final class InstallerTest extends DatabaseTestCase
{
    private string $sandboxRoot;

    private Paths $sandboxPaths;

    private Installer $installer;

    private InstallationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxRoot = sys_get_temp_dir() . '/secondstay-install-' . bin2hex(random_bytes(6));
        mkdir($this->sandboxRoot . '/config', 0o750, true);
        mkdir($this->sandboxRoot . '/migrations', 0o750, true);
        foreach (glob(self::projectRoot() . '/migrations/*.sql') ?: [] as $migration) {
            copy($migration, $this->sandboxRoot . '/migrations/' . basename($migration));
        }

        $this->sandboxPaths = new Paths($this->sandboxRoot, $this->sandboxRoot . '/storage');
        $this->state = new InstallationState($this->sandboxPaths);
        $this->installer = new Installer($this->sandboxPaths, $this->state);

        // Installation neuve : le schéma doit être créé par l'installeur.
        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->database->tables() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->sandboxRoot);
        parent::tearDown();
    }

    /**
     * @return array{
     *     database: DatabaseConfig,
     *     admin_email: string,
     *     admin_password: string,
     *     admin_first_name: string,
     *     admin_last_name: string,
     *     admin_phone: string,
     *     locale: string,
     *     property_name: string,
     *     timezone: string
     * }
     */
    private function validInput(): array
    {
        $config = self::databaseConfig();
        self::assertNotNull($config);

        return [
            'database' => $config,
            'admin_email' => 'owner@example.test',
            'admin_password' => 'Marée-Haute-2026!',
            'admin_first_name' => 'Claire',
            'admin_last_name' => 'Dubois',
            'admin_phone' => '+33600000000',
            'locale' => 'nl',
            'property_name' => 'Maison des Pins',
            'timezone' => 'Europe/Paris',
        ];
    }

    public function testFreshInstallCreatesSchemaAdminAndSettings(): void
    {
        self::assertFalse($this->state->isInstalled($this->database));

        $result = $this->installer->install($this->validInput());

        self::assertSame(['0001'], $result['migrations']);
        self::assertTrue($this->state->hasLocalConfig());
        self::assertTrue($this->state->isInstalled($this->database));

        $admin = (new UserRepository($this->database))->findByEmail('owner@example.test');
        self::assertNotNull($admin);
        self::assertSame(Role::Administrator, $admin->role);
        self::assertSame('nl', $admin->locale);
        self::assertTrue($admin->status->canAuthenticate());
    }

    public function testInstalledSettingsUseTheChosenLocale(): void
    {
        $this->installer->install($this->validInput());

        /** @var array{security: array{encryption_keys: array<string, string>}} $config */
        $config = require $this->state->localConfigPath();
        $key = $config['security']['encryption_keys']['k1'];

        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey($key),
        );

        self::assertSame('Maison des Pins', $settings->string('property.name'));
        self::assertSame('nl', $settings->string('site.default_locale'));
        self::assertSame('Europe/Paris', $settings->string('site.timezone'));
    }

    public function testLocalConfigNeverLandsInTheRepositoryAndIsRestrictive(): void
    {
        $this->installer->install($this->validInput());

        $path = $this->state->localConfigPath();
        self::assertTrue(str_starts_with($path, $this->sandboxRoot));
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $content = (string) file_get_contents($path);
        self::assertStringContainsString("'encryption_keys'", $content);
        self::assertStringNotContainsString('Marée-Haute-2026!', $content);
    }

    public function testGeneratedEncryptionKeyIsUnique(): void
    {
        $this->installer->install($this->validInput());
        /** @var array{security: array{encryption_keys: array<string, string>}} $first */
        $first = require $this->state->localConfigPath();

        $writer = new LocalConfigWriter($this->state->localConfigPath());
        $writer->write(new DatabaseConfig('h', 3306, 'n', 'u', 'p'), ['k1' => Encryptor::generateKey()], 'k1');

        self::assertNotSame(
            $first['security']['encryption_keys']['k1'],
            (require $this->state->localConfigPath())['security']['encryption_keys']['k1']
        );
    }

    public function testInstallationCannotBeRunTwice(): void
    {
        $this->installer->install($this->validInput());

        $this->expectException(\RuntimeException::class);
        $this->installer->install($this->validInput());
    }

    public function testWeakPasswordIsRejected(): void
    {
        $input = $this->validInput();
        $input['admin_password'] = 'court';

        try {
            $this->installer->install($input);
            self::fail('Un mot de passe faible aurait dû être refusé.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('admin_password', $exception->errors());
        }

        self::assertFalse($this->state->hasLocalConfig());
    }

    public function testInvalidEmailIsRejected(): void
    {
        $input = $this->validInput();
        $input['admin_email'] = 'pas-un-email';

        $this->expectException(ValidationException::class);
        $this->installer->install($input);
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $input = $this->validInput();
        $input['locale'] = 'es';

        try {
            $this->installer->install($input);
            self::fail('Une langue non supportée aurait dû être refusée.');
        } catch (ValidationException $exception) {
            self::assertSame('install.error.locale', $exception->errors()['locale']);
        }
    }

    public function testUnknownTimezoneIsRejected(): void
    {
        $input = $this->validInput();
        $input['timezone'] = 'Mars/Olympus';

        $this->expectException(ValidationException::class);
        $this->installer->install($input);
    }

    public function testConnectionErrorsAreTranslatedWithoutLeakingCredentials(): void
    {
        $result = $this->installer->testConnection(
            new DatabaseConfig('127.0.0.1', 3306, 'base_inexistante_secondstay', 'root', 'wrong-password')
        );

        self::assertFalse($result['ok']);
        self::assertStringStartsWith('install.database.error.', $result['error']);
        self::assertStringNotContainsString('wrong-password', $result['error']);
    }

    public function testConnectionTestSucceedsOnTheTestDatabase(): void
    {
        $config = self::databaseConfig();
        self::assertNotNull($config);

        $result = $this->installer->testConnection($config);

        self::assertTrue($result['ok']);
        self::assertNotSame('', $result['server']);
    }

    public function testRequirementCheckerReportsThePlatform(): void
    {
        $checker = new RequirementChecker($this->sandboxPaths);
        $ids = array_column($checker->check(), 'id');

        self::assertContains('php_version', $ids);
        self::assertContains('ext_pdo_mysql', $ids);
        self::assertContains('config_writable', $ids);
        self::assertContains('storage_writable', $ids);
        self::assertTrue($checker->isSatisfied());
    }

    public function testSchemaStateIsReported(): void
    {
        $this->installer->install($this->validInput());

        $state = $this->state->schemaState($this->database);

        self::assertSame('0001', $state['schema']);
        self::assertSame(0, $state['pending']);
        self::assertSame([], $state['drift']);
    }

    public function testStatusIsNotInstalledWithoutLocalConfig(): void
    {
        self::assertSame(InstallationStatus::NotInstalled, $this->state->status($this->database));
        self::assertTrue(InstallationStatus::NotInstalled->allowsInstaller());
    }

    public function testStatusBecomesInstalledAfterInstallation(): void
    {
        $this->installer->install($this->validInput());

        self::assertSame(InstallationStatus::Installed, $this->state->status($this->database));
    }

    /**
     * Une panne de base ne doit jamais rouvrir l'assistant d'installation
     * d'une instance déjà déployée.
     */
    public function testStatusIsUnavailableWhenTheDatabaseIsDown(): void
    {
        $this->installer->install($this->validInput());

        self::assertSame(InstallationStatus::Unavailable, $this->state->status(null));
        self::assertFalse(InstallationStatus::Unavailable->allowsInstaller());
        self::assertFalse(InstallationStatus::Unavailable->isOperational());
    }

    public function testStatusIsUnavailableWhenTheSchemaIsMissing(): void
    {
        $this->installer->install($this->validInput());

        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS `user`');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        self::assertSame(InstallationStatus::Unavailable, $this->state->status($this->database));
    }

    public function testStatusIsUnavailableWhenNoAdministratorRemains(): void
    {
        $this->installer->install($this->validInput());
        $this->database->execute('DELETE FROM `user`');

        self::assertSame(InstallationStatus::Unavailable, $this->state->status($this->database));
    }

    public function testInstallationIsAudited(): void
    {
        $this->installer->install($this->validInput());

        $actions = array_column(
            (new \SecondStay\Audit\AuditTrail($this->database))->recent(),
            'action'
        );

        self::assertContains('install.completed', $actions);
    }
}
