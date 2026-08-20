<?php

declare(strict_types=1);

namespace SecondStay\Imap;

use RuntimeException;
use SensitiveParameter;

/**
 * Client IMAP minimal, écrit sur socket.
 *
 * `ext-imap` est absente de la plupart des hébergements mutualisés visés et
 * n'est plus maintenue dans le cœur de PHP : le protocole est donc parlé
 * directement, comme l'est déjà SMTP.
 *
 * Le champ couvert est celui dont SecondStay a besoin : se connecter,
 * sélectionner une boîte, lister les UID récents, télécharger un message.
 * Pas d'IDLE — la synchronisation est périodique, jamais une connexion
 * maintenue (SPECIFICATIONS.md §36).
 */
final class ImapClient implements ImapProvider
{
    public const ENCRYPTION_TLS = 'tls';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_NONE = 'none';

    /** Un message plus gros que cela n'est pas un courrier de réservation. */
    private const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    /** @var resource|null */
    private $socket = null;

    private int $sequence = 0;

    private int $uidValidity = 0;

    private bool $selected = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        private readonly string $mailbox = 'INBOX',
        private readonly string $encryption = self::ENCRYPTION_TLS,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->username !== '' && $this->password !== '';
    }

    public function mailbox(): string
    {
        return $this->mailbox;
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'detail' => 'mailbox.error.not_configured'];
        }

        try {
            $this->select();
            $this->logout();

            return ['ok' => true, 'detail' => 'mailbox.verify.ok'];
        } catch (RuntimeException $exception) {
            $this->close();

            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    public function uidValidity(): int
    {
        $this->select();

        return $this->uidValidity;
    }

    /**
     * @return list<int>
     */
    public function listNewUids(int $sinceUid, int $limit = 50): array
    {
        $this->select();

        $from = max(1, $sinceUid + 1);
        $lines = $this->command(sprintf('UID SEARCH UID %d:*', $from));

        $uids = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '* SEARCH')) {
                continue;
            }

            foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $uid = (int) $token;
                    // `UID n:*` renvoie toujours au moins un message, même
                    // lorsque tous sont plus anciens : on filtre nous-mêmes.
                    if ($uid >= $from) {
                        $uids[] = $uid;
                    }
                }
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids);

        return array_slice($uids, 0, max(1, $limit));
    }

    public function fetch(int $uid): ?string
    {
        $this->select();

        $lines = $this->command(sprintf('UID FETCH %d (BODY.PEEK[])', $uid), true);

        return $lines['literal'] === '' ? null : $lines['literal'];
    }

    public function logout(): void
    {
        if ($this->socket !== null) {
            try {
                $this->command('LOGOUT');
            } catch (RuntimeException) {
                // La déconnexion est une politesse : son échec n'apprend rien.
            }
        }

        $this->close();
    }

    // --- Connexion -----------------------------------------------------------

    private function select(): void
    {
        if ($this->selected) {
            return;
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException('mailbox.error.not_configured');
        }

        $this->connect();
        $this->login();

        $lines = $this->command('SELECT ' . self::quote($this->mailbox));

        foreach ($lines as $line) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $line, $match) === 1) {
                $this->uidValidity = (int) $match[1];
            }
        }

        $this->selected = true;
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
            // Le message système peut décrire l'infrastructure : seule une
            // clé de traduction sort d'ici.
            throw new RuntimeException('mailbox.error.connection_failed');
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeoutSeconds);

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('mailbox.error.greeting');
        }

        if ($this->encryption === self::ENCRYPTION_STARTTLS) {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('mailbox.error.tls_failed');
            }
        }
    }

    private function login(): void
    {
        $this->command(sprintf('LOGIN %s %s', self::quote($this->username), self::quote($this->password)));
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }

        $this->selected = false;
    }

    // --- Protocole -------------------------------------------------------------

    /**
     * Envoie une commande et lit la réponse jusqu'à son étiquette.
     *
     * @return ($withLiteral is true ? array{lines: list<string>, literal: string} : list<string>)
     */
    private function command(string $command, bool $withLiteral = false): array
    {
        $tag = sprintf('A%03d', ++$this->sequence);
        $this->write($tag . ' ' . $command . "\r\n");

        $lines = [];
        $literal = '';

        while (true) {
            $line = $this->readLine();

            if ($line === '') {
                throw new RuntimeException('mailbox.error.connection_lost');
            }

            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));
                if ($status !== 'OK') {
                    throw new RuntimeException('mailbox.error.command_failed');
                }
                break;
            }

            // Une réponse peut annoncer un bloc de N octets à lire tel quel.
            if (preg_match('/\{(\d+)\}\s*$/', $line, $match) === 1) {
                $size = (int) $match[1];
                if ($size > self::MAX_MESSAGE_BYTES) {
                    throw new RuntimeException('mailbox.error.too_large');
                }
                $literal .= $this->readBytes($size);
                continue;
            }

            $lines[] = $line;
        }

        return $withLiteral ? ['lines' => $lines, 'literal' => $literal] : $lines;
    }

    private function write(string $payload): void
    {
        if ($this->socket === null || fwrite($this->socket, $payload) === false) {
            throw new RuntimeException('mailbox.error.write_failed');
        }
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('mailbox.error.connection_lost');
        }

        $line = fgets($this->socket, 8192);

        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException($meta['timed_out'] ? 'mailbox.error.timeout' : 'mailbox.error.connection_lost');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $size): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('mailbox.error.connection_lost');
        }

        $data = '';
        while (strlen($data) < $size) {
            $chunk = fread($this->socket, max(1, min(8192, $size - strlen($data))));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('mailbox.error.connection_lost');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Chaîne IMAP entre guillemets, échappée.
     *
     * Un mot de passe ou un nom de boîte contenant un guillemet doit sortir
     * du protocole sans le casser.
     */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
