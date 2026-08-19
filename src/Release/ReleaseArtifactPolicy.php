<?php

declare(strict_types=1);

namespace SecondStay\Release;

/**
 * Politique de contenu de l'artefact de production (RELEASE.md §7).
 *
 * Source de vérité unique utilisée à la fois par le constructeur du ZIP et
 * par son inspecteur, afin qu'aucun des deux ne puisse dériver.
 */
final class ReleaseArtifactPolicy
{
    /**
     * Chemins (relatifs à la racine du dépôt) copiés dans l'artefact.
     *
     * @var list<string>
     */
    public const INCLUDED_PATHS = [
        '.htaccess',
        'public',
        'src',
        'templates',
        'translations',
        'migrations',
        'config/app.php',
        'config/local.php.dist',
        'VERSION',
        'LICENSE',
    ];

    /**
     * Entrées obligatoires du ZIP : leur absence invalide la release.
     *
     * @var list<string>
     */
    public const REQUIRED_ENTRIES = [
        '.htaccess',
        'VERSION',
        'LICENSE',
        'public/index.php',
        'public/.htaccess',
        'public/assets/css/app.css',
        'public/assets/js/app.js',
        'src/Core/Kernel.php',
        'src/Core/PublicPathPolicy.php',
        'templates/layout/base.html.twig',
        'translations/fr/common.php',
        'translations/en/common.php',
        'translations/nl/common.php',
        'translations/de/common.php',
        'config/app.php',
        'vendor/autoload.php',
    ];

    /**
     * Motifs interdits dans le ZIP (expressions régulières sur le chemin).
     *
     * @var array<string, string> motif => explication
     */
    public const FORBIDDEN_PATTERNS = [
        '#(^|/)\.git(/|$)#' => 'historique git',
        '#(^|/)\.github(/|$)#' => 'workflows GitHub',
        '#^tests/#' => 'tests',
        '#^storage/#' => 'données runtime',
        '#^node_modules/#' => 'dépendances npm',
        '#^coverage/#' => 'rapports de couverture',
        '#^build/#' => 'répertoire de build',
        '#^test-results/#' => 'résultats de tests',
        '#^playwright-report/#' => 'rapport Playwright',
        '#^scripts/#' => 'outillage local',
        '#(^|/)\.env#' => 'fichier d’environnement',
        '#^config/local\.php$#' => 'configuration locale',
        '#^composer\.(json|lock)$#' => 'métadonnées Composer du projet',
        '#^package(-lock)?\.json$#' => 'métadonnées npm',
        '#^phpunit\.xml#' => 'configuration PHPUnit',
        '#^phpstan\.neon#' => 'configuration PHPStan',
        '#^playwright\.config\.js$#' => 'configuration Playwright',
        '#^vitest\.config\.js$#' => 'configuration Vitest',
        '#^sonar-project\.properties$#' => 'configuration SonarCloud',
        '#\.(pem|key|p12|pfx)$#' => 'matériel cryptographique',
        '#(^|/)\.DS_Store$#' => 'fichier système',
        '#(^|/)\.idea(/|$)#' => 'état IDE',
        '#(^|/)\.vscode(/|$)#' => 'état IDE',
        '#(^|/)CLAUDE\.md$#' => 'documentation agent',
        '#(^|/)AGENTS\.md$#' => 'documentation agent',
        '#^[A-Z]+\.md$#' => 'documentation interne',
    ];

    /**
     * Répertoires de `vendor/` supprimés de l'artefact (tests et docs des
     * dépendances : inutiles en production et sources de bruit sécurité).
     *
     * @var list<string>
     */
    public const VENDOR_PRUNED_DIRECTORIES = [
        'test', 'tests', 'Test', 'Tests', 'doc', 'docs', 'examples', 'example',
        'benchmark', 'benchmarks', '.github', '.git',
    ];

    /**
     * Fichiers d'outillage supprimés de `vendor/` : jamais utiles en
     * production et sources de faux positifs pour les scanners.
     *
     * @var list<string>
     */
    public const VENDOR_PRUNED_FILE_PATTERNS = [
        '#(^|/)phpunit\.xml(\.dist)?$#',
        '#(^|/)phpstan\.neon(\.dist)?$#',
        '#(^|/)psalm\.xml(\.dist)?$#',
        '#(^|/)\.travis\.yml$#',
        '#(^|/)\.editorconfig$#',
        '#(^|/)\.gitattributes$#',
        '#(^|/)\.gitignore$#',
        '#(^|/)Makefile$#',
        '#(^|/)[^/]+\.md$#',
        '#(^|/)\.php-cs-fixer[^/]*$#',
    ];

    /**
     * @return list<string> raisons de rejet ; tableau vide = artefact conforme
     *
     * @param list<string> $entries
     */
    public static function validate(array $entries): array
    {
        $problems = [];

        foreach (self::REQUIRED_ENTRIES as $required) {
            if (!in_array($required, $entries, true)) {
                $problems[] = 'Entrée obligatoire manquante : ' . $required;
            }
        }

        foreach ($entries as $entry) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $entry) === 1) {
                    $problems[] = sprintf('Entrée interdite (%s) : %s', $label, $entry);
                }
            }
        }

        if (!self::containsVendorAutoload($entries)) {
            $problems[] = 'vendor/autoload.php est absent : l’artefact n’est pas installable.';
        }

        return $problems;
    }

    /**
     * @param list<string> $entries
     */
    private static function containsVendorAutoload(array $entries): bool
    {
        return in_array('vendor/autoload.php', $entries, true);
    }
}
