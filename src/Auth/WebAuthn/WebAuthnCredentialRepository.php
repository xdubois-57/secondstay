<?php

declare(strict_types=1);

namespace SecondStay\Auth\WebAuthn;

use SecondStay\Database\Database;

final class WebAuthnCredentialRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param list<string> $transports
     */
    public function create(
        int $userId,
        string $credentialId,
        string $publicKeyPem,
        int $signCount,
        array $transports,
        string $label,
    ): int {
        return $this->database->insert('webauthn_credential', [
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'public_key' => $publicKeyPem,
            'sign_count' => $signCount,
            'transports' => mb_substr(implode(',', $transports), 0, 120),
            'label' => mb_substr($label, 0, 120),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `webauthn_credential` WHERE `credential_id` = :id',
            ['id' => $credentialId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `webauthn_credential` WHERE `user_id` = :user ORDER BY `id`',
            ['user' => $userId]
        );
    }

    public function updateUsage(int $id, int $signCount): void
    {
        $this->database->update('webauthn_credential', [
            'sign_count' => $signCount,
            'last_used_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->database->execute(
            'DELETE FROM `webauthn_credential` WHERE `id` = :id AND `user_id` = :user',
            ['id' => $id, 'user' => $userId]
        )->rowCount() > 0;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `webauthn_credential` WHERE `user_id` = :user',
            ['user' => $userId]
        );
    }
}
