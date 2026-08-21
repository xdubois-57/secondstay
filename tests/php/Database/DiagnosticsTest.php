<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Core\Paths;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Core\View;
use SecondStay\Diagnostics\DiagnosticResult;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Diagnostics\DiagnosticStatus;
use SecondStay\Diagnostics\MailDnsChecker;
use SecondStay\Diagnostics\MailboxDiagnostics;
use SecondStay\Diagnostics\NotificationDiagnostics;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Imap\FakeImapProvider;
use SecondStay\Logging\Logger;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Diagnostics d'installation (SPECIFICATIONS.md §18).
 *
 * Deux promesses tiennent cet écran, et elles sont faciles à casser sans s'en
 * apercevoir :
 *
 * 1. **aucun secret n'y apparaît.** On y dit qu'une configuration existe et
 *    qu'elle fonctionne, jamais ce qu'elle vaut. Un mot de passe SMTP affiché
 *    « pour aider au diagnostic » est un mot de passe publié ;
 * 2. **aucune connexion sortante n'est ouverte à l'affichage.** La sonde SMTP
 *    et la sonde IMAP existent, mais se déclenchent sur demande explicite :
 *    une page de diagnostics qui ouvre trois connexions à chaque visite finit
 *    par ne plus être consultée.
 */
final class DiagnosticsTest extends DatabaseTestCase
{
    private SettingsService $settings;

    private FakeMailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );

        $this->transport = new FakeMailTransport();
    }

    // --- Runner ---------------------------------------------------------------------

    public function testTheRunnerReportsThePlatformTheStorageAndTheDatabase(): void
    {
        $results = $this->runner()->run();

        $ids = array_map(static fn (DiagnosticResult $r): string => $r->id, $results);

        self::assertContains('php_version', $ids);
        self::assertContains('storage_backups', $ids);
        self::assertContains('database_connection', $ids);
        self::assertContains('crypto_sodium', $ids);
        self::assertContains('maintenance_mode', $ids);
    }

    public function testTheSummaryCountsWhatTheScreenShows(): void
    {
        $runner = $this->runner();
        $summary = $runner->summary();

        $expected = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($runner->run() as $result) {
            if ($result->status !== DiagnosticStatus::NotApplicable) {
                $expected[$result->status->value]++;
            }
        }

        self::assertSame($expected, $summary);
    }

    public function testAnInstallationWithoutADatabaseSaysSoRatherThanCrashing(): void
    {
        $runner = new DiagnosticRunner(
            $this->paths,
            null,
            null,
            new MaintenanceMode($this->storagePath . '/maintenance.json'),
            '0.15.0',
        );

        self::assertSame(DiagnosticStatus::Error, $this->find($runner->run(), 'database_connection')->status);
    }

    public function testMaintenanceIsReportedWhileItIsActive(): void
    {
        $maintenance = new MaintenanceMode($this->storagePath . '/maintenance.json');
        $maintenance->enable('mise à jour');

        $runner = new DiagnosticRunner($this->paths, $this->database, $this->settings, $maintenance, '0.15.0');

        self::assertSame(DiagnosticStatus::Warning, $this->find($runner->run(), 'maintenance_mode')->status);
    }

    /**
     * Les contrôles des itérations suivantes s'enregistrent : le runner ne
     * connaît ni SMTP, ni IMAP, ni le planificateur par lui-même.
     */
    public function testRegisteredChecksAreAppendedToTheRun(): void
    {
        $runner = $this->runner();
        $runner->register(static fn (): array => [
            new DiagnosticResult('essai', 'operations', DiagnosticStatus::Ok, 'diagnostics.ok'),
        ]);

        self::assertSame('essai', $this->find($runner->run(), 'essai')->id);
    }

    // --- Notifications --------------------------------------------------------------

    public function testAMissingSenderAddressIsAWarningNotAnError(): void
    {
        $results = ($this->notifications())();

        self::assertSame(DiagnosticStatus::Warning, $this->find($results, 'mail_sender')->status);
        self::assertSame('diagnostics.mail.sender_missing', $this->find($results, 'mail_sender')->messageKey);
    }

    /**
     * Sans sonde explicite, un serveur SMTP configuré est déclaré configuré —
     * pas « joignable ». La nuance évite d'ouvrir une connexion sortante à
     * chaque affichage de la page.
     */
    public function testAConfiguredSmtpIsNotProbedOnDisplay(): void
    {
        $this->settings->setMany([
            'mail.from_address' => 'noreply@example.test',
            'mail.smtp_host' => 'smtp.example.test',
            'mail.smtp_password' => 'Mot-de-passe-SMTP-de-test',
        ]);

        $results = ($this->notifications())();
        $smtp = $this->find($results, 'mail_smtp');

        self::assertSame(DiagnosticStatus::Ok, $smtp->status);
        self::assertSame('diagnostics.mail.smtp_configured', $smtp->messageKey);
        self::assertSame([], $this->transport->messages(), 'Aucune connexion ne doit être ouverte.');
    }

    public function testNoSmtpSecretEverAppearsInTheResults(): void
    {
        $this->settings->setMany([
            'mail.from_address' => 'noreply@example.test',
            'mail.smtp_host' => 'smtp.example.test',
            'mail.smtp_username' => 'expediteur',
            'mail.smtp_password' => 'Mot-de-passe-SMTP-de-test',
        ]);

        self::assertStringNotContainsString(
            'Mot-de-passe-SMTP-de-test',
            $this->flatten(($this->notifications())())
        );
    }

    public function testTheDnsReportBecomesThreeSeparateChecks(): void
    {
        $this->settings->setMany([
            'mail.from_address' => 'noreply@example.test',
            'mail.dkim_selector' => 'ss',
        ]);

        $results = ($this->notifications())();

        self::assertSame(DiagnosticStatus::Ok, $this->find($results, 'mail_spf')->status);
        self::assertSame(DiagnosticStatus::Warning, $this->find($results, 'mail_dmarc')->status);
        self::assertSame('diagnostics.mail.dmarc_weak', $this->find($results, 'mail_dmarc')->messageKey);
    }

    public function testPushDisabledIsNotApplicableAndPushWithoutKeysIsAnError(): void
    {
        $disabled = $this->find(($this->notifications())(), 'push');
        self::assertSame(DiagnosticStatus::NotApplicable, $disabled->status);

        $this->settings->setMany(['notification.push_enabled' => '1']);
        self::assertSame(DiagnosticStatus::Error, $this->find(($this->notifications())(), 'push')->status);

        self::assertSame(
            DiagnosticStatus::Ok,
            $this->find(($this->notifications(new FakePushProvider('cle-publique')))(), 'push')->status
        );
    }

    // --- Boîte de réception ---------------------------------------------------------

    public function testAMailboxLeftDisabledIsNotApplicable(): void
    {
        $results = ($this->mailbox())();

        self::assertCount(1, $results);
        self::assertSame('imap_enabled', $results[0]->id);
        self::assertSame(DiagnosticStatus::NotApplicable, $results[0]->status);
    }

    /**
     * L'adresse de réponse doit accepter le sous-adressage : sans elle, le
     * rattachement repose sur des en-têtes que certains logiciels perdent.
     */
    public function testAMailboxWithoutAReplyAddressIsReportedAsIncomplete(): void
    {
        $this->settings->setMany(['imap.enabled' => '1', 'imap.mailbox' => 'INBOX']);

        $results = ($this->mailbox())();

        self::assertSame(DiagnosticStatus::Ok, $this->find($results, 'imap_configured')->status);
        self::assertSame(DiagnosticStatus::Warning, $this->find($results, 'imap_reply_address')->status);
    }

    public function testTheImapConnectionIsOnlyOpenedWhenTheProbeIsAskedFor(): void
    {
        $this->settings->setMany([
            'imap.enabled' => '1',
            'imap.reply_address' => 'sejours@example.test',
        ]);

        $withoutProbe = array_map(
            static fn (DiagnosticResult $r): string => $r->id,
            ($this->mailbox(probe: false))()
        );
        self::assertNotContains('imap_connection', $withoutProbe);

        $withProbe = ($this->mailbox(probe: true))();
        self::assertSame(DiagnosticStatus::Ok, $this->find($withProbe, 'imap_connection')->status);
    }

    // --- Outils ---------------------------------------------------------------------

    private function runner(): DiagnosticRunner
    {
        return new DiagnosticRunner(
            $this->paths,
            $this->database,
            $this->settings,
            new MaintenanceMode($this->storagePath . '/maintenance.json'),
            '0.15.0',
        );
    }

    private function notifications(?FakePushProvider $push = null): NotificationDiagnostics
    {
        $router = new Router();
        Routes::register($router);
        $translator = new Translator(self::projectRoot() . '/translations', 'fr');
        $logger = new Logger($this->storagePath . '/logs');

        return new NotificationDiagnostics(
            $this->settings,
            new MailService(
                $this->transport,
                new View(self::projectRoot() . '/templates', $translator, new Formatter(), $router),
                $translator,
                $this->settings,
                new MailRepository($this->database),
                $logger,
            ),
            $this->dns(),
            $push ?? new FakePushProvider(''),
            new PushSubscriptionRepository($this->database),
        );
    }

    private function mailbox(bool $probe = false): MailboxDiagnostics
    {
        return new MailboxDiagnostics(
            $this->settings,
            new FakeImapProvider($this->storagePath . '/mailbox'),
            $probe,
        );
    }

    /**
     * Zone DNS injectée : le produit garde sa logique, seule la résolution
     * est fournie par le test.
     */
    private function dns(): MailDnsChecker
    {
        $zone = [
            'example.test' => ['v=spf1 include:_spf.example.test -all'],
            '_dmarc.example.test' => ['v=DMARC1; p=none'],
            'ss._domainkey.example.test' => ['v=DKIM1; k=rsa; p=MIGf'],
        ];

        return new MailDnsChecker(
            static function (string $host, int $type) use ($zone): array|false {
                if ($type !== DNS_TXT || !isset($zone[$host])) {
                    return false;
                }

                return array_map(static fn (string $txt): array => ['txt' => $txt], $zone[$host]);
            }
        );
    }

    /**
     * @param list<DiagnosticResult> $results
     */
    private function find(array $results, string $id): DiagnosticResult
    {
        foreach ($results as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        self::fail('Diagnostic absent : ' . $id);
    }

    /**
     * @param list<DiagnosticResult> $results
     */
    private function flatten(array $results): string
    {
        return (string) json_encode(
            array_map(static fn (DiagnosticResult $r): array => $r->toArray(), $results),
            JSON_UNESCAPED_UNICODE
        );
    }
}
