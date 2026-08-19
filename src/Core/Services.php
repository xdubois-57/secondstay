<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\AuthService;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\UserRepository;
use SecondStay\Backup\BackupService;
use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Http\CurlHttpFetcher;
use SecondStay\Http\HttpFetcher;
use SecondStay\Installer\InstallationState;
use SecondStay\Installer\Installer;
use SecondStay\Installer\RequirementChecker;
use SecondStay\Logging\Logger;
use SecondStay\Logging\LogLevel;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Security\Csrf;
use SecondStay\Security\Encryptor;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Update\GitHubReleaseProvider;
use SecondStay\Update\ReleaseProvider;
use SecondStay\Update\UpdateService;
use Throwable;

/**
 * Enregistrement des services applicatifs dans le conteneur.
 *
 * Les services dépendant de la base sont paresseux : l'application doit rester
 * utilisable avant l'installation et pendant une panne de base.
 */
final class Services
{
    public static function register(Container $container, string $projectRoot, string $appVersion): void
    {
        $container->set(MaintenanceMode::class, static fn (Container $c): MaintenanceMode
            => new MaintenanceMode($c->get(Paths::class)->storage('maintenance.json')));

        $container->set(Logger::class, static function (Container $c): Logger {
            $config = $c->get(Config::class);
            $logger = new Logger(
                $c->get(Paths::class)->storage('logs'),
                LogLevel::fromString($config->string('logging.level', 'info'))
            );

            return $logger->withDatabase(self::optionalDatabase($c));
        });

        $container->set(InstallationState::class, static fn (Container $c): InstallationState
            => new InstallationState($c->get(Paths::class)));

        $container->set(RequirementChecker::class, static fn (Container $c): RequirementChecker
            => new RequirementChecker($c->get(Paths::class)));

        $container->set(Installer::class, static fn (Container $c): Installer
            => new Installer($c->get(Paths::class), $c->get(InstallationState::class)));

        $container->set(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());

        $container->set(SettingRegistry::class, static fn (): SettingRegistry => new SettingRegistry());

        $container->set(HttpFetcher::class, static fn (): HttpFetcher => new CurlHttpFetcher());

        $container->set(ReleaseProvider::class, static function (Container $c): ReleaseProvider {
            $repository = 'xdubois-57/secondstay';
            if ($c->has(SettingsService::class)) {
                try {
                    $configured = $c->get(SettingsService::class)->string('update.repository');
                    if ($configured !== '') {
                        $repository = $configured;
                    }
                } catch (Throwable) {
                    // Réglages indisponibles : on garde le dépôt officiel.
                }
            }

            return new GitHubReleaseProvider($c->get(HttpFetcher::class), $repository);
        });

        $container->set(Session::class, static function (Container $c): Session {
            $config = $c->get(Config::class);

            return new PhpSession(
                $config->string('security.session_name', 'secondstay_session'),
                $config->int('security.session_lifetime_minutes', 120),
                false,
            );
        });

        $container->set(Csrf::class, static function (Container $c): Csrf {
            $session = $c->get(Session::class);
            /** @var array<string, mixed> $reference */
            $reference = &$session->reference();

            return new Csrf($reference);
        });

        // ------------------------------------------------------------------
        // Services dépendant de la base de données.
        // ------------------------------------------------------------------

        $container->set(DatabaseConfig::class, static function (Container $c): DatabaseConfig {
            $config = $c->get(Config::class);

            return new DatabaseConfig(
                $config->string('database.host', '127.0.0.1'),
                $config->int('database.port', 3306),
                $config->string('database.name'),
                $config->string('database.user'),
                $config->string('database.password'),
                $config->string('database.charset', 'utf8mb4'),
            );
        });

        $container->set(Database::class, static fn (Container $c): Database
            => new Database($c->get(DatabaseConfig::class)));

        $container->set(Encryptor::class, static function (Container $c): Encryptor {
            $config = $c->get(Config::class);

            /** @var array<string, string> $keys */
            $keys = [];
            foreach ($config->array('security.encryption_keys') as $id => $key) {
                if (is_string($key) && $key !== '') {
                    $keys[(string) $id] = $key;
                }
            }

            $single = $config->string('security.encryption_key');
            if ($keys === [] && $single !== '') {
                $keys = ['k1' => $single];
            }

            $active = $config->string('security.active_encryption_key', 'k1');
            if (!isset($keys[$active])) {
                $active = (string) array_key_first($keys);
            }

            return new Encryptor($keys, $active);
        });

        $container->set(SettingsRepository::class, static fn (Container $c): SettingsRepository
            => new SettingsRepository($c->get(Database::class)));

        $container->set(AuditTrail::class, static fn (Container $c): AuditTrail
            => new AuditTrail($c->get(Database::class), $c->get(Logger::class)->correlationId()));

        $container->set(SettingsService::class, static fn (Container $c): SettingsService => new SettingsService(
            $c->get(SettingRegistry::class),
            $c->get(SettingsRepository::class),
            $c->get(Encryptor::class),
            new \SecondStay\Settings\SettingValidator(),
            $c->get(AuditTrail::class),
        ));

        $container->set(UserRepository::class, static fn (Container $c): UserRepository
            => new UserRepository($c->get(Database::class)));

        $container->set(SessionRepository::class, static fn (Container $c): SessionRepository
            => new SessionRepository($c->get(Database::class)));

        $container->set(RateLimiter::class, static fn (Container $c): RateLimiter
            => new RateLimiter($c->get(Database::class)));

        $container->set(AuthService::class, static fn (Container $c): AuthService => new AuthService(
            $c->get(UserRepository::class),
            $c->get(SessionRepository::class),
            $c->get(Session::class),
            $c->get(PasswordHasher::class),
            $c->get(RateLimiter::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
            $c->get(Config::class)->int('security.session_lifetime_minutes', 120),
        ));

        $container->set(Migrator::class, static fn (Container $c): Migrator
            => new Migrator($c->get(Database::class), $c->get(Paths::class)->migrations()));

        $container->set(BackupService::class, static fn (Container $c): BackupService => new BackupService(
            $c->get(Database::class),
            $c->get(Paths::class),
            $c->get(MaintenanceMode::class),
            $appVersion,
            $c->get(AuditTrail::class),
        ));

        $container->set(UpdateService::class, static fn (Container $c): UpdateService => new UpdateService(
            $c->get(ReleaseProvider::class),
            $c->get(Paths::class),
            $c->get(Database::class),
            $c->get(BackupService::class),
            $c->get(MaintenanceMode::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(DiagnosticRunner::class, static function (Container $c) use ($appVersion): DiagnosticRunner {
            $database = self::optionalDatabase($c);
            $settings = null;
            if ($database !== null) {
                try {
                    $settings = $c->get(SettingsService::class);
                } catch (Throwable) {
                    $settings = null;
                }
            }

            return new DiagnosticRunner(
                $c->get(Paths::class),
                $database,
                $settings,
                $c->get(MaintenanceMode::class),
                $appVersion,
            );
        });
    }

    public static function optionalDatabase(Container $container): ?Database
    {
        try {
            $config = $container->get(Config::class);
            if ($config->string('database.name') === '') {
                return null;
            }
            $database = $container->get(Database::class);

            return $database->isReachable() ? $database : null;
        } catch (Throwable) {
            return null;
        }
    }
}
