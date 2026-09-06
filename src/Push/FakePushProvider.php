<?php

declare(strict_types=1);

namespace SecondStay\Push;

/**
 * Fournisseur factice : conserve les notifications en mémoire et sur disque
 * pour que les scénarios E2E puissent les inspecter sans réseau ni clé réelle
 * (TESTING.md §8).
 */
final class FakePushProvider implements PushProvider
{
    /** @var list<array{endpoint: string, message: array<string, string>, sent_at: string}> */
    private array $sent = [];

    public bool $shouldFail = false;

    public bool $shouldExpire = false;

    public function __construct(
        private readonly string $publicKey = '',
        private readonly ?string $spoolDirectory = null,
    ) {
    }

    /**
     * La clé publique VAPID provient de l'installation, exactement comme en
     * production : aucune clé n'est écrite en dur dans le dépôt, et le
     * parcours d'abonnement du navigateur est donc réellement exercé.
     */
    public function isConfigured(): bool
    {
        return $this->publicKey !== '';
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * @return array{ok: bool, status: int, expired: bool, error: string}
     */
    public function send(PushSubscription $subscription, PushMessage $message): array
    {
        if ($this->shouldExpire) {
            return ['ok' => false, 'status' => 410, 'expired' => true, 'error' => 'push.error.subscription_expired'];
        }

        if ($this->shouldFail) {
            return ['ok' => false, 'status' => 500, 'expired' => false, 'error' => 'push.error.rejected'];
        }

        $record = [
            'endpoint' => $subscription->endpoint,
            'message' => $message->toArray(),
            'sent_at' => gmdate('c'),
        ];

        $this->sent[] = $record;

        if ($this->spoolDirectory !== null) {
            if (
            is_dir($this->spoolDirectory)
            || mkdir($this->spoolDirectory, 0o750, true)
            || is_dir($this->spoolDirectory)
        ) {
                file_put_contents(
                    $this->spoolDirectory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json',
                    (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }
        }

        return ['ok' => true, 'status' => 201, 'expired' => false, 'error' => ''];
    }

    /**
     * @return list<array{endpoint: string, message: array<string, string>, sent_at: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
