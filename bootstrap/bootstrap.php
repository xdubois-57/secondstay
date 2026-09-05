<?php

declare(strict_types=1);

/**
 * SecondStay — installeur autonome (« bootstrap »).
 *
 * Un seul fichier, déposé par FTP à la racine d'un hébergement vide. Il
 * télécharge la dernière version publiée sur GitHub, l'installe, prouve que
 * l'installation est saine, écrit le jeton qui protège l'assistant de
 * configuration, puis se supprime.
 *
 * Ce fichier ne peut dépendre de rien : il s'exécute **avant** que
 * `vendor/autoload.php` n'existe sur le disque. Pas de Composer, pas
 * d'espace de noms, aucune classe du projet — uniquement `ZipArchive` et les
 * flux natifs de PHP. Ce n'est pas une préférence de style : c'est la seule
 * façon qu'il ait de fonctionner.
 *
 * Ce qu'il n'est pas
 * ------------------
 * Il n'installe **pas** une disposition qu'il aurait inventée. L'artefact de
 * release livre déjà son `.htaccess` racine et son `public/.htaccess`
 * (`ReleaseArtifactPolicy::REQUIRED_ENTRIES`), écrits pour l'arborescence
 * unique décrite par ARCHITECTURE.md. Le bootstrap copie cette arborescence
 * telle quelle et n'écrit **aucune** règle de serveur pour l'installation
 * livrée : s'il en écrivait, il existerait deux sources de vérité pour la
 * protection du dépôt et elles divergeraient au premier changement.
 *
 * La seule exception, et elle ne contredit pas l'invariant : le dossier
 * temporaire de travail reçoit un `.htaccess` qui refuse tout. Ce dossier
 * existe **avant** l'artefact et disparaît **avec** lui ; la règle protège
 * l'archive téléchargée pendant les quelques secondes où elle est sous la
 * racine du document, et rien de ce qui reste installé ne s'y réfère.
 *
 * Il ne déploie rien non plus : il n'y a pas de miroir, pas de `deploy.sh`,
 * pas de clé SSH. Le seul canal est HTTPS sortant vers GitHub.
 *
 * Ce qu'il garantit
 * -----------------
 * Aucun jeton n'est écrit tant qu'un portail d'acceptation n'a pas prouvé, en
 * partie depuis le serveur et en partie depuis le navigateur de l'opérateur,
 * que le site répond, que PHP s'exécute et que `src/`, `config/`, `vendor/`,
 * `storage/` et les fichiers cachés sont **inaccessibles** depuis le web. Un
 * seul contrôle en échec annule l'installation entière : les fichiers copiés
 * sont retirés. Une installation à moitié faite qu'un contrôle vient de
 * déclarer dangereuse ne doit pas rester configurable.
 *
 * Testabilité
 * -----------
 * Tout ce qui précède `bootstrap_main()` est une fonction pure ou une
 * fonction dont les entrées/sorties sont injectées. `main()` est le seul
 * endroit qui touche aux superglobales, écrit sur la sortie ou appelle
 * `exit`. La constante `BOOTSTRAP_TEST` permet de charger ce fichier sans
 * rien exécuter — voir `tests/php/Unit/Bootstrap/`.
 */

const BOOTSTRAP_REPO_OWNER = 'xdubois-57';
const BOOTSTRAP_REPO_NAME = 'secondstay';
const BOOTSTRAP_USER_AGENT = 'SecondStay-Bootstrap';
const BOOTSTRAP_HTTP_TIMEOUT = 20;
const BOOTSTRAP_DOWNLOAD_TIMEOUT = 300;

/**
 * Sauts de redirection admis pendant le téléchargement. GitHub en fait un
 * (l'API vers le service de stockage), parfois deux. Au-delà, ce n'est plus
 * une redirection, c'est une boucle ou un détour — et chaque saut est de toute
 * façon revérifié en HTTPS.
 */
const BOOTSTRAP_MAX_REDIRECTS = 5;
const BOOTSTRAP_STATE_FILE = '.bootstrap-state.php';
const BOOTSTRAP_LOCK_FILE = '.bootstrap.lock';
const BOOTSTRAP_LOCK_STALE_SECONDS = 600;
const BOOTSTRAP_TOKEN_FILE = 'token.php';
const BOOTSTRAP_TEMP_DIR_PREFIX = '.tmp-';

/**
 * Le plancher est celui de `composer.json` (`"php": ">=8.2"`), pas celui de
 * la machine de développement. Un installeur plus exigeant que
 * l'application refuserait des hébergements sur lesquels elle tourne.
 */
const BOOTSTRAP_MIN_PHP_VERSION = '8.2.0';

/**
 * Extensions exigées par `composer.json`. Elles sont vérifiées ici parce
 * qu'une extension absente ne se manifeste, sinon, qu'après l'installation —
 * sur une page blanche, sans message, chez quelqu'un qui n'a que du FTP.
 *
 * `zip` est traitée à part : sans elle, rien ne peut même être extrait.
 */
const BOOTSTRAP_REQUIRED_EXTENSIONS = [
    'pdo', 'json', 'mbstring', 'openssl', 'dom', 'fileinfo', 'intl', 'sodium', 'curl',
];

/**
 * Sous-dossiers de `storage/`, copie exacte de `Paths::ensureStorageDirectories()`.
 * L'application les créerait elle-même au premier appel, mais le portail
 * d'acceptation doit pouvoir prouver qu'ils ne sont pas lisibles depuis le
 * web **avant** que la première requête réelle n'ait lieu.
 */
const BOOTSTRAP_STORAGE_SUBDIRS = [
    'cache', 'cache/twig', 'cache/icons', 'logs', 'temp', 'media', 'media/thumbs',
    'documents', 'inspections', 'mail-attachments', 'backups',
];

/**
 * Même mode que `Paths::ensureStorageDirectories()` : ces dossiers portent
 * des documents d'identité et des pièces jointes, jamais un bit « autres ».
 */
const BOOTSTRAP_STORAGE_MODE = 0o750;

/**
 * Sous-ensemble de `ReleaseArtifactPolicy::REQUIRED_ENTRIES` : ce que
 * l'artefact doit contenir pour être installable. La liste est délibérément
 * plus courte que la politique de release — celle-ci juge un ZIP produit par
 * le dépôt, celle-là juge une archive téléchargée dont on ne sait rien.
 *
 * `.htaccess` en tête n'est pas décoratif : c'est le seul rempart de
 * l'arborescence unique. Une archive qui n'en contient pas ne s'installe pas.
 */
const BOOTSTRAP_REQUIRED_ARTIFACT_ENTRIES = [
    '.htaccess',
    'public/index.php',
    'public/.htaccess',
    'src/Core/Kernel.php',
    'config/app.php',
    'templates/layout/base.html.twig',
    'translations/fr/common.php',
    'vendor/autoload.php',
];

/**
 * Marqueur cherché dans la page de l'assistant pour le contrôle fonctionnel.
 * `data-testid` est déjà le contrat que la campagne Playwright utilise : le
 * portail s'appuie sur la même ancre plutôt que d'en inventer une seconde.
 */
const BOOTSTRAP_WIZARD_MARKER = 'data-testid="install-form"';

/**
 * Nom du paramètre par lequel le jeton est présenté à l'assistant. Il doit
 * rester identique à `InstallToken::REQUEST_PARAMETER`.
 */
const BOOTSTRAP_TOKEN_PARAMETER = 'jeton';

/**
 * Marqueur du fichier `token.php`, identique à `InstallToken::MARKER`. Le
 * fichier est du PHP valide qui répond 404 : même servi en clair par un
 * serveur mal configuré, il ne divulgue rien d'exploitable — et le portail
 * d'acceptation refuse de toute façon une installation où il le serait.
 */
const BOOTSTRAP_TOKEN_MARKER = 'SECONDSTAY-INSTALL-TOKEN';

// =============================================================================
// Préflight / environnement
// =============================================================================

/**
 * Refuse une installation dans un sous-dossier.
 *
 * L'écart entre `DOCUMENT_ROOT` et `__DIR__` n'est délibérément **pas**
 * examiné : sur un hébergement chrooté, Apache et PHP voient légitimement
 * deux chemins différents pour le même dossier. Seul l'endroit d'où le
 * script a été demandé fait foi.
 *
 * @param array<string, mixed> $server
 *
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_check_location(array $server): array
{
    $scriptName = (string) ($server['SCRIPT_NAME'] ?? '/bootstrap.php');
    $directory = str_replace('\\', '/', dirname($scriptName));
    $isRoot = $directory === '/' || $directory === '.' || $directory === '';

    return [
        'ok' => $isRoot,
        'label' => "Emplacement d'installation",
        'detail' => $isRoot
            ? 'bootstrap.php est bien à la racine du site.'
            : "bootstrap.php doit être placé à la racine du site (détecté dans « {$directory} »).",
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_check_php_version(string $version = PHP_VERSION): array
{
    $ok = version_compare($version, BOOTSTRAP_MIN_PHP_VERSION, '>=');

    return [
        'ok' => $ok,
        'label' => 'Version de PHP',
        'detail' => $ok
            ? "PHP {$version} détecté."
            : 'PHP ' . BOOTSTRAP_MIN_PHP_VERSION . " ou supérieur est requis (PHP {$version} détecté).",
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_check_zip_extension(): array
{
    $ok = class_exists('ZipArchive');

    return [
        'ok' => $ok,
        'label' => 'Extension ZipArchive',
        'detail' => $ok
            ? 'Disponible.'
            : "L'extension PHP zip (ZipArchive) est requise et absente de ce serveur.",
    ];
}

/**
 * @param list<string> $loaded extensions présentes ; par défaut celles de ce PHP
 *
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_check_extensions(?array $loaded = null): array
{
    $loaded ??= get_loaded_extensions();
    $normalised = array_map('strtolower', $loaded);

    $missing = [];
    foreach (BOOTSTRAP_REQUIRED_EXTENSIONS as $extension) {
        if (!in_array($extension, $normalised, true)) {
            $missing[] = $extension;
        }
    }

    $ok = $missing === [];

    return [
        'ok' => $ok,
        'label' => 'Extensions PHP requises',
        'detail' => $ok
            ? 'Toutes les extensions requises sont présentes.'
            : 'Extensions manquantes : ' . implode(', ', $missing)
                . '. Elles sont exigées par SecondStay ; sans elles le site s’installerait pour ne rien afficher.',
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_check_outbound_https(callable $prober): array
{
    $ok = (bool) $prober();

    return [
        'ok' => $ok,
        'label' => 'Connexion sortante HTTPS',
        'detail' => $ok
            ? 'Connexion vers GitHub établie.'
            : "Impossible d'établir une connexion HTTPS sortante vers GitHub. "
                . "Vérifiez que votre hébergeur autorise les connexions sortantes.",
    ];
}

/**
 * Purement informatif : rien de ce qui suit ne conditionne l'installation.
 * Ces valeurs servent à l'opérateur quand quelque chose échoue plus loin —
 * c'est le portail d'acceptation, et lui seul, qui tranche.
 *
 * @param array<string, mixed> $server
 *
 * @return array<string, mixed>
 */
function bootstrap_gather_environment_info(array $server): array
{
    return [
        'server_software' => $server['SERVER_SOFTWARE'] ?? 'inconnu',
        'document_root' => $server['DOCUMENT_ROOT'] ?? '',
        'memory_limit' => ini_get('memory_limit') ?: 'inconnu',
        'max_execution_time' => ini_get('max_execution_time') ?: 'inconnu',
        'open_basedir' => ini_get('open_basedir') ?: '',
        'php_version' => PHP_VERSION,
    ];
}

/**
 * Écriture, relecture et suppression d'un vrai fichier, puis `mkdir()` et
 * `rmdir()` d'un chemin jetable. `is_writable()` ment sous ACL et sous
 * `open_basedir` : rien ici ne lui fait confiance.
 */
function bootstrap_probe_writable(string $directory): bool
{
    if (!is_dir($directory)) {
        return false;
    }

    $probe = rtrim($directory, '/') . '/.bootstrap-write-probe-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'probe') === false) {
        return false;
    }
    $read = @file_get_contents($probe);
    @unlink($probe);
    if ($read !== 'probe') {
        return false;
    }

    $subdirectory = rtrim($directory, '/') . '/.bootstrap-mkdir-probe-' . bin2hex(random_bytes(4));
    if (!@mkdir($subdirectory, 0o755)) {
        return false;
    }
    $created = is_dir($subdirectory);
    @rmdir($subdirectory);

    return $created;
}

/**
 * Une installation existante ne doit jamais être écrasée. `VERSION` est
 * l'indice principal ; `src/` et `config/local.php` couvrent le cas d'une
 * installation dont le `VERSION` aurait été supprimé à la main.
 */
function bootstrap_already_installed(string $docRoot): bool
{
    return is_file($docRoot . '/VERSION')
        || is_dir($docRoot . '/src')
        || is_file($docRoot . '/config/local.php');
}

// =============================================================================
// Résolution de la release GitHub
// =============================================================================

/**
 * Extrait l'empreinte SHA-256 d'un champ `digest` de l'API GitHub, écrit
 * `sha256:<64 hexadécimaux>`.
 *
 * Tout ce qui n'est pas exactement cette forme rend une chaîne vide, c'est-à-
 * dire « pas d'empreinte » — et non « empreinte vide », qu'une comparaison
 * naïve confondrait avec une vérification réussie. Un autre algorithme un jour
 * publié par GitHub tombe ici, et l'installeur dira qu'il n'a pas vérifié
 * plutôt que de prétendre le contraire.
 */
function bootstrap_sha256_from_digest(string $digest): string
{
    if (preg_match('/^sha256:([0-9a-f]{64})$/i', trim($digest), $matches) !== 1) {
        return '';
    }

    return strtolower($matches[1]);
}

/**
 * L'artefact est choisi par son nom (suffixe `.zip`), jamais par sa position :
 * GitHub ne conserve pas l'ordre des fichiers passés à `gh release create` et
 * trie les assets alphabétiquement, si bien que `bootstrap.php` — publié comme
 * asset lui aussi — se retrouve avant `secondstay-X.Y.Z.zip`. Prendre
 * `assets[0]` reviendrait à télécharger ce fichier-ci.
 *
 * GitHub publie, pour chaque asset, l'empreinte SHA-256 des octets qu'il
 * stocke (`digest`). Elle arrive par `api.github.com`, en HTTPS vérifié, sur
 * une connexion **distincte** de celle qui sert l'archive et de la chaîne de
 * redirections qui y mène. C'est ce qui en fait une preuve utile : elle ne
 * vient pas de la même source que ce qu'elle atteste. Le `zipball_url` de
 * repli n'en a pas — l'absence est donc rapportée, jamais confondue avec une
 * vérification réussie.
 *
 * @param array<string, mixed> $release
 *
 * @return array{url: string, size: int, source: 'asset'|'zipball', digest: string}
 */
function bootstrap_resolve_archive_url(array $release): array
{
    $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $name = strtolower((string) ($asset['name'] ?? ''));
        // Le pack de preuves est publié sur la même release ; il n'est pas
        // installable et porte lui aussi l'extension .zip.
        if ($name === 'evidence.zip') {
            continue;
        }
        if (str_ends_with($name, '.zip') && isset($asset['browser_download_url'])) {
            return [
                'url' => (string) $asset['browser_download_url'],
                'size' => (int) ($asset['size'] ?? 0),
                'source' => 'asset',
                'digest' => bootstrap_sha256_from_digest((string) ($asset['digest'] ?? '')),
            ];
        }
    }

    if (!empty($release['zipball_url'])) {
        return ['url' => (string) $release['zipball_url'], 'size' => 0, 'source' => 'zipball', 'digest' => ''];
    }

    throw new RuntimeException("Impossible de déterminer l'URL de l'archive de la dernière version publiée.");
}

/**
 * Ni dépôt, ni référence, ni version, ni chemin n'est jamais lu depuis la
 * requête : le dépôt est celui des constantes, la version est toujours la
 * dernière publiée. Un installeur paramétrable par l'URL serait un
 * téléchargeur d'archive arbitraire déguisé.
 *
 * @return array<string, mixed>
 */
function bootstrap_fetch_latest_release(callable $httpGet): array
{
    $url = 'https://api.github.com/repos/' . BOOTSTRAP_REPO_OWNER . '/' . BOOTSTRAP_REPO_NAME . '/releases/latest';
    $attempts = 0;
    $lastError = null;

    while ($attempts < 3) {
        $attempts++;
        /** @var array{status: int, headers: array<string, string>, body: string} $result */
        $result = $httpGet($url, ['Accept: application/vnd.github+json']);

        if ($result['status'] === 403 && ($result['headers']['x-ratelimit-remaining'] ?? '1') === '0') {
            $reset = (int) ($result['headers']['x-ratelimit-reset'] ?? 0);

            throw new RuntimeException(
                'Limite de requêtes GitHub atteinte'
                . ($reset > 0 ? ' — réessayez après ' . date('H:i:s', $reset) . '.' : '.')
            );
        }

        if ($result['status'] === 404) {
            throw new RuntimeException("Aucune version publiée n'a été trouvée pour ce dépôt.");
        }

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $data = json_decode($result['body'], true);
            if (is_array($data)) {
                return $data;
            }
            $lastError = new RuntimeException('Réponse GitHub illisible.');
        } else {
            $lastError = new RuntimeException('Erreur GitHub (HTTP ' . $result['status'] . ').');
        }

        if ($attempts < 3) {
            usleep(500000 * $attempts);
        }
    }

    // Inatteignable tant que la boucle tourne au moins une fois : toute
    // branche d'échec renseigne $lastError. Écrit comme un throw plutôt que
    // laissé implicite pour qu'un remaniement futur échoue bruyamment.
    throw $lastError;
}

function bootstrap_download_with_retry(string $url, string $destination, callable $downloader): void
{
    $attempts = 0;
    $lastError = null;

    while ($attempts < 3) {
        $attempts++;
        try {
            $downloader($url, $destination);
            if (is_file($destination) && filesize($destination) > 0) {
                return;
            }
            $lastError = new RuntimeException('Le fichier téléchargé est vide.');
        } catch (Throwable $throwable) {
            $lastError = $throwable;
        }
        if ($attempts < 3) {
            usleep(500000 * $attempts);
        }
    }

    throw $lastError;
}

/**
 * @return array{ok: bool, degraded: bool, label: string, detail: string}
 */
function bootstrap_check_disk_space(
    string $directory,
    int $declaredArtifactSize,
    ?callable $freeSpace = null,
): array {
    $freeSpace ??= 'disk_free_space';

    if (!function_exists('disk_free_space') && $freeSpace === 'disk_free_space') {
        return [
            'ok' => true,
            'degraded' => true,
            'label' => 'Espace disque',
            'detail' => "La fonction de mesure de l'espace disque est désactivée — poursuite sans garantie.",
        ];
    }

    $free = @$freeSpace($directory);
    if ($free === false || $free === null) {
        return [
            'ok' => true,
            'degraded' => true,
            'label' => 'Espace disque',
            'detail' => "Impossible de déterminer l'espace disque disponible — poursuite sans garantie.",
        ];
    }

    if ($declaredArtifactSize <= 0) {
        return [
            'ok' => true,
            'degraded' => true,
            'label' => 'Espace disque',
            'detail' => "Taille de l'archive inconnue — vérification ignorée.",
        ];
    }

    // Trois fois la taille de l'archive : le ZIP, son extraction, et la copie
    // installée coexistent brièvement sur le disque.
    $needed = $declaredArtifactSize * 3;
    $ok = $free >= $needed;

    return [
        'ok' => $ok,
        'degraded' => false,
        'label' => 'Espace disque',
        'detail' => $ok
            ? sprintf('%.1f Mo disponibles.', $free / 1048576)
            : sprintf(
                "Seulement %.1f Mo disponibles, %.1f Mo requis (trois fois la taille de l'archive).",
                $free / 1048576,
                $needed / 1048576
            ),
    ];
}

// =============================================================================
// Extraction de l'archive
// =============================================================================

/**
 * Zip-slip : une entrée dont le chemin sort de la zone d'extraction.
 */
function bootstrap_is_zip_slip(string $entryName): bool
{
    $normalised = str_replace('\\', '/', $entryName);

    if (str_contains($normalised, '../') || str_ends_with($normalised, '/..') || $normalised === '..') {
        return true;
    }
    if (str_starts_with($normalised, '/')) {
        return true;
    }

    return preg_match('#^[A-Za-z]:#', $normalised) === 1;
}

/**
 * Extraction refusée en bloc à la moindre entrée dangereuse : l'archive est
 * validée **entièrement** avant que le premier octet ne soit écrit. Une
 * extraction partielle suivie d'un refus laisserait sur le disque exactement
 * ce qu'on voulait éviter.
 */
function bootstrap_extract_zip_safely(string $zipPath, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("L'archive téléchargée est illisible.");
    }

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if ($name === false) {
            continue;
        }
        if (bootstrap_is_zip_slip($name)) {
            $zip->close();

            throw new RuntimeException(
                "L'archive contient une entrée dont le chemin sort de la zone d'extraction : {$name}"
            );
        }

        $stat = $zip->statIndex($index);
        $externalAttributes = is_array($stat) ? (int) ($stat['external_attr'] ?? 0) : 0;
        $unixMode = ($externalAttributes >> 16) & 0xFFFF;
        if (($unixMode & 0xA000) === 0xA000) {
            $zip->close();

            throw new RuntimeException("L'archive contient un lien symbolique, ce qui n'est pas autorisé : {$name}");
        }
    }

    $extracted = $zip->extractTo($destination);
    $zip->close();

    if (!$extracted) {
        throw new RuntimeException("L'extraction de l'archive a échoué.");
    }
}

/**
 * Le ZIP de release est zippé à plat par `scripts/release-artifact.php` ; le
 * `zipball_url` de GitHub, lui, encapsule tout dans un dossier unique nommé
 * d'après le dépôt et le commit. La décision se prend sur le **type de
 * source**, jamais sur le nombre d'entrées : un artefact qui n'aurait
 * qu'une seule entrée de premier niveau ne doit pas être dépouillé.
 */
function bootstrap_resolve_archive_root(string $extractedDirectory, string $sourceType): string
{
    if ($sourceType !== 'zipball') {
        return $extractedDirectory;
    }

    $entries = array_values(array_diff(scandir($extractedDirectory) ?: [], ['.', '..']));
    if (count($entries) === 1 && is_dir($extractedDirectory . '/' . $entries[0])) {
        return $extractedDirectory . '/' . $entries[0];
    }

    return $extractedDirectory;
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrap_verify_artifact(string $sourceRoot): array
{
    $missing = [];
    foreach (BOOTSTRAP_REQUIRED_ARTIFACT_ENTRIES as $entry) {
        if (!file_exists($sourceRoot . '/' . $entry)) {
            $missing[] = $entry;
        }
    }

    $ok = $missing === [];

    return [
        'ok' => $ok,
        'label' => "Vérification de l'artefact",
        'detail' => $ok
            ? 'Tous les fichiers requis sont présents.'
            // `vendor/` est celui qui coûte cher : sans Composer sur un
            // hébergement mutualisé, une archive sans dépendances s'installe
            // proprement et donne un site mort, sans recours autre que FTP.
            : "Fichiers manquants dans l'archive : " . implode(', ', $missing) . '.',
    ];
}

// =============================================================================
// Système de fichiers : copie préservant les fichiers cachés, suppression
// =============================================================================

/**
 * Un échec survenu alors que des entrées avaient **déjà** été écrites à la
 * racine du site.
 *
 * Elle existe pour une seule raison : porter cette liste jusqu'au gestionnaire
 * d'erreur. Sans elle, `$state['installed_entries']` reste absent, le
 * gestionnaire conclut que rien n'a été copié, `bootstrap_rollback_install()`
 * n'est pas appelé — et les fichiers restent, invisibles à l'annulation comme
 * au bouton « Annuler l'installation et nettoyer », qui relit le même état.
 */
final class BootstrapPartialInstall extends RuntimeException
{
    /**
     * @param list<string> $copied entrées de premier niveau déjà écrites
     */
    public function __construct(string $message, public readonly array $copied, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * Copie chaque entrée de premier niveau de `$source` vers `$destination`,
 * sauf celles listées dans `$excludeTopLevel`.
 *
 * `DirectoryIterator` et jamais `glob()` : ce dernier ignore silencieusement
 * les fichiers cachés, et `.htaccess` est précisément ce que cette
 * installation ne peut pas se permettre de perdre.
 *
 * `$copied` est renseigné **au fur et à mesure**, et c'est ce qui compte : si
 * la copie s'interrompt en cours de route — quota atteint, disque plein,
 * `max_execution_time` expiré au milieu de `vendor/` — la valeur de retour
 * n'existe jamais, et une liste construite localement partirait avec
 * l'exception. Les entrées déjà écrites à la racine, dont `src/`, resteraient
 * alors en place sans qu'aucune annulation ne sache les nommer : reprise
 * refusée par « déjà installé », bouton « Annuler » sans effet, et plus que le
 * FTP pour s'en sortir. Le paramètre par référence survit à l'exception ; le
 * `return` ne le fait pas.
 *
 * @param list<string> $excludeTopLevel
 * @param list<string> $copied entrées effectivement copiées, renseigné même en
 *                             cas d'interruption
 *
 * @return list<string> entrées effectivement copiées, pour l'annulation
 */
function bootstrap_copy_tree(
    string $source,
    string $destination,
    array $excludeTopLevel = [],
    array &$copied = []
): array {
    if (!is_dir($destination) && !@mkdir($destination, 0o755, true) && !is_dir($destination)) {
        throw new RuntimeException('Impossible de créer le dossier de destination.');
    }

    $copied = [];
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $name = $entry->getFilename();
        if (in_array($name, $excludeTopLevel, true)) {
            continue;
        }
        bootstrap_copy_entry($source . '/' . $name, $destination . '/' . $name);
        $copied[] = $name;
    }

    return $copied;
}

function bootstrap_copy_entry(string $source, string $destination): void
{
    if (!is_dir($source)) {
        if (!@copy($source, $destination)) {
            throw new RuntimeException("Échec de la copie d'un fichier pendant l'installation.");
        }

        return;
    }

    if (!is_dir($destination) && !@mkdir($destination, 0o755, true) && !is_dir($destination)) {
        throw new RuntimeException("Impossible de créer un dossier pendant l'installation.");
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $relative = substr((string) $item, strlen($source) + 1);
        $target = $destination . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0o755, true) && !is_dir($target)) {
                throw new RuntimeException("Impossible de créer un dossier pendant l'installation.");
            }
        } elseif (!@copy((string) $item, $target)) {
            throw new RuntimeException("Échec de la copie d'un fichier pendant l'installation.");
        }
    }
}

function bootstrap_remove_path(string $path): void
{
    if (is_link($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        bootstrap_remove_directory($path);
    } elseif (file_exists($path)) {
        @unlink($path);
    }
}

function bootstrap_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isLink() || !$item->isDir()) {
            @unlink((string) $item);
        } else {
            @rmdir((string) $item);
        }
    }

    @rmdir($directory);
}

// =============================================================================
// storage/, VERSION, jeton
// =============================================================================

/**
 * @return list<string> sous-dossiers créés
 */
function bootstrap_create_storage_dirs(string $basePath): array
{
    $created = [];
    foreach (BOOTSTRAP_STORAGE_SUBDIRS as $subdirectory) {
        $path = $basePath . '/storage/' . $subdirectory;
        if (!is_dir($path) && !@mkdir($path, BOOTSTRAP_STORAGE_MODE, true) && !is_dir($path)) {
            throw new RuntimeException("Impossible de créer storage/{$subdirectory}.");
        }
        $created[] = $subdirectory;
    }

    return $created;
}

/**
 * Format identique à celui qu'écrit `UpdateService` : la version suivie d'un
 * saut de ligne. `Kernel` relit ce fichier tel quel.
 */
function bootstrap_write_version(string $basePath, string $version): void
{
    file_put_contents($basePath . '/VERSION', $version . "\n");
}

function bootstrap_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Le fichier est du PHP valide qui répond 404 et s'arrête. L'arborescence
 * unique ne le sert de toute façon jamais — tout ce qui n'existe pas sous
 * `public/` part au contrôleur frontal — mais un fichier de jeton doit rester
 * inoffensif même exécuté, et pas seulement inaccessible.
 */
function bootstrap_token_file_content(string $token): string
{
    return "<?php\n\n"
        . "declare(strict_types=1);\n\n"
        . "// Jeton de l'assistant d'installation de SecondStay, écrit par bootstrap.php.\n"
        . "// Il est lu comme du texte par SecondStay\\Installer\\InstallToken, jamais inclus.\n"
        . "// Supprimez ce fichier une fois l'installation terminée : l'application le fait\n"
        . "// elle-même dès qu'un administrateur existe.\n\n"
        . "http_response_code(404);\n"
        . "exit;\n\n"
        . '// ' . BOOTSTRAP_TOKEN_MARKER . ': ' . $token . "\n";
}

// =============================================================================
// Portail d'acceptation — partie serveur (S1-S8)
// =============================================================================

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_gate_result(string $id, bool $ok, string $label, string $detail): array
{
    return ['id' => $id, 'ok' => $ok, 'label' => $label, 'detail' => $detail];
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s1(string $basePath): array
{
    $path = $basePath . '/VERSION';
    $ok = is_file($path) && trim((string) @file_get_contents($path)) !== '';

    return bootstrap_gate_result('S1', $ok, 'Fichier VERSION', $ok ? 'Présent et lisible.' : 'Manquant ou vide.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s2(string $basePath): array
{
    $ok = is_file($basePath . '/vendor/autoload.php');

    return bootstrap_gate_result(
        'S2',
        $ok,
        'Dépendances installées',
        $ok ? 'vendor/autoload.php présent.' : 'vendor/autoload.php manquant.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s3(string $basePath): array
{
    $ok = is_file($basePath . '/public/index.php');

    return bootstrap_gate_result(
        'S3',
        $ok,
        "Point d'entrée applicatif",
        $ok ? 'public/index.php présent.' : 'public/index.php manquant.'
    );
}

/**
 * Le `.htaccess` racine est livré par l'artefact et n'est jamais écrit ici.
 * Ce contrôle vérifie qu'il est bien arrivé : c'est le seul rempart de
 * l'arborescence unique, et son absence rendrait tout le dépôt lisible.
 *
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s4(string $basePath): array
{
    $root = is_file($basePath . '/.htaccess');
    $public = is_file($basePath . '/public/.htaccess');
    $ok = $root && $public;

    $missing = [];
    if (!$root) {
        $missing[] = '.htaccess';
    }
    if (!$public) {
        $missing[] = 'public/.htaccess';
    }

    return bootstrap_gate_result(
        'S4',
        $ok,
        'Règles de protection du serveur',
        $ok
            ? 'Les deux fichiers .htaccess livrés par l’artefact sont en place.'
            : 'Fichier(s) manquant(s) : ' . implode(', ', $missing) . '.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s5(string $basePath): array
{
    $missing = [];
    foreach (BOOTSTRAP_STORAGE_SUBDIRS as $subdirectory) {
        if (!is_dir($basePath . '/storage/' . $subdirectory)) {
            $missing[] = $subdirectory;
        }
    }
    $ok = $missing === [];

    return bootstrap_gate_result(
        'S5',
        $ok,
        'Dossiers de stockage',
        $ok
            ? 'Tous les sous-dossiers de storage/ sont créés.'
            : 'Sous-dossiers manquants : ' . implode(', ', $missing) . '.'
    );
}

/**
 * Écriture réelle plutôt que `is_writable()`, pour la même raison que
 * `bootstrap_probe_writable()`.
 *
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s6(string $basePath): array
{
    $path = $basePath . '/storage/logs';
    $ok = bootstrap_probe_writable($path);
    $mode = is_dir($path) ? substr(sprintf('%o', (int) fileperms($path)), -4) : 'n/a';

    return bootstrap_gate_result(
        'S6',
        $ok,
        'Permissions de storage/',
        $ok
            ? "Accessible en écriture (mode {$mode})."
            : "storage/logs n'est pas accessible en écriture (mode {$mode})."
    );
}

/**
 * Une installation neuve ne doit porter **aucune** configuration locale :
 * `config/local.php` contient les identifiants de base et les clés de
 * chiffrement, et c'est l'assistant qui l'écrit. En trouver un ici signifie
 * que l'archive en contenait un — donc que les secrets de quelqu'un d'autre
 * viennent d'être déposés sur cet hébergement.
 *
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s7(string $basePath): array
{
    $app = is_file($basePath . '/config/app.php');
    $local = is_file($basePath . '/config/local.php');
    $ok = $app && !$local;

    if (!$app) {
        $detail = 'config/app.php est absent : l’application ne peut pas démarrer.';
    } elseif ($local) {
        $detail = 'L’archive contenait un config/local.php — elle transporte la configuration '
            . 'd’une autre installation. Refus.';
    } else {
        $detail = 'config/app.php présent, aucune configuration locale héritée.';
    }

    return bootstrap_gate_result('S7', $ok, 'Configuration', $detail);
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s8(string $temporaryDirectory): array
{
    $ok = !is_dir($temporaryDirectory);

    return bootstrap_gate_result(
        'S8',
        $ok,
        'Nettoyage du dossier temporaire',
        $ok ? 'Dossier temporaire supprimé.' : 'Le dossier temporaire existe encore.'
    );
}

// =============================================================================
// Portail d'acceptation — partie navigateur (B1-B10, F1)
//
// Ces contrôles ne peuvent pas se faire depuis PHP : ce qu'ils mesurent, c'est
// ce qu'Apache sert réellement à un client, pas ce que le disque contient. Le
// navigateur de l'opérateur va chercher chaque sonde et rapporte ce qu'il a
// obtenu ; les fonctions ci-dessous jugent ces rapports.
// =============================================================================

/**
 * B1 — témoin positif. Doit être exactement ce qui a été écrit. Un témoin en
 * échec invalide tous les contrôles suivants : si un fichier volontairement
 * public n'est pas servi, « inaccessible » ne prouve plus rien.
 */
function bootstrap_evaluate_control_probe(int $httpStatus, string $fetchedBody, string $probeContent): bool
{
    return $httpStatus === 200 && $fetchedBody === $probeContent;
}

/**
 * B2 — PHP s'exécute. Si Apache sert `public/index.php` en clair, le corps de
 * la réponse contient la balise d'ouverture PHP. C'est la panne la plus grave
 * possible : tout le code source, y compris le jeton, deviendrait lisible.
 *
 * La sonde vise l'assistant **avec son jeton**, et non « / ». Le navigateur
 * suit les redirections : « / » mène à `/{locale}/install`, que le portail à
 * jeton refuse en 403 sans jeton. Cette sonde rejetait alors une installation
 * parfaitement saine et déclenchait l'annulation complète — un contrôle qui
 * condamne ce qu'il est censé protéger. Voir `bootstrap_write_gate_probes()`.
 */
function bootstrap_evaluate_php_execution_probe(int $httpStatus, string $fetchedBody): bool
{
    if ($httpStatus < 200 || $httpStatus >= 400) {
        return false;
    }

    return stripos($fetchedBody, '<?php') === false
        && stripos($fetchedBody, 'declare(strict_types=1)') === false;
}

/**
 * B3-B9 — « non lisible » signifie 403, 404, ou un 200 dont le corps n'est
 * pas le contenu attendu (une page d'erreur, le contrôleur frontal). Jamais
 * le seul code de statut : un hébergeur qui renvoie une page personnalisée en
 * 200 pour tout ce qu'il refuse existe, et le lire comme un succès
 * transformerait une exposition en réussite.
 */
function bootstrap_evaluate_protection_probe(int $httpStatus, string $fetchedBody, string $probeContent): bool
{
    if ($httpStatus === 403 || $httpStatus === 404) {
        return true;
    }
    if ($httpStatus === 200) {
        return $fetchedBody !== $probeContent;
    }

    return false;
}

/**
 * B10 — l'URL du dossier `storage/` ne doit pas lister son contenu.
 */
function bootstrap_evaluate_no_directory_listing(int $httpStatus, string $fetchedBody): bool
{
    if ($httpStatus === 403 || $httpStatus === 404) {
        return true;
    }

    return $httpStatus === 200 && stripos($fetchedBody, 'Index of') === false;
}

/**
 * F1 — l'assistant d'installation répond réellement, jeton en main.
 */
function bootstrap_evaluate_functional_probe(int $httpStatus, string $fetchedBody, string $marker): bool
{
    return $httpStatus === 200 && str_contains($fetchedBody, $marker);
}

// =============================================================================
// État et verrou
// =============================================================================

/**
 * L'état est du PHP valide dont les données vivent dans un commentaire : posé
 * à la racine d'un hébergement, il ne divulgue rien même s'il est servi.
 *
 * @return array<string, mixed>
 */
function bootstrap_read_state(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || preg_match('#/\*(.*)\*/#s', $raw, $matches) !== 1) {
        return [];
    }
    $data = json_decode(trim($matches[1]), true);

    return is_array($data) ? $data : [];
}

/**
 * Écrit l'état, et **ne laisse aucune donnée fermer le commentaire**.
 *
 * Les données vivent entre `/*` et le `*` suivi de `/` qui le referme. Une
 * valeur d'état qui contiendrait cette séquence — un chemin, un message
 * d'erreur d'hébergeur, un nom d'entrée d'archive — refermerait le commentaire
 * par le milieu, et tout ce qui suit deviendrait du PHP exécutable dans un
 * fichier posé à la racine du document. C'est le fichier lui-même qui
 * deviendrait l'injection.
 *
 * `JSON_UNESCAPED_SLASHES` est donc retiré : JSON écrit alors chaque barre
 * oblique `\/`, l'octet `/` n'apparaît plus nulle part dans la charge, et la
 * séquence de fermeture devient impossible à construire. Les octets UTF-8
 * gardés bruts par `JSON_UNESCAPED_UNICODE` ne peuvent pas la reformer non
 * plus : une continuation UTF-8 vaut toujours 0x80 ou plus. `json_decode()`
 * relit `\/` comme `/` sans rien perdre.
 *
 * La vérification qui suit n'est donc pas censée pouvoir échouer. Elle est là
 * parce qu'un invariant de sécurité qui repose sur un raisonnement sur les
 * options d'un encodeur mérite d'être vérifié plutôt que cru.
 *
 * @param array<string, mixed> $state
 */
function bootstrap_write_state(string $path, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || str_contains($json, '*/')) {
        throw new RuntimeException("L'état de l'installation n'est pas encodable sans risque.");
    }

    file_put_contents($path, "<?php\n/*\n{$json}\n*/\n");
    bootstrap_restrict_permissions($path);
}

/**
 * Écrit l'état depuis un chemin d'erreur, sans jamais devenir l'erreur.
 *
 * `bootstrap_write_state()` lève quand la restriction des droits échoue, et
 * c'est voulu sur le chemin normal : un jeton qu'on croit protégé et qui ne
 * l'est pas est pire qu'un jeton dont on sait qu'il ne l'est pas. Mais lever
 * **depuis un `catch`** remplacerait le message du premier échec par une
 * erreur fatale, et le client n'aurait plus rien à lire — ni ce qui a échoué,
 * ni comment reprendre. Le premier diagnostic prime.
 *
 * @param array<string, mixed> $state
 */
function bootstrap_write_state_quietly(string $path, array $state): void
{
    try {
        bootstrap_write_state($path, $state);
    } catch (Throwable) {
        // Rien à faire de plus ici : le message qui compte part juste après.
    }
}

/**
 * Restreint un fichier au seul compte qui l'a écrit.
 *
 * `token.php` et `.bootstrap-state.php` naissent avec les droits de l'umask.
 * Sur un hébergement mutualisé, une umask permissive les rend lisibles par les
 * autres comptes de la machine — et `token.php` porte le jeton qui ouvre
 * l'assistant d'installation. L'échec de la restriction est signalé plutôt que
 * toléré : un jeton qu'on croit protégé et qui ne l'est pas est pire qu'un
 * jeton dont on sait qu'il ne l'est pas.
 */
function bootstrap_restrict_permissions(string $path): void
{
    if (!@chmod($path, 0o600)) {
        throw new RuntimeException(sprintf(
            'Impossible de restreindre les droits de %s à son seul propriétaire.',
            basename($path)
        ));
    }
}

/**
 * Prend le verrou d'installation, ou échoue.
 *
 * L'enjeu justifie la prudence dans les deux sens : un verrou lu périmé alors
 * qu'il est frais laisse deux installations démarrer en parallèle sur le même
 * `docRoot` — copies entrelacées, `installed_entries` divergents de ce qui est
 * réellement sur le disque, annulation partielle ; lu frais alors qu'il est
 * périmé, il condamne toute reprise jusqu'à une intervention FTP.
 *
 * ## Pourquoi ce n'est pas trois appels
 *
 * Tester l'existence, lire la date, puis écrire, forme trois opérations
 * distinctes : deux requêtes simultanées constatent toutes deux l'absence de
 * verrou et l'écrivent toutes deux. `clearstatcache()` retirait la dépendance
 * au cache de `stat()`, il ne rendait pas la prise atomique.
 *
 * La création se fait donc en **une** opération noyau : `fopen($path, 'x')`
 * ouvre avec `O_CREAT | O_EXCL` et échoue si le fichier existe. Exactement une
 * requête gagne.
 *
 * ## La reprise d'un verrou périmé est sérialisée
 *
 * Retirer un verrou périmé avant de recréer rouvrirait la course par la
 * fenêtre : deux requêtes le retireraient toutes deux, et la seconde écraserait
 * le verrou frais de la première. La reprise se fait donc sur place, sous
 * `flock()` exclusif non bloquant, et la date est relue **sous ce verrou** :
 * celle qui arrive seconde voit une date fraîche et renonce.
 */
function bootstrap_acquire_lock(string $path): bool
{
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        fwrite($handle, (string) getmypid());
        fclose($handle);

        return true;
    }

    // Le verrou existe. Reste à savoir s'il est abandonné, et une seule
    // requête à la fois a le droit de poser la question.
    $handle = @fopen($path, 'r+');
    if ($handle === false) {
        return false;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return false;
    }

    clearstatcache(true, $path);
    if ((time() - (int) @filemtime($path)) < BOOTSTRAP_LOCK_STALE_SECONDS) {
        flock($handle, LOCK_UN);
        fclose($handle);

        return false;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return true;
}

function bootstrap_release_lock(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

// =============================================================================
// Les onze étapes. Chacune est une requête POST courte : une installation
// complète en une seule requête dépasse le `max_execution_time` de la plupart
// des hébergements mutualisés, et l'état est donc persisté entre chacune.
// =============================================================================

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_preflight(string $docRoot, array $state): array
{
    foreach (bootstrap_preflight_checks($docRoot) as $check) {
        if (!$check['ok']) {
            throw new RuntimeException($check['detail']);
        }
    }

    if (bootstrap_already_installed($docRoot)) {
        throw new RuntimeException('Ce dossier contient déjà une installation SecondStay.');
    }

    $state['doc_root'] = $docRoot;
    $state['environment'] = bootstrap_gather_environment_info($_SERVER);
    $state['label'] = 'Préflight';
    $state['percent'] = 100;

    return $state;
}

/**
 * @return list<array{ok: bool, label: string, detail: string}>
 */
function bootstrap_preflight_checks(string $docRoot): array
{
    $writable = bootstrap_probe_writable($docRoot);

    return [
        bootstrap_check_location($_SERVER),
        bootstrap_check_php_version(),
        bootstrap_check_zip_extension(),
        bootstrap_check_extensions(),
        bootstrap_check_outbound_https('bootstrap_default_https_probe'),
        [
            'ok' => $writable,
            'label' => "Permissions du dossier d'installation",
            'detail' => $writable
                ? 'Accessible en écriture (écriture, relecture et suppression testées).'
                : "Le dossier n'est pas accessible en écriture.",
        ],
    ];
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_resolve(string $docRoot, array $state): array
{
    $release = bootstrap_fetch_latest_release('bootstrap_default_http_get');
    $archive = bootstrap_resolve_archive_url($release);

    $diskCheck = bootstrap_check_disk_space($docRoot, $archive['size']);
    if (!$diskCheck['ok']) {
        throw new RuntimeException($diskCheck['detail']);
    }

    $state['version'] = ltrim((string) ($release['tag_name'] ?? '0.0.0'), 'v');
    $state['archive_url'] = $archive['url'];
    $state['archive_size'] = $archive['size'];
    $state['archive_digest'] = $archive['digest'];
    $state['source_type'] = $archive['source'];
    $state['disk_check'] = $diskCheck;
    $state['label'] = 'Résolution de la dernière version';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_download(string $docRoot, array $state): array
{
    // Le dossier temporaire vit dans la cible d'installation, jamais dans
    // `sys_get_temp_dir()` : un `rename()` entre volumes échoue, et la
    // partition temporaire d'un hébergement mutualisé est souvent minuscule.
    $temporaryDirectory = $docRoot . '/' . BOOTSTRAP_TEMP_DIR_PREFIX . bin2hex(random_bytes(6));
    if (!@mkdir($temporaryDirectory, 0o700, true)) {
        throw new RuntimeException('Impossible de créer le dossier temporaire.');
    }
    // Il commence par un point : le `.htaccess` livré par l'artefact refuse
    // déjà les fichiers cachés — mais l'artefact n'est pas encore installé.
    file_put_contents($temporaryDirectory . '/.htaccess', "Require all denied\n");

    $artifactPath = $temporaryDirectory . '/artifact.zip';
    bootstrap_download_with_retry(
        (string) $state['archive_url'],
        $artifactPath,
        'bootstrap_default_downloader'
    );

    $header = (string) @file_get_contents($artifactPath, false, null, 0, 2);
    if ((int) filesize($artifactPath) < 4 || $header !== 'PK') {
        throw new RuntimeException("Le fichier téléchargé n'est pas une archive ZIP.");
    }

    // L'empreinte vient d'`api.github.com`, l'archive vient du service de
    // stockage au bout d'une chaîne de redirections : deux canaux, et c'est
    // ce qui rend la comparaison utile. Sans empreinte publiée, on le dit —
    // « non vérifiée » et « vérifiée » ne sont pas la même réponse.
    $state['integrity'] = bootstrap_verify_archive_digest(
        $artifactPath,
        (string) ($state['archive_digest'] ?? '')
    );

    $state['temp_dir'] = $temporaryDirectory;
    $state['artifact_path'] = $artifactPath;
    $state['label'] = 'Téléchargement';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_extract(string $docRoot, array $state): array
{
    $extractedDirectory = $state['temp_dir'] . '/extracted';
    if (!@mkdir($extractedDirectory, 0o700, true)) {
        throw new RuntimeException("Impossible de créer le dossier d'extraction.");
    }
    bootstrap_extract_zip_safely((string) $state['artifact_path'], $extractedDirectory);

    $state['extracted_dir'] = $extractedDirectory;
    $state['source_root'] = bootstrap_resolve_archive_root($extractedDirectory, (string) $state['source_type']);
    $state['label'] = 'Extraction';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_verify_artifact(string $docRoot, array $state): array
{
    $result = bootstrap_verify_artifact((string) $state['source_root']);
    if (!$result['ok']) {
        throw new RuntimeException($result['detail']);
    }

    if (is_file($state['source_root'] . '/config/local.php')) {
        throw new RuntimeException(
            "L'archive contient un config/local.php : elle transporte la configuration d'une autre "
            . 'installation. Installation refusée.'
        );
    }

    $state['label'] = "Vérification de l'artefact";
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_install(string $docRoot, array $state): array
{
    // `storage/` et `VERSION` sont exclus : le premier est créé à l'étape
    // suivante avec les bons droits, le second est écrit depuis le tag de la
    // release, qui fait foi sur ce que l'on vient réellement d'installer.
    $copied = [];
    try {
        bootstrap_copy_tree((string) $state['source_root'], $docRoot, ['storage', 'VERSION'], $copied);
    } catch (Throwable $throwable) {
        // Ce qui est déjà à la racine doit remonter avec l'échec, sinon
        // personne ne pourra plus le retirer. Voir BootstrapPartialInstall.
        throw new BootstrapPartialInstall($throwable->getMessage(), $copied, $throwable);
    }

    $state['install_target'] = $docRoot;
    $state['installed_entries'] = $copied;
    $state['label'] = 'Installation des fichiers';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_storage(string $docRoot, array $state): array
{
    bootstrap_create_storage_dirs((string) $state['install_target']);

    $state['label'] = 'Création du stockage';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_finalize(string $docRoot, array $state): array
{
    bootstrap_write_version((string) $state['install_target'], (string) $state['version']);
    bootstrap_remove_directory((string) $state['temp_dir']);

    $state['label'] = 'Finalisation';
    $state['percent'] = 100;

    return $state;
}

/**
 * Exécute la partie serveur du portail. Si elle échoue, l'installation est
 * annulée immédiatement : inutile de demander au navigateur de sonder un
 * arbre que l'on s'apprête à supprimer. Sinon, les sondes sont écrites et
 * leurs URL rendues au client, qui les récupérera lui-même.
 *
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_gate_prepare(string $docRoot, array $state): array
{
    $basePath = (string) $state['install_target'];

    $serverChecks = [
        bootstrap_check_s1($basePath),
        bootstrap_check_s2($basePath),
        bootstrap_check_s3($basePath),
        bootstrap_check_s4($basePath),
        bootstrap_check_s5($basePath),
        bootstrap_check_s6($basePath),
        bootstrap_check_s7($basePath),
        bootstrap_check_s8((string) $state['temp_dir']),
    ];
    $state['s_checks'] = $serverChecks;

    $failed = array_filter($serverChecks, static fn (array $check): bool => !$check['ok']);
    if ($failed !== []) {
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'S';
        $state['label'] = 'Contrôles (échec)';
        $state['percent'] = 100;

        return $state;
    }

    // Le jeton est écrit **avant** le contrôle fonctionnel, et pas après :
    // l'assistant est protégé par lui, donc la seule façon de prouver que
    // l'assistant répond est de le lui présenter. Il est retiré par
    // l'annulation si le portail échoue.
    $token = bootstrap_generate_token();
    if (@file_put_contents($docRoot . '/' . BOOTSTRAP_TOKEN_FILE, bootstrap_token_file_content($token)) === false) {
        throw new RuntimeException("Impossible d'écrire token.php à la racine du site.");
    }
    bootstrap_restrict_permissions($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);
    $state['token'] = $token;

    $state['probes'] = bootstrap_write_gate_probes($docRoot, $state);
    $state['awaiting_gate_report'] = true;
    $state['label'] = 'Contrôles';
    $state['percent'] = 50;

    return $state;
}

/**
 * Écrit les fichiers témoins et décrit les sondes que le navigateur ira
 * chercher. Chaque sonde est un fichier neuf à contenu aléatoire plutôt qu'un
 * fichier livré par l'artefact : ce que l'on compare est alors une valeur qui
 * n'existe nulle part ailleurs, et une correspondance ne peut pas être un
 * hasard de cache ou de page d'erreur.
 *
 * @param array<string, mixed> $state
 *
 * @return list<array<string, mixed>>
 */
function bootstrap_write_gate_probes(string $docRoot, array $state): array
{
    $basePath = (string) $state['install_target'];
    $random = bin2hex(random_bytes(6));
    $content = 'secondstay-probe-' . $random;

    $probes = [];

    // B1 — témoin positif. Il vit sous `public/`, parce que c'est le seul
    // endroit que l'arborescence unique sert en direct : tout le reste part
    // au contrôleur frontal. Un témoin posé à la racine serait « inaccessible »
    // par construction et ne prouverait rien.
    $controlFile = $basePath . '/public/control-' . $random . '.txt';
    file_put_contents($controlFile, $content);
    $probes[] = [
        'id' => 'B1',
        'kind' => 'control',
        'url' => '/control-' . $random . '.txt',
        'expected' => $content,
        'file' => $controlFile,
    ];

    // B2 — PHP s'exécute. L'URL porte le jeton, pour la même raison que F1
    // plus bas ne demande pas « / » : le navigateur suit les redirections, « / »
    // mène à `/{locale}/install`, et le portail à jeton y répond 403 sans
    // jeton. La sonde lisait ce 403 comme « PHP ne s'exécute pas » et faisait
    // annuler une installation saine.
    //
    // B2 et F1 partagent donc l'adresse et n'y cherchent pas la même chose :
    // B2 échoue si le corps contient du source PHP — la fuite la plus grave
    // possible — et F1 échoue si l'assistant n'est pas là. Deux pannes
    // distinctes, deux messages distincts, et l'opérateur sait laquelle il a.
    $probes[] = [
        'id' => 'B2',
        'kind' => 'php_exec',
        'url' => '/fr/install?' . BOOTSTRAP_TOKEN_PARAMETER . '=' . (string) $state['token'],
        'expected' => null,
        'file' => null,
    ];

    foreach ([
        'B3' => 'src',
        'B4' => 'config',
        'B5' => 'vendor',
    ] as $id => $directory) {
        $file = $basePath . '/' . $directory . '/canary-' . $random . '.txt';
        file_put_contents($file, $content);
        $probes[] = [
            'id' => $id,
            'kind' => 'protection',
            'label' => $directory . '/',
            'url' => '/' . $directory . '/canary-' . $random . '.txt',
            'expected' => $content,
            'file' => $file,
        ];
    }

    // B6 — un sous-dossier de storage/ livré par l'installation.
    $storageFile = $basePath . '/storage/logs/canary-' . $random . '.txt';
    file_put_contents($storageFile, $content);
    $probes[] = [
        'id' => 'B6',
        'kind' => 'protection',
        'label' => 'storage/logs/',
        'url' => '/storage/logs/canary-' . $random . '.txt',
        'expected' => $content,
        'file' => $storageFile,
    ];

    // B7 — un dossier de storage/ créé à l'instant. C'est la différence entre
    // une règle de préfixe et un fichier de refus déposé dans chaque dossier :
    // l'application crée des dossiers longtemps après l'installation
    // (`media/`, `backups/`, les répertoires de cache), et un refus par
    // dossier ne les couvrirait jamais.
    $newDirectory = $basePath . '/storage/gatecheck_' . $random;
    @mkdir($newDirectory, BOOTSTRAP_STORAGE_MODE, true);
    $newFile = $newDirectory . '/canary-' . $random . '.txt';
    file_put_contents($newFile, $content);
    $probes[] = [
        'id' => 'B7',
        'kind' => 'protection',
        'label' => 'storage/ créé après coup',
        'url' => '/storage/gatecheck_' . $random . '/canary-' . $random . '.txt',
        'expected' => $content,
        'file' => $newFile,
        'dir' => $newDirectory,
    ];

    // B8 — un fichier caché à la racine.
    $dotFile = $docRoot . '/.probe-' . $random;
    file_put_contents($dotFile, $content);
    $probes[] = [
        'id' => 'B8',
        'kind' => 'protection',
        'label' => 'fichier caché',
        'url' => '/.probe-' . $random,
        'expected' => $content,
        'file' => $dotFile,
    ];

    // B9 — un vrai fichier livré par l'artefact, refusé par une règle
    // d'extension et non par une règle de dossier. Les sondes précédentes
    // couvrent toutes des dossiers ; celle-ci couvre l'autre moitié du
    // `.htaccess`.
    $versionPath = $basePath . '/VERSION';
    if (is_file($versionPath)) {
        $probes[] = [
            'id' => 'B9',
            'kind' => 'protection',
            'label' => 'VERSION',
            'url' => '/VERSION',
            'expected' => (string) file_get_contents($versionPath),
            'file' => null,
        ];
    }

    // B10 — pas de listage de répertoire.
    $probes[] = ['id' => 'B10', 'kind' => 'listing', 'url' => '/storage/', 'expected' => null, 'file' => null];

    // F1 — l'assistant répond, jeton en main. Le chemin est explicite plutôt
    // que « / » : la racine redirige, et une redirection interceptée par une
    // page d'accueil d'hébergeur rendrait ce contrôle ambigu.
    $probes[] = [
        'id' => 'F1',
        'kind' => 'functional',
        'url' => '/fr/install?' . BOOTSTRAP_TOKEN_PARAMETER . '=' . (string) $state['token'],
        'expected' => BOOTSTRAP_WIZARD_MARKER,
        'file' => null,
    ];

    return $probes;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>|null
 */
function bootstrap_find_probe(array $state, string $id): ?array
{
    foreach ((array) ($state['probes'] ?? []) as $probe) {
        if (is_array($probe) && ($probe['id'] ?? null) === $id) {
            return $probe;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $state
 */
function bootstrap_probe_expected(array $state, string $id): string
{
    $probe = bootstrap_find_probe($state, $id);

    return $probe !== null && $probe['expected'] !== null ? (string) $probe['expected'] : '';
}

/**
 * @param array<string, mixed> $state
 */
function bootstrap_probe_label(array $state, string $id): string
{
    $probe = bootstrap_find_probe($state, $id);

    return isset($probe['label']) ? (string) $probe['label'] : $id;
}

/**
 * @param array<string, mixed> $state
 */
function bootstrap_cleanup_gate_probes(array $state): void
{
    foreach ((array) ($state['probes'] ?? []) as $probe) {
        if (!is_array($probe)) {
            continue;
        }
        if (!empty($probe['file']) && is_file((string) $probe['file'])) {
            @unlink((string) $probe['file']);
        }
        if (!empty($probe['dir']) && is_dir((string) $probe['dir'])) {
            @rmdir((string) $probe['dir']);
        }
    }
}

/**
 * Un portail en échec annule l'arbre **entier**, il ne se contente pas de
 * s'arrêter. Une installation à moitié faite qu'un contrôle vient de déclarer
 * dangereuse ne doit pas rester configurable — et si l'échec est B2 (PHP
 * servi en clair), laisser derrière soi un `public/index.php` accessible
 * revient à publier le code source et le jeton à qui les demande.
 *
 * @param array<string, mixed> $state
 */
function bootstrap_rollback_install(string $docRoot, array $state): void
{
    $target = $state['install_target'] ?? null;
    if (is_string($target)) {
        foreach ((array) ($state['installed_entries'] ?? []) as $entry) {
            bootstrap_remove_path($target . '/' . (string) $entry);
        }
        bootstrap_remove_path($target . '/storage');
        bootstrap_remove_path($target . '/VERSION');
    }

    bootstrap_remove_path($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);
    bootstrap_cleanup_gate_probes($state);

    if (!empty($state['temp_dir'])) {
        bootstrap_remove_path((string) $state['temp_dir']);
    }
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_finish_gate(array $state, bool $passed): array
{
    $state['gate_report'] = [
        's_checks' => $state['s_checks'] ?? [],
        'b_checks' => $state['b_checks'] ?? [],
        'f_checks' => $state['f_checks'] ?? [],
        'passed' => $passed,
        'generated_at' => date('c'),
    ];
    $state['gate_passed'] = $passed;
    $state['awaiting_gate_report'] = false;
    $state['done_gate'] = true;

    return $state;
}

/**
 * Juge les résultats que le navigateur rapporte pour chaque sonde.
 *
 * C'est le seul endroit de ce fichier qui fasse confiance à une valeur venue
 * du client, et c'est délibéré : seule la personne qui lance l'installation
 * peut forger ce verdict, le forger ne donne aucun privilège — l'assistant
 * reste protégé par le jeton — et le seul site qu'un « succès » forgé mette
 * en danger est le sien.
 *
 * @param array<string, mixed> $state
 * @param list<mixed> $results résultats rapportés par le navigateur : du JSON
 *        décodé, dont rien n'est supposé présent
 *
 * @return array<string, mixed>
 */
function bootstrap_evaluate_gate_report(string $docRoot, array $state, array $results): array
{
    $byId = [];
    foreach ($results as $result) {
        if (is_array($result) && isset($result['id']) && is_string($result['id'])) {
            $byId[$result['id']] = $result;
        }
    }

    $status = static fn (string $id): int => (int) ($byId[$id]['status'] ?? 0);
    $body = static fn (string $id): string => (string) ($byId[$id]['body'] ?? '');

    $browserChecks = [];

    $controlPassed = isset($byId['B1'])
        && bootstrap_evaluate_control_probe($status('B1'), $body('B1'), bootstrap_probe_expected($state, 'B1'));
    $browserChecks[] = bootstrap_gate_result(
        'B1',
        $controlPassed,
        'Témoin positif',
        $controlPassed
            ? 'Le fichier témoin a été servi tel quel.'
            : "Le fichier témoin n'a pas pu être récupéré — aucun des contrôles suivants n'est interprétable."
    );

    if (!$controlPassed) {
        foreach (['B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9', 'B10'] as $id) {
            if (bootstrap_find_probe($state, $id) !== null) {
                $browserChecks[] = bootstrap_gate_result($id, false, $id, 'Non vérifié — le témoin positif a échoué.');
            }
        }
        $state['b_checks'] = $browserChecks;
        $state['f_checks'] = [bootstrap_gate_result(
            'F1',
            false,
            "Assistant d'installation accessible",
            'Non vérifié — le témoin positif a échoué.'
        )];
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'B1';

        return $state;
    }

    $phpPassed = isset($byId['B2']) && bootstrap_evaluate_php_execution_probe($status('B2'), $body('B2'));
    $browserChecks[] = bootstrap_gate_result(
        'B2',
        $phpPassed,
        'Exécution de PHP',
        $phpPassed
            ? "Le serveur exécute PHP : la page d'accueil est rendue, pas servie en clair."
            : 'Le serveur a renvoyé du code source PHP au lieu de l’exécuter. Tout le dépôt serait '
                . 'lisible, jeton compris. Abandon immédiat.'
    );

    $protectionsPassed = true;
    foreach (['B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'] as $id) {
        $probe = bootstrap_find_probe($state, $id);
        if ($probe === null) {
            continue;
        }
        $passed = isset($byId[$id])
            && bootstrap_evaluate_protection_probe($status($id), $body($id), (string) $probe['expected']);
        $protectionsPassed = $protectionsPassed && $passed;
        $browserChecks[] = bootstrap_gate_result(
            $id,
            $passed,
            'Protection : ' . bootstrap_probe_label($state, $id),
            $passed
                ? 'Non accessible depuis le web.'
                : 'ACCESSIBLE depuis le web — cette ressource ne doit jamais l’être.'
        );
    }

    $listingPassed = true;
    if (bootstrap_find_probe($state, 'B10') !== null) {
        $listingPassed = isset($byId['B10'])
            && bootstrap_evaluate_no_directory_listing($status('B10'), $body('B10'));
        $browserChecks[] = bootstrap_gate_result(
            'B10',
            $listingPassed,
            'Pas de listage de répertoire',
            $listingPassed ? 'Aucun contenu de dossier exposé.' : 'Le contenu de storage/ est listé publiquement.'
        );
    }

    $functionalPassed = isset($byId['F1'])
        && bootstrap_evaluate_functional_probe($status('F1'), $body('F1'), BOOTSTRAP_WIZARD_MARKER);
    $functionalChecks = [bootstrap_gate_result(
        'F1',
        $functionalPassed,
        "Assistant d'installation accessible",
        $functionalPassed
            ? "L'assistant répond et accepte le jeton."
            : "L'assistant d'installation n'a pas répondu comme attendu avec son jeton."
    )];

    $state['b_checks'] = $browserChecks;
    $state['f_checks'] = $functionalChecks;

    if (!$phpPassed) {
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'B2';

        return $state;
    }

    if (!$protectionsPassed || !$listingPassed || !$functionalPassed) {
        bootstrap_rollback_install($docRoot, $state);

        return bootstrap_finish_gate($state, false);
    }

    bootstrap_cleanup_gate_probes($state);
    $state = bootstrap_finish_gate($state, true);

    // Le rapport est déposé dans l'installation elle-même : c'est la seule
    // trace de ce que le portail a réellement mesuré sur cet hébergement, et
    // elle survit à la suppression de bootstrap.php.
    $logs = $state['install_target'] . '/storage/logs';
    if (is_dir($logs)) {
        @file_put_contents(
            $logs . '/install-report.json',
            (string) json_encode(
                $state['gate_report'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    return $state;
}

/**
 * Le jeton a été écrit à l'étape 9 pour que le contrôle fonctionnel puisse
 * s'en servir ; cette étape ne fait que constater qu'il est bien en place
 * après un portail réussi. Le distinguer de l'écriture garde l'invariant
 * lisible : rien ne survit au portail sans son accord.
 *
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_token(string $docRoot, array $state): array
{
    if (empty($state['gate_passed'])) {
        throw new RuntimeException("Le jeton n'est conservé qu'après la réussite des contrôles.");
    }

    $path = $docRoot . '/' . BOOTSTRAP_TOKEN_FILE;
    $state['token_written'] = is_file($path) && trim((string) @file_get_contents($path)) !== '';

    if (!$state['token_written']) {
        // Dégradation choisie : ne pas enfermer l'opérateur dehors. Le contenu
        // exact à déposer par FTP lui est donné, et l'assistant l'acceptera.
        $state['token_write_warning'] = "token.php a disparu après les contrôles — recréez-le par FTP "
            . 'à la racine du site avec le contenu ci-dessous.';
        $state['token_manual_content'] = bootstrap_token_file_content((string) $state['token']);
    }

    $state['wizard_url'] = '/fr/install?' . BOOTSTRAP_TOKEN_PARAMETER . '=' . (string) $state['token'];
    $state['label'] = 'Jeton';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_step_cleanup(string $docRoot, array $state, ?callable $selfDelete = null): array
{
    $selfDelete ??= static fn (): bool => @unlink(__FILE__);

    @unlink($docRoot . '/' . BOOTSTRAP_STATE_FILE);
    $deleted = (bool) $selfDelete();

    $state['self_deleted'] = $deleted;
    if (!$deleted) {
        $state['cleanup_warning'] = "Impossible de supprimer bootstrap.php automatiquement — supprimez-le par FTP. "
            . "Il ne réinstallera rien tant que le fichier VERSION existe, mais le laisser en place n'a aucun "
            . 'intérêt et reste une surface inutile.';
    }

    $state['label'] = 'Nettoyage';
    $state['percent'] = 100;
    $state['done'] = true;

    return $state;
}

// =============================================================================
// Entrées/sorties réelles. Ce sont les seules fonctions qui touchent au
// réseau ; partout ailleurs elles sont injectées, pour que les tests puissent
// les remplacer.
// =============================================================================

/**
 * @param list<string> $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function bootstrap_default_http_get(string $url, array $headers = []): array
{
    $headerLines = 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n";
    foreach ($headers as $header) {
        $headerLines .= $header . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'timeout' => BOOTSTRAP_HTTP_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 1,
        ],
        // Le certificat est toujours vérifié. Il n'existe aucune situation où
        // télécharger le code que l'on s'apprête à exécuter justifie de ne
        // pas savoir à qui on parle.
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = [];

    // `$http_response_header` est une variable locale que PHP crée dans cette
    // portée après l'opération de flux — mais uniquement si une réponse est
    // réellement arrivée. Un échec de connexion (DNS, refus) ne la crée pas
    // du tout, d'où ce `isset()` que l'analyse statique croit superflu.
    // @phpstan-ignore-next-line
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
            } elseif (str_contains((string) $line, ':')) {
                [$name, $value] = explode(':', (string) $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body === false ? '' : $body];
}

function bootstrap_default_downloader(string $url, string $destination): void
{
    $current = $url;

    for ($hop = 0; $hop <= BOOTSTRAP_MAX_REDIRECTS; $hop++) {
        if (!bootstrap_url_is_https($current)) {
            throw new RuntimeException(
                'Le téléchargement quitte HTTPS : refusé. Le seul canal admis vers GitHub est chiffré.'
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n",
                'timeout' => BOOTSTRAP_DOWNLOAD_TIMEOUT,
                // `follow_location => 0` : PHP suivrait sinon une redirection
                // vers `http://` sans rien signaler. Les options `ssl`
                // ci-dessous ne protègent que les sauts qui restent en TLS —
                // elles n'ont rien à dire d'un saut qui n'en fait plus partie.
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = @fopen($current, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Le téléchargement a échoué.');
        }

        // PHP renseigne cette variable dans la portée locale à chaque
        // ouverture réussie par l'enveloppe HTTP — et le schéma vient d'être
        // contrôlé, donc c'est bien cette enveloppe qui a servi.
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;
        $status = bootstrap_status_from_headers($responseHeaders);

        if ($status >= 300 && $status < 400) {
            fclose($stream);
            $location = bootstrap_header_value($responseHeaders, 'location');
            if ($location === null) {
                throw new RuntimeException('Le téléchargement a été redirigé sans destination.');
            }
            $current = bootstrap_resolve_redirect($current, $location);
            continue;
        }

        if ($status !== 200) {
            fclose($stream);
            throw new RuntimeException(sprintf('Le téléchargement a échoué (statut HTTP %d).', $status));
        }

        $out = @fopen($destination, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new RuntimeException("Impossible d'écrire l'archive téléchargée.");
        }

        $copied = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        if ($copied === false) {
            throw new RuntimeException('Le téléchargement a échoué.');
        }

        return;
    }

    throw new RuntimeException('Le téléchargement a été redirigé trop de fois.');
}

/**
 * Compare les octets réellement reçus à l'empreinte publiée par l'API.
 *
 * Trois issues, et elles se distinguent : vérifiée, non publiée, ou fausse.
 * La dernière lève — une archive dont l'empreinte ne correspond pas n'est pas
 * une archive dégradée, c'est une archive dont on ne sait pas d'où elle vient.
 *
 * @return string ce que l'opérateur lira dans le rapport
 */
function bootstrap_verify_archive_digest(string $path, string $expected): string
{
    if ($expected === '') {
        return "non vérifiée — cette release ne publie pas d'empreinte pour son archive";
    }

    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException(
            "L'archive téléchargée ne correspond pas à l'empreinte publiée par GitHub. "
            . 'Installation interrompue.'
        );
    }

    return 'vérifiée (SHA-256)';
}

function bootstrap_url_is_https(string $url): bool
{
    $scheme = parse_url($url, PHP_URL_SCHEME);

    return is_string($scheme) && strtolower($scheme) === 'https';
}

/**
 * @param list<string> $headers
 */
function bootstrap_status_from_headers(array $headers): int
{
    // Une chaîne de redirections empile les lignes de statut : la dernière est
    // celle de la réponse qu'on tient réellement.
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}

/**
 * @param list<string> $headers
 */
function bootstrap_header_value(array $headers, string $name): ?string
{
    $needle = strtolower($name) . ':';
    $value = null;
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), $needle)) {
            $value = trim(substr($header, strlen($needle)));
        }
    }

    return $value === '' ? null : $value;
}

/**
 * Résout une destination de redirection contre l'adresse qui l'a produite.
 *
 * Une destination absolue est prise telle quelle — et repassera par le
 * contrôle de schéma. Une destination relative hérite de l'origine courante,
 * donc de son HTTPS : une redirection relative ne peut pas faire sortir du
 * chiffrement, et une redirection absolue vers `http://` est refusée au tour
 * suivant.
 */
function bootstrap_resolve_redirect(string $current, string $location): string
{
    if (parse_url($location, PHP_URL_SCHEME) !== null) {
        return $location;
    }

    $parts = parse_url($current);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return $location;
    }

    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }

    $path = (string) ($parts['path'] ?? '/');

    return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
}

function bootstrap_default_https_probe(): bool
{
    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 8,
            'header' => 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    return @file_get_contents('https://api.github.com/', false, $context) !== false;
}

// =============================================================================
// Point d'entrée HTTP — la seule partie qui touche aux superglobales et à la
// sortie.
// =============================================================================

/**
 * Retire les chemins absolus du disque et la comptabilité interne avant qu'un
 * état ne parte vers le navigateur. Le détail complet reste dans
 * `.bootstrap-state.php`, que l'opérateur peut lire par FTP.
 *
 * `token` n'est pas dans cette liste : il ne sort que par `wizard_url`, une
 * fois le portail réussi.
 *
 * @param array<string, mixed> $state
 *
 * @return array<string, mixed>
 */
function bootstrap_public_state(array $state): array
{
    $publicKeys = [
        'label', 'percent', 'version', 'done', 'done_gate', 'gate_passed', 'gate_aborted_at',
        'error', 'failed_step', 's_checks', 'b_checks', 'f_checks', 'probes', 'awaiting_gate_report',
        'token_written', 'token_write_warning', 'token_manual_content', 'wizard_url', 'self_deleted',
        'cleanup_warning', 'disk_check', 'environment', 'gate_report', 'integrity',
    ];

    $public = [];
    foreach ($publicKeys as $key) {
        if (array_key_exists($key, $state)) {
            $public[$key] = $state[$key];
        }
    }

    if (isset($public['probes']) && is_array($public['probes'])) {
        $public['probes'] = array_values(array_map(
            static fn (array $probe): array => [
                'id' => $probe['id'],
                'kind' => $probe['kind'],
                'url' => $probe['url'],
            ],
            array_filter($public['probes'], 'is_array')
        ));
    }

    return $public;
}

/**
 * Un message d'erreur ne doit jamais publier l'arborescence de l'hébergement.
 */
function bootstrap_sanitize_error_for_client(string $message, string $docRoot): string
{
    $sanitised = str_replace($docRoot, '', $message);
    $parent = dirname($docRoot);
    if ($parent !== $docRoot) {
        $sanitised = str_replace($parent, '', $sanitised);
    }

    return $sanitised;
}

/**
 * @return array{checks: list<array{ok: bool, label: string, detail: string}>, ok: bool, environment: array<string, mixed>}
 */
function bootstrap_preview_preflight(string $docRoot): array
{
    $checks = bootstrap_preflight_checks($docRoot);

    return [
        'checks' => $checks,
        'ok' => !in_array(false, array_column($checks, 'ok'), true),
        'environment' => bootstrap_gather_environment_info($_SERVER),
    ];
}

function bootstrap_handle_step_request(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    // Voir `bootstrap_send_json()` : chaque sortie passe par là plutôt que par
    // un `echo json_encode()` nu, pour qu'un avertissement PHP égaré ne
    // corrompe pas la réponse.
    ob_start();

    $input = json_decode((string) file_get_contents('php://input'), true);
    $step = (int) (is_array($input) ? ($input['step'] ?? 0) : 0);
    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;

    if ($step === 1) {
        // Volontairement pas de contrôle « déjà installé » ici :
        // `bootstrap_step_preflight()` fait exactement le même quelques lignes
        // plus bas, mais **dans** le try/catch, où l'échec déclenche
        // l'annulation. Un court-circuit ici renverrait le même message sans
        // jamais annuler, et laisserait les fichiers d'une tentative
        // abandonnée sur le disque, sans issue.
        if (!bootstrap_acquire_lock($lockFile)) {
            bootstrap_send_json(['error' => "Une installation est déjà en cours, ou une tentative précédente n'a pas "
                . 'été nettoyée. Réessayez dans dix minutes, ou supprimez tout de suite le fichier '
                . BOOTSTRAP_LOCK_FILE . ' par FTP à la racine du site.']);

            return;
        }
    } elseif (!is_file($lockFile)) {
        bootstrap_send_json(['error' => 'Aucune installation en cours. Rechargez la page.']);

        return;
    }

    $state = bootstrap_read_state($stateFile);

    try {
        switch ($step) {
            case 1:
                $state = bootstrap_step_preflight($docRoot, $state);
                break;
            case 2:
                $state = bootstrap_step_resolve($docRoot, $state);
                break;
            case 3:
                $state = bootstrap_step_download($docRoot, $state);
                break;
            case 4:
                $state = bootstrap_step_extract($docRoot, $state);
                break;
            case 5:
                $state = bootstrap_step_verify_artifact($docRoot, $state);
                break;
            case 6:
                $state = bootstrap_step_install($docRoot, $state);
                break;
            case 7:
                $state = bootstrap_step_storage($docRoot, $state);
                break;
            case 8:
                $state = bootstrap_step_finalize($docRoot, $state);
                break;
            case 9:
                $state = bootstrap_step_gate_prepare($docRoot, $state);
                break;
            case 10:
                $state = bootstrap_step_token($docRoot, $state);
                break;
            case 11:
                $state = bootstrap_step_cleanup($docRoot, $state);
                bootstrap_release_lock($lockFile);
                bootstrap_send_json(bootstrap_public_state($state));

                return;
            default:
                bootstrap_send_json(['error' => 'Étape inconnue.']);

                return;
        }

        if (($state['done_gate'] ?? false) === true && ($state['gate_passed'] ?? false) === false) {
            bootstrap_release_lock($lockFile);
        }

        bootstrap_write_state($stateFile, $state);
        bootstrap_send_json(bootstrap_public_state($state));
    } catch (Throwable $throwable) {
        $state['error'] = bootstrap_sanitize_error_for_client($throwable->getMessage(), $docRoot);
        $state['failed_step'] = $step;

        if ($throwable instanceof BootstrapPartialInstall) {
            // L'étape 6 s'est interrompue en pleine copie. Ce qu'elle avait
            // déjà écrit n'est jamais passé par sa valeur de retour : c'est
            // ici, et seulement ici, qu'il entre dans l'état — donc dans le
            // périmètre de l'annulation et du bouton « Annuler ».
            $state['install_target'] = $docRoot;
            $state['installed_entries'] = array_values(array_unique(array_merge(
                array_map('strval', (array) ($state['installed_entries'] ?? [])),
                $throwable->copied
            )));
        }

        if (!empty($state['install_target']) && !empty($state['installed_entries'])) {
            // Quelque chose a réellement été copié : on annule, quel que soit
            // le numéro d'étape de la requête **courante**. Une reprise peut
            // échouer à l'étape 1 sur « déjà installé » à cause de fichiers
            // laissés par l'étape 6 d'une requête antérieure dont le
            // navigateur n'a jamais vu la réponse ; ces fichiers-là méritent
            // exactement la même annulation.
            bootstrap_rollback_install($docRoot, $state);
        } elseif (!empty($state['temp_dir'])) {
            bootstrap_remove_directory((string) $state['temp_dir']);
        }

        bootstrap_write_state_quietly($stateFile, $state);
        bootstrap_release_lock($lockFile);
        bootstrap_send_json(['done' => true, 'error' => $state['error'], 'step' => $step]);
    }
}

/**
 * Le navigateur ne peut jamais conclure qu'une étape a échoué côté serveur du
 * seul fait qu'il n'a pas su lire la réponse : l'étape a très bien pu aboutir
 * et laisser de vrais fichiers sur le disque, verrou tenu. Sans issue
 * explicite, l'installation serait définitivement bloquée — le contrôle
 * « déjà installé » refuserait chaque reprise, et il n'y aurait aucun recours
 * sans FTP. Cette action rejoue l'annulation qu'un échec capturé aurait faite,
 * à partir du dernier état durablement écrit.
 */
function bootstrap_handle_abort_request(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $state = bootstrap_read_state($stateFile);
        bootstrap_rollback_install($docRoot, $state);
        @unlink($stateFile);
        bootstrap_release_lock($docRoot . '/' . BOOTSTRAP_LOCK_FILE);

        bootstrap_send_json([
            'ok' => true,
            'message' => 'Installation abandonnée : les fichiers déjà copiés ont été retirés. '
                . 'Rechargez la page pour recommencer.',
        ]);
    } catch (Throwable $throwable) {
        // Même le chemin de secours doit répondre quelque chose de lisible
        // plutôt qu'une erreur fatale brute : le message du client explique
        // alors comment finir le nettoyage par FTP.
        bootstrap_send_json([
            'ok' => false,
            'message' => bootstrap_sanitize_error_for_client($throwable->getMessage(), $docRoot),
        ]);
    }
}

function bootstrap_handle_gate_report(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;
    $input = json_decode((string) file_get_contents('php://input'), true);
    $results = is_array($input) && is_array($input['results'] ?? null) ? array_values($input['results']) : [];

    $state = bootstrap_read_state($stateFile);
    if (empty($state['awaiting_gate_report'])) {
        bootstrap_send_json(['error' => 'Aucun contrôle en attente.']);

        return;
    }

    try {
        $state = bootstrap_evaluate_gate_report($docRoot, $state, $results);

        if (($state['gate_passed'] ?? false) === false) {
            bootstrap_release_lock($lockFile);
        }

        bootstrap_write_state($stateFile, $state);
        bootstrap_send_json(bootstrap_public_state($state));
    } catch (Throwable $throwable) {
        $state['error'] = bootstrap_sanitize_error_for_client($throwable->getMessage(), $docRoot);
        bootstrap_rollback_install($docRoot, $state);
        bootstrap_write_state_quietly($stateFile, $state);
        bootstrap_release_lock($lockFile);
        bootstrap_send_json(['done' => true, 'error' => $state['error']]);
    }
}

// =============================================================================
// Origine des requêtes POST
// =============================================================================

/**
 * Ramène une origine à ce qui l'identifie : son hôte et son port.
 *
 * Le schéma est délibérément écarté de la comparaison. Cet installeur tourne
 * une fois, sur un hébergement dont personne ici ne sait rien, souvent
 * derrière un terminateur TLS qui ne renseigne ni `HTTPS` ni
 * `X-Forwarded-Proto`. Exiger l'égalité des schémas ferait refuser des
 * requêtes parfaitement légitimes, et l'opérateur n'aurait aucun moyen de
 * comprendre pourquoi. Ce qu'une falsification de requête inter-site ne peut
 * pas contourner, c'est l'hôte : un attaquant ne sert pas de contenu depuis le
 * nom de domaine de sa victime. C'est donc l'hôte qui décide.
 *
 * Les deux ports par défaut du web, 80 et 443, sont retirés — les deux, et
 * pas seulement celui du schéma courant, par cohérence avec la décision
 * ci-dessus. Un navigateur écrit `https://exemple.fr` là où `HTTP_HOST` peut
 * porter `exemple.fr:443` : c'est le même endroit, et le schéma qu'on
 * n'utilise pas pour comparer les hôtes ne doit pas revenir départager les
 * ports. Tout autre port reste distinctif : `exemple.fr:8080` n'est pas
 * `exemple.fr`.
 */
function bootstrap_normalise_origin_host(string $host, ?int $port): string
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }

    if ($port === null || $port === 80 || $port === 443) {
        return $host;
    }

    return $host . ':' . $port;
}

/**
 * L'hôte que le navigateur dit avoir contacté, lu dans `Origin` puis, à
 * défaut, dans `Referer`.
 *
 * `null` signifie « la requête ne dit pas d'où elle vient ». Les navigateurs
 * envoient `Origin` sur toute requête POST, y compris de même origine ; une
 * requête POST muette n'est donc pas un cas normal, et elle est refusée plus
 * bas plutôt qu'acceptée par défaut.
 *
 * @param array<string, mixed> $server
 */
function bootstrap_request_origin_host(array $server): ?string
{
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = trim((string) ($server[$header] ?? ''));
        if ($value === '' || $value === 'null') {
            continue;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['host'])) {
            continue;
        }

        return bootstrap_normalise_origin_host(
            (string) $parts['host'],
            isset($parts['port']) ? (int) $parts['port'] : null
        );
    }

    return null;
}

/**
 * L'hôte que cette installation croit être, lu dans `Host`.
 *
 * @param array<string, mixed> $server
 */
function bootstrap_expected_origin_host(array $server): string
{
    $host = trim((string) ($server['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $parts = parse_url('//' . $host);
    if (!is_array($parts) || !isset($parts['host'])) {
        return '';
    }

    return bootstrap_normalise_origin_host(
        (string) $parts['host'],
        isset($parts['port']) ? (int) $parts['port'] : null
    );
}

/**
 * Les trois actions POST changent le disque : `step` copie, `gate-report`
 * décide de l'annulation, `abort` supprime. Aucune n'est protégée par le jeton
 * d'installation — il n'existe pas encore quand les premières partent, et il
 * ne serait de toute façon pas un jeton anti-CSRF : il voyage dans une URL.
 *
 * Sans ce contrôle, un site tiers visité par l'opérateur pendant
 * l'installation peut soumettre `POST bootstrap.php?action=abort` et faire
 * effacer les fichiers déjà copiés, l'état et le verrou.
 *
 * @param array<string, mixed> $server
 */
function bootstrap_request_is_same_origin(array $server): bool
{
    $expected = bootstrap_expected_origin_host($server);
    if ($expected === '') {
        return false;
    }

    return bootstrap_request_origin_host($server) === $expected;
}


/**
 * Jette tout ce qui a déjà été mis en tampon. Un avertissement PHP imprimé
 * avant le JSON attendu — un `mkdir()` non supprimé, un hébergement avec
 * `display_errors` actif — atterrirait dans le même corps de réponse et
 * ferait échouer `response.json()` côté client avec un message
 * incompréhensible.
 *
 * @param array<string, mixed> $payload
 */
function bootstrap_send_json(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload);
}

function bootstrap_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bootstrap_render_error_page(string $message): void
{
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>SecondStay — Installation</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;'
        . 'line-height:1.5}.alert{background:#fdecea;color:#611a15;border:1px solid #f5c2c0;'
        . 'border-radius:.5rem;padding:1rem}</style>'
        . '</head><body><h1>SecondStay — Installation</h1><div class="alert">'
        . bootstrap_html_escape($message)
        . '</div></body></html>';
}

function bootstrap_render_ui(string $docRoot): void
{
    header('Content-Type: text/html; charset=utf-8');

    $preview = bootstrap_preview_preflight($docRoot);
    $stateFileNameJs = (string) json_encode(BOOTSTRAP_STATE_FILE);
    $lockFileNameJs = (string) json_encode(BOOTSTRAP_LOCK_FILE);

    $checksHtml = '';
    foreach ($preview['checks'] as $check) {
        $checksHtml .= '<div class="report-row ' . ($check['ok'] ? 'report-ok' : 'report-fail') . '">'
            . ($check['ok'] ? '✓ ' : '✗ ')
            . bootstrap_html_escape($check['label'])
            . ' — '
            . bootstrap_html_escape($check['detail'])
            . '</div>';
    }

    $target = bootstrap_html_escape($docRoot);
    $installDisabled = $preview['ok'] ? '' : 'disabled';

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SecondStay — Installation</title>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, sans-serif; max-width: 640px; margin: 0 auto;
         padding: 1.25rem; line-height: 1.5; }
  h1 { font-size: 1.4rem; }
  h3 { font-size: 1.05rem; margin-top: 0; }
  .option-box { border: 1px solid #ccc; border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .paths code { word-break: break-all; }
  button { min-height: 44px; min-width: 44px; font-size: 1rem; padding: .6rem 1.2rem;
           border-radius: .5rem; border: none; background: #0d6efd; color: #fff; cursor: pointer; }
  button:disabled { background: #999; cursor: not-allowed; }
  .report-row { padding: .4rem .2rem; border-bottom: 1px solid #eee; }
  .report-ok { color: #146c2e; }
  .report-fail { color: #b02a37; font-weight: 600; }
  .alert { border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .alert-ok { background: #e6f4ea; color: #146c2e; }
  .alert-warning { background: #fff4e5; color: #7a4100; }
  .alert-error { background: #fdecea; color: #611a15; }
  #progress-bar-outer { background: #eee; border-radius: .5rem; height: 1rem; overflow: hidden; margin: 1rem 0; }
  #progress-bar { background: #0d6efd; height: 100%; width: 0; transition: width .3s; }
  #progress-log { font-size: .85rem; max-height: 220px; overflow-y: auto; background: #f8f9fa;
                  border-radius: .5rem; padding: .75rem; }
  #progress-log p { margin: .2rem 0; }
  .log-error { color: #b02a37; font-weight: 600; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<h1>SecondStay — Installation</h1>

<section id="screen-confirm">
  <div class="report-table">{$checksHtml}</div>
  <div class="option-box">
    <h3>Ce qui va être installé</h3>
    <p class="paths">Dossier d'installation : <code>{$target}</code></p>
    <p>La dernière version publiée est téléchargée depuis GitHub et installée en une seule
       arborescence. Les règles de protection du serveur sont celles que livre l'archive :
       cet installeur n'en écrit aucune.</p>
    <p>Avant qu'un jeton ne soit écrit, une série de contrôles vérifie depuis votre navigateur
       que <code>src/</code>, <code>config/</code>, <code>vendor/</code> et <code>storage/</code>
       ne sont pas lisibles depuis le web. Si l'un d'eux échoue, l'installation est annulée
       et les fichiers copiés sont retirés.</p>
  </div>
  <button id="install-btn" {$installDisabled}>Installer</button>
</section>

<section id="screen-progress" hidden>
  <div id="progress-bar-outer"><div id="progress-bar"></div></div>
  <p id="progress-label">Démarrage…</p>
  <div id="progress-log"></div>
</section>

<section id="screen-report" hidden>
  <h2>Contrôles</h2>
  <div id="report-summary" class="alert"></div>
  <div id="report-table"></div>
</section>

<script>
(function () {
  var STATE_FILE_NAME = {$stateFileNameJs};
  var LOCK_FILE_NAME = {$lockFileNameJs};
  var TOTAL_STEPS = 11;
  var screens = {
    confirm: document.getElementById('screen-confirm'),
    progress: document.getElementById('screen-progress'),
    report: document.getElementById('screen-report')
  };
  var progressBar = document.getElementById('progress-bar');
  var progressLabel = document.getElementById('progress-label');
  var progressLog = document.getElementById('progress-log');
  var installBtn = document.getElementById('install-btn');
  var wizardUrl = '/fr/install';

  var STEP_LABELS = {
    1: 'Préflight', 2: 'Résolution', 3: 'Téléchargement', 4: 'Extraction',
    5: "Vérification de l'artefact", 6: 'Installation', 7: 'Stockage',
    8: 'Finalisation', 9: 'Contrôles', 10: 'Jeton', 11: 'Nettoyage'
  };

  function showScreen(name) {
    Object.keys(screens).forEach(function (key) { screens[key].hidden = (key !== name); });
  }

  function logLine(text, isError) {
    var line = document.createElement('p');
    line.textContent = text;
    if (isError) { line.className = 'log-error'; }
    progressLog.appendChild(line);
    progressLog.scrollTop = progressLog.scrollHeight;
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (response) { return response.json(); });
  }

  function runProbe(probe) {
    return fetch(probe.url, { cache: 'no-store' }).then(function (response) {
      return response.text().then(function (body) {
        return { id: probe.id, status: response.status, body: body };
      });
    }).catch(function () {
      return { id: probe.id, status: 0, body: '' };
    });
  }

  function setProgress(step, percentWithinStep) {
    var overall = ((step - 1) + (percentWithinStep / 100)) / TOTAL_STEPS * 100;
    progressBar.style.width = overall + '%';
    progressLabel.textContent = 'Étape ' + step + '/' + TOTAL_STEPS + ' — ' + STEP_LABELS[step];
  }

  function runStep(step) {
    setProgress(step, 0);
    postJson('?action=step', { step: step }).then(function (data) {
      if (data.error && !data.done) {
        logLine('Erreur : ' + data.error, true);
        return;
      }
      if (data.done && data.error) {
        logLine("Échec à l'étape " + step + ' : ' + data.error, true);
        showReport(data, false);
        return;
      }
      setProgress(step, 100);
      logLine((STEP_LABELS[step] || ('Étape ' + step)) + ' — terminé.');

      // L'intégrité de l'archive est dite une fois, à l'étape qui la
      // télécharge, et elle est dite même quand elle n'a pas pu être
      // vérifiée : « non vérifiée » et « vérifiée » ne sont pas la même
      // réponse, et l'opérateur a le droit de savoir laquelle il a.
      if (step === 3 && data.integrity) { logLine('Archive : ' + data.integrity + '.'); }

      if (data.wizard_url) { wizardUrl = data.wizard_url; }
      if (step === 9) { handleGate(data); return; }
      if (step === TOTAL_STEPS) { showReport(data, true); return; }
      runStep(step + 1);
    }).catch(function (error) {
      logLine('Erreur réseau : ' + error, true);
      showAbortRecovery();
    });
  }

  function handleGate(data) {
    if (data.done_gate) {
      showReport(data, false);
      return;
    }
    logLine('Vérification des protections depuis le navigateur…');
    var probes = data.probes || [];
    Promise.all(probes.map(runProbe)).then(function (results) {
      // Ces sondes demandent délibérément des chemins qui doivent être
      // refusés. Le navigateur journalise chacun de ces refus dans sa
      // console, en dehors de tout contrôle de ce code : c'est attendu,
      // et c'est même la preuve recherchée.
      logLine("Contrôles envoyés. Les erreurs affichées par la console du navigateur "
        + "à cet instant sont normales : ce sont les refus que l'on cherchait.");
      return postJson('?action=gate-report', { results: results });
    }).then(function (gateData) {
      if (gateData.gate_passed) {
        logLine('Contrôles réussis.');
        runStep(10);
      } else {
        logLine("Échec des contrôles — annulation de l'installation.", true);
        showReport(gateData, false);
      }
    }).catch(function (error) {
      logLine('Erreur réseau : ' + error, true);
      showAbortRecovery();
    });
  }

  function attachAbortButton(summary) {
    var abortBtn = document.createElement('button');
    abortBtn.textContent = "Annuler l'installation et nettoyer";
    abortBtn.addEventListener('click', function () {
      abortBtn.disabled = true;
      postJson('?action=abort', {}).then(function (data) {
        if (data.ok === false) {
          summary.textContent = (data.message || 'Le nettoyage automatique a échoué.')
            + ' Supprimez par FTP les fichiers déjà copiés, ainsi que '
            + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME + ', puis rechargez cette page.';
          summary.className = 'alert alert-error';
          abortBtn.disabled = false;
          return;
        }
        summary.textContent = (data.message || 'Nettoyage effectué.') + ' Rechargement…';
        summary.className = 'alert alert-ok';
        setTimeout(function () { window.location.reload(); }, 2500);
      }).catch(function () {
        summary.textContent = 'Le nettoyage automatique a également échoué. Supprimez par FTP les '
          + 'fichiers déjà copiés, ainsi que ' + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME
          + ', puis rechargez cette page.';
        summary.className = 'alert alert-error';
        abortBtn.disabled = false;
      });
    });
    summary.insertAdjacentElement('afterend', abortBtn);
  }

  function showAbortRecovery() {
    showScreen('report');
    document.getElementById('report-table').innerHTML = '';
    var summary = document.getElementById('report-summary');
    summary.textContent = "La réponse du serveur n'a pas pu être interprétée (problème réseau, ou "
      + "réponse inattendue). Des fichiers ont peut-être déjà été copiés. Annulez proprement cette "
      + "tentative avant de réessayer.";
    summary.className = 'alert alert-error';
    attachAbortButton(summary);
  }

  function showReport(data, passed) {
    showScreen('report');
    var container = document.getElementById('report-table');
    container.innerHTML = '';
    var groups = [
      ['Contrôles serveur', data.s_checks || (data.gate_report && data.gate_report.s_checks) || []],
      ['Contrôles navigateur', data.b_checks || (data.gate_report && data.gate_report.b_checks) || []],
      ['Contrôle fonctionnel', data.f_checks || (data.gate_report && data.gate_report.f_checks) || []]
    ];
    groups.forEach(function (group) {
      var name = group[0], items = group[1];
      if (!items.length) { return; }
      var heading = document.createElement('h3');
      heading.textContent = name;
      container.appendChild(heading);
      items.forEach(function (item) {
        var row = document.createElement('div');
        row.className = 'report-row ' + (item.ok ? 'report-ok' : 'report-fail');
        row.textContent = (item.ok ? '✓ ' : '✗ ') + item.label + ' — ' + item.detail;
        container.appendChild(row);
      });
    });

    var summary = document.getElementById('report-summary');
    var effectivePassed = passed && data.gate_passed !== false;

    if (effectivePassed && data.self_deleted === false) {
      // Jamais de redirection automatique quand bootstrap.php n'a pas su se
      // supprimer : on nomme le fichier à retirer, puis on laisse
      // l'opérateur continuer lui-même.
      summary.textContent = (data.cleanup_warning
          || "Installation terminée, mais bootstrap.php n'a pas pu se supprimer automatiquement.")
        + ' Une fois supprimé, continuez ci-dessous.';
      summary.className = 'alert alert-warning';
      if (data.token_write_warning) { logLine(data.token_write_warning, true); }
      var continueBtn = document.createElement('button');
      continueBtn.textContent = "Continuer vers l'assistant d'installation";
      continueBtn.addEventListener('click', function () { window.location.href = wizardUrl; });
      summary.insertAdjacentElement('afterend', continueBtn);
    } else if (effectivePassed) {
      summary.textContent = "Installation terminée. Redirection vers l'assistant dans quelques "
        + 'secondes — le lien porte le jeton, ne le partagez pas.';
      summary.className = 'alert alert-ok';
      if (data.token_write_warning) { logLine(data.token_write_warning, true); }
      setTimeout(function () { window.location.href = wizardUrl; }, 5000);
    } else {
      // Pour un échec d'étape simple (1 à 8, ou 10), il n'y a aucune ligne de
      // contrôle : `data.error` est le seul endroit où la raison existe, et la
      // ligne de journal qui la portait vient d'être masquée par le passage à
      // cet écran.
      var reason = data.error ? (' Raison : ' + data.error) : '';
      summary.textContent = "L'installation a été annulée et les fichiers déjà copiés ont été "
        + 'retirés.' + reason + ' Corrigez le point signalé, puis rechargez cette page.';
      summary.className = 'alert alert-error';
    }
  }

  if (installBtn) { installBtn.addEventListener('click', function () { showScreen('progress'); runStep(1); }); }
})();
</script>
</body>
</html>
HTML;
}

function bootstrap_main(): void
{
    $docRoot = __DIR__;
    $stateFile = $docRoot . '/' . BOOTSTRAP_STATE_FILE;

    if (bootstrap_already_installed($docRoot) && !is_file($stateFile)) {
        bootstrap_render_error_page(
            'Ce dossier contient déjà une installation SecondStay (fichier VERSION, dossier src/ ou '
            . 'config/local.php présent). Utilisez Administration > Mises à jour pour mettre à jour, ou '
            . 'retirez les fichiers existants avant de relancer bootstrap.php.'
        );

        return;
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Les trois actions ci-dessous écrivent ou effacent. Une requête POST qui
    // ne vient pas de cette page n'a rien à y faire.
    if ($method === 'POST' && in_array($action, ['step', 'gate-report', 'abort'], true)
        && !bootstrap_request_is_same_origin($_SERVER)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => "Requête refusée : elle ne provient pas de cette page. Rechargez bootstrap.php "
                . 'et relancez depuis l’onglet ouvert sur ce site.',
        ], JSON_UNESCAPED_UNICODE);

        return;
    }

    if ($method === 'POST' && $action === 'step') {
        bootstrap_handle_step_request($docRoot, $stateFile);

        return;
    }

    if ($method === 'POST' && $action === 'gate-report') {
        bootstrap_handle_gate_report($docRoot, $stateFile);

        return;
    }

    if ($method === 'POST' && $action === 'abort') {
        bootstrap_handle_abort_request($docRoot, $stateFile);

        return;
    }

    bootstrap_render_ui($docRoot);
}

if (!defined('BOOTSTRAP_TEST')) {
    bootstrap_main();
}
