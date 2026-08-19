<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Content\ContentRepository;
use SecondStay\Content\ContentSeeder;
use SecondStay\Content\ContentService;
use SecondStay\Content\PageKind;
use SecondStay\Content\Season;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

final class ContentServiceTest extends DatabaseTestCase
{
    private ContentRepository $repository;

    private ContentService $content;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ContentRepository($this->database);
        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->content = new ContentService(
            $this->repository,
            $this->settings,
            new \SecondStay\Support\HtmlSanitizer(),
            new AuditTrail($this->database),
        );
    }

    private function seed(): ContentSeeder
    {
        $seeder = new ContentSeeder(
            $this->repository,
            new Translator(self::projectRoot() . '/translations'),
            $this->database,
        );
        $seeder->seed();

        return $seeder;
    }

    public function testSeedingCreatesTheWholeSiteInFourLanguages(): void
    {
        $created = $this->seed();

        $pages = $this->repository->all();
        self::assertGreaterThanOrEqual(11, count($pages));

        foreach ($pages as $page) {
            foreach (Locales::ALL as $locale) {
                self::assertTrue(
                    $page->hasTranslation($locale),
                    sprintf('La page %s doit être traduite en %s', $page->slug, $locale)
                );
            }
        }

        self::assertTrue($created->hasContent());
    }

    public function testSeedingIsIdempotent(): void
    {
        $this->seed();
        $before = count($this->repository->all());

        $this->seed();

        self::assertCount($before, $this->repository->all());
    }

    public function testSeededTranslationsAreActuallyDifferentPerLocale(): void
    {
        $this->seed();
        $page = $this->repository->findBySlug('property');
        self::assertNotNull($page);

        self::assertSame('Le logement', $page->translation('fr')?->title);
        self::assertSame('The property', $page->translation('en')?->title);
        self::assertSame('De woning', $page->translation('nl')?->title);
        self::assertSame('Das Objekt', $page->translation('de')?->title);
    }

    public function testHomePageIsPublishedAndUnique(): void
    {
        $this->seed();

        $home = $this->content->home();
        self::assertNotNull($home);
        self::assertSame(PageKind::Home, $home->kind);
    }

    public function testMenuTreeSkipsHomeAndUnpublishedPages(): void
    {
        $this->seed();

        $menu = $this->content->menuForView('fr');
        $slugs = array_column($menu, 'slug');

        self::assertNotContains('home', $slugs);
        self::assertContains('property', $slugs);
        self::assertNotContains('legal-notice', $slugs, 'Les pages légales vivent dans le pied de page');

        $property = $this->repository->findBySlug('property');
        self::assertNotNull($property);
        $this->content->updatePage($property->id, ['is_published' => false]);

        self::assertNotContains('property', array_column($this->content->menuForView('fr'), 'slug'));
    }

    public function testMenuSupportsSeveralLevels(): void
    {
        $this->seed();
        $parent = $this->repository->findBySlug('activities');
        self::assertNotNull($parent);

        $childId = $this->content->createPage(['slug' => 'ski', 'kind' => 'page', 'is_published' => true]);
        $this->content->updatePage($childId, ['parent_id' => $parent->id]);
        $this->content->saveTranslations($childId, [
            'fr' => ['title' => 'Ski', 'body' => '<p>Pistes</p>'],
            'en' => ['title' => 'Skiing', 'body' => '<p>Slopes</p>'],
            'nl' => ['title' => 'Skiën', 'body' => '<p>Pistes</p>'],
            'de' => ['title' => 'Ski', 'body' => '<p>Pisten</p>'],
        ]);

        $menu = $this->content->menuForView('en');
        $activities = null;
        foreach ($menu as $item) {
            if ($item['slug'] === 'activities') {
                $activities = $item;
            }
        }

        self::assertNotNull($activities);
        self::assertSame(['ski'], array_column($activities['children'], 'slug'));
        self::assertSame('Skiing', $activities['children'][0]['label']);
    }

    public function testLegalLinksAreExposedSeparately(): void
    {
        $this->seed();

        $slugs = array_column($this->content->legalLinks('de'), 'slug');

        self::assertSame(['legal-notice', 'privacy', 'terms'], $slugs);
    }

    public function testSeasonFiltersPublicPages(): void
    {
        $this->seed();
        $winterId = $this->content->createPage(['slug' => 'winter-only', 'is_published' => true, 'season' => 'winter']);
        $this->content->saveTranslations($winterId, ['fr' => ['title' => 'Hiver', 'body' => '<p>Neige</p>']]);

        $this->settings->setMany(['site.season' => 'summer']);
        $this->settings->refresh();
        self::assertNull($this->content->findPublished('winter-only'));

        $this->settings->setMany(['site.season' => 'winter']);
        $this->settings->refresh();
        self::assertNotNull($this->content->findPublished('winter-only'));
    }

    public function testUnpublishedPagesAreNotServed(): void
    {
        $id = $this->content->createPage(['slug' => 'draft-page', 'is_published' => false]);
        self::assertNull($this->content->findPublished('draft-page'));

        $this->content->updatePage($id, ['is_published' => true]);
        self::assertNotNull($this->content->findPublished('draft-page'));
    }

    public function testSlugsAreNormalised(): void
    {
        self::assertSame('le-logement-ete', $this->content->normaliseSlug('  Le Logement — Été  '));
        self::assertSame('activites-2026', $this->content->normaliseSlug('Activités 2026'));
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $this->content->createPage(['slug' => 'unique-page']);

        $this->expectException(ValidationException::class);
        $this->content->createPage(['slug' => 'unique-page']);
    }

    public function testEmptySlugIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->content->createPage(['slug' => '   ']);
    }

    public function testSystemPagesCannotBeDeleted(): void
    {
        $this->seed();
        $home = $this->repository->findBySlug('home');
        self::assertNotNull($home);

        $this->expectException(ValidationException::class);
        $this->content->deletePage($home->id);
    }

    public function testCustomPagesCanBeDeleted(): void
    {
        $id = $this->content->createPage(['slug' => 'temporary']);
        $this->content->deletePage($id);

        self::assertNull($this->repository->findBySlug('temporary'));
    }

    public function testPageCannotBeItsOwnParent(): void
    {
        $id = $this->content->createPage(['slug' => 'loop-page']);

        $this->expectException(ValidationException::class);
        $this->content->updatePage($id, ['parent_id' => $id]);
    }

    public function testBodyIsSanitisedOnSave(): void
    {
        $id = $this->content->createPage(['slug' => 'xss-page']);
        $this->content->saveTranslations($id, [
            'fr' => ['title' => 'Test', 'body' => '<p>Bonjour</p><script>alert(1)</script>'],
        ]);

        $page = $this->repository->findBySlug('xss-page');
        self::assertNotNull($page);
        $translation = $page->translation('fr');
        self::assertNotNull($translation);
        $body = $translation->body;

        self::assertStringContainsString('Bonjour', $body);
        self::assertStringNotContainsString('alert', $body);
    }

    public function testUnsupportedLocalesAreIgnoredOnSave(): void
    {
        $id = $this->content->createPage(['slug' => 'locale-page']);
        $this->content->saveTranslations($id, [
            'fr' => ['title' => 'Titre', 'body' => '<p>Corps</p>'],
            'es' => ['title' => 'Título', 'body' => '<p>Cuerpo</p>'],
        ]);

        $page = $this->repository->findBySlug('locale-page');
        self::assertNotNull($page);
        self::assertArrayHasKey('fr', $page->translations);
        self::assertArrayNotHasKey('es', $page->translations);
    }

    public function testTranslationOverviewReportsMissingLanguages(): void
    {
        $id = $this->content->createPage(['slug' => 'partial-page']);
        $this->content->saveTranslations($id, [
            'fr' => ['title' => 'Titre', 'body' => '<p>Corps</p>'],
            'en' => ['title' => 'Title', 'body' => ''],
        ]);

        $entry = null;
        foreach ($this->content->translationOverview() as $candidate) {
            if ($candidate['page']->slug === 'partial-page') {
                $entry = $candidate;
            }
        }

        self::assertNotNull($entry);
        self::assertTrue($entry['status']['fr']);
        self::assertFalse($entry['status']['en'], 'Un corps vide n’est pas une traduction complète');
        self::assertFalse($entry['status']['nl']);
        self::assertFalse($entry['complete']);
    }

    public function testTranslationFallbackNeverReturnsARawKey(): void
    {
        $id = $this->content->createPage(['slug' => 'fallback-page', 'is_published' => true]);
        $this->content->saveTranslations($id, ['fr' => ['title' => 'Seulement en français', 'body' => '<p>x</p>']]);

        $page = $this->repository->findBySlug('fallback-page');
        self::assertNotNull($page);

        self::assertSame('Seulement en français', $page->translation('de', Locales::FALLBACK)?->title);
        self::assertFalse($page->hasTranslation('de'));
    }

    public function testContentChangesAreAudited(): void
    {
        $id = $this->content->createPage(['slug' => 'audited-page'], 'owner@example.test', 1);
        $this->content->saveTranslations($id, ['fr' => ['title' => 'X', 'body' => '<p>y</p>']], 'owner@example.test', 1);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');

        self::assertContains('content.page_created', $actions);
        self::assertContains('content.translations_saved', $actions);
    }

    public function testEffectiveSeasonFollowsSettings(): void
    {
        $this->settings->setMany(['site.season' => 'winter']);
        $this->settings->refresh();

        self::assertSame(Season::Winter, $this->content->effectiveSeason());
    }
}
