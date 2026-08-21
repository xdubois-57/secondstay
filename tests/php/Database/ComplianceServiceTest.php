<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\TokenRepository;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\WaitlistRepository;
use SecondStay\Compliance\ComplianceRepository;
use SecondStay\Compliance\ComplianceService;
use SecondStay\Compliance\ComplianceStatus;
use SecondStay\Compliance\ComplianceTopic;
use SecondStay\Content\ContentRepository;
use SecondStay\Content\ContentSeeder;
use SecondStay\I18n\Translator;
use SecondStay\Legal\BookingConsentRepository;
use SecondStay\Legal\LegalDocumentRepository;
use SecondStay\Legal\LegalDocumentType;
use SecondStay\Legal\LegalService;
use SecondStay\Logging\Logger;
use SecondStay\Notification\NotificationRepository;
use SecondStay\Payment\WebhookRepository;
use SecondStay\Police\PoliceRecordRepository;
use SecondStay\Police\PoliceRecordService;
use SecondStay\Privacy\RetentionService;
use SecondStay\Security\Encryptor;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Stay\GuestLinkRepository;
use SecondStay\Tax\TouristTaxCalculator;
use SecondStay\Tax\TouristTaxContextRepository;
use SecondStay\Tax\TouristTaxRuleRepository;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Conformité France, textes légaux versionnés, taxe datée et rétention
 * (SPECIFICATIONS.md §61 à §65).
 *
 * Les propriétés qui portent l'itération : une version publiée est immuable,
 * une réservation conserve la version **et la langue** acceptées, un barème
 * daté ne recalcule pas le passé, et une fiche de police ne survit pas à sa
 * durée de conservation.
 */
final class ComplianceServiceTest extends DatabaseTestCase
{
    private LegalService $legal;

    private LegalDocumentRepository $documents;

    private BookingConsentRepository $consents;

    private ComplianceService $compliance;

    private ComplianceRepository $items;

    private ContentRepository $content;

    private TouristTaxRuleRepository $rules;

    private TouristTaxContextRepository $contexts;

    private PoliceRecordService $police;

    private PoliceRecordRepository $policeRecords;

    private RetentionService $retention;

    private BookingRepository $bookings;

    private SettingsService $settings;

    private UserRepository $users;

    private User $owner;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());
        $logger = new Logger($this->storagePath . '/logs');

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $encryptor,
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'site.default_locale' => 'fr',
            'tax.tourist_enabled' => '1',
            'tax.tourist_per_adult_night' => '1,50',
            'tax.tourist_cap_per_stay' => '0',
        ]);

        $this->content = new ContentRepository($this->database);
        (new ContentSeeder(
            $this->content,
            new Translator(self::projectRoot() . '/translations', 'fr'),
            $this->database,
        ))->seed();

        $this->documents = new LegalDocumentRepository($this->database);
        $this->consents = new BookingConsentRepository($this->database);
        $this->legal = new LegalService(
            $this->documents,
            $this->consents,
            $this->content,
            $this->settings,
            $logger,
            new AuditTrail($this->database),
        );

        $this->items = new ComplianceRepository($this->database);
        $this->compliance = new ComplianceService(
            $this->items,
            $this->legal,
            new AuditTrail($this->database),
        );

        $this->rules = new TouristTaxRuleRepository($this->database);
        $this->contexts = new TouristTaxContextRepository($this->database);

        $this->policeRecords = new PoliceRecordRepository($this->database, $encryptor);
        $this->police = new PoliceRecordService(
            $this->policeRecords,
            $this->settings,
            $logger,
            new AuditTrail($this->database),
        );

        $this->bookings = new BookingRepository($this->database);
        $this->users = new UserRepository($this->database);

        $this->retention = new RetentionService(
            $this->settings,
            $logger,
            new NotificationRepository($this->database),
            new SessionRepository($this->database),
            new TokenRepository($this->database),
            new GuestLinkRepository($this->database),
            new WaitlistRepository($this->database),
            new WebhookRepository($this->database),
            new RateLimiter($this->database),
            $this->police,
            new AvailabilityBlockRepository($this->database),
            new AuditTrail($this->database),
        );

        $this->owner = $this->createUser('owner@example.test', Role::Administrator);
        $this->client = $this->createUser('claire@example.test', Role::Customer);
    }

    // --- Textes légaux versionnés -------------------------------------------------

    public function testPublishingFreezesTheTextOfEveryLanguage(): void
    {
        $result = $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame([], $result['missing'], 'Le contenu semé couvre les quatre langues.');
        self::assertSame(['fr', 'en', 'nl', 'de'], $result['published']);

        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            $document = $this->documents->find(LegalDocumentType::Terms, $locale, '2026-01');
            self::assertNotNull($document, $locale);
            self::assertTrue($document->isIntact());
            self::assertNotSame('', $document->body);
        }
    }

    public function testAPublishedVersionIsNeverRewritten(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        $before = $this->documents->find(LegalDocumentType::Terms, 'fr', '2026-01');
        self::assertNotNull($before);

        // Le texte éditorial change...
        $page = $this->content->findBySlug('terms');
        self::assertNotNull($page);
        $this->content->saveTranslation($page->id, 'fr', [
            'title' => 'Conditions générales',
            'body' => 'Texte entièrement réécrit.',
        ]);

        // ... republier sous le même numéro est refusé.
        $again = $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        self::assertFalse($again['ok']);
        self::assertSame('legal.error.already_published', $again['error']);

        $after = $this->documents->find(LegalDocumentType::Terms, 'fr', '2026-01');
        self::assertNotNull($after);
        self::assertSame($before->body, $after->body);
        self::assertSame($before->sha256, $after->sha256);
    }

    public function testANewVersionCapturesTheNewText(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);

        $page = $this->content->findBySlug('terms');
        self::assertNotNull($page);
        $this->content->saveTranslation($page->id, 'fr', [
            'title' => 'Conditions générales',
            'body' => 'Annulation gratuite jusqu’à trente jours avant l’arrivée.',
        ]);

        self::assertTrue($this->legal->publish(LegalDocumentType::Terms, '2026-02', $this->owner)['ok']);

        $current = $this->legal->current(LegalDocumentType::Terms, 'fr');
        self::assertNotNull($current);
        self::assertSame('2026-02', $current->version);
        self::assertStringContainsString('trente jours', $current->body);

        // L'ancienne version reste lisible telle qu'elle était.
        $previous = $this->documents->find(LegalDocumentType::Terms, 'fr', '2026-01');
        self::assertNotNull($previous);
        self::assertStringNotContainsString('trente jours', $previous->body);
    }

    public function testPublishingTermsUpdatesTheVersionQuotedByContracts(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-03', $this->owner);

        self::assertSame('2026-03', $this->settings->string('legal.terms_version'));
    }

    public function testAVersionNumberIsAnIdentifierNotFreeText(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '  2026 / 04 ', $this->owner);

        self::assertNotNull($this->documents->find(LegalDocumentType::Terms, 'fr', '2026-04'));
    }

    public function testAnEmptyVersionIsRefused(): void
    {
        $result = $this->legal->publish(LegalDocumentType::Terms, '   ', $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('legal.error.version_required', $result['error']);
        self::assertSame([], $this->documents->versions(LegalDocumentType::Terms));
    }

    // --- Consentement d'un séjour --------------------------------------------------

    public function testABookingKeepsTheVersionAndLanguageItAccepted(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        $booking = $this->booking();

        $this->legal->recordBookingAcceptance($booking, 'de', '203.0.113.7');

        $consent = $this->consents->find($booking->id, LegalDocumentType::Terms);
        self::assertNotNull($consent);
        self::assertSame('2026-01', $consent->version);
        self::assertSame('de', $consent->locale);

        $document = $this->documents->find(LegalDocumentType::Terms, 'de', '2026-01');
        self::assertNotNull($document);
        self::assertSame($document->sha256, $consent->sha256);
        self::assertTrue($this->legal->acceptanceIsIntact($consent));
    }

    public function testAnAcceptanceSurvivesANewPublication(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        $booking = $this->booking();
        $this->legal->recordBookingAcceptance($booking, 'fr');

        $page = $this->content->findBySlug('terms');
        self::assertNotNull($page);
        $this->content->saveTranslation($page->id, 'fr', [
            'title' => 'Conditions générales',
            'body' => 'Nouvelles conditions, bien plus strictes.',
        ]);
        $this->legal->publish(LegalDocumentType::Terms, '2027-01', $this->owner);

        $consent = $this->consents->find($booking->id, LegalDocumentType::Terms);
        self::assertNotNull($consent);
        // Ce que le voyageur a accepté n'a pas bougé d'un octet.
        self::assertSame('2026-01', $consent->version);
        self::assertTrue($this->legal->acceptanceIsIntact($consent));
    }

    public function testTheAddressIsOnlyKeptHashed(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        $booking = $this->booking();
        $this->legal->recordBookingAcceptance($booking, 'fr', '203.0.113.7');

        $consent = $this->consents->find($booking->id, LegalDocumentType::Terms);
        self::assertNotNull($consent);
        self::assertSame(hash('sha256', '203.0.113.7'), $consent->ipHash);

        $rows = $this->database->fetchAll('SELECT * FROM `booking_consent`');
        foreach ($rows as $row) {
            self::assertStringNotContainsString('203.0.113.7', (string) json_encode($row));
        }
    }

    public function testAcceptingTwiceDoesNotDuplicateTheProof(): void
    {
        $this->legal->publish(LegalDocumentType::Terms, '2026-01', $this->owner);
        $booking = $this->booking();

        $this->legal->recordBookingAcceptance($booking, 'fr');
        $this->legal->recordBookingAcceptance($booking, 'en');

        $consents = $this->consents->forBooking($booking->id);
        self::assertCount(1, $consents);
        // La première acceptation fait foi : c'est celle qui a eu lieu.
        self::assertSame('fr', $consents[0]->locale);
    }

    public function testNothingIsRecordedWhenNoTextIsPublished(): void
    {
        $booking = $this->booking();

        // Inventer une version serait pire que ne rien enregistrer : cela
        // ferait croire à une preuve qui n'existe pas.
        self::assertSame([], $this->legal->recordBookingAcceptance($booking, 'fr'));
    }

    // --- Assistant conformité -------------------------------------------------------

    public function testEveryTopicIsListedEvenBeforeAnythingIsEntered(): void
    {
        $items = $this->compliance->all();

        self::assertCount(count(ComplianceTopic::cases()), $items);
        foreach ($items as $item) {
            self::assertSame(ComplianceStatus::ToVerify, $item->status);
        }

        // Rien de saisi : tout réclame donc une action.
        self::assertCount(count(ComplianceTopic::cases()), $this->compliance->outstanding());
    }

    public function testDeclaringASubjectCompliantDatesTheVerification(): void
    {
        $result = $this->compliance->save(ComplianceTopic::Insurance, [
            'status' => 'compliant',
            'notes' => 'Avenant location saisonnière signé.',
            'source_url' => 'https://example.test/assurance',
        ], $this->owner);

        self::assertTrue($result['ok'], $result['error']);

        $item = $this->compliance->find(ComplianceTopic::Insurance);
        self::assertSame(ComplianceStatus::Compliant, $item->status);
        self::assertSame(gmdate('Y-m-d'), $item->lastVerified);
        // Une échéance est posée d'office : une vérification sans revue
        // finirait par dormir indéfiniment.
        self::assertNotNull($item->nextReview);
        self::assertFalse($item->needsAction());
    }

    public function testASourceThatIsNotAWebAddressIsRefused(): void
    {
        $result = $this->compliance->save(ComplianceTopic::Siret, [
            'status' => 'compliant',
            'value' => '123 456 789 00012',
            'source_url' => 'demandé par téléphone',
        ], $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('compliance.error.source', $result['error']);
        self::assertSame(ComplianceStatus::ToVerify, $this->compliance->find(ComplianceTopic::Siret)->status);
    }

    public function testASubjectNotApplicableLeavesTheOutstandingList(): void
    {
        $before = count($this->compliance->outstanding());

        $this->compliance->save(ComplianceTopic::Clearing, ['status' => 'not_applicable'], $this->owner);

        self::assertCount($before - 1, $this->compliance->outstanding());
    }

    public function testTheSummaryCountsWhatIsOverdue(): void
    {
        $this->compliance->save(ComplianceTopic::Insurance, [
            'status' => 'compliant',
            'last_verified' => '2020-01-01',
            'next_review' => '2021-01-01',
        ], $this->owner);

        $summary = $this->compliance->summary('2026-06-01');

        self::assertSame(1, $summary['compliant']);
        self::assertSame(1, $summary['overdue']);
        self::assertSame(count(ComplianceTopic::cases()), $summary['total']);

        // Une revue dépassée réclame une action, même sur un sujet conforme.
        self::assertNotSame([], array_filter(
            $this->compliance->outstanding('2026-06-01'),
            static fn (object $item): bool => $item->topic === ComplianceTopic::Insurance
        ));
    }

    // --- Taxe de séjour datée --------------------------------------------------------

    public function testADatedRuleWinsOverTheConfiguration(): void
    {
        $this->rules->create([
            'territory' => 'Communauté de communes',
            'classification' => 'unclassified',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'per_adult_night_cents' => 220,
            'cap_per_stay_cents' => 0,
            'taxable_from_age' => 18,
            'source_url' => 'https://example.test/deliberation',
            'notes' => '',
        ]);

        $calculator = $this->calculator();
        $booking = $this->booking('2026-07-04', '2026-07-11');

        // 2 adultes × 7 nuits × 2,20 €, et non le 1,50 € de la configuration.
        self::assertSame(3080, $calculator->forBooking($booking));
    }

    public function testAStayKeepsTheScaleThatAppliedOnItsArrival(): void
    {
        $this->rules->create([
            'territory' => 'Commune',
            'classification' => 'unclassified',
            'effective_from' => '2020-01-01',
            'effective_to' => '2026-12-31',
            'per_adult_night_cents' => 100,
            'cap_per_stay_cents' => 0,
            'taxable_from_age' => 18,
            'source_url' => '',
            'notes' => '',
        ]);

        $calculator = $this->calculator();
        $booking = $this->booking('2026-07-04', '2026-07-11');

        $calculator->freeze($booking);
        self::assertSame(1400, $calculator->forBooking($booking));

        // Un nouveau barème est voté, avec effet rétroactif au 1er janvier.
        $this->rules->create([
            'territory' => 'Commune',
            'classification' => 'unclassified',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'per_adult_night_cents' => 500,
            'cap_per_stay_cents' => 0,
            'taxable_from_age' => 18,
            'source_url' => '',
            'notes' => '',
        ]);

        // Le séjour déjà engagé n'en sait rien, et c'est le but.
        self::assertSame(1400, $this->calculator()->forBooking($booking));
        self::assertSame(1400, $this->calculator()->explain($booking)['total_cents']);
    }

    public function testTheFrozenContextExplainsTheCalculation(): void
    {
        $calculator = $this->calculator();
        $booking = $this->booking('2026-07-04', '2026-07-11');
        $calculator->freeze($booking);

        $explanation = $calculator->explain($booking);

        self::assertSame(2, $explanation['adults']);
        self::assertSame(7, $explanation['nights']);
        self::assertSame(150, $explanation['per_adult_night_cents']);
        self::assertSame(2100, $explanation['total_cents']);
        self::assertArrayHasKey('frozen_at', $explanation);
    }

    public function testFreezingTwiceKeepsTheFirstContext(): void
    {
        $calculator = $this->calculator();
        $booking = $this->booking('2026-07-04', '2026-07-11');

        $calculator->freeze($booking);
        $this->settings->set('tax.tourist_per_adult_night', '9,00');
        $calculator->freeze($booking);

        self::assertSame(2100, $this->calculator()->forBooking($booking));
    }

    public function testOverlappingScalesAreReported(): void
    {
        foreach ([['2026-01-01', '2026-12-31'], ['2026-06-01', null]] as [$from, $to]) {
            $this->rules->create([
                'territory' => 'Commune',
                'classification' => 'unclassified',
                'effective_from' => $from,
                'effective_to' => $to,
                'per_adult_night_cents' => 150,
                'cap_per_stay_cents' => 0,
                'taxable_from_age' => 18,
                'source_url' => '',
                'notes' => '',
            ]);
        }

        self::assertCount(1, $this->rules->overlaps());
    }

    // --- Fiche de police et rétention -------------------------------------------------

    public function testNothingIsCollectedWhileTheRecordIsDisabled(): void
    {
        $booking = $this->booking();

        $result = $this->police->save($booking, $this->policeFields(), 'fr', $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('police.error.disabled', $result['error']);
        self::assertSame(0, $this->policeRecords->count());
    }

    public function testARecordIsStoredEncrypted(): void
    {
        $this->settings->set('compliance.police_record_enabled', '1');
        $booking = $this->booking();

        self::assertTrue($this->police->save($booking, $this->policeFields(), 'fr', $this->owner)['ok']);

        $rows = $this->database->fetchAll('SELECT * FROM `police_record`');
        self::assertCount(1, $rows);
        $payload = (string) $rows[0]['payload'];
        self::assertStringStartsWith('ss1.', $payload);
        self::assertStringNotContainsString('Dubois', $payload);

        $record = $this->police->forBooking($booking);
        self::assertNotNull($record);
        self::assertSame('Dubois', $record->field('last_name'));
    }

    public function testAnIncompleteRecordIsRefused(): void
    {
        $this->settings->set('compliance.police_record_enabled', '1');
        $booking = $this->booking();

        $fields = $this->policeFields();
        $fields['nationality'] = '';

        $result = $this->police->save($booking, $fields, 'fr', $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('police.error.incomplete', $result['error']);
        self::assertSame(0, $this->policeRecords->count());
    }

    public function testARecordDisappearsAtTheEndOfItsRetention(): void
    {
        $this->settings->setMany([
            'compliance.police_record_enabled' => '1',
            'compliance.police_retention_days' => '30',
        ]);

        $booking = $this->booking('2026-07-04', '2026-07-11');
        $this->police->save($booking, $this->policeFields(), 'fr', $this->owner);

        $record = $this->police->forBooking($booking);
        self::assertNotNull($record);
        self::assertSame('2026-08-10', $record->purgeAfter);

        // Un jour avant l'échéance, la fiche est encore là.
        self::assertSame(0, $this->police->purge('2026-08-10'));
        self::assertSame(1, $this->policeRecords->count());

        self::assertSame(1, $this->police->purge('2026-08-11'));
        self::assertSame(0, $this->policeRecords->count());
    }

    public function testRetentionAppliesEveryConfiguredDuration(): void
    {
        $this->settings->setMany([
            'compliance.police_record_enabled' => '1',
            'compliance.police_retention_days' => '1',
        ]);

        $booking = $this->booking('2020-07-04', '2020-07-11');
        $this->police->save($booking, $this->policeFields(), 'fr', $this->owner);

        $removed = $this->retention->purge();

        self::assertSame(1, $removed['police_records']);
        self::assertArrayHasKey('logs', $removed);
        self::assertArrayHasKey('guest_links', $removed);
    }

    public function testTheRetentionPolicyIsReadable(): void
    {
        $policy = $this->retention->policy();

        foreach (['logs', 'notifications', 'guest_links', 'webhooks', 'police_records'] as $key) {
            self::assertArrayHasKey($key, $policy);
            self::assertGreaterThan(0, $policy[$key]);
        }
    }

    // --- Support ------------------------------------------------------------------------

    private function calculator(): TouristTaxCalculator
    {
        return new TouristTaxCalculator($this->settings, $this->rules, $this->contexts);
    }

    /**
     * @return array<string, string>
     */
    private function policeFields(): array
    {
        return [
            'last_name' => 'Dubois',
            'first_names' => 'Claire Marie',
            'birth_date' => '1984-03-11',
            'birth_place' => 'Namur',
            'nationality' => 'Belge',
            'home_address' => 'Rue de la Gare 12, Namur',
            'arrival_date' => '2026-07-04',
            'departure_date' => '2026-07-11',
        ];
    }

    private function booking(string $arrival = '2026-07-04', string $departure = '2026-07-11'): Booking
    {
        $id = $this->database->insert('booking', [
            'reference' => strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4))
                . '-' . strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4)),
            'user_id' => $this->client->id,
            'status' => BookingStatus::Confirmed->value,
            'arrival' => $arrival,
            'departure' => $departure,
            'adults' => 2,
            'children' => 1,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'total_cents' => 78000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $booking = $this->bookings->find($id);
        self::assertNotNull($booking);

        return $booking;
    }

    private function createUser(string $email, Role $role): User
    {
        $id = $this->users->create(
            $email,
            (new PasswordHasher())->hash('Marée-Haute-2026!'),
            'Prénom',
            'Nom',
            '+33600000000',
            $role,
            'fr',
            UserStatus::Active,
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
