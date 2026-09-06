<?php

declare(strict_types=1);

namespace SecondStay\Audit;

use SecondStay\Database\Database;
use SecondStay\Logging\LogSanitizer;

/**
 * Journal d'audit métier/sécurité, distinct du journal technique
 * (AGENTS.md §17). Il trace les actions sensibles : prix, réservation, rôles,
 * caution, remboursement, restauration, documents critiques, configuration.
 */
final class AuditTrail
{
    private string $correlationId;

    private string $ip = '';

    public function __construct(
        private readonly Database $database,
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? bin2hex(random_bytes(16));
    }

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $action,
        string $entityType = '',
        string $entityId = '',
        ?array $before = null,
        ?array $after = null,
        ?int $actorUserId = null,
        string $actorLabel = '',
    ): void {
        $this->database->insert('audit_event', [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'actor_user_id' => $actorUserId,
            'actor_label' => mb_substr($actorLabel, 0, 190),
            'action' => mb_substr($action, 0, 96),
            'entity_type' => mb_substr($entityType, 0, 64),
            'entity_id' => mb_substr($entityId, 0, 64),
            'before_state' => $before === null ? null : json_encode(
                LogSanitizer::sanitize($before),
                JSON_UNESCAPED_UNICODE,
            ),
            'after_state' => $after === null ? null : json_encode(
                LogSanitizer::sanitize($after),
                JSON_UNESCAPED_UNICODE,
            ),
            'ip' => $this->ip,
            'correlation_id' => $this->correlationId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50, int $offset = 0): array
    {
        // LIMIT/OFFSET ne peuvent pas être des paramètres liés en mode natif :
        // les bornes sont donc contraintes à des entiers sûrs.
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);

        return $this->database->fetchAll(
            sprintf('SELECT * FROM `audit_event` ORDER BY `id` DESC LIMIT %d OFFSET %d', $limit, $offset)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `audit_event` WHERE `entity_type` = :type AND `entity_id` = :id ORDER BY `id` DESC',
            ['type' => $entityType, 'id' => $entityId]
        );
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM `audit_event`');
    }
}
