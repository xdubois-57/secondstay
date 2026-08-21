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
 * Deux choix commandent le coût, qui se paie sur **chaque** requête de la
 * campagne :
 *
 * 1. **la liste des sources est mise en cache.** Parcourir `src/` à chaque
 *    requête coûtait trois cents appels système pour un résultat invariant ;
 * 2. **ce qui est écrit est réduit au strict nécessaire** — par fichier, les
 *    seules lignes réellement exécutées. Sérialiser l'objet de couverture
 *    entier écrivait aussi son filtre, ses caches d'analyse statique,
 *    l'identité des tests et les lignes exécutables des trois cents fichiers
 *    du filtre : cinquante fois plus d'octets pour la même information.
 *
 * Le serveur intégré traite les requêtes dans des processus distincts et sans
 * état partagé : chaque requête écrit donc son propre fichier, qu'un fichier
 * commun aurait corrompu. `scripts/coverage-merge.php` les fusionne ensuite.
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

    $sources = secondstay_coverage_sources($root, $directory);
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

            // Seules les lignes réellement exécutées sont écrites. La
            // bibliothèque rend aussi les lignes exécutables de **tous** les
            // fichiers du filtre — trois cents fichiers à chaque requête, pour
            // une information que SonarQube déduit déjà du code lui-même. Ne
            // garder que ce qui a été atteint divise la taille par vingt.
            $payload = [];
            foreach ($coverage->getData()->lineCoverage() as $file => $lines) {
                $covered = [];
                foreach ($lines as $line => $tests) {
                    if ($tests !== []) {
                        $covered[] = $line;
                    }
                }

                if ($covered !== []) {
                    $payload[$file] = $covered;
                }
            }

            if ($payload === []) {
                return;
            }

            $file = sprintf(
                '%s/%s-%d-%s.cov.gz',
                $directory,
                gmdate('Ymd\THis'),
                getmypid() ?: 0,
                bin2hex(random_bytes(6))
            );

            file_put_contents($file, gzencode(serialize($payload), 1), LOCK_EX);
        } catch (Throwable) {
            // Une requête dont la couverture n'a pas pu être écrite ne doit
            // pas faire échouer le scénario qui l'a provoquée.
        }
    });
})();

/**
 * Fichiers source à mesurer, mis en cache après le premier parcours.
 *
 * @return list<string>
 */
function secondstay_coverage_sources(string $root, string $directory): array
{
    $cache = $directory . '/sources.json';

    $raw = @file_get_contents($cache);
    if (is_string($raw) && $raw !== '') {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && $decoded !== []) {
            /** @var list<string> $decoded */
            return $decoded;
        }
    }

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

    sort($sources);
    @file_put_contents($cache, (string) json_encode($sources), LOCK_EX);

    return $sources;
}
