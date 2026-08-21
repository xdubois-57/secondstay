<?php

declare(strict_types=1);

/**
 * Fusionne les couvertures écrites requête par requête pendant la campagne
 * E2E en un seul rapport Clover (TESTING.md §14).
 *
 * Usage :
 *   php scripts/coverage-merge.php <répertoire> <clover.xml>
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require __DIR__ . '/../vendor/autoload.php';

$directory = $argv[1] ?? '';
$target = $argv[2] ?? '';

if ($directory === '' || $target === '') {
    fwrite(STDERR, "Usage: php scripts/coverage-merge.php <répertoire> <clover.xml>\n");
    exit(2);
}

$files = glob(rtrim($directory, '/') . '/*.cov.gz') ?: [];
if ($files === []) {
    // Rien à fusionner : c'est une anomalie de configuration, pas un rapport
    // vide qu'il faudrait publier comme s'il était valide.
    fwrite(STDERR, "Aucune couverture trouvée dans {$directory}.\n");
    exit(1);
}

$merged = null;
$merged_count = 0;

foreach ($files as $file) {
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        continue;
    }

    $raw = @gzdecode($raw);
    if ($raw === false) {
        continue;
    }

    $coverage = @unserialize($raw, ['allowed_classes' => true]);
    if (!$coverage instanceof CodeCoverage) {
        continue;
    }

    if ($merged === null) {
        $merged = $coverage;
    } else {
        $merged->merge($coverage);
    }

    $merged_count++;
}

if ($merged === null) {
    fwrite(STDERR, "Aucune couverture lisible dans {$directory}.\n");
    exit(1);
}

$parent = dirname($target);
if (!is_dir($parent) && !mkdir($parent, 0o750, true) && !is_dir($parent)) {
    fwrite(STDERR, "Impossible de créer {$parent}.\n");
    exit(1);
}

(new Clover())->process($merged, $target);

printf("Couverture E2E fusionnée : %d requêtes → %s\n", $merged_count, $target);
