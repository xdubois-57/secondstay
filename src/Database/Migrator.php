<?php

declare(strict_types=1);

namespace SecondStay\Database;

use RuntimeException;

/**
 * Migrations versionnées, idempotentes et auditées.
 *
 * Chaque fichier `migrations/NNNN_nom.sql` est appliqué une seule fois. Le
 * checksum est enregistré : une migration déjà appliquée qui aurait été
 * modifiée est signalée plutôt que réappliquée silencieusement.
 */
final class Migrator
{
    public const TABLE = 'schema_migration';

    public function __construct(
        private readonly Database $database,
        private readonly string $migrationsPath,
    ) {
    }

    public function ensureMigrationTable(): void
    {
        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`version` VARCHAR(64) NOT NULL,'
            . '`name` VARCHAR(190) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`applied_at` DATETIME NOT NULL,'
            . '`execution_ms` INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return list<array{version: string, name: string, path: string, checksum: string}>
     */
    public function available(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $basename = basename($file, '.sql');
            if (preg_match('/^(\d{4})_(.+)$/', $basename, $matches) !== 1) {
                throw new RuntimeException('Nom de migration invalide : ' . $basename);
            }
            $checksum = hash_file('sha256', $file);
            $migrations[] = [
                'version' => $matches[1],
                'name' => $matches[2],
                'path' => $file,
                'checksum' => $checksum === false ? '' : $checksum,
            ];
        }

        return $migrations;
    }

    /**
     * @return array<string, array{checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $this->ensureMigrationTable();
        $rows = $this->database->fetchAll(
            'SELECT `version`, `checksum`, `applied_at` FROM `' . self::TABLE . '` ORDER BY `version`'
        );

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['version']] = [
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $applied;
    }

    /**
     * @return list<array{version: string, name: string, path: string, checksum: string}>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->available(),
            static fn (array $migration): bool => !isset($applied[$migration['version']])
        ));
    }

    /**
     * @return list<string> avertissements de dérive
     */
    public function drift(): array
    {
        $applied = $this->applied();
        $warnings = [];

        foreach ($this->available() as $migration) {
            $known = $applied[$migration['version']] ?? null;
            if ($known !== null && $known['checksum'] !== $migration['checksum']) {
                $warnings[] = sprintf(
                    'La migration %s_%s a été modifiée après application.',
                    $migration['version'],
                    $migration['name']
                );
            }
        }

        foreach (array_keys($applied) as $version) {
            $exists = array_filter(
                $this->available(),
                static fn (array $m): bool => $m['version'] === $version
            );
            if ($exists === []) {
                $warnings[] = sprintf('La migration %s est appliquée mais absente du dépôt.', $version);
            }
        }

        return $warnings;
    }

    /**
     * Applique les migrations en attente.
     *
     * @return list<string> versions appliquées
     */
    public function migrate(): array
    {
        $this->ensureMigrationTable();
        $appliedVersions = [];

        foreach ($this->pending() as $migration) {
            $sql = file_get_contents($migration['path']);
            if ($sql === false) {
                throw new RuntimeException('Migration illisible : ' . $migration['path']);
            }

            $started = microtime(true);

            // Chaque migration est atomique lorsque le moteur le permet ;
            // MySQL commit implicitement le DDL, d'où l'enregistrement
            // immédiat de la version après exécution.
            foreach (SqlScriptSplitter::split($sql) as $statement) {
                $this->database->execute($statement);
            }

            $this->database->insert(self::TABLE, [
                'version' => $migration['version'],
                'name' => $migration['name'],
                'checksum' => $migration['checksum'],
                'applied_at' => gmdate('Y-m-d H:i:s'),
                'execution_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            $appliedVersions[] = $migration['version'];
        }

        return $appliedVersions;
    }

    public function currentVersion(): ?string
    {
        $applied = $this->applied();
        if ($applied === []) {
            return null;
        }

        $versions = array_keys($applied);
        sort($versions);

        return (string) end($versions);
    }
}
