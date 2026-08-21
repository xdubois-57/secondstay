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
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Calendar\CalendarService;
use SecondStay\Calendar\CalendarTokenRepository;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Stay\GuestLinkRepository;
use SecondStay\Stay\StayInfoRepository;
use SecondStay\Stay\StayPhase;
use SecondStay\Stay\StaySecretRepository;
use SecondStay\Stay\StayService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * « Mon séjour aujourd'hui », livret d'accueil et liens invité.
 *
 * Deux règles portent tout le reste : un code d'accès ne sort que pendant la
 * fenêtre du séjour, et un lien invité ne donne accès qu'aux informations
 * pratiques.
 */
final class StayServiceTest extends DatabaseTestCase
{
    private StayService $stay;

    private StayInfoRepository $blocks;

    private StaySecretRepository $secrets;

    private GuestLinkRepository $links;

    private BookingRepository $bookings;

    private SettingsService $settings;

    private UserRepository $users;

    private User $client;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $encryptor,
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'site.default_locale' => 'fr',
            'site.timezone' => 'Europe/Paris',
            'booking.checkin_time' => '16:00',
            'booking.checkout_time' => '10:00',
        ]);

        $this->blocks = new StayInfoRepository($this->database);
        $this->secrets = new StaySecretRepository($this->database, $encryptor);
        $this->links = new GuestLinkRepository($this->database);
        $this->bookings = new BookingRepository($this->database);
        $this->users = new UserRepository($this->database);

        $calendar = new CalendarService(
            new CalendarTokenRepository($this->database),
            $this->bookings,
            $this->users,
            $this->settings,
            new Translator(self::projectRoot() . '/translations', 'fr'),
            new Formatter(),
        );

        $this->stay = new StayService(
            $this->blocks,
            $this->secrets,
            $this->links,
            $this->bookings,
            $calendar,
            $this->settings,
            new Logger($this->storagePath . '/logs'),
            new AuditTrail($this->database),
        );

        $this->client = $this->createUser('claire@example.test', Role::Customer);
        $this->manager = $this->createUser('marc@example.test', Role::LocalManager);
    }

    // --- Phases -----------------------------------------------------------------

    /**
     * @return list<array{string, StayPhase}>
     */
    public static function days(): array
    {
        return [
            ['2026-07-01', StayPhase::Before],
            ['2026-07-03', StayPhase::Before],
            ['2026-07-04', StayPhase::Arrival],
            ['2026-07-05', StayPhase::During],
            ['2026-07-10', StayPhase::During],
            ['2026-07-11', StayPhase::Departure],
            ['2026-07-12', StayPhase::After],
        ];
    }

    #[DataProvider('days')]
    public function testThePhaseIsDeducedFromTheDay(string $today, StayPhase $expected): void
    {
        $view = $this->stay->forBooking($this->booking(), 'fr', $today);

        self::assertSame($expected, $view->phase);
    }

    public function testTheCountdownIsCountedInWholeDays(): void
    {
        $booking = $this->booking();

        self::assertSame(3, $this->stay->forBooking($booking, 'fr', '2026-07-01')->nightsUntilArrival);
        self::assertSame(1, $this->stay->forBooking($booking, 'fr', '2026-07-03')->nightsUntilArrival);
        self::assertSame(0, $this->stay->forBooking($booking, 'fr', '2026-07-04')->nightsUntilArrival);
        self::assertSame(-1, $this->stay->forBooking($booking, 'fr', '2026-07-05')->nightsUntilArrival);
    }

    // --- Codes d'accès -------------------------------------------------------------

    public function testAccessCodesOnlyAppearDuringTheStay(): void
    {
        $this->secrets->set('wifi_password', 'sapin-2026');
        $this->secrets->set('key_box', '4712');
        $booking = $this->booking();

        // Avant : rien.
        $before = $this->stay->forBooking($booking, 'fr', '2026-07-01');
        self::assertFalse($before->hasSecrets());
        self::assertSame('', $before->secret('wifi_password'));

        // Pendant : les codes sont là.
        $during = $this->stay->forBooking($booking, 'fr', '2026-07-06');
        self::assertTrue($during->hasSecrets());
        self::assertSame('sapin-2026', $during->secret('wifi_password'));
        self::assertSame('4712', $during->secret('key_box'));

        // Le jour de l'arrivée et celui du départ comptent.
        self::assertTrue($this->stay->forBooking($booking, 'fr', '2026-07-04')->hasSecrets());
        self::assertTrue($this->stay->forBooking($booking, 'fr', '2026-07-11')->hasSecrets());

        // Après : plus rien.
        self::assertFalse($this->stay->forBooking($booking, 'fr', '2026-07-12')->hasSecrets());
    }

    public function testAccessCodesAreEncryptedAtRest(): void
    {
        $this->secrets->set('alarm', '90210');

        $rows = $this->database->fetchAll('SELECT * FROM `stay_secret`');
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            $stored = (string) $row['value'];
            self::assertStringNotContainsString('90210', $stored);
            self::assertStringStartsWith('ss1.', $stored, 'La valeur doit être chiffrée.');
        }

        self::assertSame('90210', $this->secrets->get('alarm'));
    }

    public function testAnUnreadableSecretDoesNotBreakTheStayPage(): void
    {
        $this->secrets->set('gate', '1234');
        // Le contenu chiffré est corrompu, comme après une rotation ratée.
        $this->database->update('stay_secret', ['value' => 'ss1.k1.abc.def'], ['code' => 'gate']);

        self::assertSame('', $this->secrets->get('gate'));

        $view = $this->stay->forBooking($this->booking(), 'fr', '2026-07-06');
        self::assertFalse($view->hasSecrets());
    }

    public function testTheAdminPreviewNeverShowsTheWholeSecret(): void
    {
        $this->secrets->set('wifi_password', 'sapin-2026');

        $preview = $this->secrets->preview('wifi_password');

        self::assertNotSame('sapin-2026', $preview);
        self::assertStringEndsWith('2026', $preview);
        self::assertStringContainsString('•', $preview);
    }

    public function testAnUnknownSecretCodeIsIgnored(): void
    {
        $this->secrets->set('code_invente', 'valeur');

        self::assertSame('', $this->secrets->get('code_invente'));
        self::assertSame([], $this->database->fetchAll('SELECT * FROM `stay_secret`'));
    }

    // --- Livret d'accueil ---------------------------------------------------------------

    public function testBlocksAreShownInTheStayLanguage(): void
    {
        $this->blocks->save('wifi', 'fr', 'Wi-Fi', 'Le réseau s’appelle Chalet.');
        $this->blocks->save('wifi', 'de', 'WLAN', 'Das Netz heißt Chalet.');

        $french = $this->stay->forBooking($this->booking(), 'fr', '2026-07-06')->visibleBlocks();
        $german = $this->stay->forBooking($this->booking(), 'de', '2026-07-06')->visibleBlocks();

        self::assertSame('Wi-Fi', $french[0]->title);
        self::assertSame('WLAN', $german[0]->title);
    }

    public function testAMissingTranslationFallsBackRatherThanDisappearing(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au bout de la rue.');

        $blocks = $this->stay->forBooking($this->booking(), 'nl', '2026-07-06')->visibleBlocks();

        self::assertCount(1, $blocks);
        self::assertSame('Déchets', $blocks[0]->title, 'Mieux vaut le texte français qu’aucun texte.');
    }

    public function testAnUnpublishedOrEmptyBlockIsNotShown(): void
    {
        $this->blocks->save('rules', 'fr', 'Règles', 'Pas de fête.', false);
        $this->blocks->save('safety', 'fr', '', '');
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Faites comme chez vous.');

        $codes = array_map(
            static fn (object $block): string => $block->code,
            $this->stay->forBooking($this->booking(), 'fr', '2026-07-06')->visibleBlocks()
        );

        self::assertSame(['welcome'], $codes);
    }

    public function testABlockOnlyAppearsInItsPhase(): void
    {
        $this->blocks->save('access', 'fr', 'Entrer', 'La boîte à clés est à gauche.');
        $this->blocks->save('checkout', 'fr', 'Départ', 'Laissez les clés dans la boîte.');
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Bonjour.');

        $codes = fn (string $day): array => array_map(
            static fn (object $block): string => $block->code,
            $this->stay->forBooking($this->booking(), 'fr', $day)->visibleBlocks()
        );

        self::assertSame(['welcome', 'access'], $codes('2026-07-04'));
        self::assertSame(['welcome'], $codes('2026-07-06'));
        self::assertSame(['welcome', 'checkout'], $codes('2026-07-11'));
    }

    public function testCompletenessNamesTheMissingLanguages(): void
    {
        $this->blocks->save('wifi', 'fr', 'Wi-Fi', 'Réseau Chalet.');
        $this->blocks->save('wifi', 'en', 'Wi-Fi', 'Network Chalet.');

        $state = $this->blocks->completeness();

        self::assertSame(['fr', 'en'], $state['wifi']);
        self::assertSame([], $state['waste']);
    }

    public function testSavingTwiceUpdatesRatherThanDuplicates(): void
    {
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Premier texte.');
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Second texte.');

        $rows = $this->database->fetchAll('SELECT * FROM `stay_info` WHERE `code` = :code', ['code' => 'welcome']);

        self::assertCount(1, $rows);
        self::assertSame('Second texte.', (string) $rows[0]['body']);
    }

    // --- Liens invité ---------------------------------------------------------------------

    public function testAGuestLinkOpensTheStayWithoutAnAccount(): void
    {
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Bonjour.');
        [$booking, $during] = $this->upcomingStay();

        $issued = $this->stay->issueGuestLink($booking, 'fr', 'Les cousins', $this->client);
        self::assertTrue($issued['ok'], $issued['error']);

        $view = $this->stay->forGuestToken($issued['token'], 'fr', $during);

        self::assertNotNull($view);
        self::assertTrue($view->isGuest);
        self::assertSame($booking->reference, $view->booking->reference);
        self::assertNotSame([], $view->visibleBlocks());
    }

    public function testAGuestLinkStillHidesCodesOutsideTheStay(): void
    {
        $this->secrets->set('key_box', '4712');
        [$booking, $during, $before] = $this->upcomingStay();

        $issued = $this->stay->issueGuestLink($booking, 'fr');

        self::assertFalse($this->stay->forGuestToken($issued['token'], 'fr', $before)?->hasSecrets());
        self::assertTrue($this->stay->forGuestToken($issued['token'], 'fr', $during)?->hasSecrets());
    }

    /**
     * L'expiration est évaluée en base : une horloge d'appareil faussée ne
     * doit pas pouvoir prolonger un lien.
     */
    public function testAGuestLinkExpiresShortlyAfterTheDeparture(): void
    {
        $booking = $this->booking();

        self::assertSame('2026-07-13 23:59:59', $this->stay->guestLinkExpiry($booking));

        $issued = $this->links->issue($booking->id, '2026-07-13 23:59:59', 'fr');
        $link = $this->links->forBooking($booking->id)[0];

        self::assertFalse($link->isExpired('2026-07-13 12:00:00'));
        self::assertTrue($link->isExpired('2026-07-14 00:00:01'));

        self::assertNull($this->links->findUsable($issued['token'], '2026-07-14 00:00:01'));
        self::assertNotNull($this->links->findUsable($issued['token'], '2026-07-13 12:00:00'));
    }

    public function testAnAlreadyExpiredLinkIsUnusableEvenBeforeRevocation(): void
    {
        // Un séjour passé produit un lien déjà caduc : il ne doit rien ouvrir.
        $booking = $this->booking();
        $issued = $this->stay->issueGuestLink($booking, 'fr');

        self::assertTrue($issued['ok']);
        self::assertNull($this->stay->forGuestToken($issued['token'], 'fr'));
    }

    public function testARevokedGuestLinkStopsWorkingImmediately(): void
    {
        [$booking, $during] = $this->upcomingStay();
        $issued = $this->stay->issueGuestLink($booking, 'fr');

        self::assertNotNull($this->stay->forGuestToken($issued['token'], 'fr', $during));

        $link = $this->links->forBooking($booking->id)[0];
        self::assertTrue($this->stay->revokeGuestLink($link->id, $this->client)['ok']);

        self::assertNull($this->stay->forGuestToken($issued['token'], 'fr', $during));
    }

    public function testAGuestTokenIsNeverStoredInClear(): void
    {
        [$booking] = $this->upcomingStay();
        $issued = $this->stay->issueGuestLink($booking, 'fr');

        foreach ($this->database->fetchAll('SELECT * FROM `guest_link`') as $row) {
            self::assertNotSame($issued['token'], (string) $row['token_hash']);
            self::assertSame(hash('sha256', $issued['token']), (string) $row['token_hash']);
        }
    }

    public function testACancelledStayIssuesNoGuestLink(): void
    {
        [$booking] = $this->upcomingStay();
        $this->bookings->update($booking->id, ['status' => BookingStatus::Cancelled->value]);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);

        $result = $this->stay->issueGuestLink($reloaded, 'fr');

        self::assertFalse($result['ok']);
        self::assertSame('stay.error.not_active', $result['error']);
        self::assertSame([], $this->links->forBooking($booking->id));
    }

    public function testAnUnknownTokenOpensNothing(): void
    {
        self::assertNull($this->stay->forGuestToken(str_repeat('0', 64), 'fr'));
        self::assertNull($this->stay->forGuestToken('', 'fr'));
    }

    public function testUsingAGuestLinkRecordsItsLastUse(): void
    {
        [$booking, $during] = $this->upcomingStay();
        $issued = $this->stay->issueGuestLink($booking, 'fr');

        self::assertNull($this->links->forBooking($booking->id)[0]->lastUsedAt);

        $this->stay->forGuestToken($issued['token'], 'fr', $during);

        self::assertNotNull($this->links->forBooking($booking->id)[0]->lastUsedAt);
    }

    public function testTheGuestLinkKeepsItsOwnLanguage(): void
    {
        $this->blocks->save('welcome', 'fr', 'Bienvenue', 'Bonjour.');
        $this->blocks->save('welcome', 'de', 'Willkommen', 'Guten Tag.');

        [$booking, $during] = $this->upcomingStay();
        $issued = $this->stay->issueGuestLink($booking, 'de');
        $view = $this->stay->forGuestToken($issued['token'], null, $during);

        self::assertNotNull($view);
        self::assertSame('de', $view->locale);
        self::assertSame('Willkommen', $view->visibleBlocks()[0]->title);
    }

    public function testExpiredLinksArePurged(): void
    {
        $booking = $this->booking();
        $this->stay->issueGuestLink($booking, 'fr');

        self::assertSame(0, $this->links->purgeExpiredBefore('2026-01-01 00:00:00'));
        self::assertSame(1, $this->links->purgeExpiredBefore('2027-01-01 00:00:00'));
        self::assertSame([], $this->links->forBooking($booking->id));
    }

    // --- Contact local ------------------------------------------------------------------------

    public function testTheLocalManagerIsCarriedToTheStayPage(): void
    {
        $booking = $this->booking();
        $this->bookings->update($booking->id, ['manager_id' => $this->manager->id]);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);

        $view = $this->stay->forBooking($reloaded, 'fr', '2026-07-06');

        self::assertNotNull($view->manager);
        self::assertSame('marc@example.test', $view->manager->email);
    }

    // --- Outils --------------------------------------------------------------------------------

    /**
     * Séjour à venir, avec un jour « pendant » et un jour « avant ».
     *
     * Un lien invité expire peu après le départ : un séjour passé produirait
     * un lien déjà caduc, ce qui n'est pas ce que ces cas veulent éprouver.
     *
     * @return array{Booking, string, string}
     */
    private function upcomingStay(): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $arrival = $today->modify('+10 days');
        $departure = $arrival->modify('+7 days');

        return [
            $this->booking($arrival->format('Y-m-d'), $departure->format('Y-m-d')),
            $arrival->modify('+2 days')->format('Y-m-d'),
            $today->format('Y-m-d'),
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
            self::passwordHash('Marée-Haute-2026!'),
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
