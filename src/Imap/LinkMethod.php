<?php

declare(strict_types=1);

namespace SecondStay\Imap;

/**
 * Comment un courrier entrant a été rattaché à un séjour
 * (SPECIFICATIONS.md §36).
 *
 * L'ordre de déclaration est l'ordre de confiance : le jeton de réponse est
 * signé, les en-têtes de fil sont difficiles à forger de bout en bout, une
 * référence citée dans un corps de message ne prouve rien du tout, et
 * l'adresse de l'expéditeur encore moins.
 */
enum LinkMethod: string
{
    case Token = 'token';
    case Thread = 'thread';
    case Reference = 'reference';
    case Sender = 'sender';
    case Manual = 'manual';
    case None = '';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::None;
    }

    /**
     * Un rattachement dont on peut se contenter sans relecture humaine.
     */
    public function isTrusted(): bool
    {
        return match ($this) {
            self::Token, self::Thread, self::Manual => true,
            default => false,
        };
    }

    public function labelKey(): string
    {
        return $this === self::None ? 'mailbox.link.none' : 'mailbox.link.' . $this->value;
    }
}
