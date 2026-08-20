<?php

declare(strict_types=1);

namespace SecondStay\Imap;

/**
 * Message reçu, une fois analysé.
 *
 * Le corps est déjà décodé et converti en UTF-8, et les pièces jointes sont
 * séparées : les couches supérieures n'ont plus à connaître MIME.
 */
final class MimeMessage
{
    /**
     * @param array<string, string>                                                                   $headers
     * @param list<array{filename: string, mime: string, contents: string, content_id: string}>       $attachments
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $text,
        public readonly string $html,
        public readonly array $attachments,
        public readonly string $messageId,
        public readonly string $inReplyTo,
        /** @var list<string> */
        public readonly array $references,
        public readonly ?string $date,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Toutes les adresses présentes dans les champs de destination.
     *
     * Le jeton de rattachement peut se trouver dans `To`, `Cc` ou
     * `Delivered-To` selon la façon dont le client a répondu.
     *
     * @return list<string>
     */
    public function recipientAddresses(): array
    {
        $addresses = [];

        foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'] as $field) {
            foreach (MimeParser::addresses($this->header($field)) as $address) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Identifiant de fil : la première référence connue, sinon la réponse à.
     */
    public function threadId(): string
    {
        return $this->references[0] ?? $this->inReplyTo;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}
