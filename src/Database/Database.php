<?php

declare(strict_types=1);

namespace SecondStay\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Accès PDO centralisé. Toutes les requêtes passent par des requêtes préparées
 * (SECURITY.md §7) : aucune concaténation d'entrée utilisateur dans le SQL.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    public function __construct(private readonly DatabaseConfig $config)
    {
    }

    public static function fromCredentials(
        string $host,
        int $port,
        string $name,
        string $user,
        string $password,
        string $charset = 'utf8mb4',
    ): self {
        return new self(new DatabaseConfig($host, $port, $name, $user, $password, $charset));
    }

    public function config(): DatabaseConfig
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO(
                $this->config->dsn(),
                $this->config->user,
                $this->config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $exception) {
            // Le message PDO peut contenir des identifiants : on ne le propage pas.
            throw new RuntimeException('Connexion à la base de données impossible.', 0, $exception);
        }

        return $this->pdo;
    }

    public function isReachable(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->execute($sql, $parameters)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->execute($sql, $parameters)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        /** @var mixed $value */
        $value = $this->execute($sql, $parameters)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->identifier($table),
            implode(', ', array_map(fn (string $c): string => '`' . $this->identifier($c) . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        $this->execute($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $assignments = [];
        $parameters = [];
        foreach ($data as $column => $value) {
            $assignments[] = '`' . $this->identifier($column) . '` = :set_' . $column;
            $parameters['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $this->identifier($column) . '` = :where_' . $column;
            $parameters['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->identifier($table),
            implode(', ', $assignments),
            implode(' AND ', $conditions),
        );

        return $this->execute($sql, $parameters)->rowCount();
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        $parameters = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $this->identifier($column) . '` = :' . $column;
            $parameters[$column] = $value;
        }

        $sql = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $this->identifier($table),
            implode(' AND ', $conditions),
        );

        return $this->execute($sql, $parameters)->rowCount();
    }

    /**
     * Transaction avec support des imbrications via points de sauvegarde.
     *
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($this->transactionDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . $this->transactionDepth);
        }
        $this->transactionDepth++;

        try {
            $result = $callback($this);
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
            }

            return $result;
        } catch (Throwable $throwable) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
            }

            throw $throwable;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->fetchAll('SHOW TABLES');
        $tables = [];
        foreach ($rows as $row) {
            $value = reset($row);
            if (is_string($value)) {
                $tables[] = $value;
            }
        }
        sort($tables);

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    public function serverVersion(): string
    {
        $version = $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) ? $version : 'unknown';
    }

    /**
     * Les identifiants ne peuvent jamais provenir d'une entrée utilisateur ;
     * cette validation empêche toute dérive.
     */
    private function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Identifiant SQL invalide : ' . $name);
        }

        return $name;
    }
}
