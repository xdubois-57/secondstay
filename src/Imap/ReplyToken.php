<?php

declare(strict_types=1);

namespace SecondStay\Imap;

use SensitiveParameter;

/**
 * Adresse de réponse portant la référence du séjour.
 *
 * Les e-mails sortants annoncent un `Reply-To` de la forme
 * `boite+SS-2026-0001.a1b2c3d4@domaine` : la réponse du client revient donc
 * avec la référence, sans dépendre de ce que son logiciel de messagerie fait
 * des en-têtes de fil.
 *
 * Le suffixe est un HMAC tronqué : sans lui, il suffirait de connaître — ou de
 * deviner — une référence pour faire rattacher n'importe quel courrier au
 * séjour de quelqu'un d'autre.
 */
final class ReplyToken
{
    /** Huit octets hexadécimaux : 32 bits, largement assez contre le tirage au sort. */
    private const SIGNATURE_LENGTH = 16;

    public function __construct(#[SensitiveParameter] private readonly string $secret)
    {
    }

    /**
     * Étiquette à insérer après le `+` de l'adresse.
     */
    public function tag(string $reference): string
    {
        return $reference . '.' . $this->signature($reference);
    }

    /**
     * Adresse de réponse complète pour un séjour.
     *
     * Renvoie l'adresse inchangée si elle ne peut pas porter d'étiquette :
     * mieux vaut un rattachement par les autres voies qu'une adresse invalide.
     */
    public function address(string $mailbox, string $reference): string
    {
        $at = strrpos($mailbox, '@');
        if ($at === false || $reference === '' || $this->secret === '') {
            return $mailbox;
        }

        $local = substr($mailbox, 0, $at);
        $domain = substr($mailbox, $at + 1);

        // Une adresse déjà étiquetée ne doit pas l'être deux fois.
        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        return sprintf('%s+%s@%s', $local, $this->tag($reference), $domain);
    }

    /**
     * Référence portée par une adresse, si la signature est valide.
     */
    public function referenceFrom(string $address): ?string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }

        $local = substr($address, 0, $at);
        $plus = strpos($local, '+');
        if ($plus === false) {
            return null;
        }

        $tag = substr($local, $plus + 1);
        $dot = strrpos($tag, '.');
        if ($dot === false) {
            return null;
        }

        $reference = strtoupper(substr($tag, 0, $dot));
        $signature = strtolower(substr($tag, $dot + 1));

        if ($reference === '' || $this->secret === '') {
            return null;
        }

        return hash_equals($this->signature($reference), $signature) ? $reference : null;
    }

    /**
     * Première référence valide trouvée parmi des adresses de destination.
     *
     * @param list<string> $addresses
     */
    public function referenceFromAny(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            $reference = $this->referenceFrom($address);
            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    private function signature(string $reference): string
    {
        return substr(
            hash_hmac('sha256', 'reply:' . strtoupper($reference), $this->secret),
            0,
            self::SIGNATURE_LENGTH
        );
    }
}
