<?php

declare(strict_types=1);

/**
 * Construit et/ou inspecte l'artefact ZIP de production.
 *
 * Usage :
 *   php scripts/release-artifact.php build [chemin.zip]
 *   php scripts/release-artifact.php inspect chemin.zip
 *   php scripts/release-artifact.php verify   (build dans build/ puis inspect)
 */

require __DIR__ . '/../vendor/autoload.php';

use SecondStay\Release\ReleaseArtifactBuilder;
use SecondStay\Release\ReleaseArtifactInspector;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'verify';

$version = trim((string) file_get_contents($root . '/VERSION'));
$defaultZip = $root . '/build/release/secondstay-' . $version . '.zip';

function fail(string $message): never
{
    fwrite(STDERR, "\033[0;31m" . $message . "\033[0m" . PHP_EOL);
    exit(1);
}

/**
 * @param list<string> $problems
 */
function report(array $problems, string $zipPath): void
{
    if ($problems === []) {
        fwrite(STDOUT, "\033[0;32m  ✔ Artefact conforme : " . $zipPath . "\033[0m" . PHP_EOL);

        return;
    }

    fwrite(STDERR, "\033[0;31m  ✘ Artefact non conforme :\033[0m" . PHP_EOL);
    foreach ($problems as $problem) {
        fwrite(STDERR, '      - ' . $problem . PHP_EOL);
    }
    exit(1);
}

switch ($command) {
    case 'build':
        $zipPath = $argv[2] ?? $defaultZip;
        $builder = new ReleaseArtifactBuilder($root, $root . '/build/release-staging');
        $builder->build($zipPath);
        fwrite(STDOUT, $zipPath . PHP_EOL);
        break;

    case 'inspect':
        $zipPath = $argv[2] ?? fail('Chemin du ZIP requis.');
        report((new ReleaseArtifactInspector())->inspect($zipPath), $zipPath);
        break;

    case 'verify':
        $zipPath = $argv[2] ?? $defaultZip;
        $builder = new ReleaseArtifactBuilder($root, $root . '/build/release-staging');
        $builder->build($zipPath);
        report((new ReleaseArtifactInspector())->inspect($zipPath), $zipPath);
        break;

    default:
        fail('Commande inconnue : ' . $command);
}
