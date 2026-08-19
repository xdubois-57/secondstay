<?php

declare(strict_types=1);

namespace SecondStay\Push;

use SecondStay\Settings\SettingsService;

/**
 * Cycle de vie des clés VAPID de l'installation.
 *
 * La clé privée est stockée comme un secret (chiffrée au repos, jamais
 * affichée) ; la clé publique est destinée aux navigateurs. Aucune clé n'est
 * versionnée : chaque installation génère la sienne.
 */
final class VapidKeyManager
{
    public const PUBLIC_SETTING = 'push.vapid_public';
    public const PRIVATE_SETTING = 'push.vapid_private';
    public const SUBJECT_SETTING = 'push.subject';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function hasKeys(): bool
    {
        return $this->vapid()->isUsable();
    }

    /**
     * Génère une paire si nécessaire et renvoie la clé publique.
     *
     * Régénérer invalide tous les abonnements existants : l'appelant doit donc
     * demander explicitement le remplacement.
     */
    public function ensureKeys(bool $regenerate = false, string $actor = 'system'): string
    {
        if (!$regenerate && $this->hasKeys()) {
            return $this->settings->string(self::PUBLIC_SETTING);
        }

        $pair = Vapid::generateKeyPair();

        $this->settings->setMany([
            self::PUBLIC_SETTING => $pair['public'],
            self::PRIVATE_SETTING => $pair['private'],
        ], $actor);

        return $pair['public'];
    }

    public function publicKey(): string
    {
        return $this->settings->string(self::PUBLIC_SETTING);
    }

    public function vapid(): Vapid
    {
        $private = $this->settings->isSecretDefined(self::PRIVATE_SETTING)
            ? (string) $this->settings->get(self::PRIVATE_SETTING)
            : '';

        return new Vapid(
            $this->settings->string(self::PUBLIC_SETTING),
            $private,
            $this->subject(),
        );
    }

    /**
     * Contact joignable exigé par la RFC 8292 : l'adresse d'expédition des
     * e-mails fait un repli naturel.
     */
    private function subject(): string
    {
        $subject = $this->settings->string(self::SUBJECT_SETTING);

        return $subject !== '' ? $subject : $this->settings->string('mail.from_address');
    }
}
