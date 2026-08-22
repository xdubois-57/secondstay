<?php

declare(strict_types=1);

namespace SecondStay\Logging;

use SecondStay\Database\Database;
use Throwable;

/**
 * Journal technique. Écrit en base lorsqu'elle est disponible, sinon dans
 * `storage/logs/app.log` : une erreur d'infrastructure doit rester traçable.
 */
final class Logger
{
    /**
     * Taille au-delà de laquelle le fichier de journal est renommé.
     *
     * Le journal fichier est un **repli** : il ne sert que lorsque la base est
     * injoignable, c'est-à-dire précisément quand quelque chose ne va pas et
     * que les lignes affluent. Sans rotation, la panne qu'il documente finit
     * par en produire une seconde — un disque plein sur un hébergement
     * mutualisé à quota, qui emporte aussi les sauvegardes.
     *
     * La valeur n'est pas un réglage : elle ne dépend pas du déploiement, et
     * un propriétaire n'a aucun moyen de choisir mieux (AGENTS.md §1.12).
     */
    public const FILE_MAX_BYTES = 2_097_152;

    /** Générations conservées, la plus ancienne étant écrasée. */
    public const FILE_GENERATIONS = 3;

    private ?Database $database = null;

    private string $correlationId;

    private ?int $userId = null;

    public function __construct(
        private readonly string $logDirectory,
        private LogLevel $minimumLevel = LogLevel::Info,
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? bin2hex(random_bytes(16));
    }

    public function withDatabase(?Database $database): self
    {
        $this->database = $database;

        return $this;
    }

    public function setMinimumLevel(LogLevel $level): void
    {
        $this->minimumLevel = $level;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $category, string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $category, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $category, string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $category, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $category, string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $category, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $category, string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $category, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $category, string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $category, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $category, string $message, array $context = []): void
    {
        if ($level->severity() < $this->minimumLevel->severity()) {
            return;
        }

        $clean = LogSanitizer::sanitize($context);
        $message = LogSanitizer::redactPatterns($message);

        if ($this->writeToDatabase($level, $category, $message, $clean)) {
            return;
        }

        $this->writeToFile($level, $category, $message, $clean);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeToDatabase(LogLevel $level, string $category, string $message, array $context): bool
    {
        if (!$this->database instanceof Database) {
            return false;
        }

        try {
            $this->database->insert('app_log', [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'level' => $level->value,
                'category' => mb_substr($category, 0, 64),
                'message' => mb_substr($message, 0, 60000),
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'user_id' => $this->userId,
                'correlation_id' => $this->correlationId,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeToFile(LogLevel $level, string $category, string $message, array $context): void
    {
        if (!is_dir($this->logDirectory)) {
            @mkdir($this->logDirectory, 0o750, true);
        }

        $line = sprintf(
            "%s\t%s\t%s\t%s\t%s\t%s\n",
            gmdate('c'),
            $level->value,
            $category,
            $this->correlationId,
            str_replace(["\n", "\r", "\t"], ' ', $message),
            $context === [] ? '-' : (string) json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $file = $this->logDirectory . '/app.log';
        $this->rotateIfNeeded($file);

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Renomme le journal lorsqu'il dépasse sa taille, en décalant les
     * générations : `app.log` devient `app.log.1`, `app.log.1` devient
     * `app.log.2`, et la plus ancienne disparaît.
     *
     * `rename()` est atomique : un processus qui écrit pendant la rotation
     * continue d'écrire dans le fichier renommé, il n'écrit pas dans le vide.
     * Deux processus qui font tourner en même temps peuvent perdre une
     * génération — conséquence acceptée sur un journal de repli, et de loin
     * préférable à un disque plein.
     */
    private function rotateIfNeeded(string $file): void
    {
        clearstatcache(true, $file);
        $size = @filesize($file);

        if ($size === false || $size < self::FILE_MAX_BYTES) {
            return;
        }

        for ($generation = self::FILE_GENERATIONS - 1; $generation >= 1; $generation--) {
            $from = $file . '.' . $generation;
            if (is_file($from)) {
                @rename($from, $file . '.' . ($generation + 1));
            }
        }

        @rename($file, $file . '.1');
    }

    /**
     * Purge des journaux au-delà de la rétention configurée (RGPD §21).
     *
     * Les générations tournées du journal fichier partent avec : elles
     * portent les mêmes lignes, donc les mêmes données personnelles, et une
     * rétention qui ne viderait que la base laisserait la copie sur le disque.
     */
    public function purgeOlderThan(int $days): int
    {
        $threshold = time() - ($days * 86400);

        foreach (glob($this->logDirectory . '/app.log.*') ?: [] as $rotated) {
            $modified = @filemtime($rotated);
            if ($modified !== false && $modified < $threshold) {
                @unlink($rotated);
            }
        }

        if (!$this->database instanceof Database) {
            return 0;
        }

        return $this->database->execute(
            'DELETE FROM `app_log` WHERE `created_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', $threshold)]
        )->rowCount();
    }
}
