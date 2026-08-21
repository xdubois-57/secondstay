<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Logging\LogLevel;
use SecondStay\Logging\LogRepository;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Lecture du journal applicatif (SPECIFICATIONS.md §16).
 *
 * Deux lecteurs posent des questions différentes à la même table : l'écran des
 * journaux pagine et filtre, le tableau « À faire » compte les erreurs
 * récentes. Les laisser écrire chacun leur SQL les ferait dériver le jour où
 * la table changera — d'où ce dépôt, et d'où ces tests.
 */
final class LogRepositoryTest extends DatabaseTestCase
{
    private LogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new LogRepository($this->database);
    }

    /**
     * Une panne critique compte parmi les erreurs : sans cela, le tableau
     * « À faire » resterait vide au pire moment.
     */
    public function testCountingErrorsIncludesEverythingAtLeastAsSevere(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->entry(LogLevel::Debug, $now);
        $this->entry(LogLevel::Info, $now);
        $this->entry(LogLevel::Warning, $now);
        $this->entry(LogLevel::Error, $now);
        $this->entry(LogLevel::Critical, $now);

        $since = gmdate('Y-m-d H:i:s', time() - 3600);

        self::assertSame(2, $this->logs->countAtLeast(LogLevel::Error, $since));
        self::assertSame(3, $this->logs->countAtLeast(LogLevel::Warning, $since));
        self::assertSame(5, $this->logs->countAtLeast(LogLevel::Debug, $since));
    }

    public function testEntriesOlderThanTheWindowAreNotCounted(): void
    {
        $this->entry(LogLevel::Error, gmdate('Y-m-d H:i:s', time() - 3 * 86400));

        self::assertSame(0, $this->logs->countAtLeast(LogLevel::Error, gmdate('Y-m-d H:i:s', time() - 3600)));
    }

    public function testThePageIsOrderedNewestFirstAndBounded(): void
    {
        for ($index = 0; $index < 7; $index++) {
            $this->entry(LogLevel::Info, gmdate('Y-m-d H:i:s'), 'entrée ' . $index);
        }

        $first = $this->logs->page([], 1, 3);
        self::assertSame(7, $first['total']);
        self::assertCount(3, $first['entries']);
        self::assertSame('entrée 6', $first['entries'][0]['message']);

        $last = $this->logs->page([], 3, 3);
        self::assertCount(1, $last['entries']);
        self::assertSame('entrée 0', $last['entries'][0]['message']);
    }

    public function testFiltersNarrowTheSameQuery(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->entry(LogLevel::Error, $now, 'échec de paiement', 'payment');
        $this->entry(LogLevel::Info, $now, 'paiement encaissé', 'payment');
        $this->entry(LogLevel::Info, $now, 'connexion réussie', 'auth');

        self::assertSame(1, $this->logs->page(['level' => 'error'], 1, 50)['total']);
        self::assertSame(2, $this->logs->page(['category' => 'payment'], 1, 50)['total']);
        self::assertSame(1, $this->logs->page(['q' => 'connexion'], 1, 50)['total']);
        self::assertSame(2, $this->logs->page(['category' => 'payment', 'q' => 'paiement'], 1, 50)['total']);
    }

    public function testAnUnknownLevelIsIgnoredRatherThanEmptyingTheList(): void
    {
        $this->entry(LogLevel::Info, gmdate('Y-m-d H:i:s'));

        self::assertSame(1, $this->logs->page(['level' => 'catastrophe'], 1, 50)['total']);
    }

    /**
     * Un joker saisi par l'humain n'est pas un joker SQL : chercher « 100 % »
     * doit chercher « 100 % », pas tout le journal.
     */
    public function testUserWildcardsAreNotInterpretedBySql(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->entry(LogLevel::Info, $now, 'quota atteint à 100 %');
        $this->entry(LogLevel::Info, $now, 'sauvegarde terminée');

        self::assertSame(1, $this->logs->page(['q' => '100 %'], 1, 50)['total']);
        self::assertSame(0, $this->logs->page(['q' => '%%%'], 1, 50)['total']);
        self::assertSame(0, $this->logs->page(['q' => '_auvegarde'], 1, 50)['total']);
    }

    public function testCategoriesAreListedOnceAndSorted(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->entry(LogLevel::Info, $now, 'a', 'payment');
        $this->entry(LogLevel::Info, $now, 'b', 'payment');
        $this->entry(LogLevel::Info, $now, 'c', 'auth');

        self::assertSame(['auth', 'payment'], $this->logs->categories());
    }

    private function entry(
        LogLevel $level,
        string $at,
        string $message = 'entrée de test',
        string $category = 'test',
    ): void {
        $this->database->insert('app_log', [
            'created_at' => $at,
            'level' => $level->value,
            'category' => $category,
            'message' => $message,
            'context' => null,
            'user_id' => null,
            'correlation_id' => 'test',
        ]);
    }
}
