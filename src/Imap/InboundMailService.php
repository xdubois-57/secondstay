<?php

declare(strict_types=1);

namespace SecondStay\Imap;

use SecondStay\Audit\AuditTrail;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingReference;
use SecondStay\Booking\BookingRepository;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;
use SecondStay\Support\HtmlSanitizer;

/**
 * Récupération du courrier entrant, rattachement au séjour et extraction des
 * pièces jointes (SPECIFICATIONS.md §36 à §38).
 *
 * Le service est écrit pour être rejoué : la synchronisation reprend au
 * dernier UID importé, et l'unicité (boîte, UID) empêche tout doublon même si
 * elle repart en arrière.
 */
final class InboundMailService
{
    /** Nombre de messages traités par passe : une synchronisation doit finir. */
    public const BATCH = 25;

    /**
     * Langue à retenir pour un message : celle du séjour rattaché, sinon
     * celle de l'installation.
     */
    private function localeFor(?Booking $booking): string
    {
        return $booking === null ? $this->settings->string('site.default_locale') : $booking->locale;
    }

    public function __construct(
        private readonly ImapProvider $provider,
        private readonly InboundMailRepository $mails,
        private readonly BookingRepository $bookings,
        private readonly BookingEventRepository $events,
        private readonly DocumentService $documents,
        private readonly MimeParser $parser,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ReplyToken $replyToken,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Importe les nouveaux messages.
     *
     * @return array{ok: bool, imported: int, linked: int, documents: int, error: string}
     */
    public function synchronise(int $limit = self::BATCH): array
    {
        if (!$this->provider->isConfigured()) {
            return [
                'ok' => false, 'imported' => 0, 'linked' => 0, 'documents' => 0,
                'error' => 'mailbox.error.not_configured',
            ];
        }

        $mailbox = $this->provider->mailbox();
        $since = $this->resumePoint($mailbox);

        try {
            $uids = $this->provider->listNewUids($since, $limit);
        } catch (\RuntimeException $exception) {
            $this->logger->error('imap', 'Synchronisation impossible', ['error' => $exception->getMessage()]);

            return [
                'ok' => false, 'imported' => 0, 'linked' => 0, 'documents' => 0,
                'error' => $exception->getMessage(),
            ];
        }

        $imported = 0;
        $linked = 0;
        $documents = 0;

        foreach ($uids as $uid) {
            $raw = $this->provider->fetch($uid);
            if ($raw === null || $raw === '') {
                continue;
            }

            $result = $this->ingest($raw, $uid, $mailbox);

            if ($result['duplicate']) {
                continue;
            }

            $imported++;
            $linked += $result['booking_id'] === null ? 0 : 1;
            $documents += $result['documents'];
        }

        return ['ok' => true, 'imported' => $imported, 'linked' => $linked, 'documents' => $documents, 'error' => ''];
    }

    /**
     * Enregistre un message brut, le rattache et en extrait les documents.
     *
     * @return array{id: int, duplicate: bool, booking_id: int|null, method: LinkMethod, documents: int}
     */
    public function ingest(string $raw, int $uid, string $mailbox): array
    {
        $message = $this->parser->parse($raw);
        [$booking, $method] = $this->resolveBooking($message);

        $stored = $this->mails->store([
            'to_address' => mb_substr($message->recipientAddresses()[0] ?? '', 0, 190),
            'from_address' => mb_substr($message->fromAddress, 0, 190),
            'from_name' => mb_substr($message->fromName, 0, 190),
            'subject' => mb_substr($message->subject, 0, 255),
            // Le HTML reçu est nettoyé avant d'être stocké : il sera affiché
            // dans l'administration, et un e-mail est un contenu hostile
            // (SPECIFICATIONS.md §37).
            'body_text' => $this->plainText($message),
            'body_html' => $message->html === '' ? null : $this->sanitizer->sanitize($message->html),
            'message_id' => mb_substr($message->messageId, 0, 190),
            'in_reply_to' => mb_substr($message->inReplyTo, 0, 190),
            'thread_id' => mb_substr($message->threadId(), 0, 190),
            'booking_id' => $booking?->id,
            'linked_by' => $method->value,
            'locale' => $this->localeFor($booking),
            'mailbox' => mb_substr($mailbox, 0, 64),
            'uid' => $uid,
            'received_at' => $message->date ?? gmdate('Y-m-d H:i:s'),
        ]);

        if ($stored['duplicate'] || $stored['id'] === 0) {
            return [
                'id' => $stored['id'],
                'duplicate' => true,
                'booking_id' => $booking?->id,
                'method' => $method,
                'documents' => 0,
            ];
        }

        $documents = $this->extractAttachments($message, $stored['id'], $booking);

        if ($booking !== null) {
            $this->events->record($booking->id, 'mail_received', [
                'from' => $message->fromAddress,
                'subject' => mb_substr($message->subject, 0, 190),
                'method' => $method->value,
                'documents' => $documents,
            ]);
        }

        $this->logger->info('imap', 'Message entrant importé', [
            'uid' => $uid,
            'booking' => $booking?->id,
            'method' => $method->value,
        ]);

        return [
            'id' => $stored['id'],
            'duplicate' => false,
            'booking_id' => $booking?->id,
            'method' => $method,
            'documents' => $documents,
        ];
    }

    /**
     * Rattache manuellement un message, et lui rattache ses documents.
     *
     * @return array{ok: bool, error: string}
     */
    public function linkManually(int $mailId, Booking $booking, ?int $actorId = null, string $actorLabel = ''): array
    {
        $mail = $this->mails->find($mailId);
        if ($mail === null) {
            return ['ok' => false, 'error' => 'mailbox.error.not_found'];
        }

        $this->mails->link($mailId, $booking->id, LinkMethod::Manual);

        foreach ($this->documentsOfMail($mailId) as $document) {
            $this->documents->attachToBooking($document, $booking->id);
        }

        $this->events->record($booking->id, 'mail_linked', [
            'mail' => $mailId,
            'subject' => mb_substr((string) ($mail['subject'] ?? ''), 0, 190),
        ], $actorId, $actorLabel);

        $this->audit?->record('mail.linked', 'mail', (string) $mailId, null, [
            'booking' => $booking->reference,
        ], $actorId, $actorLabel);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Adresse de réponse à annoncer pour un séjour.
     */
    public function replyAddressFor(Booking $booking): string
    {
        $mailbox = $this->settings->string('imap.reply_address');
        if ($mailbox === '') {
            $mailbox = $this->settings->string('mail.reply_to');
        }
        if ($mailbox === '') {
            $mailbox = $this->settings->string('mail.from_address');
        }

        return $mailbox === '' ? '' : $this->replyToken->address($mailbox, $booking->reference);
    }

    // --- Rattachement --------------------------------------------------------

    /**
     * Applique les règles de rattachement, de la plus sûre à la plus faible
     * (SPECIFICATIONS.md §36).
     *
     * @return array{Booking|null, LinkMethod}
     */
    public function resolveBooking(MimeMessage $message): array
    {
        // 1. Jeton signé porté par l'adresse de réponse.
        $reference = $this->replyToken->referenceFromAny($message->recipientAddresses());
        if ($reference !== null) {
            $booking = $this->bookings->findByReference($reference);
            if ($booking !== null) {
                return [$booking, LinkMethod::Token];
            }
        }

        // 2. En-têtes de fil : la réponse cite un message que nous avons envoyé.
        foreach (array_merge([$message->inReplyTo], $message->references) as $identifier) {
            $bookingId = $this->mails->bookingOfMessageId($identifier);
            if ($bookingId !== null) {
                $booking = $this->bookings->find($bookingId);
                if ($booking !== null) {
                    return [$booking, LinkMethod::Thread];
                }
            }
        }

        // 3. Référence citée dans le sujet ou le corps. Elle ne prouve rien :
        //    n'importe qui peut l'écrire, d'où son rang.
        $quoted = $this->referenceIn($message->subject . ' ' . $message->text);
        if ($quoted !== null) {
            $booking = $this->bookings->findByReference($quoted);
            if ($booking !== null) {
                return [$booking, LinkMethod::Reference];
            }
        }

        // 4. Adresse de l'expéditeur, si elle ne désigne qu'un seul séjour en
        //    cours. Ambiguë par nature : elle ne sert que de dernier recours.
        $booking = $this->soleBookingOf($message->fromAddress);
        if ($booking !== null) {
            return [$booking, LinkMethod::Sender];
        }

        return [null, LinkMethod::None];
    }

    /**
     * Référence de séjour citée dans un texte.
     */
    public function referenceIn(string $text): ?string
    {
        if (preg_match_all(
            '/\b([' . BookingReference::ALPHABET . ']{4})[- ]?([' . BookingReference::ALPHABET . ']{4})\b/',
            strtoupper($text),
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return null;
        }

        foreach ($matches as $match) {
            $candidate = $match[1] . '-' . $match[2];
            if (BookingReference::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // --- Pièces jointes -------------------------------------------------------

    private function extractAttachments(MimeMessage $message, int $mailId, ?Booking $booking): int
    {
        $stored = 0;

        foreach ($message->attachments as $attachment) {
            // Une image intégrée au corps HTML n'est pas une pièce jointe :
            // la verser dans les documents du séjour n'aurait aucun sens.
            if ($attachment['content_id'] !== '' && $attachment['contents'] !== '') {
                continue;
            }

            $result = $this->documents->store(
                $attachment['contents'],
                $attachment['filename'],
                $this->classify($attachment['filename'], $message->subject),
                DocumentSource::Mail,
                $booking?->id,
                $mailId,
                null,
                $message->fromAddress,
                $this->localeFor($booking),
            );

            if ($result['ok'] === false) {
                $this->logger->warning('imap', 'Pièce jointe écartée', [
                    'mail' => $mailId,
                    'filename' => $attachment['filename'],
                    'error' => $result['error'],
                ]);

                continue;
            }

            $stored++;
        }

        return $stored;
    }

    /**
     * Classement proposé pour une pièce jointe (SPECIFICATIONS.md §38).
     *
     * Ce n'est qu'une proposition : l'administration peut la corriger, et
     * aucune décision n'en dépend.
     */
    public function classify(string $filename, string $subject): DocumentKind
    {
        $haystack = mb_strtolower($filename . ' ' . $subject);

        $needles = [
            'contrat', 'contract', 'overeenkomst', 'vertrag',
            'signe', 'signed', 'getekend', 'unterschrieb',
        ];

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return DocumentKind::SignedContract;
            }
        }

        $needles = [
            'facture', 'invoice', 'factuur', 'rechnung', 'recu',
            'reçu', 'receipt', 'kwitantie', 'quittung',
        ];

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return DocumentKind::Receipt;
            }
        }

        foreach (['assurance', 'insurance', 'verzekering', 'versicherung', 'attestation', 'justificatif'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return DocumentKind::Proof;
            }
        }

        return DocumentKind::Attachment;
    }

    // --- Outils -----------------------------------------------------------------

    /**
     * Point de reprise, en tenant compte d'une éventuelle renumérotation.
     *
     * Si le serveur a changé d'`UIDVALIDITY`, les UID mémorisés ne désignent
     * plus rien : repartir de zéro est la seule lecture correcte.
     */
    private function resumePoint(string $mailbox): int
    {
        $known = $this->settings->int('imap.uid_validity');
        $current = $this->provider->uidValidity();

        if ($current !== 0 && $known !== $current) {
            $this->settings->set('imap.uid_validity', (string) $current);
            $this->logger->warning('imap', 'Boîte renumérotée : synchronisation reprise depuis le début', [
                'mailbox' => $mailbox,
            ]);

            return 0;
        }

        return $this->mails->lastUid($mailbox);
    }

    private function plainText(MimeMessage $message): ?string
    {
        if ($message->text !== '') {
            return mb_substr($message->text, 0, 60000);
        }

        if ($message->html === '') {
            return null;
        }

        return mb_substr($this->sanitizer->toText($message->html), 0, 60000);
    }

    /**
     * Séjour unique associé à une adresse, ou `null` si le lien est ambigu.
     */
    private function soleBookingOf(string $email): ?Booking
    {
        if ($email === '') {
            return null;
        }

        $candidates = $this->bookings->activeForEmail($email);

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @return list<int>
     */
    private function documentsOfMail(int $mailId): array
    {
        return $this->documents->documentIdsForMail($mailId);
    }
}
