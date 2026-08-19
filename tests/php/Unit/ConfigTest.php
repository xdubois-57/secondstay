<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Config;

final class ConfigTest extends TestCase
{
    public function testDotAccess(): void
    {
        $config = new Config(['app' => ['name' => 'SecondStay', 'debug' => false], 'list' => ['a', 'b']]);

        self::assertSame('SecondStay', $config->string('app.name'));
        self::assertFalse($config->bool('app.debug'));
        self::assertSame(['a', 'b'], $config->listOfStrings('list'));
        self::assertNull($config->get('app.unknown'));
        self::assertSame('default', $config->string('app.unknown', 'default'));
    }

    public function testSetCreatesNestedKeys(): void
    {
        $config = new Config();
        $config->set('database.host', '127.0.0.1');
        $config->set('database.port', 3306);

        self::assertSame('127.0.0.1', $config->string('database.host'));
        self::assertSame(3306, $config->int('database.port'));
    }

    public function testBooleanCoercion(): void
    {
        $config = new Config(['a' => 'true', 'b' => 'off', 'c' => 1, 'd' => 'yes']);

        self::assertTrue($config->bool('a'));
        self::assertFalse($config->bool('b'));
        self::assertTrue($config->bool('c'));
        self::assertTrue($config->bool('d'));
    }

    public function testLoadReadsDefaults(): void
    {
        $config = Config::load(dirname(__DIR__, 3));

        self::assertSame('SecondStay', $config->string('app.name'));
        self::assertSame(['fr', 'en', 'nl', 'de'], $config->listOfStrings('i18n.locales'));
        self::assertSame('', $config->string('security.encryption_key'), 'Aucun secret ne doit être versionné');
        self::assertSame('', $config->string('database.password'), 'Aucun secret ne doit être versionné');
    }
}
