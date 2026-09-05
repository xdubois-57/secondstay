#!/usr/bin/env node
//
// La gate d'analyse statique du JavaScript — `npm run typecheck`.
//
// Elle joue `tsc --noEmit` sur le JavaScript de `public/assets/js/` (voir
// `tsconfig.json` : un vérificateur, jamais une étape de construction) et
// compare ce qui revient à `js-typecheck-baseline.json`, pour n'échouer que
// sur un constat **nouveau**.
//
// POURQUOI UNE BASELINE
// ---------------------------------------------------------------------------
// PHPStan en embarque une ; tsc non. Le JavaScript de ce dépôt a été écrit
// sans types et s'appuie partout sur `querySelector()`, qui rend `Element` et
// non le sous-type que le code appelant suppose — ni `value`, ni `dataset`,
// ni `focus()` n'existent sur `Element`.
//
// **Il n'y a aujourd'hui aucun fichier de baseline.** Les onze constats de la
// mise en place ont été payés (voir `public/assets/js/modules/dom.js`) plutôt
// que gelés, et ce script rapporte donc « aucun constat ». La mécanique
// ci-dessous est conservée parce que l'alternative à une baseline n'est pas
// « pas de baseline » : c'est quelqu'un qui éteint le garde-fou le jour où une
// montée de dépendance produit cinquante constats un vendredi soir. Elle
// servira ce jour-là, et sera vidée ensuite.
//
// Sans baseline le travail serait rouge en permanence et tout le monde
// apprendrait à l'ignorer, ce qui est strictement pire que pas d'analyse du
// tout : une gate que personne ne lit coûte une minute à chaque poussée et
// n'achète rien.
//
// CLÉ (FICHIER, CODE, MESSAGE), JAMAIS LA LIGNE
// ---------------------------------------------------------------------------
// C'est ce qui décide si l'outil est utilisable. Une baseline qui retiendrait
// les numéros de ligne signalerait un « nouveau constat » pour chacun des
// constats existants situés sous une modification, puisque insérer cinq
// lignes en haut d'`app.js` les déplace tous. Grouper par fichier + code +
// message fait qu'une modification sans rapport ailleurs dans le même fichier
// ne change rien.
//
// IDENTITÉ DES OCCURRENCES : LE TEXTE DE LA LIGNE, PAS LEUR NOMBRE
// ---------------------------------------------------------------------------
// Grouper ne suffit pas : compter les occurrences acceptées laisserait passer
// un échange. Une baseline qui accepte un constat dans `app.js`, une
// modification qui corrige cette occurrence-là et en introduit une autre du
// même code et du même message ailleurs dans le même fichier — le total reste
// à un, et la gate ne dirait rien du constat qu'on vient d'écrire.
//
// Chaque occupation retient donc le **texte de sa ligne source**, normalisé.
// Déplacer une ligne ne change pas son texte, donc l'insensibilité au numéro
// de ligne est conservée ; remplacer le code fait apparaître un texte qui
// n'est dans aucune entrée acceptée, et le constat est rapporté comme neuf.
// Réécrire une ligne déjà couverte la fait ressortir aussi : c'est le
// comportement voulu, puisqu'on l'a touchée.
//
// RÉGÉNÉRATION
// ---------------------------------------------------------------------------
//   node scripts/js-typecheck.mjs --generate-baseline
//
// Uniquement pour **accepter sciemment une dette existante** qu'on ne corrige
// pas maintenant. JAMAIS pour faire taire un constat que sa propre
// modification vient d'introduire — celui-là se corrige. Une baseline
// régénérée pour cacher une régression transforme la gate en décoration, tout
// en laissant chacun persuadé qu'elle fonctionne.

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { occurrenceIdentity } from './lib/occurrence-identity.mjs';

const repoRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const tscBin = path.join(repoRoot, 'node_modules', '.bin', process.platform === 'win32' ? 'tsc.cmd' : 'tsc');
const tsconfigPath = path.join(repoRoot, 'tsconfig.json');
const baselinePath = path.join(repoRoot, 'js-typecheck-baseline.json');

const generateBaseline = process.argv.includes('--generate-baseline');

// JSON n'a pas de syntaxe de commentaire : le fichier porte donc son
// avertissement en lignes `//`, que ce script retire avant de l'analyser.
// Cet avertissement est la raison d'être du fichier ; le mettre ailleurs
// reviendrait à ce que personne ne le lise en l'ouvrant.
const BASELINE_HEADER = [
    '// Constats d\'analyse statique du JavaScript préexistants, acceptés à la date de',
    '// la génération de ce fichier. Voir scripts/js-typecheck.mjs.',
    '//',
    '// À régénérer UNIQUEMENT pour accepter sciemment une dette existante que l\'on ne',
    '// corrige pas maintenant. JAMAIS pour faire taire un constat que sa propre',
    '// modification vient d\'introduire : celui-là se corrige.',
    '//',
    '// Clé : fichier + code + message, jamais le numéro de ligne — une modification',
    '// ailleurs dans un fichier ne doit pas faire passer pour neufs les constats',
    '// situés en dessous. Chaque occurrence acceptée est retenue par le texte de sa',
    '// ligne, précédé des deux lignes de code qui la précèdent : déplacer le bloc ne',
    '// change rien, le remplacer fait réapparaître le constat, et deux occurrences',
    '// au texte identique dans le même fichier ne se confondent plus — corriger',
    '// l\'une et en introduire une autre ailleurs ne se compense donc pas.',
    '',
].join('\n');

if (!existsSync(tscBin)) {
    console.error(`ERREUR : ${tscBin} est introuvable — lancez d'abord « npm ci » (voir README.md).`);
    process.exit(1);
}

const result = spawnSync(tscBin, ['-p', tsconfigPath, '--noEmit'], { cwd: repoRoot, encoding: 'utf8' });

if (result.error) {
    console.error(`ERREUR : impossible de lancer tsc : ${result.error.message}`);
    process.exit(1);
}

const stdout = result.stdout || '';
const stderr = result.stderr || '';

// tsc écrit un diagnostic par ligne, « fichier(ligne,colonne): error TSxxxx: message ».
// Ses propres erreurs fatales — un tsconfig illisible, un motif qui ne
// correspond à aucun fichier — partent sur stderr et ne produisent aucun
// diagnostic analysable.
const DIAGNOSTIC = /^(.+?)\((\d+),(\d+)\): error (TS\d+): (.+)$/;

const diagnostics = [];
for (const line of stdout.split('\n')) {
    const match = DIAGNOSTIC.exec(line.trim());
    if (match) {
        diagnostics.push({
            // Normalisé en barres obliques : une baseline générée sur une
            // plateforme doit encore correspondre sur une autre.
            file: match[1].split(path.sep).join('/'),
            line: Number(match[2]),
            column: Number(match[3]),
            code: match[4],
            message: match[5],
        });
    }
}

// Une sortie non nulle sans rien d'analysable signifie que le contrôle n'a pas
// eu lieu. C'est un échec dur, jamais un silencieux « 0 constat » : un
// tsconfig cassé se lirait sinon exactement comme un code propre.
if (diagnostics.length === 0 && result.status !== 0) {
    console.error(`ERREUR : tsc est sorti en ${result.status} sans diagnostic analysable — le contrôle n'a pas eu lieu :`);
    console.error(stdout.trim() || '(pas de sortie standard)');
    if (stderr.trim() !== '') console.error(stderr.trim());
    process.exit(1);
}

const keyOf = (d) => `${d.file} ${d.code} ${d.message}`;

/** @type {Map<string, string[]>} */
const sourceCache = new Map();

/**
 * Identité d'une occurrence, déléguée à `scripts/lib/occurrence-identity.mjs`.
 *
 * La règle vit dans un module à part parce que ce fichier-ci s'exécute
 * entièrement au chargement : rien de ce qu'il contient ne serait testable, et
 * cette règle décide seule si un constat neuf est vu ou confondu avec un
 * constat déjà accepté. `tests/js/occurrence-identity.test.js` la couvre.
 *
 * Un fichier devenu illisible — supprimé entre l'analyse et ici — rend un
 * tableau vide, donc une identité vide, qui ne correspondra à aucune entrée :
 * dans le doute, la gate parle.
 */
function identityAt(file, line) {
    if (!sourceCache.has(file)) {
        const absolute = path.isAbsolute(file) ? file : path.join(repoRoot, file);
        try {
            sourceCache.set(file, readFileSync(absolute, 'utf8').split('\n'));
        } catch {
            sourceCache.set(file, []);
        }
    }

    return occurrenceIdentity(sourceCache.get(file) ?? [], line);
}

/** @type {Map<string, {file: string, code: string, message: string, occurrences: {line: number, column: number, text: string}[]}>} */
const current = new Map();
for (const d of diagnostics) {
    const key = keyOf(d);
    let group = current.get(key);
    if (!group) {
        group = { file: d.file, code: d.code, message: d.message, occurrences: [] };
        current.set(key, group);
    }
    group.occurrences.push({ line: d.line, column: d.column, text: identityAt(d.file, d.line) });
}

if (generateBaseline) {
    /** @type {Record<string, {code: string, message: string, lines: string[]}[]>} */
    const baseline = {};
    for (const group of current.values()) {
        baseline[group.file] ??= [];
        baseline[group.file].push({
            code: group.code,
            message: group.message,
            lines: group.occurrences.map((at) => at.text).sort(),
        });
    }
    // Trié, pour qu'une régénération produise une différence lisible par un
    // relecteur plutôt qu'un brassage du fichier entier.
    for (const file of Object.keys(baseline)) {
        baseline[file].sort((a, b) => a.code.localeCompare(b.code) || a.message.localeCompare(b.message));
    }
    const sorted = Object.fromEntries(Object.entries(baseline).sort(([a], [b]) => a.localeCompare(b)));

    writeFileSync(baselinePath, `${BASELINE_HEADER}${JSON.stringify(sorted, null, 4)}\n`);
    console.log(
        `Baseline écrite dans ${path.relative(repoRoot, baselinePath)} : `
        + `${diagnostics.length} constat(s) sur ${current.size} groupe(s) (fichier, code, message) distincts.`
    );
    process.exit(0);
}

function loadBaseline() {
    if (!existsSync(baselinePath)) return {};
    return JSON.parse(readFileSync(baselinePath, 'utf8').replace(/^(\/\/.*\n)+/, ''));
}

const baseline = loadBaseline();

// Chaque occurrence courante consomme au plus une ligne acceptée portant le
// même texte. Ce qui reste sans correspondance est neuf — y compris quand le
// total n'a pas bougé parce qu'une occurrence a été corrigée et une autre
// écrite ailleurs dans le même fichier.
const newFindings = [];
for (const group of current.values()) {
    const accepted = (baseline[group.file] || [])
        .find((entry) => entry.code === group.code && entry.message === group.message);
    const unclaimed = [...(accepted?.lines ?? [])];

    const introduced = group.occurrences.filter((at) => {
        const index = unclaimed.indexOf(at.text);
        if (index === -1) return true;
        unclaimed.splice(index, 1);
        return false;
    });

    if (introduced.length > 0) {
        newFindings.push({ ...group, introduced });
    }
}

// Constats qui ne se reproduisent plus. Rapportés, jamais bloquants : une
// entrée de baseline périmée, c'est quelqu'un qui a corrigé quelque chose, et
// faire échouer la construction pour cela apprendrait à ne plus le faire.
let staleCount = 0;
for (const [file, entries] of Object.entries(baseline)) {
    for (const entry of entries) {
        const present = (current.get(`${file} ${entry.code} ${entry.message}`)?.occurrences ?? [])
            .map((at) => at.text);
        for (const text of entry.lines ?? []) {
            const index = present.indexOf(text);
            if (index === -1) staleCount += 1;
            else present.splice(index, 1);
        }
    }
}

if (newFindings.length > 0) {
    console.error('Nouveaux constats d\'analyse statique du JavaScript, absents de js-typecheck-baseline.json :\n');
    for (const finding of newFindings) {
        console.error(
            `${finding.file} — ${finding.code} : ${finding.message} `
            + `(${finding.introduced.length} nouveau(x), ${finding.occurrences.length} au total cette fois)`
        );
        // Seules les occurrences sans correspondance sont listées : les autres
        // sont acceptées, et les mêler ferait chercher au relecteur laquelle
        // est la sienne.
        for (const at of finding.introduced) {
            console.error(`    ${finding.file}:${at.line}:${at.column}`);
        }
    }
    console.error(
        `\n${newFindings.length} groupe(s) introduisent de nouvelles occurrences. Corrigez-les. `
        + 'S\'il s\'agit réellement d\'une dette préexistante que vous ne touchez pas, acceptez-la sciemment avec '
        + '« node scripts/js-typecheck.mjs --generate-baseline » — jamais pour cacher un constat introduit par votre modification.'
    );
    process.exit(1);
}

if (diagnostics.length === 0) {
    console.log('Analyse statique du JavaScript : aucun constat.');
} else {
    console.log(
        `Analyse statique du JavaScript : ${diagnostics.length} constat(s) préexistant(s) `
        + 'dans la baseline acceptée (js-typecheck-baseline.json), 0 nouveau.'
    );
}
if (staleCount > 0) {
    console.log(
        `Note : ${staleCount} occurrence(s) de la baseline ne se reproduisent plus. `
        + 'La baseline pourrait être régénérée pour la réduire ; cette note ne fait pas échouer la construction.'
    );
}
