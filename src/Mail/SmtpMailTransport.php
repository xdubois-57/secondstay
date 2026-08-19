<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use RuntimeException;
use SensitiveParameter;

/**
 * Client SMTP minimal et authentifié.
 *
 * Volontairement limité à ce dont SecondStay a besoin : STARTTLS ou TLS
 * implicite, AUTH LOGIN/PLAIN, un message à la fois. Aucun client mail
 * généraliste (AGENTS.md §11).
 */
final class SmtpMailTransport implements MailTransport
{
    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_TLS = 'tls';

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        private readonly string $encryption = self::ENCRYPTION_STARTTLS,
        private readonly ?MailAddress $defaultFrom = null,
        private readonly string $heloDomain = 'localhost',
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->port > 0;
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'detail' => 'mail.error.not_configured'];
        }

        try {
            $this->connect();
            $this->quit();

            return ['ok' => true, 'detail' => 'mail.verify.ok'];
        } catch (RuntimeException $exception) {
            $this->close();

            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    public function send(MailMessage $message): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('mail.error.not_configured');
        }

        $built = $message->build($this->domain());

        try {
            $this->connect();

            $this->command('MAIL FROM:<' . $message->from->address . '>', [250]);
            $this->command('RCPT TO:<' . $message->to->address . '>', [250, 251]);
            $this->command('DATA', [354]);

            $payload = $built['headers'] . "\r\n" . $built['body'];
            $this->write($this->dotStuff($payload) . "\r\n.\r\n");
            $this->expect([250]);

            $this->quit();

            return $built['message_id'];
        } catch (RuntimeException $exception) {
            $this->close();

            throw $exception;
        }
    }

    /**
     * Domaine utilisé pour l'identifiant de message. Il ne dépend jamais d'une
     * en-tête fournie par un tiers.
     */
    private function domain(): string
    {
        if (!$this->defaultFrom instanceof MailAddress) {
            return $this->heloDomain;
        }

        $parts = explode('@', $this->defaultFrom->address);

        return $parts[1] ?? $this->heloDomain;
    }

    private function connect(): void
    {
        $scheme = $this->encryption === self::ENCRYPTION_TLS ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errorNumber,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            // Le message système peut contenir des détails d'infrastructure :
            // on ne propage qu'une clé de traduction.
            throw new RuntimeException('mail.error.connection_failed');
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeoutSeconds);

        $this->expect([220]);
        $this->ehlo();

        if ($this->encryption === self::ENCRYPTION_STARTTLS) {
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('mail.error.tls_failed');
            }
            $this->ehlo();
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    private function ehlo(): void
    {
        $this->command('EHLO ' . $this->heloDomain, [250]);
    }

    private function authenticate(): void
    {
        // AUTH PLAIN d'abord : un seul aller-retour, largement supporté.
        $credentials = base64_encode("\0" . $this->username . "\0" . $this->password);

        try {
            $this->command('AUTH PLAIN ' . $credentials, [235]);

            return;
        } catch (RuntimeException) {
            // Certains serveurs n'acceptent que AUTH LOGIN.
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235]);
    }

    private function quit(): void
    {
        try {
            $this->command('QUIT', [221]);
        } catch (RuntimeException) {
            // Une fermeture imparfaite ne remet pas en cause l'envoi.
        }

        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @param list<int> $expected
     */
    private function command(string $command, array $expected): string
    {
        $this->write($command . "\r\n");

        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('mail.error.connection_failed');
        }

        if (@fwrite($this->socket, $data) === false) {
            throw new RuntimeException('mail.error.write_failed');
        }
    }

    /**
     * @param list<int> $expected
     */
    private function expect(array $expected): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('mail.error.connection_failed');
        }

        $response = '';
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                throw new RuntimeException('mail.error.no_response');
            }
            $response .= $line;

            // Une réponse multi-lignes se termine par « 250 » (espace).
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException($code >= 500 ? 'mail.error.rejected' : 'mail.error.unexpected_response');
        }

        return $response;
    }

    /**
     * Protection du point en début de ligne (RFC 5321 §4.5.2).
     */
    private function dotStuff(string $payload): string
    {
        $normalised = str_replace(["\r\n", "\r", "\n"], "\r\n", $payload);

        return preg_replace('/^\./m', '..', $normalised) ?? $normalised;
    }
}
