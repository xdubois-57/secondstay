<?php

declare(strict_types=1);

/**
 * Récupère l'**analyse SonarCloud complète** de ce projet pour le pack de
 * preuves.
 *
 *   SONAR_TOKEN=... php scripts/sonar-evidence.php <répertoire-de-sortie>
 *
 * La Quality Gate seule dit « passé » ou « échoué ». Elle ne dit pas ce que
 * l'analyse a trouvé, quelle part du code elle a couverte, ni où se trouve la
 * dette — et un pack de preuves dont le lecteur doit ouvrir un compte
 * SonarCloud pour l'apprendre n'est pas une preuve. On récupère donc tout :
 *
 *   sonarcloud-quality-gate.json      le verdict, condition par condition
 *   sonarcloud-measures.json          toutes les mesures du projet
 *   sonarcloud-measures-by-file.json  les mêmes, par fichier, pour que la
 *                                     dette ait un endroit
 *   sonarcloud-issues.json            tous les constats ouverts, toutes pages
 *   sonarcloud-hotspots.json          les security hotspots — autre endpoint
 *   sonarcloud-report.md              la page de garde, pour un humain
 *
 * Les hotspots sont récupérés délibérément. Ils vivent derrière leur propre
 * endpoint : un pack construit sur `issues/search` seul a l'air complet et
 * omet en silence la catégorie qu'un relecteur sécurité ouvre en premier.
 *
 * **Sans jeton, le script écrit un marqueur INDISPONIBLE et sort en 0.** Un
 * fichier manquant se lit comme un oubli ; un fichier qui dit « pas
 * disponible, et voici pourquoi » se lit comme un fait — et une pull request
 * issue d'un fork ne peut pas lire les secrets du dépôt.
 *
 * La clé de projet et l'organisation sont lues dans
 * `sonar-project.properties`, jamais codées en dur : deux endroits où écrire
 * la même chose finissent par ne plus dire la même.
 */

const SONAR_API_BASE = 'https://sonarcloud.io/api/';
const SONAR_PAGE_SIZE = 500;

/** Toutes les mesures qui valent d'être enregistrées, et non la poignée dont on se souvient. */
const SONAR_METRICS = [
    'alert_status', 'quality_gate_details',
    'bugs', 'reliability_rating', 'reliability_remediation_effort',
    'vulnerabilities', 'security_rating', 'security_remediation_effort',
    'security_hotspots', 'security_hotspots_reviewed', 'security_review_rating',
    'code_smells', 'sqale_rating', 'sqale_index', 'sqale_debt_ratio',
    'coverage', 'line_coverage', 'branch_coverage', 'lines_to_cover',
    'uncovered_lines', 'tests', 'test_failures', 'test_errors', 'skipped_tests',
    'duplicated_lines_density', 'duplicated_blocks', 'duplicated_files',
    'ncloc', 'lines', 'statements', 'functions', 'classes', 'files',
    'comment_lines_density', 'cognitive_complexity', 'complexity',
];

/**
 * Lit une propriété de `sonar-project.properties`.
 *
 * Le fichier est la source unique : le scanner et ce script doivent parler du
 * même projet, faute de quoi le pack décrirait l'analyse d'un autre.
 */
function sonarProperty(string $file, string $key): string
{
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fwrite(STDERR, "sonar-evidence : {$file} est illisible.\n");
        exit(1);
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $equals = strpos($line, '=');
        if ($equals === false) {
            continue;
        }
        if (trim(substr($line, 0, $equals)) === $key) {
            return trim(substr($line, $equals + 1));
        }
    }

    fwrite(STDERR, "sonar-evidence : la propriété « {$key} » est absente de {$file}.\n");
    exit(1);
}

/**
 * Un GET authentifié sur l'API SonarCloud.
 *
 * SonarCloud accepte le jeton comme nom d'utilisateur HTTP basic avec un mot
 * de passe vide : c'est leur schéma documenté pour les jetons.
 *
 * @return array<string, mixed>
 */
function sonarApi(string $path, string $token): array
{
    $handle = curl_init(SONAR_API_BASE . $path);
    if ($handle === false) {
        fwrite(STDERR, "sonar-evidence : initialisation cURL impossible.\n");
        exit(1);
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $token . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FAILONERROR => false,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if (!is_string($body) || $status >= 400) {
        fwrite(STDERR, "sonar-evidence : GET {$path} a échoué (HTTP {$status}) {$error}\n");
        exit(1);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "sonar-evidence : GET {$path} n'a pas rendu de JSON.\n");
        exit(1);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * @param array<string, mixed>|array{total: int, issues: list<mixed>} $data
 */
function sonarWriteJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * Suit la pagination jusqu'au bout.
 *
 * La boucle s'arrête sur une page incomplète et non sur un nombre de pages :
 * elle ne peut donc pas tronquer en silence le jour où ce projet dépassera une
 * page de constats — c'est-à-dire le jour où le rapport compte le plus.
 *
 * @return list<mixed>
 */
function sonarFetchAllPages(string $path, string $key, string $token): array
{
    $all = [];
    $page = 1;

    do {
        $separator = str_contains($path, '?') ? '&' : '?';
        $response = sonarApi($path . $separator . 'ps=' . SONAR_PAGE_SIZE . '&p=' . $page, $token);
        $batch = $response[$key] ?? [];
        if (!is_array($batch)) {
            $batch = [];
        }
        foreach ($batch as $item) {
            $all[] = $item;
        }
        $page++;

        // Le plafond est un garde-fou contre l'emballement, pas une raison de
        // s'arrêter en silence : rendre 20 pages pleines en les présentant
        // comme le tout écrirait un « total » qui a l'air complet. Une preuve
        // tronquée sans le dire est pire qu'une preuve absente.
        // `> 21` et non `> 20` : après une vingtième page pleine, `$page` vaut
        // déjà 21 et la page suivante n'a pas encore été demandée. Refuser là
        // rejetterait un jeu de résultats de très exactement 10 000 constats,
        // qui est complet. On interroge donc une page de plus, et on ne refuse
        // que si elle est pleine à son tour.
        if (count($batch) === SONAR_PAGE_SIZE && $page > 21) {
            fwrite(STDERR, "sonar-evidence : plus de 21 pages sur {$path} ; preuve tronquée, rien n'est écrit.\n");
            exit(1);
        }
    } while (count($batch) === SONAR_PAGE_SIZE);

    return $all;
}

/** SonarCloud rend les notes de 1 à 5 ; personne ne lit « 3.0 ». */
function sonarRating(string $value): string
{
    $letters = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E'];

    return $letters[substr($value, 0, 1)] ?? $value;
}

/**
 * @param array<string, string> $map
 */
function sonarMetric(array $map, string $key, string $fallback = 'n/d'): string
{
    return $map[$key] ?? $fallback;
}

// ── Préparation ─────────────────────────────────────────────────────────────

$root = dirname(__DIR__);
$outDir = $argv[1] ?? 'evidence';
if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "sonar-evidence : impossible de créer {$outDir}\n");
    exit(1);
}

$propertiesFile = $root . '/sonar-project.properties';
$projectKey = sonarProperty($propertiesFile, 'sonar.projectKey');
$organization = sonarProperty($propertiesFile, 'sonar.organization');

$token = (string) getenv('SONAR_TOKEN');
if ($token === '') {
    $marker = [
        'status' => 'INDISPONIBLE',
        'raison' => "Aucun SONAR_TOKEN n'était disponible pour cette exécution.",
        'projet' => $projectKey,
        'organisation' => $organization,
    ];
    sonarWriteJson($outDir . '/sonarcloud-quality-gate.json', $marker);
    file_put_contents(
        $outDir . '/sonarcloud-report.md',
        "## SonarCloud\n\n"
        . "**INDISPONIBLE** : aucun `SONAR_TOKEN` n'était disponible pour cette exécution.\n\n"
        . "Ce n'est pas un oubli. Une pull request issue d'un fork ne peut pas lire les\n"
        . "secrets du dépôt : le pack le dit plutôt que de laisser un fichier manquant\n"
        . "se faire passer pour une analyse qui n'a rien trouvé.\n"
    );
    fwrite(STDERR, "sonar-evidence : SONAR_TOKEN absent ; marqueur INDISPONIBLE écrit.\n");
    exit(0);
}

// ── Récupération ────────────────────────────────────────────────────────────

$gate = sonarApi('qualitygates/project_status?projectKey=' . $projectKey, $token);
sonarWriteJson($outDir . '/sonarcloud-quality-gate.json', $gate);

$measures = sonarApi(
    'measures/component?component=' . $projectKey . '&metricKeys=' . implode(',', SONAR_METRICS),
    $token
);
sonarWriteJson($outDir . '/sonarcloud-measures.json', $measures);

// Paginé, comme les constats et les points chauds. Une seule page laisserait
// tomber tout ce qui dépasse, en silence : les arbres analysés passent déjà
// les 500 fichiers, et le pack présenterait alors un relevé par fichier
// incomplet comme s'il était complet. Une preuve tronquée sans le dire est
// pire qu'une preuve absente.
$byFile = sonarFetchAllPages(
    'measures/component_tree?component=' . $projectKey
    . '&qualifiers=FIL&metricKeys='
    . 'ncloc,coverage,bugs,vulnerabilities,code_smells,duplicated_lines_density,cognitive_complexity',
    'components',
    $token
);
sonarWriteJson(
    $outDir . '/sonarcloud-measures-by-file.json',
    ['total' => count($byFile), 'components' => $byFile]
);

$issues = sonarFetchAllPages(
    'issues/search?componentKeys=' . $projectKey . '&statuses=OPEN,CONFIRMED,REOPENED',
    'issues',
    $token
);
sonarWriteJson($outDir . '/sonarcloud-issues.json', ['total' => count($issues), 'issues' => $issues]);

$hotspots = sonarFetchAllPages('hotspots/search?projectKey=' . $projectKey, 'hotspots', $token);
sonarWriteJson($outDir . '/sonarcloud-hotspots.json', ['total' => count($hotspots), 'hotspots' => $hotspots]);

// ── Page de garde ───────────────────────────────────────────────────────────

/** @var array<string, string> $measureMap */
$measureMap = [];
$component = $measures['component'] ?? [];
$componentMeasures = is_array($component) ? ($component['measures'] ?? []) : [];
if (is_array($componentMeasures)) {
    foreach ($componentMeasures as $measure) {
        if (!is_array($measure) || !isset($measure['metric']) || !is_string($measure['metric'])) {
            continue;
        }
        $value = $measure['value'] ?? '';
        $measureMap[$measure['metric']] = is_scalar($value) ? (string) $value : '';
    }
}

$projectStatus = $gate['projectStatus'] ?? [];
$status = 'INCONNU';
$conditions = [];
if (is_array($projectStatus)) {
    $rawStatus = $projectStatus['status'] ?? 'INCONNU';
    $status = is_string($rawStatus) ? $rawStatus : 'INCONNU';
    $rawConditions = $projectStatus['conditions'] ?? [];
    $conditions = is_array($rawConditions) ? $rawConditions : [];
}

$lines = [];
$lines[] = '## SonarCloud — analyse complète';
$lines[] = '';
$lines[] = 'Projet : `' . $projectKey . '` (organisation `' . $organization . '`)';
$lines[] = '';
$lines[] = 'Quality Gate : **' . $status . '**';
$lines[] = '';

$failed = [];
foreach ($conditions as $condition) {
    if (is_array($condition) && ($condition['status'] ?? '') !== 'OK') {
        $failed[] = $condition;
    }
}

if ($failed !== []) {
    $lines[] = 'Conditions en échec :';
    $lines[] = '';
    foreach ($failed as $condition) {
        $metricKey = $condition['metricKey'] ?? '?';
        $actual = $condition['actualValue'] ?? '?';
        $threshold = $condition['errorThreshold'] ?? '?';
        $lines[] = '- `' . (is_scalar($metricKey) ? (string) $metricKey : '?') . '` = '
            . (is_scalar($actual) ? (string) $actual : '?')
            . ' (seuil ' . (is_scalar($threshold) ? (string) $threshold : '?') . ')';
    }
    $lines[] = '';
}

$m = static fn (string $key): string => sonarMetric($measureMap, $key);

$reliability = sonarRating($m('reliability_rating')) . ' — ' . $m('bugs') . ' bug(s)';
$security = sonarRating($m('security_rating')) . ' — ' . $m('vulnerabilities') . ' vulnérabilité(s)';
$review = sonarRating($m('security_review_rating')) . ' — ' . $m('security_hotspots')
    . ' hotspot(s), ' . $m('security_hotspots_reviewed') . ' % examinés';
$maintain = sonarRating($m('sqale_rating')) . ' — ' . $m('code_smells') . ' code smell(s), '
    . $m('sqale_index') . ' min de dette';
$coverageText = $m('coverage') . ' % (' . $m('uncovered_lines') . ' lignes non couvertes sur '
    . $m('lines_to_cover') . ')';
$duplication = $m('duplicated_lines_density') . ' % sur ' . $m('duplicated_blocks') . ' bloc(s)';
$size = $m('ncloc') . ' lignes de code, ' . $m('files') . ' fichier(s)';
$complexity = $m('cognitive_complexity') . ' cognitive, ' . $m('complexity') . ' cyclomatique';

$lines[] = '| Mesure | Valeur |';
$lines[] = '|---|---|';
foreach ([
    'Fiabilité' => $reliability,
    'Sécurité' => $security,
    'Revue de sécurité' => $review,
    'Maintenabilité' => $maintain,
    'Couverture' => $coverageText,
    'Duplication' => $duplication,
    'Taille' => $size,
    'Complexité' => $complexity,
] as $label => $value) {
    $lines[] = '| ' . $label . ' | ' . $value . ' |';
}

$lines[] = '';
$lines[] = '### Constats ouverts (' . count($issues) . ')';
$lines[] = '';

if ($issues === []) {
    $lines[] = 'Aucun.';
} else {
    /** @var array<string, int> $bySeverity */
    $bySeverity = [];
    /** @var array<string, int> $byRule */
    $byRule = [];
    foreach ($issues as $issue) {
        if (!is_array($issue)) {
            continue;
        }
        $severityRaw = $issue['severity'] ?? 'INCONNU';
        $severity = is_string($severityRaw) ? $severityRaw : 'INCONNU';
        $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;

        $ruleRaw = $issue['rule'] ?? '?';
        $typeRaw = $issue['type'] ?? '?';
        $ruleKey = (is_string($ruleRaw) ? $ruleRaw : '?')
            . '|' . (is_string($typeRaw) ? $typeRaw : '?')
            . '|' . $severity;
        $byRule[$ruleKey] = ($byRule[$ruleKey] ?? 0) + 1;
    }

    $order = ['BLOCKER' => 0, 'CRITICAL' => 1, 'MAJOR' => 2, 'MINOR' => 3, 'INFO' => 4];
    uksort($bySeverity, static fn (string $a, string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

    $lines[] = '| Sévérité | Nombre |';
    $lines[] = '|---|---|';
    foreach ($bySeverity as $severity => $count) {
        $lines[] = '| ' . $severity . ' | ' . $count . ' |';
    }
    $lines[] = '';

    arsort($byRule);
    $lines[] = '| Règle | Type | Sévérité | Nombre |';
    $lines[] = '|---|---|---|---|';
    foreach ($byRule as $key => $count) {
        [$rule, $type, $severity] = explode('|', $key);
        $lines[] = '| `' . $rule . '` | ' . $type . ' | ' . $severity . ' | ' . $count . ' |';
    }
}

$lines[] = '';
$lines[] = '### Security hotspots (' . count($hotspots) . ')';
$lines[] = '';
if ($hotspots === []) {
    $lines[] = 'Aucun.';
} else {
    /** @var array<string, int> $byStatus */
    $byStatus = [];
    foreach ($hotspots as $hotspot) {
        if (!is_array($hotspot)) {
            continue;
        }
        $probability = $hotspot['vulnerabilityProbability'] ?? '?';
        $hotspotStatus = $hotspot['status'] ?? '?';
        $key = (is_scalar($probability) ? (string) $probability : '?')
            . ' / ' . (is_scalar($hotspotStatus) ? (string) $hotspotStatus : '?');
        $byStatus[$key] = ($byStatus[$key] ?? 0) + 1;
    }
    $lines[] = '| Probabilité / état | Nombre |';
    $lines[] = '|---|---|';
    foreach ($byStatus as $key => $count) {
        $lines[] = '| ' . $key . ' | ' . $count . ' |';
    }
}

$lines[] = '';
$lines[] = "L'enregistrement lisible par une machine se trouve à côté de ce fichier : "
    . '`sonarcloud-quality-gate.json`, `sonarcloud-measures.json`, '
    . '`sonarcloud-measures-by-file.json`, `sonarcloud-issues.json` et '
    . '`sonarcloud-hotspots.json`.';
$lines[] = '';

file_put_contents($outDir . '/sonarcloud-report.md', implode("\n", $lines));

fwrite(
    STDERR,
    'sonar-evidence : ' . count($issues) . ' constat(s) et '
    . count($hotspots) . " hotspot(s) écrits dans {$outDir}\n"
);
