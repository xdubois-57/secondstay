<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use RuntimeException;

/**
 * Transport factice : conserve les messages en mémoire et sur disque afin que
 * les scénarios E2E puissent les inspecter sans réseau (TESTING.md §8).
 */
final class FakeMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    private array $messages = [];

    public bool $shouldFail = false;

    public function __construct(private readonly ?string $spoolDirectory = null)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array
    {
        return ['ok' => !$this->shouldFail, 'detail' => $this->shouldFail ? 'mail.error.rejected' : 'mail.verify.ok'];
    }

    public function send(MailMessage $message): string
    {
        if ($this->shouldFail) {
            throw new RuntimeException('mail.error.rejected');
        }

        $this->messages[] = $message;
        $built = $message->build('example.test');

        if ($this->spoolDirectory !== null) {
            if (
            !is_dir($this->spoolDirectory)
            && !mkdir($this->spoolDirectory, 0o750, true)
            && !is_dir($this->spoolDirectory)
        ) {
                throw new RuntimeException('mail.error.write_failed');
            }

            $payload = [
                'to' => $message->to->address,
                'subject' => $message->subject,
                'locale' => $message->locale,
                'template' => $message->template,
                'html' => $message->html,
                'text' => $message->plainText(),
                'message_id' => $built['message_id'],
                'sent_at' => gmdate('c'),
            ];

            file_put_contents(
                $this->spoolDirectory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json',
                (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $built['message_id'];
    }

    /**
     * @return list<MailMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function lastMessage(): ?MailMessage
    {
        return $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
    }

    /**
     * @return list<MailMessage>
     */
    public function messagesTo(string $address): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (MailMessage $message): bool => strcasecmp($message->to->address, $address) === 0
        ));
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
