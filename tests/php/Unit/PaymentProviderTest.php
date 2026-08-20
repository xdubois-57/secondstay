<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Http\FakeHttpFetcher;
use SecondStay\Http\UrlGuard;
use SecondStay\Payment\FakePaymentProvider;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\MolliePaymentProvider;
use SecondStay\Payment\NullPaymentProvider;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentStatus;

/**
 * Adaptateurs de paiement.
 *
 * Le point sensible est le webhook : le fournisseur n'y annonce qu'un
 * identifiant, et l'adaptateur ne doit rien en déduire d'autre.
 */
final class PaymentProviderTest extends TestCase
{
    private const ENDPOINT = 'https://api.mollie.test/v2';

    /**
     * Clé d'API fictive, assemblée plutôt qu'écrite en toutes lettres.
     *
     * Une clé Mollie a la forme `<mode>_<30 caractères>` : écrite telle
     * quelle, même inventée, elle déclencherait à juste titre le contrôle
     * anti-secret du dépôt. Le contrôle reste donc entier, et le test garde
     * une clé de la bonne forme.
     */
    private static function apiKey(string $mode = 'test'): string
    {
        return $mode . '_' . str_repeat('A', 24) . '0123456789';
    }

    /**
     * Client HTTP factice dont la résolution DNS est injectée : le domaine de
     * test n'existe pas, et la protection SSRF refuserait — à juste titre —
     * un nom qui ne résout pas. Elle reste donc active, sur une adresse
     * publique fictive.
     */
    private static function http(): FakeHttpFetcher
    {
        return new FakeHttpFetcher(new UrlGuard([], static fn (string $host): array => ['93.184.216.34']));
    }

    // --- Configuration -----------------------------------------------------

    /**
     * @return list<array{string, bool}>
     */
    public static function keys(): array
    {
        return [
            [self::apiKey('test'), true],
            [self::apiKey('live'), true],
            ['test' . '_short', false],
            [str_repeat('A', 24) . '0123456789', false],
            [self::apiKey('sandbox'), false],
            ['', false],
        ];
    }

    #[DataProvider('keys')]
    public function testOnlyAWellFormedKeyCountsAsConfigured(string $key, bool $configured): void
    {
        $provider = new MolliePaymentProvider($key, self::http(), self::ENDPOINT);

        self::assertSame($configured, $provider->isConfigured());
    }

    public function testATestKeyIsNeverMistakenForALiveOne(): void
    {
        $test = new MolliePaymentProvider(self::apiKey('test'), self::http(), self::ENDPOINT);
        $live = new MolliePaymentProvider(self::apiKey('live'), self::http(), self::ENDPOINT);

        self::assertFalse($test->isLive());
        self::assertTrue($live->isLive());
    }

    public function testAnUnconfiguredProviderNeverContactsTheNetwork(): void
    {
        $http = self::http();
        $provider = new MolliePaymentProvider('', $http, self::ENDPOINT);

        $result = $provider->create(1000, 'EUR', 'test', 'https://example.test/r', 'https://example.test/w');

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.not_configured', $result['error']);
        self::assertSame([], $http->requestedUrls);
    }

    // --- Création -----------------------------------------------------------

    public function testCreateSendsADecimalStringAndReturnsTheCheckoutUrl(): void
    {
        $http = self::http();
        $http->addJsonResponse(self::ENDPOINT . '/payments', [
            'id' => 'tr_abcdef123456',
            '_links' => ['checkout' => ['href' => 'https://checkout.mollie.test/tr_abcdef123456']],
        ], 201);

        $provider = new MolliePaymentProvider(self::apiKey(), $http, self::ENDPOINT);
        $result = $provider->create(
            35_000,
            'EUR',
            'SS-2026-0001 — deposit',
            'https://example.test/return',
            'https://example.test/webhook',
            ['booking' => 'SS-2026-0001'],
        );

        self::assertTrue($result['ok']);
        self::assertSame('tr_abcdef123456', $result['reference']);
        self::assertSame('https://checkout.mollie.test/tr_abcdef123456', $result['redirect_url']);

        self::assertCount(1, $http->postedRequests);
        /** @var array<string, mixed> $sent */
        $sent = json_decode($http->postedRequests[0]['body'], true);
        /** @var array<string, mixed> $amount */
        $amount = $sent['amount'];
        self::assertSame('350.00', $amount['value'], 'Mollie refuse un flottant.');
        self::assertSame('EUR', $amount['currency']);
        self::assertSame('https://example.test/webhook', $sent['webhookUrl']);
    }

    public function testAResponseWithoutCheckoutUrlIsTreatedAsARejection(): void
    {
        $http = self::http();
        $http->addJsonResponse(self::ENDPOINT . '/payments', ['id' => 'tr_abcdef123456'], 201);

        $provider = new MolliePaymentProvider(self::apiKey(), $http, self::ENDPOINT);
        $result = $provider->create(100, 'EUR', 'x', 'https://example.test/r', 'https://example.test/w');

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.rejected', $result['error']);
    }

    public function testAnHttpErrorIsReportedAsUnreachableRatherThanSilentlyIgnored(): void
    {
        $http = self::http();
        $http->addJsonResponse(self::ENDPOINT . '/payments', ['error' => 'nope'], 503);

        $provider = new MolliePaymentProvider(self::apiKey(), $http, self::ENDPOINT);
        $result = $provider->create(100, 'EUR', 'x', 'https://example.test/r', 'https://example.test/w');

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.unreachable', $result['error']);
    }

    // --- Lecture d'état -----------------------------------------------------

    public function testFetchReadsTheStatusAndAmountFromTheProvider(): void
    {
        $http = self::http();
        $http->addJsonResponse(self::ENDPOINT . '/payments/tr_abcdef123456', [
            'id' => 'tr_abcdef123456',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '350.00'],
        ]);

        $provider = new MolliePaymentProvider(self::apiKey(), $http, self::ENDPOINT);
        $result = $provider->fetch('tr_abcdef123456');

        self::assertTrue($result['ok']);
        self::assertSame(PaymentStatus::Paid, $result['status']);
        self::assertSame(35_000, $result['amount_cents']);
    }

    public function testAnUnreachableProviderNeverLooksLikeAPaidPayment(): void
    {
        $provider = new MolliePaymentProvider(self::apiKey(), self::http(), self::ENDPOINT);
        $result = $provider->fetch('tr_abcdef123456');

        self::assertFalse($result['ok']);
        self::assertSame(PaymentStatus::Pending, $result['status']);
        self::assertSame('payment.error.unreachable', $result['error']);
    }

    /**
     * @return list<array{string, PaymentStatus}>
     */
    public static function statuses(): array
    {
        return [
            ['paid', PaymentStatus::Paid],
            ['authorized', PaymentStatus::Authorized],
            ['canceled', PaymentStatus::Cancelled],
            ['cancelled', PaymentStatus::Cancelled],
            ['expired', PaymentStatus::Failed],
            ['failed', PaymentStatus::Failed],
            ['open', PaymentStatus::Pending],
            ['pending', PaymentStatus::Pending],
            ['n’importe quoi', PaymentStatus::Pending],
            ['', PaymentStatus::Pending],
        ];
    }

    #[DataProvider('statuses')]
    public function testUnknownProviderStatusesFallBackToPending(string $raw, PaymentStatus $expected): void
    {
        self::assertSame($expected, MolliePaymentProvider::translateStatus($raw));
    }

    /**
     * @return list<array{string, int}>
     */
    public static function amounts(): array
    {
        return [
            ['350.00', 35_000],
            ['0.01', 1],
            ['1.99', 199],
            ['1234.56', 123_456],
            ['0', 0],
            ['', 0],
        ];
    }

    #[DataProvider('amounts')]
    public function testDecimalAmountsAreConvertedWithoutRoundingLoss(string $raw, int $cents): void
    {
        self::assertSame($cents, MolliePaymentProvider::toCents($raw));
    }

    // --- Webhook ------------------------------------------------------------

    /**
     * @return list<array{array<string, mixed>, string|null}>
     */
    public static function webhookPayloads(): array
    {
        return [
            [['id' => 'tr_abcdef123456'], 'tr_abcdef123456'],
            [['id' => 're_abcdef123456'], 're_abcdef123456'],
            [['id' => 'tr_abcdef123456', 'status' => 'paid'], 'tr_abcdef123456'],
            [['id' => 'xx_abcdef123456'], null],
            [['id' => 'tr_'], null],
            [['id' => 'tr_../../etc/passwd'], null],
            [['id' => 12345], null],
            [['payment' => 'tr_abcdef123456'], null],
            [[], null],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('webhookPayloads')]
    public function testOnlyAWellFormedIdentifierIsExtractedFromAWebhook(array $payload, ?string $expected): void
    {
        $provider = new MolliePaymentProvider(self::apiKey(), self::http(), self::ENDPOINT);

        self::assertSame($expected, $provider->referenceFromWebhook($payload, (string) json_encode($payload)));
    }

    // --- Fournisseur factice -------------------------------------------------

    public function testTheFakeProviderReproducesTheWholeSequence(): void
    {
        $provider = new FakePaymentProvider();

        $created = $provider->create(35_000, 'EUR', 'acompte', 'https://example.test/return', '');
        self::assertTrue($created['ok']);
        self::assertSame('https://example.test/return', $created['redirect_url']);

        $before = $provider->fetch($created['reference']);
        self::assertSame(PaymentStatus::Pending, $before['status']);

        self::assertTrue($provider->settle($created['reference']));

        $after = $provider->fetch($created['reference']);
        self::assertSame(PaymentStatus::Paid, $after['status']);
        self::assertSame(35_000, $after['amount_cents']);
    }

    public function testTheFakeProviderRefusesAnOversizedRefund(): void
    {
        $provider = new FakePaymentProvider();
        $created = $provider->create(1000, 'EUR', 'acompte', 'https://example.test/r', '');

        self::assertFalse($provider->refund($created['reference'], 1001)['ok']);
        self::assertTrue($provider->refund($created['reference'], 600)['ok']);
        self::assertFalse($provider->refund($created['reference'], 500)['ok']);
    }

    public function testTheFakeProviderStateSurvivesAcrossInstances(): void
    {
        $file = sys_get_temp_dir() . '/secondstay-fake-payments-' . bin2hex(random_bytes(6)) . '.json';

        try {
            $first = new FakePaymentProvider('/fr/payment/return', $file);
            $created = $first->create(2500, 'EUR', 'acompte', 'https://example.test/r', '');
            $first->settle($created['reference']);

            // Une requête HTTP suivante repart d'un conteneur neuf.
            $second = new FakePaymentProvider('/fr/payment/return', $file);

            self::assertSame([$created['reference']], $second->references());
            self::assertSame(PaymentStatus::Paid, $second->fetch($created['reference'])['status']);
            self::assertSame(2500, $second->fetch($created['reference'])['amount_cents']);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheFakeProviderOnlyRecognisesReferencesItIssued(): void
    {
        $provider = new FakePaymentProvider();
        $created = $provider->create(1000, 'EUR', 'x', 'https://example.test/r', '');

        self::assertSame($created['reference'], $provider->referenceFromWebhook(['id' => $created['reference']]));
        self::assertNull($provider->referenceFromWebhook(['id' => 'tr_inconnu']));
    }

    // --- Absence de fournisseur ----------------------------------------------

    public function testTheNullProviderNeverPretendsToCollectAnything(): void
    {
        $provider = new NullPaymentProvider();

        self::assertFalse($provider->isConfigured());
        self::assertFalse($provider->create(100, 'EUR', 'x', '', '')['ok']);
        self::assertFalse($provider->fetch('tr_abcdef123456')['ok']);
        self::assertFalse($provider->refund('tr_abcdef123456', 100)['ok']);
        self::assertNull($provider->referenceFromWebhook(['id' => 'tr_abcdef123456']));
    }

    // --- Sémantique des états -------------------------------------------------

    /**
     * @return list<array{PaymentStatus, PaymentStatus, bool}>
     */
    public static function transitions(): array
    {
        return [
            [PaymentStatus::Pending, PaymentStatus::Paid, true],
            [PaymentStatus::Pending, PaymentStatus::Failed, true],
            [PaymentStatus::Authorized, PaymentStatus::Paid, true],
            [PaymentStatus::Paid, PaymentStatus::Paid, false],
            // Une notification en retard ne défait pas un encaissement.
            [PaymentStatus::Paid, PaymentStatus::Failed, false],
            [PaymentStatus::Paid, PaymentStatus::Pending, false],
            [PaymentStatus::Paid, PaymentStatus::Cancelled, false],
            [PaymentStatus::Paid, PaymentStatus::Refunded, true],
            [PaymentStatus::Refunded, PaymentStatus::Paid, false],
            [PaymentStatus::Cancelled, PaymentStatus::Paid, false],
        ];
    }

    #[DataProvider('transitions')]
    public function testASettledPaymentNeverRegresses(PaymentStatus $from, PaymentStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canBeReplacedBy($to));
    }

    public function testOnlyTheDepositConfirmsABooking(): void
    {
        foreach (PaymentKind::cases() as $kind) {
            self::assertSame(
                $kind === PaymentKind::Deposit,
                $kind->confirmsBooking(),
                $kind->value
            );
        }
    }

    public function testASecurityDepositIsNeverCountedAsRevenue(): void
    {
        self::assertFalse(PaymentKind::SecurityDeposit->isRevenue());
        self::assertTrue(PaymentKind::Deposit->isRevenue());
        self::assertTrue(PaymentKind::Balance->isRevenue());
    }

    /**
     * @return list<array{HoldStatus, HoldStatus, bool}>
     */
    public static function holdTransitions(): array
    {
        return [
            [HoldStatus::ToPay, HoldStatus::Received, true],
            [HoldStatus::Received, HoldStatus::ToReturn, true],
            [HoldStatus::ToReturn, HoldStatus::Returned, true],
            [HoldStatus::ToReturn, HoldStatus::PartiallyRetained, true],
            [HoldStatus::ToPay, HoldStatus::Returned, false],
            [HoldStatus::Returned, HoldStatus::ToPay, false],
            [HoldStatus::None, HoldStatus::Received, false],
        ];
    }

    #[DataProvider('holdTransitions')]
    public function testTheSecurityDepositCycleIsDeclared(HoldStatus $from, HoldStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }
}
