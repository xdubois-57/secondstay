<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use SecondStay\Http\HttpFetcher;
use SensitiveParameter;
use Throwable;

/**
 * Mollie, premier fournisseur de paiement.
 *
 * Le domaine ne connaît que `PaymentProvider` : cette classe traduit dans les
 * deux sens et ne laisse jamais remonter de détail technique.
 */
final class MolliePaymentProvider implements PaymentProvider
{
    public const NAME = 'mollie';
    public const API = 'https://api.mollie.com/v2';

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly HttpFetcher $http,
        private readonly string $endpoint = self::API,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        // Les clés Mollie sont préfixées par leur mode : une clé de test ne
        // doit jamais être confondue avec une clé de production.
        return preg_match('/^(live|test)_[A-Za-z0-9]{20,}$/', $this->apiKey) === 1;
    }

    public function isLive(): bool
    {
        return str_starts_with($this->apiKey, 'live_');
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array{ok: bool, reference: string, redirect_url: string, error: string}
     */
    public function create(
        int $amountCents,
        string $currency,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        array $metadata = [],
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'reference' => '', 'redirect_url' => '', 'error' => 'payment.error.not_configured'];
        }

        $payload = [
            'amount' => [
                'currency' => $currency,
                // Mollie attend une chaîne décimale, jamais un flottant.
                'value' => number_format($amountCents / 100, 2, '.', ''),
            ],
            'description' => mb_substr($description, 0, 255),
            'redirectUrl' => $redirectUrl,
            'webhookUrl' => $webhookUrl,
            'metadata' => $metadata,
        ];

        $response = $this->post('/payments', $payload);
        if ($response === null) {
            return ['ok' => false, 'reference' => '', 'redirect_url' => '', 'error' => 'payment.error.unreachable'];
        }

        $reference = is_string($response['id'] ?? null) ? $response['id'] : '';
        /** @var array<string, mixed> $links */
        $links = is_array($response['_links'] ?? null) ? $response['_links'] : [];
        /** @var array<string, mixed> $checkout */
        $checkout = is_array($links['checkout'] ?? null) ? $links['checkout'] : [];
        $url = is_string($checkout['href'] ?? null) ? $checkout['href'] : '';

        if ($reference === '' || $url === '') {
            return ['ok' => false, 'reference' => '', 'redirect_url' => '', 'error' => 'payment.error.rejected'];
        }

        return ['ok' => true, 'reference' => $reference, 'redirect_url' => $url, 'error' => ''];
    }

    /**
     * @return array{ok: bool, status: PaymentStatus, amount_cents: int, error: string}
     */
    public function fetch(string $reference): array
    {
        if (!$this->isConfigured() || $reference === '') {
            return [
                'ok' => false,
                'status' => PaymentStatus::Pending,
                'amount_cents' => 0,
                'error' => 'payment.error.not_configured',
            ];
        }

        try {
            $response = $this->http->getJson(
                $this->endpoint . '/payments/' . rawurlencode($reference),
                $this->headers()
            );
        } catch (Throwable) {
            $response = null;
        }

        if ($response === null) {
            return [
                'ok' => false,
                'status' => PaymentStatus::Pending,
                'amount_cents' => 0,
                'error' => 'payment.error.unreachable',
            ];
        }

        /** @var array<string, mixed> $amount */
        $amount = is_array($response['amount'] ?? null) ? $response['amount'] : [];

        return [
            'ok' => true,
            'status' => self::translateStatus(is_string($response['status'] ?? null) ? $response['status'] : ''),
            'amount_cents' => self::toCents(is_string($amount['value'] ?? null) ? $amount['value'] : '0'),
            'error' => '',
        ];
    }

    /**
     * @return array{ok: bool, reference: string, error: string}
     */
    public function refund(string $reference, int $amountCents, string $description = ''): array
    {
        if (!$this->isConfigured() || $reference === '' || $amountCents <= 0) {
            return ['ok' => false, 'reference' => '', 'error' => 'payment.error.not_configured'];
        }

        $response = $this->post('/payments/' . rawurlencode($reference) . '/refunds', [
            'amount' => ['currency' => 'EUR', 'value' => number_format($amountCents / 100, 2, '.', '')],
            'description' => mb_substr($description === '' ? 'Remboursement' : $description, 0, 255),
        ]);

        if ($response === null || !is_string($response['id'] ?? null)) {
            return ['ok' => false, 'reference' => '', 'error' => 'payment.error.refund_failed'];
        }

        return ['ok' => true, 'reference' => $response['id'], 'error' => ''];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function referenceFromWebhook(array $payload, string $rawBody = ''): ?string
    {
        // Mollie n'envoie qu'un identifiant : c'est volontaire, et c'est
        // exactement ce qu'il faut. L'état est ensuite lu chez le fournisseur.
        $id = $payload['id'] ?? null;

        if (!is_string($id) || preg_match('/^(tr|re)_[A-Za-z0-9]{6,}$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    public static function translateStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'paid' => PaymentStatus::Paid,
            'authorized' => PaymentStatus::Authorized,
            'canceled', 'cancelled' => PaymentStatus::Cancelled,
            'expired', 'failed' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    public static function toCents(string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = $this->http->post(
                $this->endpoint . $path,
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->headers() + ['Content-Type' => 'application/json'],
            );
        } catch (Throwable) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'];
    }
}
