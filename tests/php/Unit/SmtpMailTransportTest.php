<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailAttachment;
use SecondStay\Mail\MailMessage;
use SecondStay\Mail\SmtpMailTransport;

/**
 * Client SMTP : le protocole réellement parlé est vérifié face à un serveur
 * factice local (aucun réseau sortant, aucun identifiant réel).
 */
final class SmtpMailTransportTest extends TestCase
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

    private function startStub(string $scenario = 'ok'): void
    {
        $this->transcriptPath = sys_get_temp_dir() . '/secondstay-smtp-' . bin2hex(random_bytes(6)) . '.txt';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/bin/smtp-stub.php',
                '0',
                $this->transcriptPath,
                $scenario,
            ],
            $descriptors,
            $pipes
        );

        self::assertIsResource($process);
        $this->stub = $process;

        // Le stub publie son port dès que la socket écoute : aucune course.
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

        self::fail('Le serveur SMTP factice n’a pas démarré.');
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function transport(string $username = 'expediteur', string $password = 'mot-de-passe'): SmtpMailTransport
    {
        return new SmtpMailTransport(
            '127.0.0.1',
            $this->port,
            $username,
            $password,
            SmtpMailTransport::ENCRYPTION_NONE,
            new MailAddress('noreply@example.test', 'SecondStay'),
            'secondstay.test',
            5,
        );
    }

    private function message(string $html = '<p>Bonjour</p>'): MailMessage
    {
        return new MailMessage(
            new MailAddress('noreply@example.test', 'SecondStay'),
            new MailAddress('claire@example.test', 'Claire Dubois'),
            'Confirmation',
            $html,
        );
    }

    private function transcript(): string
    {
        // Le stub écrit la transcription à la fermeture de la session.
        for ($attempt = 0; $attempt < 250; $attempt++) {
            clearstatcache(true, $this->transcriptPath);
            if (is_file($this->transcriptPath) && filesize($this->transcriptPath) > 0) {
                return (string) file_get_contents($this->transcriptPath);
            }
            usleep(20_000);
        }

        return '';
    }

    public function testAnUnconfiguredTransportRefusesToSend(): void
    {
        $transport = new SmtpMailTransport('', 0, '', '');

        self::assertFalse($transport->isConfigured());
        self::assertSame(['ok' => false, 'detail' => 'mail.error.not_configured'], $transport->verify());

        $this->expectExceptionMessage('mail.error.not_configured');
        $transport->send($this->message());
    }

    public function testConnectionFailureIsReportedAsATranslationKey(): void
    {
        $transport = new SmtpMailTransport('127.0.0.1', $this->freePort(), '', '', SmtpMailTransport::ENCRYPTION_NONE);

        $result = $transport->verify();

        self::assertFalse($result['ok']);
        self::assertSame('mail.error.connection_failed', $result['detail']);
        // Aucun détail système (port, errno, nom d'hôte) ne fuit.
        self::assertStringNotContainsString('127.0.0.1', $result['detail']);
    }

    public function testVerifyOpensAndClosesASession(): void
    {
        $this->startStub();

        self::assertSame(['ok' => true, 'detail' => 'mail.verify.ok'], $this->transport()->verify());

        $transcript = $this->transcript();
        self::assertStringContainsString('EHLO secondstay.test', $transcript);
        self::assertStringContainsString('QUIT', $transcript);
    }

    public function testASentMessageFollowsTheFullSmtpConversation(): void
    {
        $this->startStub();

        $messageId = $this->transport()->send($this->message());

        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.test>$/', $messageId);

        $transcript = $this->transcript();
        self::assertStringContainsString('EHLO secondstay.test', $transcript);
        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0expediteur\0mot-de-passe"), $transcript);
        self::assertStringContainsString('MAIL FROM:<noreply@example.test>', $transcript);
        self::assertStringContainsString('RCPT TO:<claire@example.test>', $transcript);
        self::assertStringContainsString('DATA', $transcript);
        self::assertStringContainsString('Subject: Confirmation', $transcript);
        self::assertStringContainsString('Message-ID: ' . $messageId, $transcript);
        self::assertStringContainsString('QUIT', $transcript);
    }

    public function testAuthenticationIsSkippedWithoutAUsername(): void
    {
        $this->startStub();

        $this->transport('', '')->send($this->message());

        self::assertStringNotContainsString('AUTH', $this->transcript());
    }

    public function testAuthLoginIsUsedWhenAuthPlainIsRefused(): void
    {
        $this->startStub('plain-unsupported');

        $this->transport()->send($this->message());

        $transcript = $this->transcript();
        self::assertStringContainsString('AUTH LOGIN', $transcript);
        self::assertStringContainsString(base64_encode('expediteur'), $transcript);
        self::assertStringContainsString(base64_encode('mot-de-passe'), $transcript);
    }

    public function testRejectedCredentialsSurfaceAsATranslationKey(): void
    {
        $this->startStub('bad-credentials');

        $result = $this->transport()->verify();

        self::assertFalse($result['ok']);
        self::assertSame('mail.error.rejected', $result['detail']);
    }

    public function testARejectedRecipientRaisesATranslatableError(): void
    {
        $this->startStub('reject-recipient');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mail.error.rejected');

        $this->transport()->send($this->message());
    }

    public function testARejectedMessageRaisesATranslatableError(): void
    {
        $this->startStub('reject-data');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mail.error.rejected');

        $this->transport()->send($this->message());
    }

    public function testALineStartingWithADotIsStuffedAndNeverEndsTheMessageEarly(): void
    {
        $this->startStub();

        // La chaîne encodée en base64 ne peut pas contenir de « . » en début de
        // ligne : le risque vient d'une pièce jointe ou d'un en-tête. On force
        // donc un nom de pièce jointe long pour vérifier que le corps complet
        // traverse la session sans être tronqué.
        $message = $this->message()->withAttachment(
            new MailAttachment('facture.txt', ".\r\n.\r\nligne finale", 'text/plain')
        );

        $this->transport()->send($message);

        $transcript = $this->transcript();
        self::assertStringContainsString('Content-Type: multipart/mixed;', $transcript);
        self::assertStringContainsString(base64_encode(".\r\n.\r\nligne finale"), $transcript);
        // Le point de fin de données arrive après la pièce jointe, pas avant.
        self::assertStringContainsString('QUIT', $transcript);
    }
}
