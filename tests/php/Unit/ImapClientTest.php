<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Imap\ImapClient;

/**
 * Client IMAP, confronté à un vrai serveur factice.
 *
 * Un client de protocole ne se teste pas avec un bouchon d'objet : le test
 * ouvre une socket, fait parler le client, et relit la transcription pour
 * vérifier ce qui a réellement été émis.
 */
final class ImapClientTest extends TestCase
{
    /** @var resource|null */
    private $stub = null;

    private string $transcriptPath = '';

    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->stub)) {
            proc_terminate($this->stub);
            proc_close($this->stub);
        }
        $this->stub = null;

        foreach ([$this->transcriptPath, $this->transcriptPath . '.port'] as $file) {
            if ($file !== '.port' && is_file($file)) {
                unlink($file);
            }
        }
    }

    // --- Session complète ------------------------------------------------------

    public function testAFullSessionSpeaksTheExpectedProtocol(): void
    {
        $this->startStub();
        $client = $this->client();

        self::assertSame(42, $client->uidValidity());
        self::assertSame([11, 12, 17], $client->listNewUids(0));
        self::assertStringContainsString('Subject: Premier', (string) $client->fetch(11));

        $client->logout();

        $transcript = $this->transcript();

        self::assertStringContainsString('LOGIN "boite@example.test"', $transcript);
        self::assertStringContainsString('SELECT "INBOX"', $transcript);
        self::assertStringContainsString('UID SEARCH UID 1:*', $transcript);
        self::assertStringContainsString('UID FETCH 11 (BODY.PEEK[])', $transcript);
        self::assertStringContainsString('LOGOUT', $transcript);
    }

    public function testThePasswordNeverAppearsInTheTranscript(): void
    {
        $this->startStub();
        $client = $this->client();
        $client->uidValidity();
        $client->logout();

        // Le stub masque le mot de passe : si le client l'envoyait ailleurs
        // qu'en argument de LOGIN, il ressortirait ici.
        self::assertStringNotContainsString('mot-de-passe', $this->transcript());
    }

    public function testEachCommandCarriesADistinctTag(): void
    {
        $this->startStub();
        $client = $this->client();
        $client->listNewUids(0);
        $client->fetch(11);
        $client->logout();

        $tags = [];
        foreach (explode("\n", $this->transcript()) as $line) {
            $tags[] = explode(' ', $line)[0];
        }

        self::assertSame($tags, array_unique($tags), 'Deux commandes ne doivent pas partager une étiquette.');
    }

    // --- Reprise ------------------------------------------------------------------

    public function testOnlyUidsAboveTheResumePointAreReturned(): void
    {
        $this->startStub();
        $client = $this->client();

        self::assertSame([12, 17], $client->listNewUids(11));
        self::assertSame([17], $client->listNewUids(12));
    }

    /**
     * Un serveur IMAP renvoie toujours au moins un message à `UID n:*`, même
     * quand tous sont plus anciens : le client doit filtrer lui-même, sinon il
     * réimporterait le dernier message à chaque relève.
     */
    public function testTheLastMessageIsNotReturnedAgainWhenNothingIsNew(): void
    {
        $this->startStub();

        self::assertSame([], $this->client()->listNewUids(17));
    }

    public function testTheNumberOfUidsIsBounded(): void
    {
        $this->startStub();

        self::assertCount(2, $this->client()->listNewUids(0, 2));
    }

    public function testAFetchOfAnUnknownUidReturnsNothing(): void
    {
        $this->startStub();

        self::assertNull($this->client()->fetch(999));
    }

    public function testALiteralIsReadByItsAnnouncedLength(): void
    {
        $this->startStub();

        $raw = $this->client()->fetch(12);

        self::assertNotNull($raw);
        self::assertSame("From: Paul <paul@example.test>\r\nSubject: Deuxième\r\n\r\nCorps deux.\r\n", $raw);
    }

    // --- Échecs -----------------------------------------------------------------------

    public function testAServerRefusingTheGreetingIsReported(): void
    {
        $this->startStub('refuse_greeting');

        $result = $this->client()->verify();

        self::assertFalse($result['ok']);
        self::assertSame('mailbox.error.greeting', $result['detail']);
    }

    public function testRefusedCredentialsAreReported(): void
    {
        $this->startStub('refuse_login');

        $result = $this->client()->verify();

        self::assertFalse($result['ok']);
        self::assertSame('mailbox.error.command_failed', $result['detail']);
    }

    public function testAnUnreachableServerIsReportedWithoutLeakingItsAddress(): void
    {
        $client = new ImapClient(
            '127.0.0.1',
            $this->freePort(),
            'boite@example.test',
            'mot-de-passe',
            'INBOX',
            ImapClient::ENCRYPTION_NONE,
            2,
        );

        $result = $client->verify();

        self::assertFalse($result['ok']);
        self::assertSame('mailbox.error.connection_failed', $result['detail']);
        self::assertStringNotContainsString('127.0.0.1', $result['detail']);
    }

    public function testAnIncompleteConfigurationNeverOpensASocket(): void
    {
        $client = new ImapClient('', 993, '', '', 'INBOX', ImapClient::ENCRYPTION_NONE, 1);

        self::assertFalse($client->isConfigured());
        self::assertSame('mailbox.error.not_configured', $client->verify()['detail']);
    }

    public function testAWorkingSessionVerifiesSuccessfully(): void
    {
        $this->startStub();

        $result = $this->client()->verify();

        self::assertTrue($result['ok']);
        self::assertSame('mailbox.verify.ok', $result['detail']);
    }

    // --- Échappement --------------------------------------------------------------------

    /**
     * @return list<array{string, string}>
     */
    public static function quotable(): array
    {
        return [
            ['INBOX', '"INBOX"'],
            ['Boîte reçue', '"Boîte reçue"'],
            ['avec "guillemets"', '"avec \\"guillemets\\""'],
            ['anti\\slash', '"anti\\\\slash"'],
            ['', '""'],
        ];
    }

    #[DataProvider('quotable')]
    public function testStringsAreQuotedWithoutBreakingTheProtocol(string $value, string $expected): void
    {
        self::assertSame($expected, ImapClient::quote($value));
    }

    public function testAMailboxNameContainingAQuoteCannotInjectACommand(): void
    {
        $this->startStub();

        $client = new ImapClient(
            '127.0.0.1',
            $this->port,
            'boite@example.test',
            'mot-de-passe',
            'INBOX" \r\nX999 LOGOUT',
            ImapClient::ENCRYPTION_NONE,
            5,
        );

        // Le nom est échappé : le serveur reçoit une seule commande SELECT.
        $client->verify();
        $transcript = $this->transcript();

        self::assertStringNotContainsString("\nX999 LOGOUT", $transcript);
    }

    // --- Harnais ----------------------------------------------------------------------------

    private function client(): ImapClient
    {
        return new ImapClient(
            '127.0.0.1',
            $this->port,
            'boite@example.test',
            'mot-de-passe',
            'INBOX',
            ImapClient::ENCRYPTION_NONE,
            5,
        );
    }

    private function startStub(string $scenario = 'ok'): void
    {
        $this->transcriptPath = sys_get_temp_dir() . '/secondstay-imap-' . bin2hex(random_bytes(6)) . '.txt';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/bin/imap-stub.php', '0', $this->transcriptPath, $scenario],
            $descriptors,
            $pipes
        );

        self::assertIsResource($process);
        $this->stub = $process;

        $portFile = $this->transcriptPath . '.port';
        for ($attempt = 0; $attempt < 250; $attempt++) {
            clearstatcache(true, $portFile);
            if (is_file($portFile)) {
                $port = (int) trim((string) file_get_contents($portFile));
                if ($port > 0) {
                    $this->port = $port;

                    return;
                }
            }
            usleep(20_000);
        }

        self::fail('Le serveur IMAP factice n’a pas démarré.');
    }

    /**
     * Le bouchon publie sa transcription par `rename()`, donc en une fois.
     * L'attente porte malgré tout sur un contenu non vide et non sur la seule
     * existence du fichier : une attente qui accepte zéro octet ne prouve
     * rien, et c'est ce qui rendait le scénario intermittent.
     */
    private function transcript(): string
    {
        for ($attempt = 0; $attempt < 250; $attempt++) {
            clearstatcache(true, $this->transcriptPath);
            if (is_file($this->transcriptPath)) {
                $contents = (string) file_get_contents($this->transcriptPath);
                if ($contents !== '') {
                    return $contents;
                }
            }
            usleep(20_000);
        }

        self::fail('Aucune transcription produite.');
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
