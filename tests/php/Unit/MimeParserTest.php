<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Imap\MimeParser;

/**
 * Analyse des messages entrants.
 *
 * Un message reçu vient d'Internet : chaque cas vérifie soit qu'un message
 * courant est correctement compris, soit qu'un message hostile ou malformé ne
 * fait pas plus de dégâts qu'une perte de contenu.
 */
final class MimeParserTest extends TestCase
{
    private function parser(): MimeParser
    {
        return new MimeParser();
    }

    public function testAPlainTextReplyIsUnderstood(): void
    {
        $message = $this->parser()->parse(
            "From: Claire Dubois <claire@example.test>\r\n"
            . "To: logement@example.test\r\n"
            . "Subject: Re: Votre reservation\r\n"
            . "Message-ID: <abc123@mail.example.test>\r\n"
            . "In-Reply-To: <origine@secondstay.test>\r\n"
            . "Date: Sat, 04 Jul 2026 10:15:00 +0200\r\n"
            . "\r\n"
            . "Bonjour,\r\nVoici ma reponse.\r\n"
        );

        self::assertSame('claire@example.test', $message->fromAddress);
        self::assertSame('Claire Dubois', $message->fromName);
        self::assertSame('Re: Votre reservation', $message->subject);
        self::assertSame('abc123@mail.example.test', $message->messageId);
        self::assertSame('origine@secondstay.test', $message->inReplyTo);
        self::assertSame('2026-07-04 08:15:00', $message->date);
        self::assertStringContainsString('Voici ma reponse.', $message->text);
        self::assertFalse($message->hasAttachments());
    }

    public function testEncodedWordsAreDecoded(): void
    {
        $subject = '=?UTF-8?B?' . base64_encode('Réservation confirmée — été') . '?=';
        $from = '=?UTF-8?Q?Claire_Dubois?= <claire@example.test>';

        $message = $this->parser()->parse(
            "From: {$from}\r\nSubject: {$subject}\r\n\r\nCorps\r\n"
        );

        self::assertSame('Réservation confirmée — été', $message->subject);
        self::assertSame('Claire Dubois', $message->fromName);
    }

    public function testAdjacentEncodedWordsAreJoinedWithoutASpuriousSpace(): void
    {
        $subject = '=?UTF-8?B?' . base64_encode('Pré') . '?= =?UTF-8?B?' . base64_encode('nom') . '?=';

        $message = $this->parser()->parse("Subject: {$subject}\r\n\r\nx\r\n");

        self::assertSame('Prénom', $message->subject);
    }

    public function testALatin1BodyIsConvertedToUtf8(): void
    {
        $body = (string) mb_convert_encoding('Séjour à Chamonix', 'ISO-8859-1', 'UTF-8');

        $message = $this->parser()->parse(
            "Content-Type: text/plain; charset=ISO-8859-1\r\n\r\n{$body}\r\n"
        );

        self::assertSame('Séjour à Chamonix', $message->text);
    }

    public function testAnUnknownCharsetDoesNotLoseTheMessage(): void
    {
        $message = $this->parser()->parse(
            "Content-Type: text/plain; charset=x-inconnu-42\r\n\r\nTexte simple\r\n"
        );

        self::assertStringContainsString('Texte simple', $message->text);
    }

    public function testQuotedPrintableAndBase64BodiesAreDecoded(): void
    {
        $quoted = $this->parser()->parse(
            "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "S=C3=A9jour confirm=C3=A9\r\n"
        );
        self::assertSame('Séjour confirmé', $quoted->text);

        $base64 = $this->parser()->parse(
            "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('Séjour confirmé')) . "\r\n"
        );
        self::assertSame('Séjour confirmé', $base64->text);
    }

    public function testAMultipartAlternativeKeepsBothRepresentations(): void
    {
        $message = $this->parser()->parse($this->multipartAlternative());

        self::assertStringContainsString('Version texte', $message->text);
        self::assertStringContainsString('<p>Version <b>HTML</b></p>', $message->html);
    }

    public function testAttachmentsAreExtractedWithTheirNameAndType(): void
    {
        $message = $this->parser()->parse($this->withAttachment('contrat-signe.pdf', '%PDF-1.4 signé'));

        self::assertTrue($message->hasAttachments());
        self::assertCount(1, $message->attachments);
        self::assertSame('contrat-signe.pdf', $message->attachments[0]['filename']);
        self::assertSame('application/pdf', $message->attachments[0]['mime']);
        self::assertSame('%PDF-1.4 signé', $message->attachments[0]['contents']);
        self::assertStringContainsString('Voici le contrat', $message->text);
    }

    public function testAnAttachmentNameEncodedAsRfc2047IsDecoded(): void
    {
        $encoded = '=?UTF-8?B?' . base64_encode('contrat signé.pdf') . '?=';
        $message = $this->parser()->parse($this->withAttachment($encoded, '%PDF-1.4'));

        self::assertSame('contrat signé.pdf', $message->attachments[0]['filename']);
    }

    public function testAPartWithoutDispositionButWithANameIsTreatedAsAnAttachment(): void
    {
        $raw = "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"limite\"\r\n\r\n"
            . "--limite\r\nContent-Type: text/plain\r\n\r\nTexte\r\n"
            . "--limite\r\nContent-Type: application/pdf; name=\"joint.pdf\"\r\n\r\n%PDF\r\n"
            . "--limite--\r\n";

        $message = $this->parser()->parse($raw);

        self::assertCount(1, $message->attachments);
        self::assertSame('joint.pdf', $message->attachments[0]['filename']);
    }

    public function testNestedMultipartsAreTraversed(): void
    {
        $raw = "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"externe\"\r\n\r\n"
            . "--externe\r\n"
            . "Content-Type: multipart/alternative; boundary=\"interne\"\r\n\r\n"
            . "--interne\r\nContent-Type: text/plain\r\n\r\nTexte imbrique\r\n"
            . "--interne\r\nContent-Type: text/html\r\n\r\n<p>HTML imbrique</p>\r\n"
            . "--interne--\r\n"
            . "--externe\r\n"
            . "Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\n\r\n%PDF\r\n"
            . "--externe--\r\n";

        $message = $this->parser()->parse($raw);

        self::assertStringContainsString('Texte imbrique', $message->text);
        self::assertStringContainsString('HTML imbrique', $message->html);
        self::assertCount(1, $message->attachments);
    }

    /**
     * Un message imbriqué sans fin ne doit pas épuiser la mémoire ni le temps.
     */
    public function testDeeplyNestedMultipartsAreBounded(): void
    {
        $raw = "Content-Type: multipart/mixed; boundary=\"b0\"\r\n\r\n";
        for ($depth = 0; $depth < 40; $depth++) {
            $raw .= "--b{$depth}\r\nContent-Type: multipart/mixed; boundary=\"b" . ($depth + 1) . "\"\r\n\r\n";
        }
        $raw .= "--b40\r\nContent-Type: text/plain\r\n\r\nTrop profond\r\n--b40--\r\n";

        $start = hrtime(true);
        $message = $this->parser()->parse($raw);
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;

        self::assertLessThan(2.0, $elapsed, 'L’analyse doit rester bornée.');
        self::assertStringNotContainsString('Trop profond', $message->text);
    }

    public function testAMultipartWithoutBoundaryIsIgnoredRatherThanFatal(): void
    {
        $message = $this->parser()->parse("Content-Type: multipart/mixed\r\n\r\nn'importe quoi\r\n");

        self::assertSame('', $message->text);
        self::assertSame([], $message->attachments);
    }

    public function testHeadersFoldedOverSeveralLinesAreJoined(): void
    {
        $message = $this->parser()->parse(
            "Subject: Une ligne\r\n tres longue repliee\r\n\ttrois fois\r\n\r\nCorps\r\n"
        );

        self::assertSame('Une ligne tres longue repliee trois fois', $message->subject);
    }

    public function testReferencesGiveTheThreadIdentifier(): void
    {
        $message = $this->parser()->parse(
            "References: <racine@secondstay.test> <milieu@example.test>\r\n"
            . "In-Reply-To: <milieu@example.test>\r\n\r\nx\r\n"
        );

        self::assertSame(['racine@secondstay.test', 'milieu@example.test'], $message->references);
        self::assertSame('racine@secondstay.test', $message->threadId());
    }

    public function testAllRecipientFieldsAreCollected(): void
    {
        $message = $this->parser()->parse(
            "To: logement+SS-2026-0001-ab12@example.test, autre@example.test\r\n"
            . "Cc: Copie <copie@example.test>\r\n"
            . "Delivered-To: boite@example.test\r\n\r\nx\r\n"
        );

        self::assertSame(
            [
                'logement+ss-2026-0001-ab12@example.test',
                'autre@example.test',
                'copie@example.test',
                'boite@example.test',
            ],
            $message->recipientAddresses()
        );
    }

    /**
     * @return list<array{string, string}>
     */
    public static function malformed(): array
    {
        return [
            ['', ''],
            ["Subject: Sans corps\r\n", ''],
            ["Pas d'en-tete du tout", ''],
            ["Subject:\r\n\r\nCorps seul\r\n", 'Corps seul'],
            [": valeur sans nom\r\n\r\nCorps\r\n", 'Corps'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedMessagesDoNotThrow(string $raw, string $expectedText): void
    {
        $message = $this->parser()->parse($raw);

        self::assertSame($expectedText, $message->text);
    }

    public function testTheDateOfAnUnparsableHeaderIsNull(): void
    {
        self::assertNull($this->parser()->parse("Date: pas une date\r\n\r\nx\r\n")->date);
        self::assertNull($this->parser()->parse("\r\nx\r\n")->date);
    }

    private function multipartAlternative(): string
    {
        return "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"limite\"\r\n\r\n"
            . "--limite\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nVersion texte\r\n"
            . "--limite\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Version <b>HTML</b></p>\r\n"
            . "--limite--\r\n";
    }

    private function withAttachment(string $filename, string $contents): string
    {
        return "From: Claire <claire@example.test>\r\n"
            . "Subject: Contrat\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"limite\"\r\n\r\n"
            . "--limite\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nVoici le contrat signé.\r\n"
            . "--limite\r\n"
            . "Content-Type: application/pdf; name=\"{$filename}\"\r\n"
            . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($contents))
            . "--limite--\r\n";
    }
}
