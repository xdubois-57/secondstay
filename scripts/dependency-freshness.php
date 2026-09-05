<?php

declare(strict_types=1);

/**
 * Fraîcheur des dépendances — gate de release (RELEASE.md §6).
 *
 * ## Ce que cette gate ajoute aux autres
 *
 * `composer audit` et Dependabot répondent à « une de mes dépendances est-elle
 * vulnérable ? ». `dependency-inventory.php` répond à « sous quelle licence
 * sont-elles ? ». Aucun des deux ne répond à « depuis combien de temps
 * n'ai-je rien mis à jour ? », et c'est pourtant cette dérive-là qui rend la
 * montée suivante coûteuse : trois versions de retard se rattrapent, trente
 * se négocient.
 *
 * ## La distinction qui compte
 *
 * Le verdict n'est pas « en retard / à jour » mais celui que Composer et npm
 * donnent eux-mêmes :
 *
 * - **une montée que les contraintes autorisent déjà** (`semver-safe-update`)
 *   est à un `composer update` de distance. La laisser traîner n'est pas une
 *   décision, c'est un oubli — donc la release **refuse** ;
 * - **une montée qui exige de changer la contrainte** (`update-possible`,
 *   typiquement une version majeure) est une décision, avec sa lecture de
 *   notes de version et ses tests. La release **avertit** et continue.
 *
 * Inventer un seuil arbitraire — « plus de six mois », « plus de deux
 * versions » — aurait produit un chiffre que personne ne peut défendre. Les
 * deux outils savent déjà distinguer ce qui est gratuit de ce qui ne l'est
 * pas ; cette gate ne fait que les lire.
 *
 * ## Les bibliothèques vendorisées
 *
 * `public/assets/vendor/` ne relève d'aucun gestionnaire de paquets : ces
 * fichiers sont copiés à la main, précisément pour qu'aucune page n'aille
 * chercher un CDN (SECURITY.md, politique de contenu). Personne ne les met
 * donc à jour, sauf à y penser — et personne n'y pense. Leur version est lue
 * dans la bannière du fichier minifié et comparée à la dernière release
 * publiée en amont.
 *
 * Usage :
 *
 *     php scripts/dependency-freshness.php
 *
 * Sortie 0 : rien à rattraper gratuitement. Sortie 1 : au moins une montée
 * était à portée de `composer update` ou `npm update`.
 */

/**
 * Bibliothèques copiées dans `public/assets/vendor/`, avec le dépôt amont où
 * lire la dernière version publiée.
 *
 * Ajouter une bibliothèque vendorisée sans l'ajouter ici la rend invisible à
 * cette gate — et le contrôle plus bas refuse justement un répertoire absent
 * de cette carte, pour que l'oubli soit bruyant.
 */
const VENDORED_UPSTREAM = [
    'bootstrap' => 'twbs/bootstrap',
];

/**
 * @return array{stale: list<string>, deliberate: list<string>}
 */
function freshnessOfComposer(string $root): array
{
    $json = shellJson('cd ' . escapeshellarg($root) . ' && composer outdated --direct --format=json');
    $stale = [];
    $deliberate = [];

    /** @var list<array<string, mixed>> $packages */
    $packages = is_array($json['installed'] ?? null) ? $json['installed'] : [];
    foreach ($packages as $package) {
        $name = (string) ($package['name'] ?? '');
        $version = (string) ($package['version'] ?? '?');
        $latest = (string) ($package['latest'] ?? '?');
        $status = (string) ($package['latest-status'] ?? '');

        $line = sprintf('%s %s → %s', $name, $version, $latest);
        if ($status === 'semver-safe-update') {
            $stale[] = $line;
        } elseif ($status === 'update-possible') {
            $deliberate[] = $line . ' (change la contrainte)';
        }
    }

    return ['stale' => $stale, 'deliberate' => $deliberate];
}

/**
 * @return array{stale: list<string>, deliberate: list<string>}
 */
function freshnessOfNpm(string $root): array
{
    // `npm outdated` sort en 1 quand il trouve quelque chose : ce n'est pas
    // une erreur, c'est son verdict — d'où le 1 dans les codes acceptés. Tout
    // autre code reste une panne, et fait échouer la gate plutôt que de la
    // laisser conclure « à jour » sur une sortie vide.
    $json = shellJson('cd ' . escapeshellarg($root) . ' && npm outdated --json', [0, 1]);
    $stale = [];
    $deliberate = [];

    foreach ($json as $name => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $current = (string) ($entry['current'] ?? '?');
        $wanted = (string) ($entry['wanted'] ?? '?');
        $latest = (string) ($entry['latest'] ?? '?');

        // `wanted` est ce que la contrainte autorise déjà : s'il diffère de
        // l'installé, la montée est gratuite. Sinon seul `latest` avance, et
        // c'est une décision.
        if ($current !== $wanted) {
            $stale[] = sprintf('%s %s → %s', (string) $name, $current, $wanted);
        } elseif ($current !== $latest) {
            $deliberate[] = sprintf('%s %s → %s (change la contrainte)', (string) $name, $current, $latest);
        }
    }

    return ['stale' => $stale, 'deliberate' => $deliberate];
}

/**
 * @return array{stale: list<string>, deliberate: list<string>}
 */
function freshnessOfVendored(string $root): array
{
    $stale = [];
    $deliberate = [];

    $directory = $root . '/public/assets/vendor';
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        if (!is_dir($directory . '/' . $entry)) {
            continue;
        }

        if (!array_key_exists($entry, VENDORED_UPSTREAM)) {
            // Bruyant plutôt que silencieux : une bibliothèque vendorisée que
            // cette gate ne connaît pas est une bibliothèque que personne ne
            // met à jour et dont personne ne le sait.
            $stale[] = sprintf(
                'public/assets/vendor/%s : aucun dépôt amont déclaré dans VENDORED_UPSTREAM',
                (string) $entry
            );
            continue;
        }

        $installed = bannerVersion($directory . '/' . $entry);
        if ($installed === null) {
            $stale[] = sprintf('public/assets/vendor/%s : version illisible dans la bannière', (string) $entry);
            continue;
        }

        $upstream = latestUpstreamVersion(VENDORED_UPSTREAM[$entry]);
        if ($upstream === null) {
            // Une version amont introuvable n'est pas un retard : c'est une
            // mesure impossible. Le dire, et ne pas refuser la release
            // là-dessus — sinon une panne de GitHub bloque une publication.
            fwrite(STDERR, sprintf(
                "  ? %s : version amont introuvable (réseau ?) — non vérifiée\n",
                (string) $entry
            ));
            continue;
        }

        if ($installed !== $upstream) {
            $line = sprintf('public/assets/vendor/%s %s → %s', (string) $entry, $installed, $upstream);
            // Une majeure vendorisée se relit avant de se copier ; le reste
            // est une copie de fichiers.
            if (majorOf($installed) !== majorOf($upstream)) {
                $deliberate[] = $line . ' (version majeure)';
            } else {
                $stale[] = $line;
            }
        }
    }

    return ['stale' => $stale, 'deliberate' => $deliberate];
}

/**
 * Première version trouvée dans la bannière d'un fichier minifié du répertoire.
 */
function bannerVersion(string $directory): ?string
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $head = (string) @file_get_contents((string) $file, false, null, 0, 400);
        if (preg_match('/v?(\d+\.\d+\.\d+)/', $head, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
}

/**
 * La dernière version publiée en amont d'une bibliothèque vendorisée.
 *
 * `null` signifie « pas su demander » — réseau coupé, quota d'API atteint,
 * dépôt renommé — et l'appelant l'affiche comme *non vérifiée* plutôt que de
 * la compter à jour. C'est la même règle que `shellJson()` : une gate ne
 * conclut pas sur une mesure qu'elle n'a pas faite.
 */
function latestUpstreamVersion(string $repository): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: SecondStay-Freshness\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents('https://api.github.com/repos/' . $repository . '/releases/latest', false, $context);
    if ($body === false) {
        return null;
    }

    $data = json_decode($body, true);
    $tag = is_array($data) ? (string) ($data['tag_name'] ?? '') : '';

    return preg_match('/(\d+\.\d+\.\d+)/', $tag, $matches) === 1 ? $matches[1] : null;
}

/**
 * Le numéro majeur seul. Une bibliothèque vendorisée en retard d'un majeur est
 * une décision — la migration se lit dans son journal de version — là où un
 * retard de correctif est un oubli.
 */
function majorOf(string $version): string
{
    return explode('.', $version)[0];
}

/**
 * Exécute une commande censée écrire du JSON, et **refuse de deviner**.
 *
 * La version précédente renvoyait `[]` dès que la sortie n'était pas du JSON.
 * Les appelants lisaient alors une liste vide, imprimaient « à jour » et la
 * release continuait — alors que le contrôle n'avait pas eu lieu. Composer
 * absent, `node_modules` non installé, réseau coupé, JSON tronqué : tous ces
 * cas donnaient un vert. C'est exactement le vert qui ne prouve rien que cette
 * gate existe pour empêcher ailleurs.
 *
 * Un code de sortie inattendu ou un JSON illisible lève donc, et la gate
 * échoue. Le seul silence toléré est une sortie vide sur un code 0, que `npm
 * outdated` produit encore dans certaines versions quand il n'a rien à dire.
 *
 * @param list<int> $acceptedExitCodes codes de sortie qui sont un verdict et
 *                                     non une panne — `npm outdated` sort en 1
 *                                     quand il a trouvé quelque chose
 *
 * @return array<mixed>
 *
 * @throws RuntimeException
 */
function shellJson(string $command, array $acceptedExitCodes = [0]): array
{
    $errorFile = tempnam(sys_get_temp_dir(), 'freshness-');
    if ($errorFile === false) {
        throw new RuntimeException("Impossible de créer le fichier temporaire de l'erreur standard.");
    }

    $lines = [];
    $status = 0;
    exec($command . ' 2>' . escapeshellarg($errorFile), $lines, $status);

    $stderr = trim((string) @file_get_contents($errorFile));
    @unlink($errorFile);

    if (!in_array($status, $acceptedExitCodes, true)) {
        throw new RuntimeException(sprintf(
            "La commande a échoué (sortie %d) : %s\n%s",
            $status,
            $command,
            $stderr === '' ? '(aucun message sur l\'erreur standard)' : $stderr
        ));
    }

    $raw = trim(implode("\n", $lines));
    if ($raw === '') {
        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'La commande est sortie en %d sans rien écrire : %s',
                $status,
                $command
            ));
        }

        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf(
            "La commande n'a pas produit de JSON exploitable : %s\n%s",
            $command,
            substr($raw, 0, 500)
        ));
    }

    return $decoded;
}

// -----------------------------------------------------------------------------

$root = dirname(__DIR__);

// Une gate qui ne peut pas mesurer ne conclut pas : elle échoue. Le message
// nomme la commande fautive, parce que « impossible de lire la fraîcheur » est
// un diagnostic et « à jour » sur un outil absent est un mensonge.
try {
    $sections = [
        'Composer (dépendances directes)' => freshnessOfComposer($root),
        'npm (dépendances directes)' => freshnessOfNpm($root),
        'Bibliothèques vendorisées' => freshnessOfVendored($root),
    ];
} catch (RuntimeException $exception) {
    fwrite(STDERR, "\nLa fraîcheur des dépendances n'a pas pu être établie.\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$stale = [];
$deliberate = [];

foreach ($sections as $title => $result) {
    if ($result['stale'] === [] && $result['deliberate'] === []) {
        printf("  ✔ %s : à jour\n", $title);
        continue;
    }

    printf("  %s :\n", $title);
    foreach ($result['stale'] as $line) {
        printf("    ✘ %s\n", $line);
        $stale[] = $line;
    }
    foreach ($result['deliberate'] as $line) {
        printf("    ⚠ %s\n", $line);
        $deliberate[] = $line;
    }
}

if ($deliberate !== []) {
    printf(
        "\n%d montée(s) exigent de changer une contrainte : ce sont des décisions, pas des oublis.\n"
        . "Elles ne bloquent pas cette release — mais une liste qui s'allonge release après release\n"
        . "est exactement la dérive que cette gate existe pour rendre visible.\n",
        count($deliberate)
    );
}

if ($stale !== []) {
    printf(
        "\n%d montée(s) sont autorisées par les contraintes actuelles : un `composer update` ou\n"
        . "`npm update` de distance. Les rattraper avant de publier, ou déroger explicitement.\n",
        count($stale)
    );
    exit(1);
}

printf("\nRien à rattraper gratuitement.\n");
exit(0);
