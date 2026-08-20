<?php

declare(strict_types=1);

namespace SecondStay\Payment;

/**
 * Absence de fournisseur de paiement.
 *
 * Ce n'est pas un fournisseur factice : il ne prétend jamais avoir encaissé
 * quoi que ce soit. Tant qu'aucune clé n'est configurée, le paiement en ligne
 * n'est simplement pas proposé, et seuls le virement et l'encaissement manuel
 * restent possibles. Un fournisseur factice à cette place laisserait un
 * visiteur confirmer un séjour sans jamais payer.
 */
final class NullPaymentProvider implements PaymentProvider
{
    public const NAME = 'none';

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return false;
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
        return ['ok' => false, 'reference' => '', 'redirect_url' => '', 'error' => 'payment.error.not_configured'];
    }

    /**
     * @return array{ok: bool, status: PaymentStatus, amount_cents: int, error: string}
     */
    public function fetch(string $reference): array
    {
        return [
            'ok' => false,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 0,
            'error' => 'payment.error.not_configured',
        ];
    }

    /**
     * @return array{ok: bool, reference: string, error: string}
     */
    public function refund(string $reference, int $amountCents, string $description = ''): array
    {
        return ['ok' => false, 'reference' => '', 'error' => 'payment.error.not_configured'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function referenceFromWebhook(array $payload, string $rawBody = ''): ?string
    {
        return null;
    }
}
