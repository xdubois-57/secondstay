<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Diagnostics\MailDnsChecker;

/**
 * Diagnostic SPF / DKIM / DMARC.
 *
 * La résolution DNS est injectée : aucun test ne dépend du réseau ni d'un
 * domaine réel.
 */
final class MailDnsCheckerTest extends TestCase
{
    /**
     * @param array<string, list<string>> $zone
     */
    private function checker(array $zone): MailDnsChecker
    {
        return new MailDnsChecker(
            static function (string $host, int $type) use ($zone): array|false {
                if ($type !== DNS_TXT || !isset($zone[$host])) {
                    return false;
                }

                return array_map(static fn (string $txt): array => ['txt' => $txt], $zone[$host]);
            }
        );
    }

    public function testDomainIsExtractedFromTheSenderAddress(): void
    {
        self::assertSame('example.test', MailDnsChecker::domainOf('Noreply@Example.test'));
        self::assertSame('', MailDnsChecker::domainOf('pas-une-adresse'));
        self::assertSame('', MailDnsChecker::domainOf(''));
    }

    public function testACompleteZoneIsReportedAsCompliant(): void
    {
        $report = $this->checker([
            'example.test' => ['v=spf1 include:_spf.provider.test -all'],
            'selecteur._domainkey.example.test' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkq'],
            '_dmarc.example.test' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@example.test'],
        ])->check('example.test', 'selecteur');

        self::assertSame('ok', $report['spf']['status']);
        self::assertSame('ok', $report['dkim']['status']);
        self::assertSame('ok', $report['dmarc']['status']);
        self::assertSame('quarantine', $report['dmarc']['policy']);
    }

    public function testAnEmptyZoneReportsEveryRecordAsMissing(): void
    {
        $report = $this->checker([])->check('example.test', 'selecteur');

        self::assertSame('missing', $report['spf']['status']);
        self::assertSame('missing', $report['dkim']['status']);
        self::assertSame('missing', $report['dmarc']['status']);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function spfRecords(): array
    {
        return [
            ['v=spf1 include:_spf.provider.test -all', 'ok'],
            ['v=spf1 mx ~all', 'ok'],
            // `+all` autorise n'importe quel émetteur : la protection est nulle.
            ['v=spf1 +all', 'weak'],
            ['v=spf1 include:_spf.provider.test all', 'weak'],
        ];
    }

    #[DataProvider('spfRecords')]
    public function testAPermissiveSpfIsFlagged(string $record, string $expected): void
    {
        $report = $this->checker(['example.test' => [$record]])->check('example.test');

        self::assertSame($expected, $report['spf']['status']);
        self::assertSame($record, $report['spf']['value']);
    }

    public function testOtherTxtRecordsDoNotMasqueradeAsSpf(): void
    {
        $report = $this->checker([
            'example.test' => ['google-site-verification=abc', 'v=spf1 -all'],
        ])->check('example.test');

        self::assertSame('ok', $report['spf']['status']);
        self::assertSame('v=spf1 -all', $report['spf']['value']);
    }

    public function testDkimIsNotCheckableWithoutASelector(): void
    {
        $report = $this->checker([
            'selecteur._domainkey.example.test' => ['v=DKIM1; p=MIIBIjANBgkq'],
        ])->check('example.test');

        self::assertSame('unknown', $report['dkim']['status']);
        self::assertSame('', $report['dkim']['selector']);
    }

    public function testARevokedDkimKeyIsFlagged(): void
    {
        $report = $this->checker([
            'selecteur._domainkey.example.test' => ['v=DKIM1; k=rsa; p='],
        ])->check('example.test', 'selecteur');

        self::assertSame('weak', $report['dkim']['status']);
    }

    public function testThePublicKeyIsNeverEchoedBack(): void
    {
        $report = $this->checker([
            'selecteur._domainkey.example.test' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'],
        ])->check('example.test', 'selecteur');

        self::assertStringNotContainsString('MIIBIjAN', $report['dkim']['value']);
        self::assertStringContainsString('p=…', $report['dkim']['value']);
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function dmarcRecords(): array
    {
        return [
            ['v=DMARC1; p=reject', 'ok', 'reject'],
            ['v=DMARC1; p=quarantine; pct=100', 'ok', 'quarantine'],
            // `p=none` n'applique aucune règle : c'est un mode observation.
            ['v=DMARC1; p=none; rua=mailto:a@example.test', 'weak', 'none'],
            ['v=DMARC1; rua=mailto:a@example.test', 'weak', 'none'],
        ];
    }

    #[DataProvider('dmarcRecords')]
    public function testDmarcPolicyIsRead(string $record, string $status, string $policy): void
    {
        $report = $this->checker(['_dmarc.example.test' => [$record]])->check('example.test');

        self::assertSame($status, $report['dmarc']['status']);
        self::assertSame($policy, $report['dmarc']['policy']);
    }

    public function testALongTxtRecordSplitInSegmentsIsReassembled(): void
    {
        $checker = new MailDnsChecker(
            static fn (string $host, int $type): array|false => $host === '_dmarc.example.test'
                ? [['entries' => ['v=DMARC1; p=re', 'ject; rua=mailto:a@example.test']]]
                : false
        );

        $report = $checker->check('example.test');

        self::assertSame('ok', $report['dmarc']['status']);
        self::assertSame('reject', $report['dmarc']['policy']);
    }

    public function testAnEmptyDomainIsNotChecked(): void
    {
        $report = $this->checker([])->check('');

        self::assertSame('unknown', $report['spf']['status']);
        self::assertSame('unknown', $report['dkim']['status']);
        self::assertSame('unknown', $report['dmarc']['status']);
    }
}
