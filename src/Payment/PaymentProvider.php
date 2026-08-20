<?php

declare(strict_types=1);

namespace SecondStay\Payment;

/**
 * Frontière de paiement (ARCHITECTURE.md §13).
 *
 * L'interface expose ce dont le domaine a besoin, sans l'enfermer dans un
 * fournisseur : Mollie est le premier, il n'est pas le seul possible.
 */
interface PaymentProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Crée un paiement chez le fournisseur et renvoie l'URL de redirection.
     *
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
    ): array;

    /**
     * État courant d'un paiement, tel que le fournisseur le connaît.
     *
     * @return array{ok: bool, status: PaymentStatus, amount_cents: int, error: string}
     */
    public function fetch(string $reference): array;

    /**
     * @return array{ok: bool, reference: string, error: string}
     */
    public function refund(string $reference, int $amountCents, string $description = ''): array;

    /**
     * Extrait l'identifiant de paiement d'une notification.
     *
     * Le corps d'un webhook n'est jamais cru sur parole : il ne sert qu'à
     * savoir **quel** paiement re-interroger auprès du fournisseur.
     *
     * @param array<string, mixed> $payload
     */
    public function referenceFromWebhook(array $payload, string $rawBody = ''): ?string;
}
