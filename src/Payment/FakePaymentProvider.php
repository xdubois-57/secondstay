<?php

declare(strict_types=1);

namespace SecondStay\Payment;

/**
 * Fournisseur de paiement factice (TESTING.md §8).
 *
 * Il reproduit le déroulé réel — création, redirection, notification, lecture
 * de l'état, remboursement — sans réseau ni clé. Les scénarios de paiement
 * sont donc jouables de bout en bout, y compris le webhook.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const NAME = 'fake';

    /** @var array<string, array{status: PaymentStatus, amount_cents: int, refunded_cents: int, description: string}> */
    private array $payments = [];

    public bool $shouldFail = false;

    /** État attribué à un paiement créé ; le webhook de test le fera évoluer. */
    public PaymentStatus $initialStatus = PaymentStatus::Pending;

    /**
     * @param string|null $spoolFile fichier d'état, pour survivre d'une
     *                               requête HTTP à la suivante pendant les
     *                               tests de bout en bout
     */
    public function __construct(
        private readonly string $returnPath = '/fr/payment/return',
        private readonly ?string $spoolFile = null,
    ) {
        $this->load();
    }

    private function load(): void
    {
        if ($this->spoolFile === null || !is_file($this->spoolFile)) {
            return;
        }

        $raw = file_get_contents($this->spoolFile);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $reference => $payment) {
            if (!is_string($reference) || !is_array($payment)) {
                continue;
            }

            $this->payments[$reference] = [
                'status' => PaymentStatus::fromString((string) ($payment['status'] ?? 'pending')),
                'amount_cents' => (int) ($payment['amount_cents'] ?? 0),
                'refunded_cents' => (int) ($payment['refunded_cents'] ?? 0),
                'description' => (string) ($payment['description'] ?? ''),
            ];
        }
    }

    private function persist(): void
    {
        if ($this->spoolFile === null) {
            return;
        }

        $directory = dirname($this->spoolFile);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return;
        }

        $data = [];
        foreach ($this->payments as $reference => $payment) {
            $data[$reference] = [
                'status' => $payment['status']->value,
                'amount_cents' => $payment['amount_cents'],
                'refunded_cents' => $payment['refunded_cents'],
                'description' => $payment['description'],
            ];
        }

        file_put_contents($this->spoolFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return true;
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
        if ($this->shouldFail) {
            return ['ok' => false, 'reference' => '', 'redirect_url' => '', 'error' => 'payment.error.rejected'];
        }

        $reference = 'tr_' . bin2hex(random_bytes(8));
        $this->payments[$reference] = [
            'status' => $this->initialStatus,
            'amount_cents' => $amountCents,
            'refunded_cents' => 0,
            'description' => $description,
        ];
        $this->persist();

        return [
            'ok' => true,
            'reference' => $reference,
            // La redirection ramène sur l'application : aucun service externe
            // n'est contacté, le parcours reste complet.
            'redirect_url' => $redirectUrl !== '' ? $redirectUrl : $this->returnPath,
            'error' => '',
        ];
    }

    /**
     * @return array{ok: bool, status: PaymentStatus, amount_cents: int, error: string}
     */
    public function fetch(string $reference): array
    {
        $payment = $this->payments[$reference] ?? null;
        if ($payment === null) {
            return [
                'ok' => false,
                'status' => PaymentStatus::Pending,
                'amount_cents' => 0,
                'error' => 'payment.error.unknown_reference',
            ];
        }

        return [
            'ok' => true,
            'status' => $payment['status'],
            'amount_cents' => $payment['amount_cents'],
            'error' => '',
        ];
    }

    /**
     * @return array{ok: bool, reference: string, error: string}
     */
    public function refund(string $reference, int $amountCents, string $description = ''): array
    {
        $payment = $this->payments[$reference] ?? null;
        if ($payment === null || $this->shouldFail) {
            return ['ok' => false, 'reference' => '', 'error' => 'payment.error.refund_failed'];
        }

        $remaining = $payment['amount_cents'] - $payment['refunded_cents'];
        if ($amountCents <= 0 || $amountCents > $remaining) {
            return ['ok' => false, 'reference' => '', 'error' => 'payment.error.refund_amount'];
        }

        $this->payments[$reference]['refunded_cents'] += $amountCents;
        $this->persist();

        return ['ok' => true, 'reference' => 're_' . bin2hex(random_bytes(8)), 'error' => ''];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function referenceFromWebhook(array $payload, string $rawBody = ''): ?string
    {
        $id = $payload['id'] ?? null;

        return is_string($id) && isset($this->payments[$id]) ? $id : null;
    }

    /**
     * Simule l'évolution d'un paiement chez le fournisseur.
     *
     * C'est ce que le fournisseur réel ferait : l'application ne fait que le
     * constater à la lecture, jamais sur la foi du corps du webhook.
     */
    public function settle(string $reference, PaymentStatus $status = PaymentStatus::Paid): bool
    {
        if (!isset($this->payments[$reference])) {
            return false;
        }

        $this->payments[$reference]['status'] = $status;
        $this->persist();

        return true;
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return array_keys($this->payments);
    }
}
