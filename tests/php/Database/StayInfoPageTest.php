<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

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

    private function settings(): SettingsService
    {
        return new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $this->container->get(Encryptor::class),
        );
    }
}
