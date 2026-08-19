<?php

declare(strict_types=1);

namespace SecondStay\Backup;

use PDO;
use RuntimeException;
use SecondStay\Database\Database;
use SecondStay\Database\SqlScriptSplitter;

/**
 * Export SQL 100 % PHP (AGENTS.md §1.8) : aucun binaire externe n'est requis
 * sur l'hébergement mutualisé.
 */
final class SqlDumper
{
    private const BATCH = 500;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param resource $handle
     *
     * @return array<string, int> nombre de lignes exportées par table
     */
    public function dumpTo($handle): array
    {
        $tables = $this->database->tables();
        $counts = [];

        $this->write($handle, "-- SecondStay — sauvegarde SQL\n");
        $this->write($handle, '-- ' . gmdate('c') . "\n");
        $this->write($handle, "SET NAMES utf8mb4;\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $create = $this->database->fetchOne('SHOW CREATE TABLE `' . $this->safeIdentifier($table) . '`');
            $definition = null;
            foreach ($create ?? [] as $key => $value) {
                if (is_string($key) && str_contains(strtolower($key), 'create') && is_string($value)) {
                    $definition = $value;
                }
            }
            if ($definition === null) {
                throw new RuntimeException('Structure illisible pour la table ' . $table);
            }

            $this->write($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
            $this->write($handle, $definition . ";\n");

            $counts[$table] = $this->dumpRows($handle, $table);
            $this->write($handle, "\n");
        }

        $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

        return $counts;
    }

    /**
     * @param resource $handle
     */
    private function dumpRows($handle, string $table): int
    {
        $safe = $this->safeIdentifier($table);
        $total = (int) $this->database->fetchValue('SELECT COUNT(*) FROM `' . $safe . '`');
        if ($total === 0) {
            return 0;
        }

        $pdo = $this->database->pdo();
        $offset = 0;

        while ($offset < $total) {
            $statement = $this->database->execute(
                sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $safe, self::BATCH, $offset)
            );

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll();
            if ($rows === []) {
                break;
            }

            $columns = array_keys($rows[0]);
            $columnList = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));

            $values = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $column) {
                    /** @var mixed $value */
                    $value = $row[$column];
                    if ($value === null) {
                        $cells[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $cells[] = (string) $value;
                    } elseif (is_bool($value)) {
                        $cells[] = $value ? '1' : '0';
                    } else {
                        $cells[] = $pdo->quote((string) $value);
                    }
                }
                $values[] = '(' . implode(', ', $cells) . ')';
            }

            $this->write(
                $handle,
                'INSERT INTO `' . $safe . '` (' . $columnList . ") VALUES\n" . implode(",\n", $values) . ";\n"
            );

            $offset += self::BATCH;
        }

        return $total;
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $content): void
    {
        if (fwrite($handle, $content) === false) {
            throw new RuntimeException('Écriture de la sauvegarde SQL impossible.');
        }
    }

    private function safeIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Nom de table invalide : ' . $name);
        }

        return $name;
    }

    /**
     * Restaure un fichier SQL. L'appelant est responsable du mode maintenance.
     */
    public function restoreFromFile(string $sqlFile): int
    {
        $handle = fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Fichier SQL de restauration illisible.');
        }

        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $buffer = '';
        $executed = 0;

        try {
            while (($chunk = fread($handle, 262144)) !== false && $chunk !== '') {
                $buffer .= $chunk;
                $result = SqlScriptSplitter::splitStreaming($buffer);
                foreach ($result['statements'] as $statement) {
                    $pdo->exec($statement);
                    $executed++;
                }
                $buffer = $result['remainder'];
            }

            foreach (SqlScriptSplitter::split($buffer) as $statement) {
                $pdo->exec($statement);
                $executed++;
            }
        } finally {
            fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return $executed;
    }
}
