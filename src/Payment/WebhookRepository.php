<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use PDOException;
use SecondStay\Database\Database;

/**
 * Journal des webhooks et **idempotence** (SPECIFICATIONS.md §34).
 *
 * L'unicité porte sur le couple fournisseur / identifiant : un même événement
 * rejoué, reçu en double ou dans le désordre ne peut pas être traité deux
 * fois. Comme pour les nuits réservées, c'est la contrainte de base de données
 * qui décide, pas une vérification applicative.
 */
final class WebhookRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IGNORED = 'ignored';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Enregistre la réception d'un événement.
     *
     * @return array{first: bool, id: int}
     */
    public function receive(string $provider, string $externalId, string $rawBody): array
    {
        try {
            $id = $this->database->insert('webhook_event', [
                'provider' => $provider,
                'external_id' => mb_substr($externalId, 0, 190),
                'payload_hash' => hash('sha256', $rawBody),
                'status' => self::STATUS_RECEIVED,
                'attempts' => 1,
                'received_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return ['first' => true, 'id' => $id];
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }
        }

        // Déjà connu : on compte la tentative, sans retraiter.
        $this->database->execute(
            'UPDATE `webhook_event` SET `attempts` = `attempts` + 1 '
            . 'WHERE `provider` = :provider AND `external_id` = :external',
            ['provider' => $provider, 'external' => $externalId]
        );

        $row = $this->database->fetchOne(
            'SELECT `id` FROM `webhook_event` WHERE `provider` = :provider AND `external_id` = :external',
            ['provider' => $provider, 'external' => $externalId]
        );

        return ['first' => false, 'id' => $row === null ? 0 : (int) $row['id']];
    }

    public function markProcessed(int $id): void
    {
        $this->database->update('webhook_event', [
            'status' => self::STATUS_PROCESSED,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->database->update('webhook_event', [
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 255),
        ], ['id' => $id]);
    }

    public function markIgnored(int $id, string $reason): void
    {
        $this->database->update('webhook_event', [
            'status' => self::STATUS_IGNORED,
            'error' => mb_substr($reason, 0, 255),
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM `webhook_event` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `webhook_event` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public function purgeBefore(string $timestamp): int
    {
        return $this->database->execute(
            'DELETE FROM `webhook_event` WHERE `received_at` < :threshold',
            ['threshold' => $timestamp]
        )->rowCount();
    }
}
