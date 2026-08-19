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

        @file_put_contents($this->logDirectory . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Purge des journaux au-delà de la rétention configurée (RGPD §21).
     */
    public function purgeOlderThan(int $days): int
    {
        if (!$this->database instanceof Database) {
            return 0;
        }

        return $this->database->execute(
            'DELETE FROM `app_log` WHERE `created_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        )->rowCount();
    }
}
