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
        $config = Config::load($this->sandbox());

        self::assertSame('SecondStay', $config->string('app.name'));
        self::assertSame(['fr', 'en', 'nl', 'de'], $config->listOfStrings('i18n.locales'));
    }

    /**
     * Les valeurs par défaut versionnées ne contiennent jamais de secret ni de
     * donnée propre à une installation (AGENTS.md §1.4).
     */
    public function testVersionedDefaultsContainNoSecret(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require dirname(__DIR__, 3) . '/config/app.php';
        $config = new Config($defaults);

        self::assertSame('', $config->string('security.encryption_key'));
        self::assertSame('', $config->string('database.password'));
        self::assertSame('', $config->string('database.user'));
        self::assertSame('', $config->string('database.name'));
    }

    public function testLocalConfigOverridesDefaults(): void
    {
        $root = $this->sandbox();
        file_put_contents(
            $root . '/config/local.php',
            "<?php return ['app' => ['env' => 'testing'], 'database' => ['name' => 'demo']];"
        );

        $config = Config::load($root);

        self::assertSame('testing', $config->string('app.env'));
        self::assertSame('demo', $config->string('database.name'));
        self::assertSame('SecondStay', $config->string('app.name'), 'La fusion doit rester profonde');
    }

    /**
     * Racine de projet temporaire : les tests ne doivent jamais dépendre de la
     * présence ou de l'absence d'une installation locale dans le dépôt.
     */
    private function sandbox(): string
    {
        $root = sys_get_temp_dir() . '/secondstay-config-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0o750, true);
        copy(dirname(__DIR__, 3) . '/config/app.php', $root . '/config/app.php');
        $this->sandboxes[] = $root;

        return $root;
    }

    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $root) {
            foreach (glob($root . '/config/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($root . '/config');
            @rmdir($root);
        }
        $this->sandboxes = [];
    }
}
