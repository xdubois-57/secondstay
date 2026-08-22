<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Media\MediaRepository;
use SecondStay\Stay\StayBlockReferences;
use SecondStay\Stay\StayInfoRepository;
use SecondStay\Stay\StaySecretRepository;
use SecondStay\Support\QrCode;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;
use SecondStay\Tests\Support\QrDecoder;

/**
 * QR physiques : une adresse stable par bloc du livret
 * (SPECIFICATIONS.md §47).
 *
 * Ce que ces tests protègent tient en trois phrases :
 *
 * 1. **rien n'est public tant que le propriétaire ne l'a pas décidé**, bloc par
 *    bloc. Un livret contient des choses qui n'ont rien à faire sur le web
 *    ouvert, et un réglage qui publierait tout d'un coup transformerait une
 *    commodité en fuite ;
 * 2. **l'adresse ne dépend que du code du bloc.** L'autocollant collé sur la
 *    machine à laver ne se met pas à jour : si l'adresse bouge, le QR devient
 *    un lien mort dans la cuisine de quelqu'un ;
 * 3. **aucun secret n'y transite jamais.** Les codes d'accès vivent chiffrés
 *    ailleurs et ne sont rendus que dans « Mon séjour ».
 */
final class StayInfoPageTest extends InstalledAppTestCase
{
    private StayInfoRepository $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blocks = new StayInfoRepository($this->database);
    }

    public function testABlockIsNotPublicUntilItIsExplicitlyMadePublic(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true);

        self::assertSame(404, $this->request('/fr/info/waste')->status());
    }

    public function testAPublicBlockIsServedAtItsStableAddress(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true);

        $response = $this->request('/fr/info/waste');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Le tri se fait au fond du jardin.', $response->content());
        self::assertStringContainsString('data-block="waste"', $response->content());
    }

    /**
     * L'adresse est la même quelle que soit la date, le séjour ou le
     * voyageur : c'est tout l'intérêt d'un QR imprimé une fois.
     */
    public function testTheAddressCarriesNothingButTheBlockCode(): void
    {
        $this->blocks->save('wifi', 'fr', 'Wi-Fi', 'Le réseau s’appelle Maison-des-Pins.', true, true);

        $first = $this->request('/fr/info/wifi');
        $second = $this->request('/fr/info/wifi');

        self::assertSame(200, $first->status());
        self::assertSame($first->status(), $second->status());
        self::assertStringContainsString('Maison-des-Pins', $second->content());
    }

    public function testAnUnpublishedBlockIsNotServedEvenIfMarkedPublic(): void
    {
        $this->blocks->save('rules', 'fr', 'Règles', 'Pas de fête après 22 h.', false, true);

        self::assertSame(404, $this->request('/fr/info/rules')->status());
    }

    /**
     * Un QR ne doit jamais ouvrir une page vide : celui qui l'a scanné est
     * debout devant un appareil qu'il ne sait pas faire marcher.
     */
    public function testAnEmptyBlockIsNotServed(): void
    {
        $this->blocks->save('appliances', 'fr', '', '', true, true);

        self::assertSame(404, $this->request('/fr/info/appliances')->status());
    }

    public function testAnUnknownBlockCodeIsRefused(): void
    {
        self::assertSame(404, $this->request('/fr/info/coffre')->status());
    }

    /**
     * Un voyageur néerlandophone qui scanne un autocollant ne doit pas tomber
     * sur une page vide parce que le bloc n'a été écrit qu'en français : une
     * information dans la mauvaise langue reste utile, une page absente non.
     */
    public function testAMissingTranslationFallsBackToThePropertyLanguage(): void
    {
        $this->settings()->setMany(['site.default_locale' => 'fr']);
        $this->blocks->save('safety', 'fr', 'Sécurité', 'L’extincteur est sous l’évier.', true, true);

        $response = $this->request('/nl/info/safety');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('L’extincteur est sous l’évier.', $response->content());
        self::assertStringContainsString('data-block-locale="fr"', $response->content());
        self::assertStringContainsString('data-testid="info-fallback"', $response->content());
    }

    public function testATranslatedBlockIsServedInTheRequestedLanguageWithoutTheFallbackNotice(): void
    {
        $this->blocks->save('safety', 'fr', 'Sécurité', 'L’extincteur est sous l’évier.', true, true);
        $this->blocks->save('safety', 'nl', 'Veiligheid', 'De brandblusser staat onder de gootsteen.', true, true);

        $response = $this->request('/nl/info/safety');

        self::assertStringContainsString('De brandblusser staat onder de gootsteen.', $response->content());
        self::assertStringNotContainsString('data-testid="info-fallback"', $response->content());
    }

    /**
     * La page est publique par nécessité, pas pour être trouvée : elle porte
     * `noindex` et `robots.txt` la refuse.
     */
    public function testThePageIsNeverIndexed(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true);

        self::assertStringContainsString(
            'name="robots" content="noindex, nofollow"',
            $this->request('/fr/info/waste')->content()
        );

        $robots = $this->request('/robots.txt')->content();
        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            self::assertStringContainsString('Disallow: /' . $locale . '/info/', $robots);
        }
    }

    /**
     * La page n'ouvre jamais la porte : les codes d'accès vivent chiffrés
     * dans une autre table et ne sont rendus que dans « Mon séjour ».
     */
    public function testNoAccessSecretEverReachesThePublicPage(): void
    {
        $secrets = new StaySecretRepository($this->database, $this->container->get(Encryptor::class));
        $secrets->set('wifi_password', 'Bouleau-2026-Secret');
        $secrets->set('keybox_code', '481516');

        $this->blocks->save('wifi', 'fr', 'Wi-Fi', 'Le réseau s’appelle Maison-des-Pins.', true, true);
        $this->blocks->save('access', 'fr', 'Accès', 'La boîte à clés est à gauche du portail.', true, true);

        foreach (['/fr/info/wifi', '/fr/info/access'] as $path) {
            $content = $this->request($path)->content();
            self::assertStringNotContainsString('Bouleau-2026-Secret', $content);
            self::assertStringNotContainsString('481516', $content);
        }
    }

    /**
     * Le texte du bloc est saisi par un humain : il est échappé, jamais
     * interprété.
     */
    public function testTheBlockTextIsNeverInterpretedAsMarkup(): void
    {
        $this->blocks->save('rules', 'fr', 'Règles', '<script>alert(1)</script>', true, true);

        $content = $this->request('/fr/info/rules')->content();

        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
    }

    public function testMakingABlockPrivateAgainClosesItsAddress(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true);
        self::assertSame(200, $this->request('/fr/info/waste')->status());

        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, false);
        self::assertSame(404, $this->request('/fr/info/waste')->status());
    }

    /**
     * Le QR imprimé depuis l'administration doit encoder **exactement**
     * l'adresse servie : un QR juste à un caractère près est un lien mort
     * collé sur une machine à laver, découvert par un voyageur un dimanche.
     *
     * Le décodeur est écrit indépendamment de l'encodeur (TESTING.md §9).
     */
    public function testThePrintedQrEncodesTheAddressThatIsActuallyServed(): void
    {
        $this->settings()->setMany(['site.public_url' => 'https://maison-des-pins.example']);
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true);

        $this->loginAs();
        $admin = $this->request('/fr/admin/stay', 'GET', [], [], [], ['locale' => 'fr']);

        self::assertSame(200, $admin->status());

        $matched = preg_match(
            '#data-testid="qr-url-waste">([^<]+)<#',
            $admin->content(),
            $matches
        );
        self::assertSame(1, $matched, 'L’adresse publique doit être affichée à côté du QR.');

        $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        self::assertSame('https://maison-des-pins.example/fr/info/waste', $url);

        $decoder = new QrDecoder(QrCode::encode($url));
        $decoder->assertFormatCopiesAgree();
        self::assertSame($url, $decoder->decode());

        // Et cette adresse est bien celle qui répond.
        self::assertSame(200, $this->request('/fr/info/waste')->status());
    }

    // --- Illustrations (SPECIFICATIONS.md §45 et §55) --------------------------------

    /**
     * Une photo explique le tri des déchets mieux qu'un paragraphe, et se lit
     * dans n'importe quelle langue.
     */
    public function testAPublicBlockCarriesItsIllustration(): void
    {
        $mediaId = $this->media('poubelles.jpg');
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true, $mediaId);

        $content = $this->request('/fr/info/waste')->content();

        self::assertStringContainsString('data-block-illustration="waste"', $content);
        self::assertStringContainsString('/media/large/poubelles.jpg', $content);
        self::assertStringContainsString('alt="Le local à poubelles"', $content);
    }

    /**
     * Le livret est lu par un voyageur qui n'est pas administrateur : un média
     * privé y produirait une image cassée, c'est-à-dire une illustration qui
     * n'illustre rien.
     */
    public function testAPrivateOrUnpublishedMediumIsNeverUsedAsAnIllustration(): void
    {
        $private = $this->media('privee.jpg', private: true);
        $this->blocks->save('waste', 'fr', 'Déchets', 'Texte du bloc.', true, true, $private);
        self::assertStringNotContainsString('data-block-illustration', $this->request('/fr/info/waste')->content());

        $unpublished = $this->media('brouillon.jpg', published: false);
        $this->blocks->save('waste', 'fr', 'Déchets', 'Texte du bloc.', true, true, $unpublished);
        self::assertStringNotContainsString('data-block-illustration', $this->request('/fr/info/waste')->content());
    }

    /**
     * Supprimer un média retire l'illustration ; il ne fait pas disparaître le
     * texte, qui porte l'essentiel de l'information.
     */
    public function testDeletingTheMediumLeavesTheBlockIntact(): void
    {
        $mediaId = $this->media('poubelles.jpg');
        $this->blocks->save('waste', 'fr', 'Déchets', 'Le tri se fait au fond du jardin.', true, true, $mediaId);

        $this->database->delete('media', ['id' => $mediaId]);

        $response = $this->request('/fr/info/waste');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Le tri se fait au fond du jardin.', $response->content());
        self::assertStringNotContainsString('data-block-illustration', $response->content());
        self::assertNull($this->blocks->find('waste', 'fr')?->mediaId);
    }

    public function testAnIllustrationChosenOutsideThePublishedLibraryIsRefused(): void
    {
        $private = $this->media('privee.jpg', private: true);

        $this->loginAs();
        $this->request('/fr/admin/stay', 'POST', $this->withCsrf([
            'locale' => 'fr',
            'title_waste' => 'Déchets',
            'body_waste' => 'Texte du bloc.',
            'published_waste' => '1',
            'media_waste' => (string) $private,
        ]));

        self::assertNull($this->blocks->find('waste', 'fr')?->mediaId);
    }

    /**
     * Un média sans texte alternatif traduit retombe sur sa légende, puis sur
     * le titre du bloc : une image sans alternative n'existe pas pour qui ne
     * la voit pas.
     */
    public function testTheAlternativeTextFallsBackRatherThanBeingEmpty(): void
    {
        $mediaId = $this->media('sans-alt.jpg', altText: '', caption: 'Le fond du jardin');
        $this->blocks->save('waste', 'fr', 'Déchets', 'Texte du bloc.', true, true, $mediaId);

        self::assertStringContainsString('alt="Le fond du jardin"', $this->request('/fr/info/waste')->content());

        $this->database->delete('media_translation', ['media_id' => $mediaId]);

        self::assertStringContainsString('alt="Déchets"', $this->request('/fr/info/waste')->content());
    }

    // --- Carte et source (SPECIFICATIONS.md §55) ------------------------------------

    /**
     * « Le local à poubelles est au bout de la rue à gauche » ne se suit pas
     * depuis un téléphone, dans le noir, avec une valise : le bloc porte un
     * lien ouvrable, et la provenance datée de la règle locale.
     */
    public function testAPublicBlockCarriesItsMapLinkAndItsDatedSource(): void
    {
        $this->blocks->save(
            'waste',
            'fr',
            'Déchets',
            'La collecte a lieu le mardi.',
            true,
            true,
            null,
            new StayBlockReferences(
                'https://maps.example/local-poubelles',
                'Aller au local à poubelles',
                'https://commune.example/collecte',
                '2026-03-14',
            ),
        );

        $content = $this->request('/fr/info/waste')->content();

        self::assertStringContainsString('href="https://maps.example/local-poubelles"', $content);
        self::assertStringContainsString('Aller au local à poubelles', $content);
        self::assertStringContainsString('href="https://commune.example/collecte"', $content);
        self::assertStringContainsString('commune.example', $content);
        self::assertStringContainsString('data-block-link="waste"', $content);
        self::assertStringContainsString('data-block-source="waste"', $content);
    }

    /**
     * Un lien du livret ouvre un site tiers : il ne doit pas lui donner la
     * main sur la page d'origine.
     */
    public function testAnExternalLinkNeverHandsOverTheOpenerWindow(): void
    {
        $this->blocks->save(
            'waste',
            'fr',
            'Déchets',
            'La collecte a lieu le mardi.',
            true,
            true,
            null,
            new StayBlockReferences('https://maps.example/local', '', 'https://commune.example/collecte', '2026-03-14'),
        );

        $content = $this->request('/fr/info/waste')->content();

        self::assertSame(
            2,
            substr_count($content, 'rel="noopener noreferrer"'),
            'La carte et la source doivent toutes deux couper le lien avec la page d’origine.'
        );
    }

    public function testABlockWithoutReferencesShowsNoEmptySourceLine(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'La collecte a lieu le mardi.', true, true);

        $content = $this->request('/fr/info/waste')->content();

        self::assertStringNotContainsString('data-block-references', $content);
        self::assertStringNotContainsString('data-block-source', $content);
    }

    /**
     * L'administration accepte une carte et une source, et les relit telles
     * qu'elles ont été saisies.
     */
    public function testTheOwnerCanAttachAMapAndASourceFromTheAdminScreen(): void
    {
        $this->loginAs();
        $this->request('/fr/admin/stay', 'POST', $this->withCsrf([
            'locale' => 'fr',
            'title_waste' => 'Déchets',
            'body_waste' => 'La collecte a lieu le mardi.',
            'published_waste' => '1',
            'link_url_waste' => 'https://maps.example/local-poubelles',
            'link_label_waste' => 'Aller au local',
            'source_url_waste' => 'https://commune.example/collecte',
            'source_checked_on_waste' => '2026-03-14',
        ]));

        $references = $this->blocks->find('waste', 'fr')?->references;

        self::assertNotNull($references);
        self::assertSame('https://maps.example/local-poubelles', $references->linkUrl);
        self::assertSame('Aller au local', $references->linkLabel);
        self::assertSame('https://commune.example/collecte', $references->sourceUrl);
        self::assertSame('2026-03-14', $references->checkedOn);

        // Et le formulaire les réaffiche pour la prochaine modification.
        $form = $this->request('/fr/admin/stay', 'GET', [], [], [], ['locale' => 'fr'])->content();
        self::assertStringContainsString('value="https://maps.example/local-poubelles"', $form);
        self::assertStringContainsString('value="https://commune.example/collecte"', $form);
        self::assertStringContainsString('value="2026-03-14"', $form);
    }

    /**
     * `javascript:` dans un `href` n'est pas une carte : c'est du code. Le
     * livret entier est refusé plutôt qu'enregistré à moitié.
     */
    public function testAHostileSchemeIsRefusedAndNothingIsWritten(): void
    {
        $this->blocks->save('waste', 'fr', 'Déchets', 'Texte d’origine.', true, true);

        $this->loginAs();
        $this->request('/fr/admin/stay', 'POST', $this->withCsrf([
            'locale' => 'fr',
            'title_waste' => 'Déchets réécrits',
            'body_waste' => 'Texte réécrit.',
            'published_waste' => '1',
            'link_url_waste' => 'javascript:alert(document.cookie)',
        ]));

        $block = $this->blocks->find('waste', 'fr');

        self::assertNotNull($block);
        self::assertSame('Texte d’origine.', $block->body, 'Un refus ne doit rien enregistrer.');
        self::assertFalse($block->references->hasLink());
        self::assertStringNotContainsString('javascript:', $this->request('/fr/info/waste')->content());
    }

    /**
     * Une source saisie sans date n'est pas vérifiable : elle est datée du
     * jour, jamais laissée sans repère.
     */
    public function testASourceSavedWithoutADateIsStampedWithToday(): void
    {
        $this->loginAs();
        $this->request('/fr/admin/stay', 'POST', $this->withCsrf([
            'locale' => 'fr',
            'title_waste' => 'Déchets',
            'body_waste' => 'La collecte a lieu le mardi.',
            'published_waste' => '1',
            'source_url_waste' => 'https://commune.example/collecte',
        ]));

        self::assertSame(
            gmdate('Y-m-d'),
            $this->blocks->find('waste', 'fr')?->references->checkedOn
        );
    }

    /**
     * La carte et la source vivent par langue, comme le reste du bloc : une
     * commune néerlandophone et une commune francophone ne publient pas la
     * même page.
     */
    public function testEachLanguageCarriesItsOwnSource(): void
    {
        $this->blocks->save(
            'waste',
            'fr',
            'Déchets',
            'Collecte le mardi.',
            true,
            true,
            null,
            new StayBlockReferences('', '', 'https://commune.example/dechets', '2026-03-14'),
        );
        $this->blocks->save(
            'waste',
            'nl',
            'Afval',
            'Ophaling op dinsdag.',
            true,
            true,
            null,
            new StayBlockReferences('', '', 'https://gemeente.example/afval', '2026-03-20'),
        );

        self::assertStringContainsString('gemeente.example', $this->request('/nl/info/waste')->content());
        self::assertStringNotContainsString('gemeente.example', $this->request('/fr/info/waste')->content());
        self::assertStringContainsString('commune.example', $this->request('/fr/info/waste')->content());
    }

    /**
     * Vider le champ retire le lien : le livret ne garde pas une carte que le
     * propriétaire a effacée.
     */
    public function testClearingTheFieldRemovesTheLink(): void
    {
        $this->blocks->save(
            'waste',
            'fr',
            'Déchets',
            'Collecte le mardi.',
            true,
            true,
            null,
            new StayBlockReferences('https://maps.example/local', 'Le local', '', null),
        );

        $this->loginAs();
        $this->request('/fr/admin/stay', 'POST', $this->withCsrf([
            'locale' => 'fr',
            'title_waste' => 'Déchets',
            'body_waste' => 'Collecte le mardi.',
            'published_waste' => '1',
            'public_waste' => '1',
            'link_url_waste' => '',
        ]));

        self::assertFalse($this->blocks->find('waste', 'fr')?->references->hasLink());
        self::assertStringNotContainsString('data-block-link', $this->request('/fr/info/waste')->content());
    }

    private function media(
        string $filename,
        bool $published = true,
        bool $private = false,
        string $altText = 'Le local à poubelles',
        string $caption = 'Poubelles',
    ): int {
        $repository = new MediaRepository($this->database);

        $id = $repository->create([
            'filename' => $filename,
            'original_filename' => $filename,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 900,
            'height' => 600,
            'category' => 'general',
            'season' => 'all',
            'position' => 0,
            'is_published' => $published ? 1 : 0,
            'is_private' => $private ? 1 : 0,
            'hash' => str_repeat('a', 64),
        ]);

        $repository->saveTranslation($id, 'fr', $caption, $altText);

        return $id;
    }

    private function settings(): SettingsService
    {
        return new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $this->container->get(Encryptor::class),
        );
    }
}
