<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use SecondStay\Support\HtmlSanitizer;

/**
 * Message sortant. La construction MIME vit ici afin que tous les transports
 * produisent exactement le même message.
 */
final class MailMessage
{
    /** @var list<MailAttachment> */
    private array $attachments = [];

    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        public readonly MailAddress $from,
        public readonly MailAddress $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text = '',
        public readonly string $locale = 'fr',
        public readonly string $template = '',
        public readonly ?MailAddress $replyTo = null,
    ) {
    }

    public function withAttachment(MailAttachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        // Aucun retour de ligne ne peut être injecté dans un en-tête.
        $this->headers[$name] = str_replace(["\r", "\n", "\0"], '', $value);

        return $this;
    }

    /**
     * @return list<MailAttachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, string>
     */
    public function extraHeaders(): array
    {
        return $this->headers;
    }

    public function plainText(): string
    {
        if (trim($this->text) !== '') {
            return $this->text;
        }

        return (new HtmlSanitizer())->toText($this->html);
    }

    public function messageId(string $domain): string
    {
        $existing = $this->headers['Message-ID'] ?? '';
        if ($existing !== '') {
            return $existing;
        }

        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }

    /**
     * Construit le message MIME complet (en-têtes + corps).
     *
     * @return array{headers: string, body: string, message_id: string}
     */
    public function build(string $domain, ?string $date = null): array
    {
        $messageId = $this->messageId($domain);
        $boundaryAlternative = 'ss-alt-' . bin2hex(random_bytes(8));
        $boundaryMixed = 'ss-mix-' . bin2hex(random_bytes(8));

        $headers = [
            'Date' => $date ?? gmdate('r'),
            'Message-ID' => $messageId,
            'From' => $this->from->toHeader(),
            'To' => $this->to->toHeader(),
            'Subject' => $this->encodeSubject($this->subject),
            'MIME-Version' => '1.0',
            'Content-Language' => $this->locale,
            'Auto-Submitted' => 'auto-generated',
        ];

        if ($this->replyTo instanceof MailAddress) {
            $headers['Reply-To'] = $this->replyTo->toHeader();
        }

        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, 'Message-ID') === 0) {
                continue;
            }
            $headers[$name] = $value;
        }

        $alternative = "--" . $boundaryAlternative . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->plainText())) . "\r\n"
            . "--" . $boundaryAlternative . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->html)) . "\r\n"
            . "--" . $boundaryAlternative . "--\r\n";

        if ($this->attachments === []) {
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundaryAlternative . '"';
            $body = $alternative;
        } else {
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundaryMixed . '"';
            $body = "--" . $boundaryMixed . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundaryAlternative . "\"\r\n\r\n"
                . $alternative;

            foreach ($this->attachments as $attachment) {
                $body .= "--" . $boundaryMixed . "\r\n"
                    . 'Content-Type: ' . $attachment->mimeType . '; name="' . $attachment->safeFilename() . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $attachment->safeFilename() . "\"\r\n\r\n"
                    . chunk_split(base64_encode($attachment->content)) . "\r\n";
            }

            $body .= "--" . $boundaryMixed . "--\r\n";
        }

        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        return ['headers' => $headerLines, 'body' => $body, 'message_id' => $messageId];
    }

    private function encodeSubject(string $subject): string
    {
        $clean = str_replace(["\r", "\n", "\0"], '', $subject);

        if (preg_match('/^[\x20-\x7E]*$/', $clean) === 1) {
            return $clean;
        }

        return '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }
}
