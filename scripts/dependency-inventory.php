<?php

declare(strict_types=1);

/**
 * Imprime, en Markdown, toutes les dépendances de ce projet avec leur version
 * et leur licence.
 *
 *   php scripts/dependency-inventory.php
 *
 * Écrit pour `scripts/release.sh`, qui replie la sortie dans les notes de
 * release. Tout l'intérêt est qu'une personne qui lit une release voie
 * exactement ce qui est parti, sans cloner le tag ni lancer deux
 * gestionnaires de paquets.
 *
 * Généré, jamais rédigé : une liste écrite une fois est une liste que
 * personne ne met à jour, et un inventaire faux est pire qu'un inventaire
 * absent.
 *
 * Quatre surfaces, et les quatre comptent pour des raisons différentes :
 *
 *  - **PHP, production.** Elles sont DANS le ZIP déployable. Leurs versions
 *    sont ce qui tourne chez l'hébergeur.
 *  - **PHP, développement.** Non déployées, mais ce sont les outils sur le
 *    verdict desquels la release repose. Une release qui dit « PHPUnit est
 *    passé » mérite qu'on sache quelle version de PHPUnit.
 *  - **JavaScript, développement.** Outillage de test seulement : la
 *    production sert du JavaScript non transformé, sans Node.
 *  - **Ressources embarquées.** Celles que rien n'installe et que tout le
 *    monde oublie. Elles ne sont dans aucun fichier de verrouillage : elles
 *    sont versionnées dans `public/assets/vendor/`. Ce sont les seules
 *    dépendances tierces que le navigateur d'un visiteur exécute réellement.
 *
 * Les versions sont lues dans les **fichiers de verrouillage**, jamais dans
 * `composer.json` ni `package.json` : une contrainte comme `^3.11` n'est pas
 * une version, et toute la valeur de cette liste est de dire ce qui est parti
 * plutôt que ce qui était permis.
 */

$root = dirname(__DIR__);

/**
 * Licences des ressources embarquées.
 *
 * Les seules valeurs tenues à la main de ce fichier, et inévitablement :
 * rien ne télécharge ces bibliothèques, il n'existe donc aucun fichier de
 * verrouillage d'où la lire. Le balayage ci-dessous pilote la liste, et une
 * ressource présente dans `public/assets/vendor/` sans entrée ici est
 * rapportée **inconnue** plutôt que passée sous silence — ajouter une
 * bibliothèque oblige donc à compléter cette carte, au lieu de laisser
 * l'inventaire silencieusement incomplet.
 *
 * @var array<string, string>
 */
const VENDOR_LICENCES = [
    'bootstrap' => 'MIT',
];

/**
 * Lit et décode un fichier JSON, ou échoue bruyamment.
 *
 * @return array<string, mixed>
 */
function inventoryReadJson(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "dependency-inventory : {$path} est absent\n");
        exit(1);
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "dependency-inventory : {$path} n'est pas du JSON valide\n");
        exit(1);
    }

    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * Les ressources embarquées, balayées depuis le disque.
 *
 * Balayées plutôt qu'énumérées ici, volontairement. Une copie tenue à la main
 * serait fausse dès la première montée de version, et fausse en silence — ce
 * qui est précisément le mode de défaillance qu'un inventaire existe pour
 * empêcher.
 *
 * La version est lue dans la bannière que ces bibliothèques minifiées portent
 * toutes en tête ; à défaut, elle est déclarée inconnue plutôt que devinée.
 *
 * @return array<string, array{version: string, licence: string}>
 */
function inventoryVendorAssets(string $root): array
{
    $base = $root . '/public/assets/vendor';
    if (!is_dir($base)) {
        return [];
    }

    $found = [];
    foreach ((array) scandir($base) as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        $directory = $base . '/' . $entry;
        if (!is_dir($directory)) {
            continue;
        }

        $version = inventoryBannerVersion($directory);
        $found[$entry] = [
            'version' => $version,
            'licence' => VENDOR_LICENCES[$entry] ?? '**inconnue — ajoutez-la à VENDOR_LICENCES**',
        ];
    }

    ksort($found);

    return $found;
}

/**
 * Première version trouvée dans la bannière d'un des fichiers d'un répertoire.
 */
function inventoryBannerVersion(string $directory): string
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS
    ));

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $handle = @fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            continue;
        }
        $head = (string) fread($handle, 400);
        fclose($handle);

        if (preg_match('/\bv?(\d+\.\d+\.\d+)\b/', $head, $matches) === 1) {
            return $matches[1];
        }
    }

    return '**inconnue — aucune bannière de version**';
}

/**
 * Un tableau Markdown de lignes nom / version / licence.
 *
 * La colonne licence n'est pas décorative. Ce projet est AGPL-3.0-or-later,
 * le copyleft le plus fort d'usage courant, et savoir si une dépendance peut
 * y être combinée dépend entièrement de cette colonne. L'imprimer à côté de la
 * version est ce qui permet à un lecteur de vérifier la réponse plutôt que de
 * la croire.
 *
 * @param array<string, array{version: string, licence: string}> $rows
 */
function inventoryTable(array $rows, string $emptyNote): string
{
    if ($rows === []) {
        return $emptyNote . "\n";
    }

    $out = "| Paquet | Version | Licence |\n|---|---|---|\n";
    foreach ($rows as $name => $row) {
        $out .= '| `' . $name . '` | ' . $row['version'] . ' | ' . $row['licence'] . " |\n";
    }

    return $out;
}

/**
 * @param array<string, mixed> $lock
 *
 * @return array<string, array{version: string, licence: string}>
 */
function inventoryComposerPackages(array $lock, string $key): array
{
    $packages = $lock[$key] ?? [];
    if (!is_array($packages)) {
        return [];
    }

    $rows = [];
    foreach ($packages as $package) {
        if (!is_array($package) || !isset($package['name'], $package['version'])) {
            continue;
        }
        $licence = $package['license'] ?? [];
        $licences = is_array($licence) ? $licence : [$licence];
        $rows[(string) $package['name']] = [
            'version' => (string) $package['version'],
            'licence' => $licences === []
                ? '**non déclarée**'
                : implode(' / ', array_map('strval', $licences)),
        ];
    }
    ksort($rows);

    return $rows;
}

/**
 * Les dépendances JavaScript de développement, à leur version verrouillée.
 *
 * `package-lock.json` v3 indexe l'arbre installé sous « packages », où les
 * dépendances directes apparaissent comme « node_modules/<nom> ». Seules
 * celles que `package.json` demande vraiment sont listées : l'arbre complet
 * fait plusieurs centaines d'entrées et personne ne le lit.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $lock
 *
 * @return array<string, array{version: string, licence: string}>
 */
function inventoryNpmPackages(array $manifest, array $lock): array
{
    $direct = [];
    foreach (['dependencies', 'devDependencies'] as $section) {
        $entries = $manifest[$section] ?? [];
        if (is_array($entries)) {
            foreach (array_keys($entries) as $name) {
                $direct[] = (string) $name;
            }
        }
    }

    $packages = $lock['packages'] ?? [];
    $packages = is_array($packages) ? $packages : [];

    $rows = [];
    foreach ($direct as $name) {
        $entry = $packages['node_modules/' . $name] ?? null;
        $licence = is_array($entry) ? ($entry['license'] ?? null) : null;
        $licences = is_array($licence) ? $licence : ($licence === null ? [] : [$licence]);
        $rows[$name] = [
            'version' => is_array($entry) && isset($entry['version'])
                ? (string) $entry['version']
                : 'non installée',
            'licence' => $licences === []
                ? '**non déclarée**'
                : implode(' / ', array_map('strval', $licences)),
        ];
    }
    ksort($rows);

    return $rows;
}

$composerLock = inventoryReadJson($root . '/composer.lock');
$packageJson = inventoryReadJson($root . '/package.json');
$packageLock = inventoryReadJson($root . '/package-lock.json');

$php = inventoryComposerPackages($composerLock, 'packages');
$phpDev = inventoryComposerPackages($composerLock, 'packages-dev');
$node = inventoryNpmPackages($packageJson, $packageLock);
$vendor = inventoryVendorAssets($root);

$platform = $composerLock['platform'] ?? [];
$phpRequirement = is_array($platform) && isset($platform['php'])
    ? (string) $platform['php']
    : 'voir composer.json';

$extensions = [];
if (is_array($platform)) {
    foreach (array_keys($platform) as $name) {
        if (is_string($name) && str_starts_with($name, 'ext-')) {
            $extensions[] = substr($name, 4);
        }
    }
}
sort($extensions);

echo "### Dépendances\n\n";
echo "Lues dans les fichiers de verrouillage : ce sont donc les versions qui\n";
echo "sont parties, et non les contraintes qui étaient permises.\n\n";

echo '**PHP :** `' . $phpRequirement . "`\n\n";
echo '**Extensions PHP requises :** '
    . ($extensions === [] ? '_aucune déclarée._' : '`' . implode('`, `', $extensions) . '`')
    . "\n\n";

echo '#### PHP — production (' . count($php) . ")\n\n";
echo "Embarquées dans le ZIP déployable : c'est ce qui tourne chez l'hébergeur.\n\n";
echo inventoryTable($php, '_Aucune._');

echo "\n#### PHP — développement (" . count($phpDev) . ")\n\n";
echo "Non déployées. Ce sont les outils sur le verdict desquels cette release\n";
echo "repose.\n\n";
echo inventoryTable($phpDev, '_Aucune._');

echo "\n#### JavaScript — développement (" . count($node) . ")\n\n";
echo "Outillage de test uniquement. Le JavaScript de production n'est ni\n";
echo "transformé ni empaqueté et n'a besoin d'aucun Node : rien de ceci\n";
echo "n'atteint un navigateur.\n\n";
echo inventoryTable($node, '_Aucune._');

echo "\n#### Ressources embarquées — exécutées par le navigateur (" . count($vendor) . ")\n\n";
echo "Dans aucun fichier de verrouillage : versionnées dans\n";
echo "`public/assets/vendor/`, servies depuis l'installation elle-même et\n";
echo "jamais depuis un CDN — la politique de sécurité de contenu du produit ne\n";
echo "l'autoriserait pas. Ce sont les seules dépendances tierces qu'un\n";
echo "visiteur exécute réellement.\n\n";
echo inventoryTable($vendor, '_Aucune trouvée — vérifiez le balayage de scripts/dependency-inventory.php._');

echo "\n#### Ce qui n'est pas couvert par la licence du projet\n\n";
echo "_Rien à ce jour._ SecondStay n'embarque ni police, ni image, ni marque\n";
echo "sous des conditions distinctes : les icônes de l'application sont\n";
echo "générées à l'installation avec la police interne de GD, et les documents\n";
echo "PDF utilisent les polices standard du format, qu'aucun fichier du dépôt\n";
echo "ne porte.\n\n";
echo "Cette section reste imprimée même vide : « rien » est une information,\n";
echo "l'absence de section n'en est pas une.\n";

echo "\n#### Compatibilité des licences\n\n";
echo "Ce projet est **AGPL-3.0-or-later**. Tout ce qui précède peut y être\n";
echo "combiné :\n\n";
echo "- **MIT, ISC, BSD-2/3-Clause, 0BSD, CC0, BlueOak** — permissives, et\n";
echo "  compatibles dans le sens qui compte ici.\n";
echo "- **Apache-2.0** — compatible dans un seul sens : du code Apache-2.0 peut\n";
echo "  entrer dans une œuvre AGPL-3.0, l'inverse non. Outillage de\n";
echo "  développement uniquement, donc combiné à rien de ce qui est livré.\n";
echo "- **MPL-2.0** — copyleft au fichier, explicitement compatible avec la\n";
echo "  (A)GPL par sa propre section 3.3. Outillage de développement\n";
echo "  uniquement.\n\n";
echo "Ajouter une dépendance de **production** sous une licence autre que MIT,\n";
echo "BSD, ISC ou Apache-2.0 est une décision à soumettre, pas à prendre en\n";
echo "silence (AGENTS.md).\n";
