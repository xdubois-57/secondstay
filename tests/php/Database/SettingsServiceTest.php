<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingDefinition;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Settings\SettingType;
use SecondStay\Tests\Support\DatabaseTestCase;

final class SettingsServiceTest extends DatabaseTestCase
{
    private SettingsService $settings;

    private Encryptor $encryptor;

    private SettingRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());
        $this->registry = new SettingRegistry();
        $this->registry->add(new SettingDefinition('smtp.password', SettingType::Secret, null, 'mail'));

        $this->settings = new SettingsService(
            $this->registry,
            new SettingsRepository($this->database),
            $this->encryptor,
            new \SecondStay\Settings\SettingValidator(),
            new AuditTrail($this->database),
        );
    }

    public function testDefaultsAreReturnedWhenNothingIsStored(): void
    {
        self::assertSame('mandatory', $this->settings->string('pricing.cleaning_mode'));
        self::assertSame(10000, $this->settings->money('pricing.cleaning_price'));
        self::assertFalse($this->settings->bool('booking.saturday_to_saturday'));
    }

    public function testMoneyIsStoredInIntegerCents(): void
    {
        $this->settings->setMany(['pricing.cleaning_price' => '120,50']);

        self::assertSame(12050, $this->settings->money('pricing.cleaning_price'));
    }

    public function testTypedValidationRejectsInvalidValues(): void
    {
        try {
            $this->settings->setMany([
                'booking.max_guests' => 'beaucoup',
                'property.contact_email' => 'pas-un-email',
                'site.default_locale' => 'es',
                'site.public_url' => 'ftp://example.test',
            ]);
            self::fail('La validation aurait dû échouer.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            self::assertSame('settings.error.integer', $errors['booking.max_guests']);
            self::assertSame('settings.error.email', $errors['property.contact_email']);
            self::assertSame('settings.error.enum', $errors['site.default_locale']);
            self::assertSame('settings.error.url_scheme', $errors['site.public_url']);
        }
    }

    public function testRangeIsEnforced(): void
    {
        $this->expectException(ValidationException::class);
        $this->settings->setMany(['pricing.deposit_percent' => '150']);
    }

    public function testSecretsAreEncryptedAtRestAndNeverDisplayedInFull(): void
    {
        $this->settings->setMany(['smtp.password' => 'sup3r-s3cret-value']);

        $raw = (string) $this->database->fetchValue(
            'SELECT `value` FROM `setting` WHERE `key` = :key',
            ['key' => 'smtp.password']
        );

        self::assertStringStartsWith('ss1.', $raw);
        self::assertStringNotContainsString('sup3r', $raw);
        self::assertSame('sup3r-s3cret-value', $this->settings->get('smtp.password'));

        $preview = $this->settings->secretPreview('smtp.password');
        self::assertStringEndsWith('alue', $preview);
        self::assertStringNotContainsString('sup3r', $preview);
        self::assertTrue($this->settings->isSecretDefined('smtp.password'));
    }

    public function testEmptySecretKeepsThePreviousValue(): void
    {
        $this->settings->setMany(['smtp.password' => 'first-secret-value']);
        $this->settings->setMany(['smtp.password' => '']);

        self::assertSame('first-secret-value', $this->settings->get('smtp.password'));
    }

    public function testSecretsAreExcludedFromSafeExport(): void
    {
        $this->settings->setMany(['smtp.password' => 'another-secret-value']);
        $export = $this->settings->exportSafe();

        self::assertSame('***defined***', $export['smtp.password']);
        self::assertStringNotContainsString('another-secret', (string) json_encode($export));
    }

    public function testKeyRotationRewritesSecretsWithTheActiveKey(): void
    {
        $firstKey = Encryptor::generateKey();
        $secondKey = Encryptor::generateKey();

        $repository = new SettingsRepository($this->database);
        $initial = new SettingsService($this->registry, $repository, new Encryptor(['k1' => $firstKey], 'k1'));
        $initial->setMany(['smtp.password' => 'rotate-me-please']);

        $rotating = new SettingsService(
            $this->registry,
            $repository,
            new Encryptor(['k1' => $firstKey, 'k2' => $secondKey], 'k2')
        );

        self::assertSame(['smtp.password'], $rotating->rotateSecrets());
        self::assertSame('rotate-me-please', $rotating->get('smtp.password'));

        $raw = (string) $this->database->fetchValue(
            'SELECT `value` FROM `setting` WHERE `key` = :key',
            ['key' => 'smtp.password']
        );
        self::assertStringStartsWith('ss1.k2.', $raw);
    }

    public function testSensitiveModuleChangesAreAudited(): void
    {
        $this->settings->setMany(['pricing.cleaning_price' => '150.00'], 'owner@example.test', 1);

        $events = (new AuditTrail($this->database))->forEntity('setting', 'pricing.cleaning_price');
        self::assertCount(1, $events);
        self::assertSame('settings.updated', $events[0]['action']);
        self::assertSame('owner@example.test', $events[0]['actor_label']);
    }

    public function testSecretValuesNeverAppearInAudit(): void
    {
        $this->registry->add(new SettingDefinition('update.token', SettingType::Secret, null, 'update'));
        $this->settings->setMany(['update.token' => 'never-audit-this'], 'owner@example.test', 1);

        $events = (new AuditTrail($this->database))->recent();
        self::assertStringNotContainsString('never-audit-this', (string) json_encode($events));
    }

    public function testUnknownSettingIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->settings->setMany(['unknown.key' => 'x']);
    }
}
