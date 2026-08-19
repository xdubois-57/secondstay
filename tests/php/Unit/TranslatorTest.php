<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/secondstay-translator-' . bin2hex(random_bytes(4));
        foreach (Locales::ALL as $locale) {
            mkdir($this->path . '/' . $locale, 0o770, true);
        }

        file_put_contents(
            $this->path . '/fr/demo.php',
            "<?php return ['hello' => 'Bonjour {name}', 'nested' => ['deep' => 'Profond'], 'items' => 'aucun|un|{count} éléments'];"
        );
        file_put_contents(
            $this->path . '/en/demo.php',
            "<?php return ['hello' => 'Hello {name}', 'nested' => ['deep' => 'Deep'], 'items' => 'none|one|{count} items'];"
        );
        file_put_contents($this->path . '/nl/demo.php', "<?php return ['hello' => 'Hallo {name}'];");
        file_put_contents($this->path . '/de/demo.php', "<?php return ['hello' => 'Hallo {name}'];");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->path);
    }

    public function testTranslatesWithParameters(): void
    {
        $translator = new Translator($this->path, 'fr');
        self::assertSame('Bonjour Claire', $translator->trans('demo.hello', ['name' => 'Claire']));

        $translator->setLocale('en');
        self::assertSame('Hello Claire', $translator->trans('demo.hello', ['name' => 'Claire']));
    }

    public function testNestedKeysAreFlattened(): void
    {
        $translator = new Translator($this->path, 'fr');
        self::assertSame('Profond', $translator->trans('demo.nested.deep'));
    }

    public function testFallsBackToDefaultLocale(): void
    {
        $translator = new Translator($this->path, 'nl', 'fr');
        self::assertSame('Profond', $translator->trans('demo.nested.deep'));
    }

    public function testMissingKeyNeverLeaksRawKey(): void
    {
        $translator = new Translator($this->path, 'fr');
        self::assertSame('Absent key', $translator->trans('demo.absent_key'));
    }

    public function testMissingKeysAreCollectedWhenRequested(): void
    {
        $translator = new Translator($this->path, 'fr', 'fr', true);
        $translator->trans('demo.absent_key');
        self::assertSame(['demo.absent_key'], $translator->missingKeys());
    }

    public function testPluralisation(): void
    {
        $translator = new Translator($this->path, 'fr');
        self::assertSame('aucun', $translator->transChoice('demo.items', 0));
        self::assertSame('un', $translator->transChoice('demo.items', 1));
        self::assertSame('4 éléments', $translator->transChoice('demo.items', 4));
    }

    public function testUnsupportedLocaleFallsBackImmediately(): void
    {
        $translator = new Translator($this->path, 'es', 'fr');
        self::assertSame('fr', $translator->locale());
    }

    public function testHasKey(): void
    {
        $translator = new Translator($this->path, 'fr');
        self::assertTrue($translator->has('demo.hello'));
        self::assertFalse($translator->has('demo.nope'));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
