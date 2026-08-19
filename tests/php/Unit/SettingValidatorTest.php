<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Settings\SettingDefinition;
use SecondStay\Settings\SettingType;
use SecondStay\Settings\SettingValidator;

final class SettingValidatorTest extends TestCase
{
    private SettingValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SettingValidator();
    }

    private function definition(SettingType $type, mixed ...$args): SettingDefinition
    {
        return new SettingDefinition('demo.key', $type, ...$args);
    }

    private function value(SettingDefinition $definition, mixed $raw): mixed
    {
        $result = $this->validator->validate($definition, $raw);
        if ($result['ok'] === false) {
            self::fail('Valeur refusée alors qu’elle devait être acceptée : ' . $result['error']);
        }

        return $result['value'];
    }

    private function error(SettingDefinition $definition, mixed $raw): string
    {
        $result = $this->validator->validate($definition, $raw);
        if ($result['ok'] === true) {
            self::fail('Valeur acceptée alors qu’elle devait être refusée.');
        }

        return $result['error'];
    }

    public function testMoneyAcceptsBothDecimalSeparators(): void
    {
        $definition = $this->definition(SettingType::Money);

        self::assertSame(12050, $this->value($definition, '120,50'));
        self::assertSame(12050, $this->value($definition, '120.50'));
        self::assertSame(10000, $this->value($definition, '100'));
        self::assertSame(123456, $this->value($definition, '1 234,56'));
    }

    public function testMoneyRejectsInvalidValues(): void
    {
        $definition = $this->definition(SettingType::Money);

        self::assertSame('settings.error.money', $this->error($definition, '12,345'));
        self::assertSame('settings.error.money', $this->error($definition, 'cent euros'));
    }

    public function testIntegerRangeIsEnforced(): void
    {
        $definition = new SettingDefinition('demo.key', SettingType::Integer, null, 'core', min: 1, max: 10);

        self::assertSame(5, $this->value($definition, '5'));
        self::assertSame('settings.error.too_small', $this->error($definition, '0'));
        self::assertSame('settings.error.too_large', $this->error($definition, '11'));
        self::assertSame('settings.error.integer', $this->error($definition, '3.5'));
    }

    public function testBooleanCoercion(): void
    {
        $definition = $this->definition(SettingType::Bool);

        self::assertTrue($this->value($definition, '1'));
        self::assertTrue($this->value($definition, 'on'));
        self::assertTrue($this->value($definition, true));
        self::assertFalse($this->value($definition, '0'));
        self::assertFalse($this->value($definition, null));
    }

    public function testEnumRestrictsValues(): void
    {
        $definition = new SettingDefinition('demo.key', SettingType::Enum, null, 'core', enumValues: ['a', 'b']);

        self::assertSame('a', $this->value($definition, 'a'));
        self::assertSame('settings.error.enum', $this->error($definition, 'c'));
    }

    public function testTimeAndDateFormats(): void
    {
        self::assertSame('16:00', $this->value($this->definition(SettingType::Time), '16:00'));
        self::assertSame('settings.error.time', $this->error($this->definition(SettingType::Time), '25:00'));
        self::assertSame('2026-07-04', $this->value($this->definition(SettingType::Date), '2026-07-04'));
        self::assertSame('settings.error.date', $this->error($this->definition(SettingType::Date), '04/07/2026'));
    }

    public function testUrlMustBeHttp(): void
    {
        $definition = $this->definition(SettingType::Url);

        self::assertSame('https://example.test', $this->value($definition, 'https://example.test'));
        self::assertSame('settings.error.url_scheme', $this->error($definition, 'ftp://example.test'));
        self::assertSame('settings.error.url', $this->error($definition, 'pas une url'));
    }

    public function testJsonMustDecodeToAStructure(): void
    {
        $definition = $this->definition(SettingType::Json);

        self::assertSame(['a' => 1], $this->value($definition, '{"a":1}'));
        self::assertSame('settings.error.json', $this->error($definition, '{invalide}'));
    }

    public function testRequiredFieldsRejectEmptyValues(): void
    {
        $definition = new SettingDefinition('demo.key', SettingType::String, null, 'core', required: true);

        self::assertSame('settings.error.required', $this->error($definition, ''));
    }

    public function testOptionalEmptyValueBecomesNull(): void
    {
        self::assertNull($this->value($this->definition(SettingType::String), ''));
    }

    public function testDurationIsExpressedInMinutes(): void
    {
        $definition = new SettingDefinition('demo.key', SettingType::Duration, null, 'core', min: 5, max: 60);

        self::assertSame(30, $this->value($definition, '30'));
        self::assertSame('settings.error.too_large', $this->error($definition, '120'));
        self::assertSame('settings.error.duration', $this->error($definition, '-5'));
    }

    public function testStringLengthLimit(): void
    {
        $definition = new SettingDefinition('demo.key', SettingType::String, null, 'core', max: 5);

        self::assertSame('abcde', $this->value($definition, 'abcde'));
        self::assertSame('settings.error.too_long', $this->error($definition, 'abcdef'));
    }

    public function testInputTypesAreUsable(): void
    {
        self::assertSame('checkbox', SettingType::Bool->inputType());
        self::assertSame('password', SettingType::Secret->inputType());
        self::assertSame('textarea', SettingType::Text->inputType());
        self::assertSame('select', SettingType::Enum->inputType());
        self::assertSame('number', SettingType::Money->inputType());
        self::assertTrue(SettingType::Secret->isSecret());
        self::assertFalse(SettingType::String->isSecret());
    }
}
