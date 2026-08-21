<?php

declare(strict_types=1);

/**
 * Collecte de couverture PHP pendant la campagne E2E (TESTING.md §14).
 *
 * Sans cela, les contrôleurs apparaissent comme non couverts alors que la
 * campagne Playwright les traverse de bout en bout : la mesure décrivait
 * l'outillage, pas le produit.
 *
 * Ce fichier n'est chargé que par le routeur du serveur de développement, et
 * seulement lorsque `SECONDSTAY_COVERAGE_DIR` est défini — comme les
 * fournisseurs factices, la collecte s'active par variable d'environnement et
 * n'est jamais sélectionnable depuis l'interface.
 *
 * Chaque requête écrit son propre fichier : le serveur intégré tourne avec
 * plusieurs processus, et un fichier partagé serait corrompu par les écritures
 * concurrentes. `scripts/coverage-merge.php` les fusionne ensuite.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

(static function (): void {
    $directory = getenv('SECONDSTAY_COVERAGE_DIR');
    if ($directory === false || $directory === '') {
        return;
    }

    if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
        return;
    }

    $root = dirname(__DIR__);

    // La liste de fichiers est explicite : `Filter` ne connaît que des
    // fichiers, et n'inclure que `src/` évite de mesurer le vendor ou les
    // gabarits compilés.
    $sources = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[] = $file->getPathname();
        }
    }

    if ($sources === []) {
        return;
    }

    $filter = new Filter();
    $filter->includeFiles($sources);

    try {
        $coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
    } catch (Throwable) {
        // Aucun pilote de couverture : la campagne doit tourner quand même,
        // sans mesure. Un échec ici ne dit rien du produit.
        return;
    }

    $coverage->start('e2e');

    register_shutdown_function(static function () use ($coverage, $directory): void {
        try {
            $coverage->stop();

            $file = sprintf(
                '%s/%s-%d-%s.cov.gz',
                $directory,
                gmdate('Ymd\THis'),
                getmypid() ?: 0,
                bin2hex(random_bytes(6))
            );

            // Une campagne complète produit plusieurs milliers de fichiers :
            // compressés, ils tiennent sur le disque d'un exécuteur
            // d'intégration continue, ce qui n'est pas le cas autrement.
            file_put_contents($file, gzencode(serialize($coverage), 6), LOCK_EX);
        } catch (Throwable) {
            // Une requête dont la couverture n'a pas pu être écrite ne doit
            // pas faire échouer le scénario qui l'a provoquée.
        }
    });
})();
