<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailAttachment;
use SecondStay\Mail\MailMessage;

/**
 * Construction MIME des e-mails sortants.
 *
 * Les tests portent en priorité sur ce qui est exploitable par un attaquant :
 * injection d'en-têtes, encodage et confinement des pièces jointes.
 */
final class MailMessageTest extends TestCase
{
    private function message(string $subject = 'Confirmation', string $html = '<p>Bonjour</p>'): MailMessage
    {
        return new MailMessage(
            new MailAddress('noreply@example.test', 'SecondStay'),
            new MailAddress('claire@example.test', 'Claire Dubois'),
            $subject,
            $html,
            '',
            'fr',
            'account_confirmation',
            new MailAddress('contact@example.test'),
        );
    }

    public function testAddressRefusesAnInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailAddress('pas-une-adresse');
    }

    public function testAddressHeaderNeverCarriesALineBreak(): void
    {
        $address = new MailAddress('claire@example.test', "Claire\r\nBcc: victime@example.test");

        self::assertStringNotContainsString("\r", $address->toHeader());
        self::assertStringNotContainsString("\n", $address->toHeader());
        self::assertStringContainsString('claire@example.test', $address->toHeader());
    }

    public function testAddressHeaderEncodesNonAsciiNames(): void
    {
        self::assertStringStartsWith('=?UTF-8?B?', (new MailAddress('a@example.test', 'Café Marée'))->toHeader());
        self::assertSame('"Claire" <a@example.test>', (new MailAddress('a@example.test', 'Claire'))->toHeader());
        self::assertSame('a@example.test', (new MailAddress('a@example.test', '   '))->toHeader());
    }

    public function testBuildProducesAMultipartAlternativeMessage(): void
    {
        $built = $this->message()->build('example.test', 'Tue, 19 Aug 2026 08:00:00 +0000');

        self::assertStringContainsString('From: "SecondStay" <noreply@example.test>', $built['headers']);
        self::assertStringContainsString('To: "Claire Dubois" <claire@example.test>', $built['headers']);
        self::assertStringContainsString('Reply-To: contact@example.test', $built['headers']);
        self::assertStringContainsString('Date: Tue, 19 Aug 2026 08:00:00 +0000', $built['headers']);
        self::assertStringContainsString('Content-Language: fr', $built['headers']);
        self::assertStringContainsString('Auto-Submitted: auto-generated', $built['headers']);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $built['headers']);

        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $built['body']);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $built['body']);
        self::assertStringContainsString(base64_encode('<p>Bonjour</p>'), $built['body']);
    }

    public function testMessageIdentifierIsUniqueAndBoundToTheDomain(): void
    {
        $first = $this->message()->build('example.test')['message_id'];
        $second = $this->message()->build('example.test')['message_id'];

        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.test>$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testSubjectIsEncodedOnlyWhenNecessaryAndNeverInjectsHeaders(): void
    {
        self::assertStringContainsString(
            'Subject: Confirmation',
            $this->message('Confirmation')->build('example.test')['headers']
        );

        $accented = $this->message('Réservation confirmée')->build('example.test')['headers'];
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Réservation confirmée') . '?=', $accented);

        // Le sujet ne peut pas ouvrir une nouvelle ligne d'en-tête.
        $injected = $this->message("Bonjour\r\nBcc: victime@example.test")->build('example.test')['headers'];
        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $injected);
        self::assertSame(1, preg_match_all('/^Subject:/mi', $injected));
    }

    public function testCustomHeadersAreStrippedOfControlCharactersAndCannotOverrideMessageId(): void
    {
        $message = $this->message()
            ->withHeader('X-SecondStay-Booking', "42\r\nBcc: victime@example.test")
            ->withHeader('Message-ID', '<forged@evil.test>');

        $built = $message->build('example.test');

        self::assertStringContainsString('X-SecondStay-Booking: 42Bcc: victime@example.test', $built['headers']);
        self::assertSame(1, substr_count($built['headers'], 'Message-ID:'));
        // Le Message-ID explicite est conservé mais n'est jamais dupliqué.
        self::assertSame('<forged@evil.test>', $built['message_id']);
    }

    public function testPlainTextFallsBackToAReadableVersionOfTheHtml(): void
    {
        $message = $this->message('Sujet', '<p>Bonjour Claire</p><p><a href="https://example.test">Confirmer</a></p>');

        $text = $message->plainText();

        self::assertStringContainsString('Bonjour Claire', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    public function testExplicitPlainTextIsPreserved(): void
    {
        $message = new MailMessage(
            new MailAddress('noreply@example.test'),
            new MailAddress('claire@example.test'),
            'Sujet',
            '<p>HTML</p>',
            'Version texte',
        );

        self::assertSame('Version texte', $message->plainText());
    }

    public function testAttachmentsSwitchTheMessageToMultipartMixed(): void
    {
        $message = $this->message()->withAttachment(
            new MailAttachment('contrat de location.pdf', '%PDF-1.7 binaire', 'application/pdf')
        );

        $built = $message->build('example.test');

        self::assertStringContainsString('Content-Type: multipart/mixed;', $built['headers']);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $built['body']);
        self::assertStringContainsString('Content-Disposition: attachment; filename="contrat_de_location.pdf"', $built['body']);
        self::assertStringContainsString(base64_encode('%PDF-1.7 binaire'), $built['body']);
        self::assertCount(1, $message->attachments());
    }

    public function testAttachmentFilenameCannotEscapeItsHeader(): void
    {
        $attachment = new MailAttachment('../../etc/passwd"; x="y', 'contenu');

        self::assertSame('.._.._etc_passwd_x_y', $attachment->safeFilename());
        self::assertStringNotContainsString('"', $attachment->safeFilename());
        self::assertSame('document', (new MailAttachment('///', 'x'))->safeFilename());
        self::assertSame('document', (new MailAttachment('../..', 'x'))->safeFilename());
        self::assertSame(120, mb_strlen((new MailAttachment(str_repeat('a', 400) . '.pdf', 'x'))->safeFilename()));
    }
}
