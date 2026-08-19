<?php

declare(strict_types=1);

namespace SecondStay\Push;

use SecondStay\Http\HttpFetcher;
use Throwable;

/**
 * Envoi Web Push standard (RFC 8030 / 8291 / 8292).
 *
 * Le message est chiffré de bout en bout : le service de push relaie un
 * conteneur qu'il ne peut pas lire.
 */
final class WebPushProvider implements PushProvider
{
    public const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly Vapid $vapid,
        private readonly HttpFetcher $http,
        private readonly PushEncryption $encryption = new PushEncryption(),
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->vapid->isUsable();
    }

    public function publicKey(): string
    {
        return $this->vapid->publicKey;
    }

    /**
     * @return array{ok: bool, status: int, expired: bool, error: string}
     */
    public function send(PushSubscription $subscription, PushMessage $message): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => 'push.error.not_configured'];
        }

        try {
            $body = $this->encryption->encrypt(
                $message->toJson(),
                $subscription->binaryPublicKey(),
                $subscription->binaryAuthSecret(),
            );

            $authorization = $this->vapid->authorizationHeader($subscription->endpoint);

            $response = $this->http->post($subscription->endpoint, $body, [
                'Authorization' => $authorization['authorization'],
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) $this->ttlSeconds,
                'Urgency' => 'normal',
            ]);
        } catch (Throwable $throwable) {
            $reason = $throwable->getMessage();

            return [
                'ok' => false,
                'status' => 0,
                'expired' => false,
                // Seules les clés de traduction connues sont propagées.
                'error' => str_starts_with($reason, 'push.error.') ? $reason : 'push.error.transport',
            ];
        }

        $status = $response['status'];

        // 404 et 410 signalent un abonnement révoqué par le navigateur : il
        // doit être supprimé, pas réessayé.
        $expired = in_array($status, [404, 410], true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'expired' => false, 'error' => ''];
        }

        return [
            'ok' => false,
            'status' => $status,
            'expired' => $expired,
            'error' => $expired ? 'push.error.subscription_expired' : 'push.error.rejected',
        ];
    }
}
