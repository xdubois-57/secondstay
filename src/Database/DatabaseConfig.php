<?php

declare(strict_types=1);

namespace SecondStay\Database;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        public readonly string $password,
        public readonly string $charset = 'utf8mb4',
    ) {
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset,
        );
    }

    /**
     * DSN sans base de données : utilisé pour tester la connexion pendant
     * l'installation.
     */
    public function serverDsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;charset=%s', $this->host, $this->port, $this->charset);
    }

    /**
     * Représentation sûre pour les diagnostics : jamais de mot de passe.
     *
     * @return array{host: string, port: int, name: string, user: string, charset: string}
     */
    public function toSafeArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'name' => $this->name,
            'user' => $this->user,
            'charset' => $this->charset,
        ];
    }
}
