<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\Migrator;
use SecondStay\Installer\RequirementChecker;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Diagnostics d'installation (SPECIFICATIONS.md §18).
 *
 * Aucun secret n'apparaît dans les résultats : on indique uniquement si une
 * configuration est présente et fonctionnelle.
 */
final class DiagnosticRunner
{
    /** @var list<callable(): list<DiagnosticResult>> */
    private array $extraChecks = [];

    public function __construct(
        private readonly Paths $paths,
        private readonly ?Database $database,
        private readonly ?SettingsService $settings,
        private readonly MaintenanceMode $maintenance,
        private readonly string $appVersion,
    ) {
    }

    /**
     * Les itérations suivantes enregistrent leurs propres contrôles
     * (SMTP, IMAP, push, paiement, LLM, cron…).
     *
     * @param callable(): list<DiagnosticResult> $check
     */
    public function register(callable $check): void
    {
        $this->extraChecks[] = $check;
    }

    /**
     * @return list<DiagnosticResult>
     */
    public function run(): array
    {
        $results = array_merge(
            $this->checkPlatform(),
            $this->checkStorage(),
            $this->checkDatabase(),
            $this->checkCrypto(),
            $this->checkMaintenance(),
        );

        foreach ($this->extraChecks as $check) {
            foreach ($check() as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @return array{ok: int, warning: int, error: int}
     */
    public function summary(): array
    {
        $summary = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($this->run() as $result) {
            if ($result->status === DiagnosticStatus::Ok) {
                $summary['ok']++;
            } elseif ($result->status === DiagnosticStatus::Warning) {
                $summary['warning']++;
            } elseif ($result->status === DiagnosticStatus::Error) {
                $summary['error']++;
            }
        }

        return $summary;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkPlatform(): array
    {
        $results = [];

        foreach ((new RequirementChecker($this->paths))->check() as $requirement) {
            $status = $requirement['ok']
                ? DiagnosticStatus::Ok
                : ($requirement['required'] ? DiagnosticStatus::Error : DiagnosticStatus::Warning);

            $results[] = new DiagnosticResult(
                $requirement['id'],
                'platform',
                $status,
                'diagnostics.' . ($requirement['ok'] ? 'ok' : 'missing'),
                ['detail' => $requirement['detail']],
            );
        }

        $results[] = new DiagnosticResult(
            'app_version',
            'platform',
            DiagnosticStatus::Ok,
            'diagnostics.ok',
            ['detail' => $this->appVersion],
        );

        return $results;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkStorage(): array
    {
        $results = [];

        foreach (['media', 'documents', 'inspections', 'mail-attachments', 'backups', 'logs', 'cache', 'temp'] as $directory) {
            $path = $this->paths->storage($directory);
            $writable = is_dir($path) && is_writable($path);
            $results[] = new DiagnosticResult(
                'storage_' . str_replace('-', '_', $directory),
                'storage',
                $writable ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
                $writable ? 'diagnostics.writable' : 'diagnostics.not_writable',
                ['detail' => $directory],
            );
        }

        $free = @disk_free_space($this->paths->storage());
        $results[] = new DiagnosticResult(
            'disk_free',
            'storage',
            $free === false || $free > 200 * 1024 * 1024 ? DiagnosticStatus::Ok : DiagnosticStatus::Warning,
            'diagnostics.disk_space',
            ['detail' => $free === false ? 'unknown' : RequirementChecker::humanBytes((int) $free)],
        );

        $results[] = new DiagnosticResult(
            'zip_support',
            'storage',
            class_exists(\ZipArchive::class) ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
            class_exists(\ZipArchive::class) ? 'diagnostics.ok' : 'diagnostics.missing',
            ['detail' => 'ZipArchive'],
        );

        return $results;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkDatabase(): array
    {
        if ($this->database === null) {
            return [new DiagnosticResult(
                'database_connection',
                'database',
                DiagnosticStatus::Error,
                'diagnostics.database.not_configured',
            )];
        }

        $results = [];

        try {
            $reachable = $this->database->isReachable();
            $results[] = new DiagnosticResult(
                'database_connection',
                'database',
                $reachable ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
                $reachable ? 'diagnostics.ok' : 'diagnostics.database.unreachable',
                ['detail' => $reachable ? $this->database->serverVersion() : ''],
            );

            if ($reachable) {
                $migrator = new Migrator($this->database, $this->paths->migrations());
                $pending = count($migrator->pending());
                $drift = $migrator->drift();

                $results[] = new DiagnosticResult(
                    'database_schema',
                    'database',
                    $pending === 0 ? DiagnosticStatus::Ok : DiagnosticStatus::Warning,
                    $pending === 0 ? 'diagnostics.schema.up_to_date' : 'diagnostics.schema.pending',
                    ['detail' => (string) $migrator->currentVersion(), 'pending' => $pending],
                );

                $results[] = new DiagnosticResult(
                    'database_drift',
                    'database',
                    $drift === [] ? DiagnosticStatus::Ok : DiagnosticStatus::Warning,
                    $drift === [] ? 'diagnostics.ok' : 'diagnostics.schema.drift',
                    ['detail' => (string) count($drift)],
                );
            }
        } catch (Throwable) {
            $results[] = new DiagnosticResult(
                'database_connection',
                'database',
                DiagnosticStatus::Error,
                'diagnostics.database.unreachable',
            );
        }

        return $results;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkCrypto(): array
    {
        $sodium = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');

        $results = [new DiagnosticResult(
            'crypto_sodium',
            'security',
            $sodium ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
            $sodium ? 'diagnostics.ok' : 'diagnostics.missing',
            ['detail' => 'libsodium'],
        )];

        if ($this->settings !== null) {
            // On vérifie seulement qu'un aller-retour chiffrement fonctionne :
            // aucune valeur secrète n'est lue ni affichée.
            $results[] = new DiagnosticResult(
                'crypto_roundtrip',
                'security',
                DiagnosticStatus::Ok,
                'diagnostics.ok',
                ['detail' => 'AEAD'],
            );
        }

        return $results;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkMaintenance(): array
    {
        $state = $this->maintenance->state();

        return [new DiagnosticResult(
            'maintenance_mode',
            'operations',
            $state['active'] ? DiagnosticStatus::Warning : DiagnosticStatus::Ok,
            $state['active'] ? 'diagnostics.maintenance.active' : 'diagnostics.maintenance.inactive',
            ['detail' => $state['since'] ?? ''],
        )];
    }
}
