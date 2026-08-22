<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\Scheduler;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;
use SecondStay\Update\FakeReleaseProvider;
use SecondStay\Update\ReleaseProvider;
use SecondStay\Update\UpdateService;

/**
 * Déclenchement HTTP des tâches périodiques (SECURITY.md §38).
 *
 * Cette route est la seule porte du produit qui exécute du travail sans
 * session. Elle n'existe que parce qu'une partie des hébergements mutualisés
 * ne propose de cron que par URL — et une installation sans tâches
 * périodiques se dégrade sans que personne le voie.
 *
 * Ce qui est vérifié ici est donc entièrement défensif : fermée par défaut,
 * muette sur sa propre existence, et limitée d'une façon qui gêne réellement
 * celui qui essaie.
 */
final class SchedulerHttpTriggerTest extends InstalledAppTestCase
{
    private const TOKEN = 'jeton-de-test-suffisamment-long-pour-etre-accepte';

    protected function setUp(): void
    {
        parent::setUp();

        // Le contrôle de mise à jour interroge GitHub : la suite ne doit
        // dépendre d'aucun service en ligne (TESTING.md §7).
        $this->container->set(
            ReleaseProvider::class,
            static fn (): ReleaseProvider => new FakeReleaseProvider()
        );
        $this->container->forget(UpdateService::class);
    }

    public function testWithoutATokenTheRouteDoesNotExist(): void
    {
        $response = $this->request('/tasks/run', 'GET', [], [], [], ['token' => 'peu-importe']);

        self::assertSame(404, $response->status());
    }

    public function testWithATokenRegisteredAWrongTokenIsIndistinguishableFromNoRoute(): void
    {
        $this->registerToken();

        $absent = $this->request('/tasks/run');
        $wrong = $this->request('/tasks/run', 'GET', [], [], [], ['token' => str_repeat('x', 48)]);

        self::assertSame(404, $absent->status());
        self::assertSame(404, $wrong->status());
        self::assertSame($absent->content(), $wrong->content());
    }

    /**
     * Un jeton court n'est pas un jeton : il est refusé à la saisie, et la
     * route reste donc fermée.
     */
    public function testATokenTooShortIsRefusedBeforeItCanOpenAnything(): void
    {
        $short = str_repeat('a', Scheduler::MINIMUM_TOKEN_LENGTH - 1);

        $result = (new \SecondStay\Settings\SettingValidator())->validate(
            (new SettingRegistry())->get('scheduler.http_token'),
            $short
        );

        self::assertFalse($result['ok']);
        self::assertSame('settings.error.token_too_short', $result['error']);
    }

    public function testTheRightTokenRunsWhatIsDue(): void
    {
        $this->registerToken();

        $response = $this->request('/tasks/run', 'GET', [], [], [], ['token' => self::TOKEN]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('booking_holds ok', $response->content());

        // Les tâches ont réellement tourné, pas seulement répondu.
        self::assertNotNull((new TaskStateRepository($this->database))->lastSuccessfulRun());
    }

    /**
     * Un second appel immédiat ne relance rien : l'intervalle des tâches est
     * porté par le produit, pas par la fréquence du cron de l'hébergeur.
     */
    public function testASecondImmediateCallRunsNothing(): void
    {
        $this->registerToken();

        $this->request('/tasks/run', 'GET', [], [], [], ['token' => self::TOKEN]);
        $second = $this->request('/tasks/run', 'GET', [], [], [], ['token' => self::TOKEN]);

        self::assertSame(200, $second->status());
        self::assertSame("nothing due\n", $second->content());
    }

    /**
     * Le balayage essaie un jeton différent à chaque coup : c'est bien
     * l'appelant qui doit être compté, sinon la limitation ne limite rien.
     */
    public function testScanningWithDifferentTokensIsRateLimited(): void
    {
        $this->registerToken();

        $statuses = [];
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $statuses[] = $this->request(
                '/tasks/run',
                'GET',
                [],
                ['REMOTE_ADDR' => '203.0.113.7'],
                [],
                ['token' => 'essai-numero-' . $attempt . str_repeat('z', 40)]
            )->status();
        }

        self::assertContains(429, $statuses, 'Un balayage doit finir par être refusé.');
    }

    /**
     * Un cron légitime appelle l'URL très souvent : il ne doit pas se limiter
     * lui-même. Un appel valide remet donc le compteur à zéro.
     */
    public function testALegitimateCronIsNeverRateLimitedByItsOwnCalls(): void
    {
        $this->registerToken();
        $states = new TaskStateRepository($this->database);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            // Chaque appel est valide ; l'intervalle empêche les tâches de
            // retourner, mais la route doit continuer de répondre 200.
            $response = $this->request(
                '/tasks/run',
                'GET',
                [],
                ['REMOTE_ADDR' => '203.0.113.8'],
                [],
                ['token' => self::TOKEN]
            );

            self::assertSame(200, $response->status(), 'Appel ' . $attempt);
        }

        self::assertSame('ok', $states->state(ScheduledTask::BookingHolds)->lastStatus);
    }

    private function registerToken(): void
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $this->container->get(Encryptor::class),
        );
        $settings->setMany(['scheduler.http_token' => self::TOKEN]);
    }
}
