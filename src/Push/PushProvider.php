<?php

declare(strict_types=1);

namespace SecondStay\Push;

/**
 * Frontière du push navigateur (ARCHITECTURE.md §3).
 *
 * Une implémentation factice permet de rejouer tous les parcours sans réseau
 * ni clé réelle (TESTING.md §8).
 */
interface PushProvider
{
    public function isConfigured(): bool;

    /**
     * Clé publique VAPID à transmettre au navigateur, en base64url.
     */
    public function publicKey(): string;

    /**
     * @return array{ok: bool, status: int, expired: bool, error: string}
     */
    public function send(PushSubscription $subscription, PushMessage $message): array;
}
