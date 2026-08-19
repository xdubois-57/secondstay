<?php

declare(strict_types=1);

/**
 * Régénère MANIFEST.md à partir des fichiers de référence du projet.
 * À exécuter après toute modification de la documentation de référence.
 */

$root = dirname(__DIR__);
$files = [
    'AGENTS.md', 'ARCHITECTURE.md', 'CLAUDE.md', 'I18N.md', 'README.md',
    'RELEASE.md', 'ROADMAP.md', 'SECURITY.md', 'SPECIFICATIONS.md',
    'TESTING.md', 'VERSION',
];

$lines = ["# MANIFEST.md", '', 'Fichiers de référence SecondStay :', ''];
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, 'Fichier de référence manquant : ' . $file . PHP_EOL);
        exit(1);
    }
    $lines[] = sprintf(
        '- `%s` — %d bytes — sha256 `%s`',
        $file,
        filesize($path),
        hash_file('sha256', $path)
    );
}
$lines[] = '';

file_put_contents($root . '/MANIFEST.md', implode("\n", $lines));
fwrite(STDOUT, 'MANIFEST.md régénéré.' . PHP_EOL);
