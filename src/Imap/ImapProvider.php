<?php

declare(strict_types=1);

namespace SecondStay\Imap;

/**
 * Frontière de récupération du courrier entrant (ARCHITECTURE.md §3).
 *
 * Le domaine ne connaît que cette interface : le client réel et le
 * fournisseur factice sont interchangeables, ce qui rend le parcours
 * « réponse par e-mail → document du séjour » jouable sans boîte aux lettres.
 */
interface ImapProvider
{
    public function isConfigured(): bool;

    /**
     * Vérifie que la boîte répond et est lisible.
     *
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array;

    /**
     * Identifiant de validité de la boîte.
     *
     * Il change lorsque le serveur renumérote : les UID mémorisés deviennent
     * alors caducs et la synchronisation doit repartir de zéro.
     */
    public function uidValidity(): int;

    /**
     * UID strictement supérieurs à `$sinceUid`, dans l'ordre croissant.
     *
     * @return list<int>
     */
    public function listNewUids(int $sinceUid, int $limit = 50): array;

    /**
     * Message brut correspondant à un UID, ou `null` s'il a disparu.
     */
    public function fetch(int $uid): ?string;

    /**
     * Nom de la boîte synchronisée, tel que stocké avec chaque message.
     */
    public function mailbox(): string;
}
