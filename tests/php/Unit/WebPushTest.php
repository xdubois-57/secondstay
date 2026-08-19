<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Http\FakeHttpFetcher;
use SecondStay\Http\UrlGuard;
use SecondStay\Push\Base64Url;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushEncryption;
use SecondStay\Push\PushMessage;
use SecondStay\Push\PushSubscription;
use SecondStay\Push\Vapid;
use SecondStay\Push\WebPushProvider;

/**
 * Push navigateur : identification VAPID (RFC 8292) et chiffrement de charge
 * utile `aes128gcm` (RFC 8291).
 *
 * Le test joue le rôle du navigateur : il déchiffre réellement ce que le
 * serveur produit. Une implémentation qui « paraît » correcte mais qu'aucun
 * navigateur ne saurait lire échoue donc ici.
 */
final class WebPushTest extends TestCase
{
    private const ENDPOINT = 'https://push.example.test/subscription/abc123';

    /**
     * Fetcher factice dont la résolution DNS est fixée : le contrôle SSRF
     * reste appliqué à l'identique, mais aucun test ne dépend du réseau.
     */
    private static function fetcher(): FakeHttpFetcher
    {
        return new FakeHttpFetcher(new UrlGuard([], static fn (string $host): array => match ($host) {
            'push.example.test' => ['93.184.216.34'],
            default => [],
        }));
    }

    protected function setUp(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL requis.');
        }
    }

    // --- base64url ---------------------------------------------------------

    public function testBase64UrlRoundTripsEveryRemainderLength(): void
    {
        // Chaque reste modulo 4 est couvert, y compris la chaîne vide.
        foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $length) {
            $binary = $length === 0 ? '' : random_bytes($length);
            $encoded = Base64Url::encode($binary);

            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]*$/', $encoded);
            self::assertSame($binary, Base64Url::decode($encoded));
        }
    }

    public function testBase64UrlRefusesStandardBase64(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base64Url::decode('abc+def/ghi=');
    }

    // --- VAPID -------------------------------------------------------------

    public function testGeneratedKeyPairHasTheExpectedShape(): void
    {
        $pair = Vapid::generateKeyPair();

        $public = Base64Url::decode($pair['public']);
        self::assertSame(65, strlen($public));
        self::assertSame("\x04", $public[0]);
        self::assertSame(32, strlen(Base64Url::decode($pair['private'])));

        // Deux générations ne produisent jamais la même clé.
        self::assertNotSame($pair['public'], Vapid::generateKeyPair()['public']);
    }

    public function testAnIncompleteKeyPairIsNotUsable(): void
    {
        $pair = Vapid::generateKeyPair();

        self::assertTrue((new Vapid($pair['public'], $pair['private']))->isUsable());
        self::assertFalse((new Vapid('', $pair['private']))->isUsable());
        self::assertFalse((new Vapid($pair['public'], ''))->isUsable());
        self::assertFalse((new Vapid('pas-du-base64url!', $pair['private']))->isUsable());
        self::assertFalse((new Vapid(Base64Url::encode('trop court'), $pair['private']))->isUsable());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function audiences(): array
    {
        return [
            ['https://fcm.googleapis.com/fcm/send/abc', 'https://fcm.googleapis.com'],
            ['https://updates.push.services.mozilla.com/wpush/v2/xyz', 'https://updates.push.services.mozilla.com'],
            ['https://push.example.test:8443/s/1', 'https://push.example.test:8443'],
        ];
    }

    #[DataProvider('audiences')]
    public function testAudienceKeepsOnlyTheOrigin(string $endpoint, string $expected): void
    {
        self::assertSame($expected, Vapid::audienceOf($endpoint));
    }

    public function testAudienceRefusesAMalformedEndpoint(): void
    {
        $this->expectException(RuntimeException::class);

        Vapid::audienceOf('pas-une-url');
    }

    public function testAuthorizationHeaderCarriesAVerifiableJwt(): void
    {
        $pair = Vapid::generateKeyPair();
        $vapid = new Vapid($pair['public'], $pair['private'], 'mailto:proprietaire@example.test');

        $header = $vapid->authorizationHeader(self::ENDPOINT, 1_770_000_000);

        self::assertSame(1_770_000_000 + Vapid::TOKEN_LIFETIME, $header['expires_at']);
        self::assertStringStartsWith('vapid t=', $header['authorization']);
        self::assertStringContainsString(', k=' . $pair['public'], $header['authorization']);

        [$token] = explode(', k=', substr($header['authorization'], strlen('vapid t=')));
        [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);

        /** @var array{typ: string, alg: string} $jwtHeader */
        $jwtHeader = json_decode(Base64Url::decode($encodedHeader), true);
        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], $jwtHeader);

        /** @var array{aud: string, exp: int, sub: string} $claims */
        $claims = json_decode(Base64Url::decode($encodedClaims), true);
        self::assertSame('https://push.example.test', $claims['aud']);
        self::assertSame(1_770_000_000 + Vapid::TOKEN_LIFETIME, $claims['exp']);
        self::assertSame('mailto:proprietaire@example.test', $claims['sub']);

        // La signature brute r||s doit être vérifiable par la clé publique.
        $signature = Base64Url::decode($encodedSignature);
        self::assertSame(64, strlen($signature));

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedClaims,
            self::rawSignatureToDer($signature),
            PushEncryption::publicKeyPem(Base64Url::decode($pair['public'])),
            OPENSSL_ALGO_SHA256
        );

        self::assertSame(1, $verified);
    }

    public function testSubjectAlwaysResolvesToAReachableContact(): void
    {
        $pair = Vapid::generateKeyPair();

        $claims = static function (Vapid $vapid): array {
            $header = $vapid->authorizationHeader(self::ENDPOINT);
            [$token] = explode(', k=', substr($header['authorization'], strlen('vapid t=')));
            /** @var array{sub: string} $decoded */
            $decoded = json_decode(Base64Url::decode(explode('.', $token)[1]), true);

            return $decoded;
        };

        // Une adresse nue est préfixée, une URL de contact est conservée.
        self::assertSame(
            'mailto:contact@example.test',
            $claims(new Vapid($pair['public'], $pair['private'], 'contact@example.test'))['sub']
        );
        self::assertSame(
            'https://example.test/contact',
            $claims(new Vapid($pair['public'], $pair['private'], 'https://example.test/contact'))['sub']
        );
        // Sans contact configuré, la valeur reste un mailto valide.
        self::assertStringStartsWith('mailto:', $claims(new Vapid($pair['public'], $pair['private']))['sub']);
    }

    // --- Chiffrement de charge utile ---------------------------------------

    public function testThePayloadIsReadableByTheSubscribedBrowserOnly(): void
    {
        $browser = Vapid::generateKeyPair();
        $authSecret = random_bytes(16);
        $payload = '{"title":"Réservation confirmée"}';

        $body = (new PushEncryption())->encrypt(
            $payload,
            Base64Url::decode($browser['public']),
            $authSecret,
        );

        self::assertSame($payload, self::decrypt($body, $browser, $authSecret));

        // Un autre secret d'authentification ne déchiffre rien.
        self::assertNull(self::decrypt($body, $browser, random_bytes(16)));

        // Une autre paire de clés non plus.
        self::assertNull(self::decrypt($body, Vapid::generateKeyPair(), $authSecret));
    }

    public function testTheHeaderFollowsTheAes128gcmLayout(): void
    {
        $browser = Vapid::generateKeyPair();

        $body = (new PushEncryption())->encrypt(
            'x',
            Base64Url::decode($browser['public']),
            random_bytes(16),
            str_repeat("\x01", 16),
        );

        self::assertSame(str_repeat("\x01", 16), substr($body, 0, 16));
        /** @var array{1: int} $recordSize */
        $recordSize = unpack('N', substr($body, 16, 4));
        self::assertSame(PushEncryption::RECORD_SIZE, $recordSize[1]);
        self::assertSame(65, ord($body[20]));
        self::assertSame("\x04", $body[21]);
    }

    public function testEachDeliveryUsesAFreshSaltAndEphemeralKey(): void
    {
        $browser = Vapid::generateKeyPair();
        $authSecret = random_bytes(16);
        $encryption = new PushEncryption();

        $first = $encryption->encrypt('même message', Base64Url::decode($browser['public']), $authSecret);
        $second = $encryption->encrypt('même message', Base64Url::decode($browser['public']), $authSecret);

        self::assertNotSame($first, $second);
        self::assertNotSame(substr($first, 0, 16), substr($second, 0, 16));
        self::assertNotSame(substr($first, 21, 65), substr($second, 21, 65));

        // Les deux restent lisibles par le navigateur abonné.
        self::assertSame('même message', self::decrypt($first, $browser, $authSecret));
        self::assertSame('même message', self::decrypt($second, $browser, $authSecret));
    }

    public function testMalformedSubscriptionKeysAreRefused(): void
    {
        $encryption = new PushEncryption();
        $valid = Base64Url::decode(Vapid::generateKeyPair()['public']);

        foreach ([
            ['x', random_bytes(16)],
            [substr($valid, 0, 64), random_bytes(16)],
            ["\x03" . substr($valid, 1), random_bytes(16)],
            [$valid, random_bytes(8)],
        ] as [$publicKey, $authSecret]) {
            try {
                $encryption->encrypt('x', $publicKey, $authSecret);
                self::fail('Une clé invalide aurait dû être refusée.');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('push.error.', $exception->getMessage());
            }
        }
    }

    public function testAnOversizedPayloadIsRefusedRatherThanTruncated(): void
    {
        $browser = Vapid::generateKeyPair();

        $this->expectExceptionMessage('push.error.payload_too_large');

        (new PushEncryption())->encrypt(
            str_repeat('a', PushEncryption::RECORD_SIZE),
            Base64Url::decode($browser['public']),
            random_bytes(16),
        );
    }

    // --- Abonnements -------------------------------------------------------

    public function testSubscriptionRefusesAnythingButAnHttpsEndpoint(): void
    {
        $pair = Vapid::generateKeyPair();

        foreach (['http://push.example.test/a', 'ftp://push.example.test/a', 'pas-une-url', ''] as $endpoint) {
            self::assertFalse(PushSubscription::isValidEndpoint($endpoint), $endpoint);
        }

        self::assertFalse(PushSubscription::isValidEndpoint('https://push.example.test/' . str_repeat('a', 2000)));
        self::assertTrue(PushSubscription::isValidEndpoint(self::ENDPOINT));

        $this->expectException(InvalidArgumentException::class);
        new PushSubscription('http://push.example.test/a', $pair['public'], Base64Url::encode(random_bytes(16)));
    }

    public function testSubscriptionRefusesMalformedKeys(): void
    {
        $pair = Vapid::generateKeyPair();

        $this->expectExceptionMessage('push.error.invalid_subscription_key');
        new PushSubscription(self::ENDPOINT, $pair['public'], Base64Url::encode(random_bytes(8)));
    }

    public function testSubscriptionExposesOnlyItsServiceHost(): void
    {
        $pair = Vapid::generateKeyPair();
        $subscription = new PushSubscription(self::ENDPOINT, $pair['public'], Base64Url::encode(random_bytes(16)));

        self::assertSame('push.example.test', $subscription->serviceHost());
        self::assertSame(hash('sha256', self::ENDPOINT), $subscription->endpointHash());
    }

    // --- Message -----------------------------------------------------------

    public function testMessageBoundsItsTitleAndBody(): void
    {
        $message = new PushMessage(str_repeat('t', 400), str_repeat('b', 900), '/fr/account', 'tag', 'de');
        $payload = $message->toArray();

        self::assertSame(PushMessage::MAX_TITLE, mb_strlen($payload['title']));
        self::assertSame(PushMessage::MAX_BODY, mb_strlen($payload['body']));
        self::assertSame('de', $payload['locale']);
        self::assertStringContainsString('"path":"/fr/account"', $message->toJson());
    }

    // --- Fournisseur -------------------------------------------------------

    public function testTheProviderSendsAnEncryptedBodyWithVapidHeaders(): void
    {
        $pair = Vapid::generateKeyPair();
        $browser = Vapid::generateKeyPair();
        $authSecret = random_bytes(16);

        $http = self::fetcher();
        $http->addResponse(self::ENDPOINT, '', 201);

        $provider = new WebPushProvider(new Vapid($pair['public'], $pair['private'], 'mailto:a@example.test'), $http);
        self::assertTrue($provider->isConfigured());

        $result = $provider->send(
            new PushSubscription(self::ENDPOINT, $browser['public'], Base64Url::encode($authSecret)),
            new PushMessage('Titre', 'Corps', '/fr/account', 'tag', 'fr'),
        );

        self::assertSame(['ok' => true, 'status' => 201, 'expired' => false, 'error' => ''], $result);
        self::assertCount(1, $http->postedRequests);

        $request = $http->postedRequests[0];
        self::assertSame('aes128gcm', $request['headers']['Content-Encoding']);
        self::assertSame('application/octet-stream', $request['headers']['Content-Type']);
        self::assertStringStartsWith('vapid t=', $request['headers']['Authorization']);
        self::assertSame((string) WebPushProvider::DEFAULT_TTL, $request['headers']['TTL']);

        // Le corps est réellement lisible par le navigateur abonné.
        $decrypted = self::decrypt($request['body'], $browser, $authSecret);
        self::assertNotNull($decrypted);
        /** @var array{title: string, body: string, path: string} $payload */
        $payload = json_decode($decrypted, true);
        self::assertSame('Titre', $payload['title']);
        self::assertSame('/fr/account', $payload['path']);
    }

    public function testAnUnconfiguredProviderRefusesToSend(): void
    {
        $provider = new WebPushProvider(new Vapid('', ''), self::fetcher());
        $browser = Vapid::generateKeyPair();

        self::assertFalse($provider->isConfigured());

        $result = $provider->send(
            new PushSubscription(self::ENDPOINT, $browser['public'], Base64Url::encode(random_bytes(16))),
            new PushMessage('Titre', 'Corps'),
        );

        self::assertFalse($result['ok']);
        self::assertSame('push.error.not_configured', $result['error']);
    }

    /**
     * @return list<array{int, bool, string}>
     */
    public static function providerResponses(): array
    {
        return [
            [201, false, ''],
            [404, true, 'push.error.subscription_expired'],
            [410, true, 'push.error.subscription_expired'],
            [400, false, 'push.error.rejected'],
            [429, false, 'push.error.rejected'],
            [500, false, 'push.error.rejected'],
        ];
    }

    #[DataProvider('providerResponses')]
    public function testProviderResponsesAreTranslatedNeverLeaked(int $status, bool $expired, string $error): void
    {
        $pair = Vapid::generateKeyPair();
        $browser = Vapid::generateKeyPair();

        $http = self::fetcher();
        $http->addResponse(self::ENDPOINT, 'UnsubscribedError: gone at 10.0.0.1', $status);

        $result = (new WebPushProvider(new Vapid($pair['public'], $pair['private']), $http))->send(
            new PushSubscription(self::ENDPOINT, $browser['public'], Base64Url::encode(random_bytes(16))),
            new PushMessage('Titre', 'Corps'),
        );

        self::assertSame($status >= 200 && $status < 300, $result['ok']);
        self::assertSame($expired, $result['expired']);
        self::assertSame($error, $result['error']);
        // Aucun détail du service distant ne remonte.
        self::assertStringNotContainsString('10.0.0.1', $result['error']);
    }

    public function testAnEndpointOnAPrivateAddressIsRefusedBySsrfGuard(): void
    {
        $pair = Vapid::generateKeyPair();
        $browser = Vapid::generateKeyPair();

        $result = (new WebPushProvider(new Vapid($pair['public'], $pair['private']), self::fetcher()))->send(
            new PushSubscription('https://127.0.0.1/push', $browser['public'], Base64Url::encode(random_bytes(16))),
            new PushMessage('Titre', 'Corps'),
        );

        self::assertFalse($result['ok']);
        self::assertSame('push.error.transport', $result['error']);
    }

    public function testTheFakeProviderRecordsWhatItWouldHaveSent(): void
    {
        $browser = Vapid::generateKeyPair();
        $subscription = new PushSubscription(self::ENDPOINT, $browser['public'], Base64Url::encode(random_bytes(16)));

        $provider = new FakePushProvider('cle-publique-de-test');
        self::assertTrue($provider->isConfigured());
        self::assertSame('cle-publique-de-test', $provider->publicKey());

        self::assertTrue($provider->send($subscription, new PushMessage('Titre', 'Corps'))['ok']);
        self::assertCount(1, $provider->sent());
        self::assertSame('Titre', $provider->sent()[0]['message']['title']);

        $provider->shouldExpire = true;
        $expired = $provider->send($subscription, new PushMessage('Titre', 'Corps'));
        self::assertTrue($expired['expired']);

        $provider->shouldExpire = false;
        $provider->shouldFail = true;
        self::assertFalse($provider->send($subscription, new PushMessage('Titre', 'Corps'))['ok']);

        // Sans clé publique, le fournisseur factice n'est pas configuré : le
        // parcours d'abonnement du navigateur reste réellement exercé.
        self::assertFalse((new FakePushProvider(''))->isConfigured());
    }

    // --- Outils du test ----------------------------------------------------

    /**
     * Déchiffrement tel que l'effectuerait le navigateur abonné.
     *
     * @param array{public: string, private: string} $browser
     */
    private static function decrypt(string $body, array $browser, string $authSecret): ?string
    {
        $salt = substr($body, 0, 16);
        $keyLength = ord($body[20]);
        $serverPublic = substr($body, 21, $keyLength);
        $rest = substr($body, 21 + $keyLength);
        $tag = substr($rest, -16);
        $ciphertext = substr($rest, 0, -16);

        $browserPublic = Base64Url::decode($browser['public']);
        $private = openssl_pkey_get_private(
            Vapid::privateKeyPem(Base64Url::decode($browser['private']), $browserPublic)
        );
        $peer = openssl_pkey_get_public(PushEncryption::publicKeyPem($serverPublic));
        if ($private === false || $peer === false) {
            return null;
        }

        $shared = openssl_pkey_derive($peer, $private, 32);
        if ($shared === false) {
            return null;
        }

        $ikm = PushEncryption::hkdf(
            $authSecret,
            $shared,
            "WebPush: info\x00" . $browserPublic . $serverPublic,
            32
        );
        $key = PushEncryption::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = PushEncryption::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        $plain = openssl_decrypt($ciphertext, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $plain === false ? null : rtrim($plain, "\x02");
    }

    private static function rawSignatureToDer(string $signature): string
    {
        $encode = static function (string $value): string {
            $trimmed = ltrim($value, "\x00");
            if ($trimmed === '' || ord($trimmed[0]) > 0x7f) {
                $trimmed = "\x00" . $trimmed;
            }

            return "\x02" . chr(strlen($trimmed)) . $trimmed;
        };

        $sequence = $encode(substr($signature, 0, 32)) . $encode(substr($signature, 32));

        return "\x30" . chr(strlen($sequence)) . $sequence;
    }
}
