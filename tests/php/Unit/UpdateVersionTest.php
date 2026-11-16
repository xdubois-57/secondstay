<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Update\UpdateVersion;

/**
 * Les deux formes de `VERSION` : une release et une construction de
 * développement. Ce que PHP ne sait pas comparer, c'est l'une à l'autre.
 */
final class UpdateVersionTest extends TestCase
{
    public function testFormsAreRecognised(): void
    {
        self::assertTrue(UpdateVersion::isRelease('1.2.3'));
        self::assertFalse(UpdateVersion::isDev('1.2.3'));
        self::assertTrue(UpdateVersion::isDev('1.2.3-dev.abc1234'));
        self::assertSame('abc1234', UpdateVersion::commit('1.2.3-dev.abc1234'));
        self::assertNull(UpdateVersion::commit('1.2.3'));
        self::assertSame('1.2.3', UpdateVersion::base('1.2.3-dev.abc1234'));
        self::assertSame('0.0.0', UpdateVersion::base('n’importe quoi'));

        foreach (['', 'v1.2.3', '1.2', '1.2.3-dev.', '1.2.3-dev.XYZ1234', '1.2.3-beta.1', "1.2.3\n"] as $invalid) {
            self::assertFalse(UpdateVersion::isValid($invalid), $invalid);
        }
    }

    /**
     * @return list<array{string, string, bool}>
     */
    public static function newerReleases(): array
    {
        return [
            ['1.2.4', '1.2.3', true],
            ['1.2.3', '1.2.3', false],
            ['1.2.2', '1.2.3', false],
            // Une construction de développement contient des commits
            // postérieurs à sa base : la release de même numéro est plus
            // ancienne qu'elle — `version_compare()` dirait l'inverse.
            ['1.2.3', '1.2.3-dev.abc1234', false],
            ['1.2.4', '1.2.3-dev.abc1234', true],
            ['1.10.0', '1.9.9', true],
            // Seule une release est candidate.
            ['1.2.4-dev.abc1234', '1.2.3', false],
            ['2.0.0', '0.0.0', true],
        ];
    }

    #[DataProvider('newerReleases')]
    public function testOnlyAStrictlyHigherReleaseIsNewer(string $candidate, string $installed, bool $expected): void
    {
        self::assertSame($expected, UpdateVersion::isNewerRelease($candidate, $installed));
    }
}
