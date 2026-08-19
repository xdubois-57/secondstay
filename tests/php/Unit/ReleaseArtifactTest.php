<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Release\ReleaseArtifactBuilder;
use SecondStay\Release\ReleaseArtifactInspector;
use SecondStay\Release\ReleaseArtifactPolicy;

final class ReleaseArtifactTest extends TestCase
{
    public function testPolicyRejectsMissingRequiredEntries(): void
    {
        $problems = ReleaseArtifactPolicy::validate(['public/index.php']);

        self::assertNotSame([], $problems);
        self::assertTrue(
            (bool) array_filter($problems, static fn (string $p): bool => str_contains($p, 'vendor/autoload.php'))
        );
    }

    public function testPolicyRejectsForbiddenEntries(): void
    {
        $forbidden = [
            'tests/php/bootstrap.php',
            'storage/logs/app.log',
            '.github/workflows/ci.yml',
            'node_modules/vitest/package.json',
            '.env',
            'config/local.php',
            'composer.json',
            'package.json',
            'coverage/lcov.info',
            'secret.pem',
            'AGENTS.md',
            'README.md',
            'scripts/check.sh',
        ];

        $problems = ReleaseArtifactPolicy::validate(
            array_merge(ReleaseArtifactPolicy::REQUIRED_ENTRIES, $forbidden)
        );

        foreach ($forbidden as $entry) {
            self::assertTrue(
                (bool) array_filter($problems, static fn (string $p): bool => str_contains($p, $entry)),
                $entry . ' doit être refusé dans l’artefact'
            );
        }
    }

    public function testPolicyAcceptsAMinimalValidArtifact(): void
    {
        $entries = array_merge(ReleaseArtifactPolicy::REQUIRED_ENTRIES, [
            'vendor/twig/twig/src/Environment.php',
            'vendor/twig/twig/composer.json',
            'migrations/0001_core.sql',
            'config/local.php.dist',
        ]);

        self::assertSame([], ReleaseArtifactPolicy::validate($entries));
    }

    public function testBuiltArtifactIsConformAndInstallable(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/vendor')) {
            self::markTestSkipped('vendor/ absent');
        }

        $staging = $root . '/build/test-release-staging';
        $zip = $root . '/build/test-release/secondstay-test.zip';

        $builder = new ReleaseArtifactBuilder($root, $staging);
        $entries = $builder->build($zip);

        try {
            self::assertSame([], (new ReleaseArtifactInspector())->inspect($zip));

            // L'artefact doit rester réellement exécutable.
            self::assertContains('vendor/autoload.php', $entries);
            self::assertContains('vendor/twig/twig/src/Environment.php', $entries);
            self::assertContains('vendor/twig/twig/src/Node/Expression/Test/DefinedTest.php', $entries);

            // Aucune dépendance de développement.
            foreach ($entries as $entry) {
                self::assertStringNotContainsString('vendor/phpunit/', $entry);
                self::assertStringNotContainsString('vendor/phpstan/', $entry);
            }

            // Les quatre langues sont livrées.
            foreach (['fr', 'en', 'nl', 'de'] as $locale) {
                self::assertContains('translations/' . $locale . '/common.php', $entries);
            }
        } finally {
            @unlink($zip);
            ReleaseArtifactBuilder::removeDirectory($staging);
            ReleaseArtifactBuilder::removeDirectory(dirname($zip));
        }
    }
}
