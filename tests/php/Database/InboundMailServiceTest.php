<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\Imap\FakeImapProvider;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Imap\InboundMailService;
use SecondStay\Imap\LinkMethod;
use SecondStay\Imap\MimeParser;
use SecondStay\Imap\ReplyToken;
use SecondStay\Logging\Logger;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Support\HtmlSanitizer;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Courrier entrant : rattachement au séjour et pièces jointes → documents.
 *
 * L'ordre des règles de rattachement est le cœur du sujet : un jeton signé
 * fait autorité, une référence citée dans un corps de message ne prouve rien.
 */
final class InboundMailServiceTest extends DatabaseTestCase
{
    private InboundMailService $inbound;

    private InboundMailRepository $mails;

    private DocumentRepository $documents;

    private BookingRepository $bookings;

    private FakeImapProvider $provider;

    private ReplyToken $replyToken;

    private SettingsService $settings;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'site.default_locale' => 'fr',
            'imap.reply_address' => 'logement@example.test',
        ]);

        $this->mails = new InboundMailRepository($this->database);
        $this->documents = new DocumentRepository($this->database);
        $this->bookings = new BookingRepository($this->database);
        $this->provider = new FakeImapProvider($this->storagePath . '/imap');
        $this->replyToken = new ReplyToken('secret-de-test');

        $logger = new Logger($this->storagePath . '/logs');

        $this->inbound = new InboundMailService(
            $this->provider,
            $this->mails,
            $this->bookings,
            new BookingEventRepository($this->database),
            new DocumentService($this->documents, $this->paths, $logger),
            new MimeParser(),
            new HtmlSanitizer(),
            $this->replyToken,
            $this->settings,
            $logger,
            new AuditTrail($this->database),
        );

        $this->booking = $this->createBooking('WXYZ-3456', 'claire@example.test');
    }

    // --- Rattachement ------------------------------------------------------------

    public function testASignedReplyAddressAttachesTheMessage(): void
    {
        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);
        self::assertStringContainsString('+', $to);

        $result = $this->inbound->ingest($this->message(['To' => $to]), 1, 'INBOX');

        self::assertSame($this->booking->id, $result['booking_id']);
        self::assertSame(LinkMethod::Token, $result['method']);
    }

    public function testAForgedReplyAddressIsRefused(): void
    {
        // La référence est juste, la signature ne l'est pas.
        $forged = 'logement+' . $this->booking->reference . '.0000000000000000@example.test';

        $result = $this->inbound->ingest(
            $this->message(['To' => $forged, 'From' => 'inconnu@example.test']),
            1,
            'INBOX'
        );

        self::assertNull($result['booking_id'], 'Une signature invalide ne doit rien rattacher.');
        self::assertSame(LinkMethod::None, $result['method']);
    }

    public function testAReplyQuotingOurMessageIdIsAttachedByThread(): void
    {
        $this->database->insert('mail_message', [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'direction' => 'outbound',
            'status' => 'sent',
            'to_address' => 'claire@example.test',
            'subject' => 'Votre séjour',
            'message_id' => 'sortant-1@secondstay.test',
            'booking_id' => $this->booking->id,
        ]);

        $result = $this->inbound->ingest(
            $this->message([
                'To' => 'logement@example.test',
                'From' => 'quelqun.dautre@example.test',
                'In-Reply-To' => '<sortant-1@secondstay.test>',
            ]),
            1,
            'INBOX'
        );

        self::assertSame($this->booking->id, $result['booking_id']);
        self::assertSame(LinkMethod::Thread, $result['method']);
    }

    public function testAQuotedReferenceAttachesTheMessageWithTheWeakestConfidence(): void
    {
        $result = $this->inbound->ingest(
            $this->message([
                'To' => 'logement@example.test',
                'From' => 'inconnu@example.test',
                'Subject' => 'Question sur ' . $this->booking->reference,
            ]),
            1,
            'INBOX'
        );

        self::assertSame($this->booking->id, $result['booking_id']);
        self::assertSame(LinkMethod::Reference, $result['method']);
        self::assertFalse($result['method']->isTrusted(), 'Une référence citée ne prouve rien.');
    }

    public function testTheSenderAddressAttachesOnlyWhenItIsUnambiguous(): void
    {
        $result = $this->inbound->ingest(
            $this->message(['To' => 'logement@example.test', 'From' => 'claire@example.test']),
            1,
            'INBOX'
        );

        self::assertSame($this->booking->id, $result['booking_id']);
        self::assertSame(LinkMethod::Sender, $result['method']);
    }

    public function testASenderWithTwoStaysIsNotAttachedAutomatically(): void
    {
        $this->createBooking('QRST-4567', 'claire@example.test');

        $result = $this->inbound->ingest(
            $this->message(['To' => 'logement@example.test', 'From' => 'claire@example.test']),
            1,
            'INBOX'
        );

        self::assertNull($result['booking_id'], 'Deux séjours pour une adresse : aucune conclusion possible.');
    }

    public function testACancelledStayDoesNotCaptureTheSenderRule(): void
    {
        $this->bookings->update($this->booking->id, ['status' => BookingStatus::Cancelled->value]);

        $result = $this->inbound->ingest(
            $this->message(['To' => 'logement@example.test', 'From' => 'claire@example.test']),
            1,
            'INBOX'
        );

        self::assertNull($result['booking_id']);
    }

    public function testTheSignedTokenWinsOverAQuotedReference(): void
    {
        $other = $this->createBooking('MNPQ-5678', 'paul@example.test');
        $to = $this->replyToken->address('logement@example.test', $other->reference);

        $result = $this->inbound->ingest(
            $this->message([
                'To' => $to,
                'From' => 'claire@example.test',
                'Subject' => 'Au sujet de ' . $this->booking->reference,
            ]),
            1,
            'INBOX'
        );

        self::assertSame($other->id, $result['booking_id']);
        self::assertSame(LinkMethod::Token, $result['method']);
    }

    // --- Pièces jointes ------------------------------------------------------------

    public function testAnAttachmentBecomesADocumentOfTheStay(): void
    {
        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);

        $result = $this->inbound->ingest(
            $this->withAttachment($to, 'contrat-signe.pdf', $this->pdf()),
            1,
            'INBOX'
        );

        self::assertSame(1, $result['documents']);

        $documents = $this->documents->forBooking($this->booking->id);
        self::assertCount(1, $documents);
        self::assertSame('contrat-signe.pdf', $documents[0]->filename);
        self::assertSame(DocumentKind::SignedContract, $documents[0]->kind);
        self::assertSame('claire@example.test', $documents[0]->sender);
        self::assertSame($result['id'], $documents[0]->mailId);
    }

    public function testAnUnsupportedAttachmentIsDiscardedWithoutLosingTheMessage(): void
    {
        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);

        $result = $this->inbound->ingest(
            $this->withAttachment($to, 'malveillant.pdf', '<?php echo "compromis";'),
            1,
            'INBOX'
        );

        self::assertSame(0, $result['documents']);
        self::assertSame($this->booking->id, $result['booking_id']);
        self::assertSame([], $this->documents->forBooking($this->booking->id));
    }

    public function testAnInlineImageIsNotFiledAsADocument(): void
    {
        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);

        $raw = "From: Claire <claire@example.test>\r\nTo: {$to}\r\nSubject: Avec logo\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"limite\"\r\n\r\n"
            . "--limite\r\nContent-Type: text/html\r\n\r\n<p>Bonjour <img src=\"cid:logo\"></p>\r\n"
            . "--limite\r\nContent-Type: image/png\r\nContent-ID: <logo>\r\n"
            . "Content-Disposition: inline; filename=\"logo.png\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->png()))
            . "--limite--\r\n";

        $result = $this->inbound->ingest($raw, 1, 'INBOX');

        self::assertSame(0, $result['documents']);
    }

    // --- Rejeu et reprise ------------------------------------------------------------

    public function testTheSameUidIsNeverImportedTwice(): void
    {
        $raw = $this->message(['To' => 'logement@example.test']);

        $first = $this->inbound->ingest($raw, 7, 'INBOX');
        $second = $this->inbound->ingest($raw, 7, 'INBOX');

        self::assertFalse($first['duplicate']);
        self::assertTrue($second['duplicate']);
        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $this->mails->recentInbound(10));
    }

    public function testSynchronisationResumesAtTheLastImportedUid(): void
    {
        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);

        $this->provider->deliver($this->message(['To' => $to, 'Subject' => 'Premier']));
        $this->provider->deliver($this->message(['To' => $to, 'Subject' => 'Deuxième']));

        $first = $this->inbound->synchronise();
        self::assertTrue($first['ok']);
        self::assertSame(2, $first['imported']);
        self::assertSame(2, $first['linked']);

        // Une seconde passe sans nouveau message ne réimporte rien.
        $second = $this->inbound->synchronise();
        self::assertSame(0, $second['imported']);

        $this->provider->deliver($this->message(['To' => $to, 'Subject' => 'Troisième']));

        $third = $this->inbound->synchronise();
        self::assertSame(1, $third['imported']);
        self::assertCount(3, $this->mails->recentInbound(10));
    }

    public function testTheHtmlBodyIsSanitisedBeforeBeingStored(): void
    {
        $raw = "From: Claire <claire@example.test>\r\nTo: logement@example.test\r\n"
            . "Subject: Bonjour\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . "<p onclick=\"alert(1)\">Bonjour<script>alert('xss')</script>"
            . "<a href=\"javascript:alert(1)\">lien</a></p>\r\n";

        $result = $this->inbound->ingest($raw, 1, 'INBOX');

        $mail = $this->mails->find($result['id']);
        self::assertNotNull($mail);

        $html = (string) $mail['body_html'];
        self::assertStringContainsString('Bonjour', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testAMessageWithoutTextGetsAReadableFallback(): void
    {
        $raw = "From: Claire <claire@example.test>\r\nTo: logement@example.test\r\n"
            . "Subject: HTML seul\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . "<p>Bonjour, <b>merci</b> pour tout.</p>\r\n";

        $result = $this->inbound->ingest($raw, 1, 'INBOX');
        $mail = $this->mails->find($result['id']);

        self::assertNotNull($mail);
        self::assertStringContainsString('Bonjour', (string) $mail['body_text']);
        self::assertStringNotContainsString('<b>', (string) $mail['body_text']);
    }

    // --- Rattachement manuel ------------------------------------------------------------

    public function testManualLinkingAlsoMovesTheAttachments(): void
    {
        $result = $this->inbound->ingest(
            $this->withAttachment('logement@example.test', 'justificatif.pdf', $this->pdf(), 'inconnu@example.test'),
            1,
            'INBOX'
        );

        self::assertNull($result['booking_id']);
        self::assertSame(1, $result['documents']);

        $orphan = $this->documents->forMail($result['id']);
        self::assertCount(1, $orphan);
        self::assertNull($orphan[0]->bookingId);

        $linked = $this->inbound->linkManually($result['id'], $this->booking);
        self::assertTrue($linked['ok'], $linked['error']);

        $mail = $this->mails->find($result['id']);
        self::assertNotNull($mail);
        self::assertSame($this->booking->id, (int) $mail['booking_id']);
        self::assertSame(LinkMethod::Manual->value, (string) $mail['linked_by']);

        $documents = $this->documents->forBooking($this->booking->id);
        self::assertCount(1, $documents);
        self::assertSame('justificatif.pdf', $documents[0]->filename);
    }

    public function testUnlinkedMessagesAreListedForReview(): void
    {
        $this->inbound->ingest($this->message(['To' => 'logement@example.test', 'From' => 'inconnu@example.test']), 1, 'INBOX');

        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);
        $this->inbound->ingest($this->message(['To' => $to, 'From' => 'claire@example.test']), 2, 'INBOX');

        $unlinked = $this->mails->unlinked();

        self::assertCount(1, $unlinked);
        self::assertSame('inconnu@example.test', (string) $unlinked[0]['from_address']);
    }

    public function testTheCommunicationTimelineIsASingleOrderedList(): void
    {
        $this->database->insert('mail_message', [
            'created_at' => '2026-01-01 09:00:00',
            'direction' => 'outbound',
            'status' => 'sent',
            'to_address' => 'claire@example.test',
            'subject' => 'Confirmation',
            'booking_id' => $this->booking->id,
        ]);

        $to = $this->replyToken->address('logement@example.test', $this->booking->reference);
        $this->inbound->ingest($this->message(['To' => $to, 'Subject' => 'Merci']), 1, 'INBOX');

        $timeline = $this->mails->forBooking($this->booking->id);

        self::assertCount(2, $timeline);
        self::assertSame('outbound', (string) $timeline[0]['direction']);
        self::assertSame('inbound', (string) $timeline[1]['direction']);
    }

    // --- Outils ---------------------------------------------------------------------------

    /**
     * @param array<string, string> $headers
     */
    private function message(array $headers = []): string
    {
        $headers += [
            'From' => 'Claire Dubois <claire@example.test>',
            'To' => 'logement@example.test',
            'Subject' => 'Bonjour',
            'Date' => 'Sat, 04 Jul 2026 10:15:00 +0200',
        ];

        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }

        return $raw . "\r\nBonjour, voici ma réponse.\r\n";
    }

    private function withAttachment(
        string $to,
        string $filename,
        string $contents,
        string $from = 'Claire Dubois <claire@example.test>',
    ): string {
        return "From: {$from}\r\nTo: {$to}\r\nSubject: Contrat\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"limite\"\r\n\r\n"
            . "--limite\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nVoici le document.\r\n"
            . "--limite\r\nContent-Type: application/pdf; name=\"{$filename}\"\r\n"
            . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($contents))
            . "--limite--\r\n";
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n";
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
    }

    private function createBooking(string $reference, string $email): Booking
    {
        $id = $this->database->insert('booking', [
            'reference' => $reference,
            'status' => BookingStatus::ToConfirm->value,
            'arrival' => '2026-07-04',
            'departure' => '2026-07-11',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => $email,
            'guest_name' => 'Claire Dubois',
            'total_cents' => 78000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $booking = $this->bookings->find($id);
        self::assertNotNull($booking);

        return $booking;
    }
}
