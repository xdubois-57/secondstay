<?php

declare(strict_types=1);

namespace SecondStay\Installer;

use SecondStay\Auth\UserRepository;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\Migrator;
use Throwable;

/**
 * Détermine l'état d'installation.
 *
 * Une installation est complète lorsque la configuration locale existe, que la
 * base répond, que le schéma est migré et qu'au moins un administrateur actif
 * existe.
 */
final class InstallationState
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function localConfigPath(): string
    {
        return $this->paths->root('config/local.php');
    }

    public function hasLocalConfig(): bool
    {
        // Après l'installation, tout processus persistant doit voir
        // immédiatement le nouveau fichier de configuration.
        clearstatcache(true, $this->localConfigPath());

        return is_file($this->localConfigPath());
    }

    public function status(?Database $database): InstallationStatus
    {
        if (!$this->hasLocalConfig()) {
            return InstallationStatus::NotInstalled;
        }

        // À partir d'ici l'instance a déjà été installée : quoi qu'il arrive,
        // l'assistant d'installation ne doit plus jamais être accessible.
        if ($database === null) {
            return InstallationStatus::Unavailable;
        }

        try {
            if (!$database->isReachable() || !$database->tableExists('user')) {
                return InstallationStatus::Unavailable;
            }

            return (new UserRepository($database))->countAdministrators() > 0
                ? InstallationStatus::Installed
                : InstallationStatus::Unavailable;
        } catch (Throwable) {
            return InstallationStatus::Unavailable;
        }
    }

    public function isInstalled(?Database $database): bool
    {
        return $this->status($database)->isOperational();
    }

    /**
     * @return array{schema: ?string, pending: int, drift: list<string>}
     */
    public function schemaState(Database $database): array
    {
        $migrator = new Migrator($database, $this->paths->migrations());

        return [
            'schema' => $migrator->currentVersion(),
            'pending' => count($migrator->pending()),
            'drift' => $migrator->drift(),
        ];
    }
}
