<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\SubStatus;
use SecondStay\Contract\ContractBuilder;
use SecondStay\Contract\ContractRepository;
use SecondStay\Contract\ContractService;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Pricing\DateRange;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Tests\Support\PdfReader;

/**
 * Contrat : contenu, instantané et acceptation.
 *
 * Ce qui compte ici n'est pas que le PDF existe, mais qu'il soit **figé** :
 * un séjour doit conserver le texte, la version et la langue que le client a
 * réellement acceptés.
 */
final class ContractServiceTest extends DatabaseTestCase
{
    private ContractService $contracts;

    private ContractRepository $acceptances;

    private DocumentRepository $documents;

    private DocumentService $documentService;

    private BookingRepository $bookings;

    private SettingsService $settings;

    private UserRepository $users;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'property.address_line1' => '12 route des Mélèzes',
            'property.postal_code' => '74400',
            'property.city' => 'Chamonix',
            'property.siret' => '12345678900012',
            'booking.max_guests' => '6',
            'booking.checkin_time' => '16:00',
            'booking.checkout_time' => '10:00',
            'legal.terms_version' => '2026-01',
        ]);

        $this->documents = new DocumentRepository($this->database);
        $logger = new Logger($this->storagePath . '/logs');

        $this->documentService = new DocumentService($this->documents, $this->paths, $logger);
        $this->acceptances = new ContractRepository($this->database);
        $this->bookings = new BookingRepository($this->database);
        $this->users = new UserRepository($this->database);

        $translator = new Translator(self::projectRoot() . '/translations', 'fr');

        $this->contracts = new ContractService(
            new ContractBuilder($translator, new Formatter(), $this->settings),
            $this->acceptances,
            $this->documentService,
            $this->documents,
            new PaymentRepository($this->database),
            $this->bookings,
            new BookingEventRepository($this->database),
            $logger,
            new AuditTrail($this->database),
        );

        $this->client = $this->createClient();
    }

    // --- Contenu ---------------------------------------------------------------

    public function testTheContractCarriesTheRequiredContent(): void
    {
        $booking = $this->booking();
        $document = $this->contracts->contractFor($booking);

        self::assertNotNull($document);
        self::assertSame(DocumentKind::Contract, $document->kind);
        self::assertSame('application/pdf', $document->mime);

        $contents = $this->documentService->read($document);
        self::assertNotNull($contents);

        $text = (new PdfReader($contents))->text();

        foreach ([
            'Maison des Pins',
            '12 route des Mélèzes',
            '12345678900012',
            'Claire Dubois',
            'claire@example.test',
            $booking->reference,
            'Chamonix',
            '2026-01',
        ] as $expected) {
            self::assertStringContainsString($expected, $text, $expected . ' doit figurer au contrat.');
        }
    }

    public function testTheContractStatesEveryAmountOfTheStay(): void
    {
        $booking = $this->booking();
        $document = $this->contracts->contractFor($booking);
        self::assertNotNull($document);

        $contents = $this->documentService->read($document);
        self::assertNotNull($contents);
        $text = (new PdfReader($contents))->text();

        // Les montants du contrat sont ceux figés au séjour, formatés dans sa
        // langue : un espace insécable sépare le nombre du symbole.
        foreach (['700,00', '80,00', '780,00', '500,00'] as $amount) {
            self::assertStringContainsString($amount, $text, $amount . ' doit figurer au contrat.');
        }
    }

    /**
     * @return list<array{string, list<string>}>
     */
    public static function locales(): array
    {
        return [
            ['fr', ['Contrat de location saisonnière', 'Les parties', 'Annulation']],
            ['en', ['Seasonal rental agreement', 'The parties', 'Cancellation']],
            ['nl', ['Huurovereenkomst voor vakantieverblijf', 'De partijen', 'Annulering']],
            ['de', ['Mietvertrag für Ferienunterkunft', 'Die Parteien', 'Stornierung']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('locales')]
    public function testTheContractIsRenderedInTheLanguageOfTheStay(string $locale, array $expected): void
    {
        $booking = $this->booking($locale);
        $document = $this->contracts->contractFor($booking);
        self::assertNotNull($document);
        self::assertSame($locale, $document->locale);

        $contents = $this->documentService->read($document);
        self::assertNotNull($contents);
        $text = (new PdfReader($contents))->text();

        foreach ($expected as $needle) {
            self::assertStringContainsString($needle, $text, $needle . ' attendu en ' . $locale);
        }
    }

    // --- Instantané ----------------------------------------------------------------

    public function testTheContractIsGeneratedOnceAndReused(): void
    {
        $booking = $this->booking();

        $first = $this->contracts->contractFor($booking);
        $second = $this->contracts->contractFor($booking);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
    }

    public function testAPriceChangeDoesNotRewriteAnExistingContract(): void
    {
        $booking = $this->booking();
        $document = $this->contracts->contractFor($booking);
        self::assertNotNull($document);

        $before = $this->documentService->read($document);
        self::assertNotNull($before);

        // Le logement est renommé et retarifé après coup.
        $this->settings->setMany(['property.name' => 'Autre nom']);
        $this->bookings->update($booking->id, ['total_cents' => 999999]);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);

        $again = $this->contracts->contractFor($reloaded);
        self::assertNotNull($again);
        self::assertSame($document->id, $again->id, 'Le contrat existant ne doit pas être remplacé.');

        $after = $this->documentService->read($again);
        self::assertSame($before, $after);
        self::assertStringContainsString('Maison des Pins', (new PdfReader((string) $after))->text());
    }

    public function testAnExplicitRegenerationProducesANewSnapshot(): void
    {
        $booking = $this->booking();
        $first = $this->contracts->contractFor($booking);
        self::assertNotNull($first);

        $this->settings->setMany(['property.name' => 'Chalet du Col']);

        $second = $this->contracts->generate($booking);
        self::assertNotNull($second);
        self::assertNotSame($first->id, $second->id);

        $contents = $this->documentService->read($second);
        self::assertNotNull($contents);
        self::assertStringContainsString('Chalet du Col', (new PdfReader($contents))->text());

        // L'ancien instantané reste lisible tel quel.
        $old = $this->documentService->read($first);
        self::assertNotNull($old);
        self::assertStringContainsString('Maison des Pins', (new PdfReader($old))->text());
    }

    // --- Acceptation ------------------------------------------------------------------

    public function testAcceptanceRecordsVersionLocaleAndFingerprint(): void
    {
        $booking = $this->booking('de');

        $result = $this->contracts->accept($booking, $this->client, '203.0.113.7', 'Navigateur/1.0');
        self::assertTrue($result['ok'], $result['error']);

        $acceptance = $this->acceptances->forBooking($booking->id);
        self::assertNotNull($acceptance);
        self::assertSame(ContractBuilder::VERSION, $acceptance->version);
        self::assertSame('de', $acceptance->locale);
        self::assertSame($this->client->id, $acceptance->userId);
        self::assertSame('claire@example.test', $acceptance->acceptedBy);
        self::assertNotSame('', $acceptance->sha256);

        $document = $this->documents->latestKind($booking->id, DocumentKind::Contract);
        self::assertNotNull($document);
        self::assertSame($document->sha256, $acceptance->sha256);
    }

    public function testTheClientAddressIsNeverStoredInClear(): void
    {
        $booking = $this->booking();
        $this->contracts->accept($booking, $this->client, '203.0.113.7', 'Navigateur/1.0');

        $acceptance = $this->acceptances->forBooking($booking->id);
        self::assertNotNull($acceptance);

        self::assertSame(hash('sha256', '203.0.113.7'), $acceptance->ipHash);
        self::assertStringNotContainsString('203.0.113.7', $acceptance->ipHash);
    }

    public function testAcceptanceMovesTheContractSubStatusToDone(): void
    {
        $booking = $this->booking();
        $this->contracts->accept($booking, $this->client, '', '');

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertSame(SubStatus::Done, $reloaded->contractStatus);
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $booking = $this->booking();

        self::assertTrue($this->contracts->accept($booking, $this->client, '', '')['ok']);

        $second = $this->contracts->accept($booking, $this->client, '', '');
        self::assertFalse($second['ok']);
        self::assertSame('contract.error.already_accepted', $second['error']);
    }

    public function testSomeoneElseCannotAcceptAContract(): void
    {
        $booking = $this->booking();
        $stranger = $this->createClient('paul@example.test');

        $result = $this->contracts->accept($booking, $stranger, '', '');

        self::assertFalse($result['ok']);
        self::assertSame('contract.error.not_owner', $result['error']);
        self::assertNull($this->acceptances->forBooking($booking->id));
    }

    public function testAnAlteredSnapshotIsDetected(): void
    {
        $booking = $this->booking();
        $this->contracts->accept($booking, $this->client, '', '');

        $acceptance = $this->acceptances->forBooking($booking->id);
        self::assertNotNull($acceptance);
        self::assertTrue($this->contracts->acceptanceIsIntact($acceptance));

        // Le fichier stocké est réécrit sous le dos de l'application.
        $document = $this->documents->find((int) $acceptance->documentId);
        self::assertNotNull($document);
        $path = $this->documentService->absolutePath($document);
        self::assertNotNull($path);
        file_put_contents($path, "%PDF-1.4\nfalsifié\n");

        self::assertFalse($this->contracts->acceptanceIsIntact($acceptance));
    }

    // --- Outils --------------------------------------------------------------------------

    private function booking(string $locale = 'fr'): Booking
    {
        $id = $this->database->insert('booking', [
            'reference' => strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4))
                . '-' . strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4)),
            'user_id' => $this->client->id,
            'status' => BookingStatus::ToConfirm->value,
            'arrival' => '2026-07-04',
            'departure' => '2026-07-11',
            'adults' => 2,
            'children' => 1,
            'infants' => 0,
            'locale' => $locale,
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'guest_phone' => '+33600000000',
            'accommodation_cents' => 70000,
            'cleaning_cents' => 8000,
            'discount_cents' => 0,
            'total_cents' => 78000,
            'deposit_cents' => 23400,
            'security_deposit_cents' => 50000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $booking = $this->bookings->find($id);
        self::assertNotNull($booking);
        self::assertSame(DateRange::fromStrings('2026-07-04', '2026-07-11')->nights(), $booking->nights());

        return $booking;
    }

    private function createClient(string $email = 'claire@example.test'): User
    {
        $id = $this->users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '+33600000000',
            Role::Customer,
            'fr',
            UserStatus::Active,
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
