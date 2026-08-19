<?php

declare(strict_types=1);

namespace SecondStay\Mail;

/**
 * Frontière d'envoi d'e-mail (ARCHITECTURE.md §3).
 *
 * L'implémentation de production est SMTP authentifié ; DKIM est géré par le
 * fournisseur SMTP (AGENTS.md §11).
 */
interface MailTransport
{
    /**
     * @return string Message-ID du message envoyé
     */
    public function send(MailMessage $message): string;

    public function isConfigured(): bool;

    /**
     * Vérifie la configuration sans envoyer de message réel.
     *
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array;
}
