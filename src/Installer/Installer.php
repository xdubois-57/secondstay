<?php

declare(strict_types=1);

namespace SecondStay\Installer;

use PDO;
use PDOException;
use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Database\Migrator;
use SecondStay\I18n\Locales;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SensitiveParameter;
use Throwable;

/**
 * Installation d'une nouvelle instance : base, schéma, premier administrateur
 * et réglages initiaux.
 */
final class Installer
{
    public function __construct(
        private readonly Paths $paths,
        private readonly InstallationState $state,
        private readonly PasswordHasher $hasher = new PasswordHasher(),
    ) {
    }

    /**
     * Teste une connexion sans jamais divulguer les identifiants en cas d'échec.
     *
     * @return array{ok: true, server: string}|array{ok: false, error: string}
     */
    public function testConnection(DatabaseConfig $config): array
    {
        try {
            $pdo = new PDO($config->dsn(), $config->user, $config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return ['ok' => true, 'server' => is_string($version) ? $version : 'unknown'];
        } catch (PDOException $exception) {
            return ['ok' => false, 'error' => self::translateConnectionError($exception)];
        }
    }

    private static function translateConnectionError(PDOException $exception): string
    {
        $code = (string) $exception->getCode();

        return match (true) {
            str_contains($exception->getMessage(), 'Unknown database') => 'install.database.error.unknown_database',
            $code === '1045' || str_contains($exception->getMessage(), 'Access denied') => 'install.database.error.access_denied',
            str_contains($exception->getMessage(), 'getaddrinfo') || $code === '2002' => 'install.database.error.host_unreachable',
            default => 'install.database.error.generic',
        };
    }

    /**
     * @param array{
     *     database: DatabaseConfig,
     *     admin_email: string,
     *     admin_password: string,
     *     admin_first_name: string,
     *     admin_last_name: string,
     *     admin_phone?: string,
     *     locale: string,
     *     property_name: string,
     *     timezone?: string
     * } $input
     *
     * @return array{database: Database, admin_id: int, migrations: list<string>}
     *
     * @throws ValidationException
     */
    public function install(#[SensitiveParameter] array $input): array
    {
        if ($this->state->isInstalled($this->safeDatabase($input['database']))) {
            throw new RuntimeException('L’installation est déjà terminée.');
        }

        $this->validate($input);

        $connection = $this->testConnection($input['database']);
        if ($connection['ok'] === false) {
            throw new ValidationException(['database' => $connection['error']]);
        }

        $this->paths->ensureStorageDirectories();

        $encryptionKey = Encryptor::generateKey();
        $writer = new LocalConfigWriter($this->state->localConfigPath());
        $writer->backupExisting();
        $writer->write($input['database'], ['k1' => $encryptionKey], 'k1');

        $database = new Database($input['database']);
        $migrator = new Migrator($database, $this->paths->migrations());
        $applied = $migrator->migrate();

        $users = new UserRepository($database);
        $locale = Locales::normalise($input['locale']) ?? Locales::FALLBACK;

        $adminId = $users->create(
            $input['admin_email'],
            $this->hasher->hash($input['admin_password']),
            $input['admin_first_name'],
            $input['admin_last_name'],
            $input['admin_phone'] ?? '',
            Role::Administrator,
            $locale,
            UserStatus::Active,
        );

        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($database),
            Encryptor::fromSingleKey($encryptionKey),
        );

        $settings->setMany([
            'property.name' => $input['property_name'],
            'site.default_locale' => $locale,
            'site.timezone' => $input['timezone'] ?? 'Europe/Paris',
        ], 'installer');

        (new AuditTrail($database))->record(
            'install.completed',
            'installation',
            '1',
            null,
            ['locale' => $locale, 'migrations' => count($applied)],
            $adminId,
            $input['admin_email'],
        );

        return ['database' => $database, 'admin_id' => $adminId, 'migrations' => $applied];
    }

    private function safeDatabase(DatabaseConfig $config): ?Database
    {
        try {
            $database = new Database($config);

            return $database->isReachable() ? $database : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    private function validate(array $input): void
    {
        $errors = [];

        $email = is_string($input['admin_email'] ?? null) ? trim($input['admin_email']) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = 'install.error.email_invalid';
        }

        $password = is_string($input['admin_password'] ?? null) ? $input['admin_password'] : '';
        $evaluation = $this->hasher->evaluate($password);
        if ($evaluation['errors'] !== []) {
            $errors['admin_password'] = $evaluation['errors'][0];
        }

        foreach (['admin_first_name', 'admin_last_name', 'property_name'] as $field) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ($value === '') {
                $errors[$field] = 'install.error.required';
            }
        }

        $locale = is_string($input['locale'] ?? null) ? $input['locale'] : '';
        if (Locales::normalise($locale) === null) {
            $errors['locale'] = 'install.error.locale';
        }

        $timezone = is_string($input['timezone'] ?? null) ? $input['timezone'] : 'Europe/Paris';
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'install.error.timezone';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
