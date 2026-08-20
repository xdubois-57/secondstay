<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\SubStatus;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Core\View;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Incident\IncidentService;
use SecondStay\Incident\IncidentSeverity;
use SecondStay\Incident\IncidentStatus;
use SecondStay\Inspection\EntryState;
use SecondStay\Inspection\InspectionKind;
use SecondStay\Inspection\InspectionRepository;
use SecondStay\Inspection\InspectionService;
use SecondStay\Inspection\ZoneRepository;
use SecondStay\Logging\Logger;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Notification\NotificationPreferenceRepository;
use SecondStay\Notification\NotificationRepository;
use SecondStay\Notification\NotificationService;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * États des lieux et incidents (SPECIFICATIONS.md §53 et §54).
 *
 * Les deux propriétés qui portent tout le reste : au départ, une photo
 * manquante empêche de clore ; un incident ne change d'état que par une
 * transition permise, et chaque changement laisse une trace.
 */
final class InspectionServiceTest extends DatabaseTestCase
{
    private InspectionService $inspections;

    private InspectionRepository $repository;

    private IncidentService $incidents;

    private IncidentRepository $incidentRepository;

    private ZoneRepository $zones;

    private BookingRepository $bookings;

    private DocumentRepository $documents;

    private UserRepository $users;

    private FakeMailTransport $mailTransport;

    private SettingsService $settings;

    private User $client;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->bookings = new BookingRepository($this->database);
        $this->zones = new ZoneRepository($this->database);
        $this->repository = new InspectionRepository($this->database, $this->zones);
        $this->incidentRepository = new IncidentRepository($this->database);
        $this->documents = new DocumentRepository($this->database);

        $logger = new Logger($this->storagePath . '/logs');
        $router = new Router();
        Routes::register($router);
        $translator = new Translator(self::projectRoot() . '/translations', 'fr');

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'site.default_locale' => 'fr',
            'mail.from_address' => 'noreply@example.test',
        ]);

        $this->mailTransport = new FakeMailTransport();

        $documentService = new DocumentService(
            $this->documents,
            $this->paths,
            $logger,
            new AuditTrail($this->database),
        );

        $notifications = new NotificationService(
            new MailService(
                $this->mailTransport,
                new View(self::projectRoot() . '/templates', $translator, new Formatter(), $router),
                $translator,
                $this->settings,
                new MailRepository($this->database),
                $logger,
            ),
            new FakePushProvider(''),
            new PushSubscriptionRepository($this->database),
            new NotificationRepository($this->database),
            new NotificationPreferenceRepository($this->database),
            $translator,
            $this->settings,
            $logger,
        );

        $this->incidents = new IncidentService(
            $this->incidentRepository,
            $documentService,
            $this->users,
            $notifications,
            $this->settings,
            $logger,
            new BookingEventRepository($this->database),
            new AuditTrail($this->database),
        );

        $this->inspections = new InspectionService(
            $this->repository,
            $this->zones,
            $documentService,
            $this->bookings,
            $this->incidents,
            $this->settings,
            $logger,
            new BookingEventRepository($this->database),
            new AuditTrail($this->database),
        );

        $this->zones->seedDefaults();

        $this->client = $this->createUser('claire@example.test', Role::Customer);
        $this->manager = $this->createUser('marc@example.test', Role::LocalManager);
    }

    // --- Ouverture ------------------------------------------------------------------

    public function testTheDefaultZonesAreCreatedOnceAndKeepTheWalkingOrder(): void
    {
        // Une deuxième amorce ne recrée rien.
        self::assertSame(0, $this->zones->seedDefaults());

        $codes = array_map(
            static fn (object $zone): string => $zone->code,
            $this->zones->active('fr')
        );

        self::assertSame(array_keys(ZoneRepository::DEFAULTS), $codes);
    }

    public function testOpeningTwiceReturnsTheSameInspection(): void
    {
        $booking = $this->booking();

        $first = $this->inspections->prepare($booking, InspectionKind::Checkin);
        $second = $this->inspections->prepare($booking, InspectionKind::Checkin);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
        self::assertSame(
            1,
            (int) $this->database->fetchValue('SELECT COUNT(*) FROM `inspection`')
        );
    }

    public function testArrivalAndDepartureAreTwoDistinctInspections(): void
    {
        $booking = $this->booking();

        $arrival = $this->inspections->prepare($booking, InspectionKind::Checkin);
        $departure = $this->inspections->prepare($booking, InspectionKind::Checkout);

        self::assertNotNull($arrival);
        self::assertNotNull($departure);
        self::assertNotSame($arrival->id, $departure->id);
    }

    public function testAZoneAddedLaterAppearsInAnOpenInspection(): void
    {
        $booking = $this->booking();
        $before = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($before);

        $this->zones->save('cellar', ['position' => 80, 'photo_required' => 0, 'active' => 1]);

        $after = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($after);
        self::assertCount(count($before->entries) + 1, $after->entries);
    }

    // --- Arrivée --------------------------------------------------------------------

    public function testAnArrivalClosesWithoutAnyPhoto(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        foreach ($inspection->entries as $entry) {
            $this->inspections->recordEntry($inspection, $entry->zone->id, EntryState::Ok, '', $this->client);
        }

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($reloaded);

        $result = $this->inspections->complete($booking, $reloaded, $this->client);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(SubStatus::Done, $this->reload($booking)->checkinStatus);
        self::assertSame(SubStatus::None, $this->reload($booking)->checkoutStatus);
    }

    public function testAnArrivalWithAZoneLeftPendingCannotBeClosed(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        $result = $this->inspections->complete($booking, $inspection, $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('inspection.error.incomplete', $result['error']);
        self::assertNotSame([], $result['blocking']);
        self::assertSame(SubStatus::None, $this->reload($booking)->checkinStatus);
    }

    // --- Départ ---------------------------------------------------------------------

    public function testADepartureIsBlockedWhileARequiredPhotoIsMissing(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkout);
        self::assertNotNull($inspection);

        foreach ($inspection->entries as $entry) {
            $this->inspections->recordEntry($inspection, $entry->zone->id, EntryState::Ok, '', $this->client);
        }

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkout);
        self::assertNotNull($reloaded);

        $result = $this->inspections->complete($booking, $reloaded, $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('inspection.error.photos_required', $result['error']);

        // Ne bloquent que les zones qui exigent réellement une photo.
        foreach ($result['blocking'] as $entry) {
            self::assertTrue($entry->zone->photoRequired);
        }
    }

    public function testADepartureClosesOnceEveryRequiredPhotoIsThere(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkout);
        self::assertNotNull($inspection);

        foreach ($inspection->entries as $entry) {
            $this->inspections->recordEntry($inspection, $entry->zone->id, EntryState::Ok, '', $this->client);

            if ($entry->zone->photoRequired) {
                $stored = $this->inspections->addPhoto(
                    $inspection,
                    $entry->zone->id,
                    $this->jpeg($entry->zone->code),
                    $entry->zone->code . '.jpg',
                    $this->client,
                );
                self::assertTrue($stored['ok'], $stored['error']);
            }
        }

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkout);
        self::assertNotNull($reloaded);

        $result = $this->inspections->complete($booking, $reloaded, $this->client);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(SubStatus::Done, $this->reload($booking)->checkoutStatus);

        $closed = $this->inspections->find($booking, InspectionKind::Checkout);
        self::assertNotNull($closed);
        self::assertTrue($closed->status->isCompleted());
        self::assertSame('ok', $closed->summary);
    }

    public function testPhotosAreStoredAsOrdinaryDocumentsOutsideTheDocumentRoot(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkout);
        self::assertNotNull($inspection);

        $zone = $inspection->entries[0]->zone;
        $this->inspections->recordEntry($inspection, $zone->id, EntryState::Ok, '', $this->client);
        $this->inspections->addPhoto($inspection, $zone->id, $this->jpeg('salon'), 'salon.jpg', $this->client);

        $documents = $this->documents->forBooking($booking->id);
        self::assertCount(1, $documents);
        self::assertSame(DocumentKind::Inventory, $documents[0]->kind);
        self::assertStringStartsWith('documents/', $documents[0]->storagePath);
        self::assertStringNotContainsString('..', $documents[0]->storagePath);
    }

    public function testAPdfIsRefusedWhereAPhotoIsExpected(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkout);
        self::assertNotNull($inspection);

        $zone = $inspection->entries[0]->zone;

        $result = $this->inspections->addPhoto(
            $inspection,
            $zone->id,
            "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n",
            'constat.pdf',
            $this->client,
        );

        self::assertFalse($result['ok']);
        self::assertSame('inspection.error.not_a_photo', $result['error']);
        self::assertSame([], $this->documents->forBooking($booking->id));
    }

    public function testAClosedInspectionCannotBeChangedAnyMore(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        foreach ($inspection->entries as $entry) {
            $this->inspections->recordEntry($inspection, $entry->zone->id, EntryState::Ok, '', $this->client);
        }

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($reloaded);
        self::assertTrue($this->inspections->complete($booking, $reloaded, $this->client)['ok']);

        $closed = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($closed);

        $zone = $closed->entries[0]->zone;
        $write = $this->inspections->recordEntry($closed, $zone->id, EntryState::Anomaly, 'après coup', $this->client);

        self::assertFalse($write['ok']);
        self::assertSame('inspection.error.completed', $write['error']);

        $again = $this->inspections->complete($booking, $closed, $this->client);
        self::assertFalse($again['ok']);
    }

    // --- De l'anomalie à l'incident -------------------------------------------------

    public function testAnAnomalyCanBecomeAnIncident(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        $zone = $inspection->entries[0]->zone;
        $this->inspections->recordEntry($inspection, $zone->id, EntryState::Anomaly, 'Volet cassé', $this->client);

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($reloaded);

        $result = $this->inspections->raiseIncident(
            $booking,
            $reloaded,
            $zone->id,
            IncidentSeverity::Urgent,
            '',
            $this->client,
        );

        self::assertTrue($result['ok'], $result['error']);

        $incidents = $this->incidentRepository->forBooking($booking->id);
        self::assertCount(1, $incidents);
        // Sans description propre, la note du constat sert de description.
        self::assertSame('Volet cassé', $incidents[0]->description);
        self::assertSame(IncidentSeverity::Urgent, $incidents[0]->severity);
    }

    public function testAnIncidentCannotBeOpenedOnACompliantZone(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        $zone = $inspection->entries[0]->zone;
        $this->inspections->recordEntry($inspection, $zone->id, EntryState::Ok, '', $this->client);

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($reloaded);

        $result = $this->inspections->raiseIncident(
            $booking,
            $reloaded,
            $zone->id,
            IncidentSeverity::Normal,
            'Rien à signaler',
            $this->client,
        );

        self::assertFalse($result['ok']);
        self::assertSame('inspection.error.not_an_anomaly', $result['error']);
        self::assertSame([], $this->incidentRepository->forBooking($booking->id));
    }

    public function testTheSummaryNamesTheZonesInAnomaly(): void
    {
        $booking = $this->booking();
        $inspection = $this->inspections->prepare($booking, InspectionKind::Checkin);
        self::assertNotNull($inspection);

        foreach ($inspection->entries as $index => $entry) {
            $this->inspections->recordEntry(
                $inspection,
                $entry->zone->id,
                $index === 0 ? EntryState::Anomaly : EntryState::Ok,
                '',
                $this->client,
            );
        }

        $reloaded = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($reloaded);
        $this->inspections->complete($booking, $reloaded, $this->client);

        $closed = $this->inspections->find($booking, InspectionKind::Checkin);
        self::assertNotNull($closed);
        self::assertSame('anomalies:' . $inspection->entries[0]->zone->code, $closed->summary);
    }

    // --- Incidents ------------------------------------------------------------------

    public function testAnIncidentFollowsItsLifecycleAndKeepsItsHistory(): void
    {
        $booking = $this->booking();

        $reported = $this->incidents->report(
            'Chauffe-eau en panne',
            'Plus d’eau chaude depuis ce matin.',
            IncidentSeverity::Normal,
            $booking->id,
            null,
            'fr',
            $this->client,
        );

        self::assertTrue($reported['ok'], $reported['error']);
        $incident = $reported['incident'];
        self::assertNotNull($incident);
        self::assertSame(IncidentStatus::Reported, $incident->status);

        self::assertTrue($this->incidents->assign($incident, $this->manager->id, $this->manager)['ok']);
        self::assertTrue(
            $this->incidents->transition($incident, IncidentStatus::Acknowledged, 'Je passe demain', $this->manager)['ok']
        );

        $acknowledged = $this->incidentRepository->find($incident->id);
        self::assertNotNull($acknowledged);
        self::assertSame(IncidentStatus::Acknowledged, $acknowledged->status);
        self::assertSame($this->manager->id, $acknowledged->assignedTo);

        self::assertTrue(
            $this->incidents->transition($acknowledged, IncidentStatus::Resolved, 'Résistance changée', $this->manager)['ok']
        );

        $resolved = $this->incidentRepository->find($incident->id);
        self::assertNotNull($resolved);
        self::assertTrue($resolved->status->isResolved());
        self::assertNotNull($resolved->resolvedAt);

        $types = array_map(
            static fn (object $event): string => $event->type,
            $resolved->events
        );
        self::assertSame(['reported', 'assigned', 'acknowledged', 'resolved'], $types);
    }

    public function testAForbiddenTransitionIsRefusedAndChangesNothing(): void
    {
        $reported = $this->incidents->report(
            'Portail bloqué',
            '',
            IncidentSeverity::Low,
            null,
            null,
            'fr',
            $this->manager,
        );

        $incident = $reported['incident'];
        self::assertNotNull($incident);

        $result = $this->incidents->transition($incident, IncidentStatus::Reported, '', $this->manager);

        self::assertFalse($result['ok']);
        self::assertSame('incident.error.transition', $result['error']);

        $unchanged = $this->incidentRepository->find($incident->id);
        self::assertNotNull($unchanged);
        self::assertSame(IncidentStatus::Reported, $unchanged->status);
        self::assertCount(1, $unchanged->events);
    }

    public function testReopeningClearsTheResolutionDate(): void
    {
        $reported = $this->incidents->report('Fuite', '', IncidentSeverity::Normal, null, null, 'fr', $this->manager);
        $incident = $reported['incident'];
        self::assertNotNull($incident);

        $this->incidents->transition($incident, IncidentStatus::Resolved, '', $this->manager);

        $resolved = $this->incidentRepository->find($incident->id);
        self::assertNotNull($resolved);
        self::assertNotNull($resolved->resolvedAt);

        $this->incidents->transition($resolved, IncidentStatus::Acknowledged, 'Ça recommence', $this->manager);

        $reopened = $this->incidentRepository->find($incident->id);
        self::assertNotNull($reopened);
        self::assertNull($reopened->resolvedAt);
        self::assertTrue($reopened->isOpen());
    }

    public function testAnIncidentCannotBeAssignedToACustomer(): void
    {
        $reported = $this->incidents->report('Store cassé', '', IncidentSeverity::Low, null, null, 'fr', $this->manager);
        $incident = $reported['incident'];
        self::assertNotNull($incident);

        $result = $this->incidents->assign($incident, $this->client->id, $this->manager);

        self::assertFalse($result['ok']);
        self::assertSame('incident.error.assignee', $result['error']);

        $unchanged = $this->incidentRepository->find($incident->id);
        self::assertNotNull($unchanged);
        self::assertNull($unchanged->assignedTo);
    }

    public function testAnUrgentIncidentWarnsTheOperationalRolesImmediately(): void
    {
        $this->mailTransport->clear();

        $this->incidents->report(
            'Fuite de gaz',
            'Odeur forte dans la cuisine.',
            IncidentSeverity::Urgent,
            null,
            null,
            'fr',
            $this->client,
        );

        self::assertNotSame([], $this->mailTransport->messagesTo($this->manager->email));
        // Le client n'a pas à recevoir l'alerte d'exploitation.
        self::assertSame([], $this->mailTransport->messagesTo($this->client->email));
    }

    public function testANormalIncidentDoesNotWakeAnyone(): void
    {
        $this->mailTransport->clear();

        $this->incidents->report(
            'Ampoule grillée',
            '',
            IncidentSeverity::Normal,
            null,
            null,
            'fr',
            $this->client,
        );

        self::assertSame([], $this->mailTransport->messages());
    }

    public function testAnIncidentWithoutTitleIsRefused(): void
    {
        $result = $this->incidents->report('   ', 'description', IncidentSeverity::Normal, null, null, 'fr', null);

        self::assertFalse($result['ok']);
        self::assertSame('incident.error.title_required', $result['error']);
        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `incident`'));
    }

    public function testIncidentPhotosAreNeverVisibleToTheCustomer(): void
    {
        $booking = $this->booking();
        $reported = $this->incidents->report(
            'Trace sur le parquet',
            '',
            IncidentSeverity::Normal,
            $booking->id,
            null,
            'fr',
            $this->manager,
        );

        $incident = $reported['incident'];
        self::assertNotNull($incident);

        self::assertTrue(
            $this->incidents->attachPhoto($incident, $this->jpeg('parquet'), 'parquet.jpg', $this->manager)['ok']
        );

        $documents = $this->documents->forBooking($booking->id);
        self::assertCount(1, $documents);
        self::assertSame(DocumentKind::Incident, $documents[0]->kind);
        self::assertFalse($documents[0]->kind->visibleToCustomer());
    }

    public function testOpenIncidentsAreCounted(): void
    {
        $this->incidents->report('A', '', IncidentSeverity::Normal, null, null, 'fr', $this->manager);
        $second = $this->incidents->report('B', '', IncidentSeverity::Low, null, null, 'fr', $this->manager);

        self::assertSame(2, $this->incidentRepository->countOpen());

        $incident = $second['incident'];
        self::assertNotNull($incident);
        $this->incidents->transition($incident, IncidentStatus::Resolved, '', $this->manager);

        self::assertSame(1, $this->incidentRepository->countOpen());
    }

    // --- Support --------------------------------------------------------------------

    private function reload(Booking $booking): Booking
    {
        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function jpeg(string $seed): string
    {
        $image = imagecreatetruecolor(80, 60);
        // Une couleur par zone : deux photos différentes ne partagent pas la
        // même empreinte, et donc pas le même document.
        // Les trois composantes viennent de l'empreinte du nom de la zone :
        // `ord()` garantit un octet, et deux zones différentes ne produisent
        // donc pas la même image — donc pas le même document.
        $fingerprint = hash('crc32b', $seed);
        $colour = imagecolorallocate(
            $image,
            ord($fingerprint[0]),
            ord($fingerprint[1]),
            ord($fingerprint[2]),
        );
        imagefill($image, 0, 0, $colour === false ? 0 : $colour);

        ob_start();
        imagejpeg($image, null, 90);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
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
            'children' => 0,
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
