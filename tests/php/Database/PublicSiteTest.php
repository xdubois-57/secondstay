<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Content\ContentRepository;
use SecondStay\Content\ContentService;
use SecondStay\I18n\Locales;
use SecondStay\Seo\SeoBuilder;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;

/**
 * Site public rendu à partir des contenus éditoriaux.
 */
final class PublicSiteTest extends InstalledAppTestCase
{
    /**
     * @return list<array{string, string, string}>
     */
    public static function localisedPages(): array
    {
        return [
            ['fr', '/fr/property', 'Le logement'],
            ['en', '/en/property', 'The property'],
            ['nl', '/nl/property', 'De woning'],
            ['de', '/de/property', 'Das Objekt'],
            ['fr', '/fr/rates', 'Tarifs et conditions'],
            ['de', '/de/rates', 'Preise und Bedingungen'],
            ['nl', '/nl/access', 'Hoe u ons bereikt'],
            ['en', '/en/terms', 'Rental terms and conditions'],
        ];
    }

    #[DataProvider('localisedPages')]
    public function testEditorialPagesAreServedInEveryLocale(string $locale, string $path, string $expected): void
    {
        $response = $this->request($path);

        self::assertSame(200, $response->status());
        self::assertStringContainsString($expected, $response->content());
        self::assertStringContainsString('<html lang="' . $locale . '"', $response->content());
    }

    public function testHomeUsesTheEditorialContent(): void
    {
        $content = $this->request('/fr/')->content();

        self::assertStringContainsString('Bienvenue', $content);
        self::assertStringContainsString('data-testid="home-body"', $content);
    }

    public function testMenuIsBuiltFromPublishedPages(): void
    {
        $content = $this->request('/de/')->content();

        self::assertStringContainsString('data-menu-slug="property"', $content);
        self::assertStringContainsString('Das Objekt', $content);
        self::assertStringNotContainsString('data-menu-slug="legal-notice"', $content);
    }

    public function testLegalPagesAreLinkedInTheFooter(): void
    {
        $content = $this->request('/nl/')->content();

        self::assertStringContainsString('data-legal-slug="legal-notice"', $content);
        self::assertStringContainsString('data-legal-slug="privacy"', $content);
        self::assertStringContainsString('data-legal-slug="terms"', $content);
    }

    public function testUnpublishedPageReturns404(): void
    {
        $repository = new ContentRepository($this->database);
        $page = $repository->findBySlug('activities');
        self::assertNotNull($page);
        $repository->update($page->id, ['is_published' => 0]);

        self::assertSame(404, $this->request('/fr/activities')->status());
    }

    public function testSeasonalPageIsHiddenOutOfSeason(): void
    {
        $content = $this->container->get(ContentService::class);
        $id = $content->createPage(['slug' => 'winter-sports', 'is_published' => true, 'season' => 'winter']);
        $content->saveTranslations($id, ['fr' => ['title' => 'Sports d’hiver', 'body' => '<p>Ski</p>']]);

        $settings = $this->container->get(SettingsService::class);
        $settings->setMany(['site.season' => 'summer']);
        $settings->refresh();
        self::assertSame(404, $this->request('/fr/winter-sports')->status());

        $settings->setMany(['site.season' => 'winter']);
        $settings->refresh();
        self::assertSame(200, $this->request('/fr/winter-sports')->status());
    }

    public function testFallbackNoticeIsShownForUntranslatedPages(): void
    {
        $content = $this->container->get(ContentService::class);
        $id = $content->createPage(['slug' => 'french-only', 'is_published' => true]);
        $content->saveTranslations($id, ['fr' => ['title' => 'Seulement en français', 'body' => '<p>Texte</p>']]);

        $french = $this->request('/fr/french-only');
        self::assertStringNotContainsString('data-testid="fallback-notice"', $french->content());

        $german = $this->request('/de/french-only');
        self::assertSame(200, $german->status());
        self::assertStringContainsString('data-testid="fallback-notice"', $german->content());
        self::assertStringContainsString('Seulement en français', $german->content());
    }

    public function testCanonicalAndHreflangAreEmittedForEveryPage(): void
    {
        $content = $this->request('/nl/rates')->content();

        self::assertStringContainsString('<link rel="canonical" href="/nl/rates">', $content);
        foreach (Locales::ALL as $locale) {
            self::assertStringContainsString('hreflang="' . $locale . '" href="/' . $locale . '/rates"', $content);
        }
    }

    public function testHomeExposesStructuredData(): void
    {
        $this->container->get(SettingsService::class)->setMany([
            'property.name' => 'Maison des Pins',
            'property.city' => 'Saint-Étienne-de-Tinée',
            'property.postal_code' => '06660',
        ]);
        $this->container->get(SettingsService::class)->refresh();

        $content = $this->request('/fr/')->content();

        self::assertStringContainsString('application/ld+json', $content);
        self::assertStringContainsString('LodgingBusiness', $content);
        self::assertStringContainsString('Maison des Pins', $content);
    }

    public function testSitemapListsEveryPageInEveryLocale(): void
    {
        $this->container->get(SettingsService::class)->setMany(['site.public_url' => 'https://example.test']);
        $this->container->get(SettingsService::class)->refresh();

        $response = $this->request('/sitemap.xml');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/xml', $response->headers()['content-type']);

        $xml = $response->content();
        foreach (Locales::ALL as $locale) {
            self::assertStringContainsString('https://example.test/' . $locale . '/', $xml);
            self::assertStringContainsString('https://example.test/' . $locale . '/property', $xml);
        }
        self::assertStringContainsString('hreflang="x-default"', $xml);
    }

    public function testRobotsProtectsPrivateAreas(): void
    {
        $this->container->get(SettingsService::class)->setMany(['site.public_url' => 'https://example.test']);
        $this->container->get(SettingsService::class)->refresh();

        $body = $this->request('/robots.txt')->content();

        self::assertStringContainsString('Disallow: /admin', $body);
        self::assertStringContainsString('Disallow: /login', $body);
        self::assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $body);
    }

    public function testSeoBuilderProducesAbsoluteAlternates(): void
    {
        $this->container->get(SettingsService::class)->setMany(['site.public_url' => 'https://example.test/']);
        $this->container->get(SettingsService::class)->refresh();

        $alternates = $this->container->get(SeoBuilder::class)->alternates('/gallery');

        self::assertSame('https://example.test/fr/gallery', $alternates['fr']);
        self::assertSame('https://example.test/de/gallery', $alternates['de']);
    }

    public function testAdministratorCanEditContentInEveryLocale(): void
    {
        $this->loginAs();

        $repository = new ContentRepository($this->database);
        $page = $repository->findBySlug('activities');
        self::assertNotNull($page);

        $post = $this->withCsrf([
            'slug' => 'activities',
            'season' => 'all',
            'is_published' => '1',
            'show_in_menu' => '1',
            'position' => '60',
            'parent_id' => '0',
        ]);

        foreach (['fr' => 'Randonnées', 'en' => 'Hikes', 'nl' => 'Wandelingen', 'de' => 'Wanderungen'] as $locale => $title) {
            $post['title_' . $locale] = $title;
            $post['body_' . $locale] = '<p>' . $title . '</p><script>alert(1)</script>';
        }

        $response = $this->request('/fr/admin/content/' . $page->id, 'POST', $post);
        self::assertSame(302, $response->status());

        foreach (['fr' => 'Randonnées', 'en' => 'Hikes', 'nl' => 'Wandelingen', 'de' => 'Wanderungen'] as $locale => $title) {
            $public = $this->request('/' . $locale . '/activities');
            self::assertSame(200, $public->status());
            self::assertStringContainsString($title, $public->content());
            self::assertStringNotContainsString('alert(1)', $public->content());
        }
    }

    public function testContentAdministrationRequiresAnAdministrator(): void
    {
        self::assertSame(403, $this->request('/fr/admin/content')->status());
        self::assertSame(403, $this->request('/fr/admin/media')->status());
    }

    public function testTranslationCompletenessIsVisibleInTheAdminArea(): void
    {
        $this->loginAs();

        $content = $this->container->get(ContentService::class);
        $id = $content->createPage(['slug' => 'partial']);
        $content->saveTranslations($id, ['fr' => ['title' => 'Partiel', 'body' => '<p>x</p>']]);

        $html = $this->request('/fr/admin/content')->content();

        self::assertStringContainsString('data-page-slug="partial"', $html);
        self::assertStringContainsString('data-locale-status="nl" data-complete="0"', $html);
        self::assertStringContainsString('data-locale-status="fr" data-complete="1"', $html);
    }

    public function testGalleryPageRendersEvenWhenEmpty(): void
    {
        $response = $this->request('/fr/gallery');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-testid="gallery-empty"', $response->content());
    }
}
