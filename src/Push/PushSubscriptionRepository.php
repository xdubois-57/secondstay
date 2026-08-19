<?php

declare(strict_types=1);

namespace SecondStay\Push;

use SecondStay\Database\Database;

/**
 * Abonnements push. L'endpoint complet est conservé pour l'envoi ; son
 * condensat sert de clé d'unicité et n'expose rien de plus.
 */
final class PushSubscriptionRepository
{
    public const MAX_FAILURES = 5;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Enregistre ou rafraîchit un abonnement. Un même endpoint ne produit
     * jamais de doublon, même après réinstallation de l'application.
     */
    public function save(PushSubscription $subscription, string $userAgent = ''): int
    {
        $existing = $this->findByEndpointHash($subscription->endpointHash());
        $now = gmdate('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->database->update('push_subscription', [
                'user_id' => $subscription->userId,
                'public_key' => $subscription->publicKey,
                'auth_secret' => $subscription->authSecret,
                'locale' => $subscription->locale,
                'user_agent' => mb_substr($userAgent, 0, 255),
                'failures' => 0,
            ], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->database->insert('push_subscription', [
            'user_id' => $subscription->userId,
            'endpoint' => $subscription->endpoint,
            'endpoint_hash' => $subscription->endpointHash(),
            'public_key' => $subscription->publicKey,
            'auth_secret' => $subscription->authSecret,
            'locale' => $subscription->locale,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEndpointHash(string $hash): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `push_subscription` WHERE `endpoint_hash` = :hash',
            ['hash' => $hash]
        );
    }

    /**
     * @return list<PushSubscription>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM `push_subscription` WHERE `user_id` = :user ORDER BY `id`',
            ['user' => $userId]
        );

        return array_map(static fn (array $row): PushSubscription => PushSubscription::fromRow($row), $rows);
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `push_subscription` WHERE `user_id` = :user',
            ['user' => $userId]
        );
    }

    public function countAll(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM `push_subscription`');
    }

    public function markUsed(int $id): void
    {
        $this->database->update(
            'push_subscription',
            ['last_used_at' => gmdate('Y-m-d H:i:s'), 'failures' => 0],
            ['id' => $id]
        );
    }

    /**
     * Un abonnement qui échoue durablement est supprimé : conserver une
     * adresse morte ne sert qu'à rallonger chaque envoi.
     */
    public function markFailed(int $id): bool
    {
        $this->database->execute(
            'UPDATE `push_subscription` SET `failures` = `failures` + 1 WHERE `id` = :id',
            ['id' => $id]
        );

        $failures = (int) $this->database->fetchValue(
            'SELECT `failures` FROM `push_subscription` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($failures >= self::MAX_FAILURES) {
            $this->delete($id);

            return true;
        }

        return false;
    }

    public function delete(int $id): void
    {
        $this->database->delete('push_subscription', ['id' => $id]);
    }

    public function deleteByEndpointHash(string $hash): bool
    {
        return $this->database->delete('push_subscription', ['endpoint_hash' => $hash]) > 0;
    }

    /**
     * Supprime tous les abonnements : nécessaire après un changement de clés
     * VAPID, qui rend les anciens abonnements indéchiffrables.
     */
    public function clearAll(): int
    {
        return $this->database->execute('DELETE FROM `push_subscription`')->rowCount();
    }

    public function deleteForUser(int $userId): int
    {
        return $this->database->delete('push_subscription', ['user_id' => $userId]);
    }
}
