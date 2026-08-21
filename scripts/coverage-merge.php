<?php

declare(strict_types=1);

/**
 * Fusionne les couvertures écrites requête par requête pendant la campagne
 * E2E en un seul rapport Clover (TESTING.md §14).
 *
 * Chaque fichier d'entrée porte, par fichier source, les lignes réellement
 * exécutées. La fusion est donc une somme d'entiers : aucune désérialisation
 * d'objet lourd, ce qui la rend praticable sur les quelques milliers de
 * requêtes d'une campagne complète.
 *
 * Le rapport est écrit directement, sans passer par la bibliothèque de
 * couverture : celle-ci exige un pilote actif — xdebug ou pcov — pour
 * seulement construire un objet, alors qu'à cette étape il n'y a plus rien à
 * mesurer, uniquement des entiers à mettre en forme. Faire dépendre la mise en
 * forme d'un pilote rendrait la fusion impossible à rejouer sur un rapport
 * déjà collecté.
 *
 * Les lignes jamais atteintes n'apparaissent pas dans le rapport. C'est exact :
 * SonarQube établit lui-même la liste des lignes à couvrir à partir du code, et
 * compte comme non couvert ce dont aucun rapport ne parle.
 *
 * Usage :
 *   php scripts/coverage-merge.php <répertoire> <clover.xml>
 */

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

/** @var array<string, array<int, int>> $lines fichier => ligne => nombre de passages */
$lines = [];
$read = 0;

foreach ($files as $file) {
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        continue;
    }

    $raw = @gzdecode($raw);
    if ($raw === false) {
        continue;
    }

    /** @var mixed $payload */
    $payload = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($payload)) {
        continue;
    }

    foreach ($payload as $source => $covered) {
        if (!is_string($source) || !is_array($covered)) {
            continue;
        }

        foreach ($covered as $line) {
            $line = (int) $line;
            $lines[$source][$line] = ($lines[$source][$line] ?? 0) + 1;
        }
    }

    $read++;
}

if ($lines === []) {
    fwrite(STDERR, "Aucune couverture lisible dans {$directory}.\n");
    exit(1);
}

ksort($lines);

$parent = dirname($target);
if (!is_dir($parent) && !mkdir($parent, 0o750, true) && !is_dir($parent)) {
    fwrite(STDERR, "Impossible de créer {$parent}.\n");
    exit(1);
}

$totalStatements = 0;
foreach ($lines as $fileLines) {
    $totalStatements += count($fileLines);
}

$writer = new XMLWriter();
if ($writer->openUri($target) === false) {
    fwrite(STDERR, "Impossible d’écrire {$target}.\n");
    exit(1);
}

$writer->setIndent(true);
$writer->setIndentString('  ');
$writer->startDocument('1.0', 'UTF-8');

$writer->startElement('coverage');
// L'horodatage est celui de la fusion : il n'entre dans aucune comparaison.
$writer->writeAttribute('generated', (string) time());

$writer->startElement('project');
$writer->writeAttribute('timestamp', (string) time());

foreach ($lines as $source => $fileLines) {
    ksort($fileLines);

    $writer->startElement('file');
    $writer->writeAttribute('name', $source);

    foreach ($fileLines as $line => $count) {
        $writer->startElement('line');
        $writer->writeAttribute('num', (string) $line);
        $writer->writeAttribute('type', 'stmt');
        $writer->writeAttribute('count', (string) $count);
        $writer->endElement();
    }

    $writer->startElement('metrics');
    $writer->writeAttribute('statements', (string) count($fileLines));
    $writer->writeAttribute('coveredstatements', (string) count($fileLines));
    $writer->endElement();

    $writer->endElement();
}

$writer->startElement('metrics');
$writer->writeAttribute('files', (string) count($lines));
$writer->writeAttribute('statements', (string) $totalStatements);
$writer->writeAttribute('coveredstatements', (string) $totalStatements);
$writer->endElement();

$writer->endElement();
$writer->endElement();
$writer->endDocument();
$writer->flush();

printf(
    "Couverture E2E fusionnée : %d requêtes, %d fichiers, %d lignes couvertes → %s\n",
    $read,
    count($lines),
    $totalStatements,
    $target
);
