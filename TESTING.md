# TESTING.md

# Stratégie de tests et Definition of Done

## 1. Objectif

Chaque itération doit être testable complètement et indépendamment.

Les mêmes commandes doivent être utilisables :

- sur le Mac dans Claude Code ;
- dans GitHub Actions ;
- indirectement depuis iPhone en déclenchant les workflows GitHub.

## 2. Commande canonique

```bash
./scripts/check.sh
```

Cette commande doit produire un résultat clair et non ambigu.

## 3. Sous-commandes utiles

Possibles :

```bash
./scripts/check.sh --fast
./scripts/check.sh --php
./scripts/check.sh --js
./scripts/check.sh --db
./scripts/check.sh --e2e
./scripts/check.sh --full
```

La variante complète est l’autorité locale avant release.

Chaque contrôle est aussi appelable seul, ce qui est commode pour chercher une
régression précise. Ce sont des **alias** de ce que `check.sh` fait déjà ; ils
ne remplacent pas la commande canonique :

```bash
composer run analyse           # PHPStan niveau 8
composer run analyse:baseline  # régénère une baseline — voir §4
composer run test              # PHPUnit, suite unitaire
composer run test:coverage     # PHPUnit avec Clover et JUnit, comme en CI
composer run coverage:merge    # fusionne les couvertures d'une campagne E2E instrumentée
npm run typecheck              # tsc, vérificateur du JavaScript
npm run typecheck:baseline     # régénère la baseline JavaScript — voir §4
```

`test:coverage` exige un pilote de couverture (`pcov` ou Xdebug) et échoue
sans, volontairement : une couverture silencieusement absente se lit comme une
couverture nulle sur le tableau de bord. `check.sh`, lui, se rabat sur une
exécution sans couverture et le dit.

`coverage:merge` suppose qu'une campagne E2E instrumentée a déjà écrit dans
`build/coverage/e2e` ; sans cela il refuse plutôt que de produire un rapport
vide.

## 4. PHP

### Syntaxe

Vérifier tous les fichiers PHP applicatifs.

### PHPStan

Analyse statique de niveau 8.

Aucune erreur acceptée dans `main`.

#### Aucune baseline commitée

**« Vert » signifie *aucun constat*, et non *aucun constat nouveau*.** Ce dépôt
ne porte ni `phpstan-baseline.neon` ni `js-typecheck-baseline.json`.

La mécanique reste pourtant disponible — `composer run analyse:baseline`,
`npm run typecheck:baseline` — et c'est délibéré : l'alternative à une baseline
n'est pas « pas de baseline », c'est quelqu'un qui éteint le garde-fou le jour
où une montée de dépendance produit cinquante constats un vendredi soir. La
régénérer sert à **accepter sciemment une dette existante**, jamais à faire
taire un constat que sa propre modification vient d'introduire — celui-là se
corrige.

Activer une baseline PHPStan demande d'ajouter soi-même la ligne `includes:`
dans `phpstan.neon.dist`. Cette friction est voulue : l'acceptation d'une dette
doit se voir en revue, avec sa raison dans le message de commit, et repartir
dès que la dette est payée.

La règle ne s'applique pas au scan dynamique : là, un constat se corrige ou se
filtre nommément dans le plan ZAP, avec la raison écrite à côté. Une liste de
constats « acceptés » qui s'allonge est exactement la façon dont un rapport de
sécurité cesse de vouloir dire quelque chose.

### PHPUnit

Tests :

- unitaires ;
- services ;
- repositories ;
- sécurité ;
- validation ;
- calculs tarifaires ;
- taxe séjour ;
- i18n ;
- backup/restore helpers ;
- update logic.

Produire :

- JUnit ;
- Clover coverage.

## 5. Base de données

Tests d’intégration avec MySQL/MariaDB de test.

Couvrir :

- migrations ;
- repositories ;
- contraintes ;
- transactions ;
- anti-double-booking ;
- idempotence.

GitHub Actions fournit un service MySQL.

Localement, la commande peut utiliser Docker ou une DB test explicitement configurée, mais ne doit jamais toucher la DB de production.

## 6. JavaScript

Vitest pour :

- validation UI ;
- interactions calendrier ;
- formatage ;
- composants first-party ;
- helpers PWA ;
- comportement formulaires.

Produire LCOV.

### 6.1 `tsc`, vérificateur du JavaScript

```bash
npm run typecheck
```

C'est la moitié manquante de l'analyse statique : les défauts du JavaScript
navigateur qu'aucune des trois campagnes ne voit, **parce qu'ils vivent dans
du code qu'elles n'exécutent pas**. Un gestionnaire d'événement qu'aucun test
ne déclenche est du code que rien ne lit ; identifiants inconnus, mauvais
nombre d'arguments, propriétés absentes de l'objet touché s'y installent sans
que rien ne devienne rouge.

**TypeScript est ici un vérificateur, jamais une étape de construction.**
`noEmit` : rien n'est compilé, rien n'est empaqueté, la production continue de
servir le JavaScript non transformé de `public/assets/js/` (AGENTS.md §2).
Aucun fichier `.ts` n'existe dans ce dépôt et il ne doit pas en apparaître ;
`allowJs` + `checkJs` pointent l'outil sur le JavaScript déjà écrit.

`strict: false` volontairement : ce code a été écrit sans types, et activer la
rigueur enterrerait les constats qui méritent une correction sous des
centaines d'autres sur des annotations absentes. `noImplicitReturns` et
`noFallthroughCasesInSwitch` sont conservés parce qu'ils attrapent de vraies
fautes.

Les onze constats de la mise en place ont été **payés**, pas gelés :
`public/assets/js/modules/dom.js` porte les accesseurs typés
(`queryElement`, `asInput`, `asFormField`, `documentOf`) qui disent une fois,
à un seul endroit, ce que le code sait déjà du DOM qu'il manipule. Une
assertion dispersée sur chaque appel se serait corrigée en quinze endroits.

`scripts/js-typecheck.mjs` porte la mécanique de baseline. **Aucun fichier de
baseline n'est commité** : « vert » veut dire *aucun constat*, et non *aucun
constat nouveau*. La mécanique reste disponible (`npm run typecheck:baseline`)
parce que l'alternative à une baseline n'est pas « pas de baseline » : c'est
quelqu'un qui éteint le garde-fou le jour où une montée de dépendance produit
cinquante constats un vendredi soir. Elle sert à accepter sciemment une dette
existante, jamais à faire taire un constat que sa propre modification vient
d'introduire.

## 7. Playwright E2E

### Principes

- vraie app PHP ;
- vraie DB test ;
- fake providers ;
- données reproductibles ;
- viewports desktop + mobile ;
- traces/screenshots sur échec.

### Scénarios critiques permanents

1. fresh install ;
2. login admin ;
3. protection chemins sensibles ;
4. signup/confirmation/login client ;
5. rôles ;
6. calendrier/pricing ;
7. réservation ;
8. double booking concurrent ;
9. paiement fake + webhook ;
10. SMTP fake + push fake ;
11. IMAP mail + attachment → Documents ;
12. PWA/offline ;
13. guest link ;
14. check-in/check-out mobile ;
15. backup/restore ;
16. update/migration ;
17. conformité/versioning légal ;
18. i18n FR/EN/NL/DE.

### 7.1 Campagne en HTTPS

```bash
SECONDSTAY_E2E_TLS=1 SECONDSTAY_BASE_URL=https://localhost:8443 \
SECONDSTAY_PORT=8443 SECONDSTAY_BACKEND_PORT=8444 \
    npx playwright test --project=desktop-chromium
```

`npm run e2e` en clair ne change pas : sans `SECONDSTAY_E2E_TLS=1`, rien de ce
qui suit ne s'active.

Avec, la préparation globale recule le serveur d'application sur un port
interne en `127.0.0.1` et pose `scripts/dast-tls-proxy.php` devant lui, muni
d'un certificat généré pour la campagne. `scripts/dast-https-prepend.php`,
chargé par `auto_prepend_file` **pour ce processus seulement**, traduit
l'en-tête du terminateur en `$_SERVER['HTTPS']` : l'application n'apprend rien
et ne sait pas que la campagne existe.

Deux détails qui ne se négocient pas :

- le certificat est émis pour **`localhost`**, jamais pour une adresse IP —
  une IP n'est pas une *relying party* WebAuthn valide, et les parcours de
  clés d'accès seraient refusés par le navigateur ;
- `ignoreHTTPSErrors` est **conditionné** à cette campagne. Une campagne
  ordinaire qui ignorerait les erreurs de certificat cesserait de pouvoir en
  signaler une vraie.

La préparation **prouve** ensuite que l'instance se croit en HTTPS — en-tête
HSTS et cookie de session `Secure` — et refuse de continuer sinon. Sans cette
preuve, une campagne entière irait redécouvrir un défaut du harnais pour le
rapporter comme un défaut du produit.

`SECONDSTAY_TIMEOUT_FACTOR` multiplie tous les délais Playwright. Les scénarios
font le même travail et portent les mêmes assertions : seule la patience
change, parce que chaque requête traverse désormais une poignée de main TLS.

## 8. Fake providers

Obligatoires :

- `FakePaymentProvider`
- `FakeMailTransport`
- `FakeImapProvider`
- `FakePushProvider`
- `FakeLlmProvider`

Ils doivent permettre un scénario complet sans réseau externe.

## 9. Tests i18n

### Static/check

- aucune clé manquante ;
- placeholders cohérents ;
- catalogues FR/EN/NL/DE valides ;
- aucun texte système critique en dur détectable dans les zones concernées si le projet met en place un lint.

« Aucune clé manquante » recouvre **deux** contrôles distincts, et le second
est celui qu'on oublie :

1. **parité entre catalogues** — aucune clé FR sans équivalent EN/NL/DE ;
2. **existence des clés citées** — toute clé écrite littéralement dans `src/`
   ou `templates/` existe réellement dans le catalogue.

Le premier ne peut pas détecter une clé inventée : elle manque dans les quatre
langues à la fois, donc symétriquement, et le traducteur rend le dernier
segment lisible plutôt que d'échouer. Le défaut est alors invisible en revue
comme à l'écran.

### E2E

Matrice de locale pour les parcours principaux.

La release doit au minimum démontrer :

- navigation FR ;
- navigation EN ;
- navigation NL ;
- navigation DE ;
- réservation localisée ;
- e-mail localisé ;
- contrat localisé/fallback maîtrisé.

## 10. Accessibilité

Automatiser autant que possible :

- axe ou outil équivalent via Playwright ;
- labels ;
- focus ;
- navigation clavier ;
- erreurs formulaire ;
- contrastes lorsque mesurable.

Objectif WCAG 2.2 AA.

### Mise en œuvre

`rendering.spec.js` exécute `@axe-core/playwright` sur les trois familles de
pages — contenu, formulaire, administration — avec les étiquettes `wcag2a`,
`wcag2aa`, `wcag21a`, `wcag21aa` et `wcag22aa`, **dans les deux thèmes**. Le
thème sombre n'est pas une variante décorative : c'est le thème par défaut
d'une bonne partie des téléphones, les couleurs y sont différentes, donc les
contrastes aussi, et une correction faite en clair peut n'y rien corriger.

Le contrôle est **strict** : aucune violation tolérée, pas de liste
d'exceptions. Une liste d'exceptions s'allonge toujours, et la première
exécution de cette analyse a précisément trouvé un défaut réel et généralisé —
les variantes « outline » de Bootstrap n'atteignaient pas le seuil de contraste.

Un outil automatique ne prouve pas l'accessibilité : il ne voit ni l'ordre de
lecture, ni la pertinence d'un texte alternatif. Il attrape en revanche ce qui
se casse en silence à chaque modification de gabarit.

## 11. Sécurité

Tests obligatoires :

- accès direct fichiers privés ;
- authorization/IDOR ;
- CSRF ;
- XSS ;
- HTML e-mail sanitization ;
- uploads ;
- SSRF ;
- share/ICS tokens ;
- webhook replay ;
- session revocation ;
- secrets jamais affichés ;
- release artifact leak.

## 12. CI GitHub

### 12.0 Où vivent les gates

Les gates ne sont pas écrites dans `ci.yml` : elles vivent dans
`.github/workflows/checks.yml`, un workflow **réutilisable**
(`on: workflow_call`) que les pipelines appellent.

`ci.yml` est la boucle de retour **rapide**, jouée à chaque poussée ; la passe
de release, plus lente, appellera le même fichier. Les deux ne sont pas
fusionnés — l'intérêt de la première est d'être rapide — mais ils ne doivent
pas diverger sur ce que « vert » veut dire. Le bloc `setup-php` était déjà
recopié cinq fois : cinq occasions de répondre différemment à la question
« de quelles extensions PHP ce projet a-t-il besoin ». Il est désormais écrit
une fois par travail, avec la même liste partout :
`mbstring, intl, pdo_mysql, zip, gd, dom, sodium`.

`ci.yml` ne contient donc plus que deux travaux : l'appel à `checks.yml` et
`sonarcloud`, qui dépend du premier par `needs`.

### 12.1 Mode preuve

`checks.yml` prend une entrée booléenne `evidence`, à `false` par défaut.

- `false` (CI) : les travaux se comportent exactement comme avant l'extraction
  du fichier. Une exécution de CI produit un verdict, pas un pack de preuves.
- `true` : chaque travail téléverse en plus, sous un artefact `evidence-*`, ce
  que son outil émet **nativement** — JUnit de PHPUnit (un par version de PHP)
  et de Vitest, rapport HTML de Playwright, sortie de PHPStan et de `tsc`,
  rapport JSON de `composer audit`, inventaire et empreinte du ZIP.

Rien n'y écrit un résumé rédigé à la main : une preuve rédigée n'est pas
maintenue, et une preuve non maintenue finit par mentir.

Pour PHPStan, la sortie seule ne prouve rien : une exécution propre imprime
`[OK] No errors`, et une configuration qui n'analyse **aucun** fichier
l'imprime tout aussi volontiers. Le mode preuve écrit donc à côté le périmètre
— version de l'outil, version de PHP, niveau, chemins analysés, nombre de
fichiers effectivement traités — obtenu par une seconde passe `--debug`, que
seule une passe de preuve peut se permettre.

### 12.2 Les travaux de `checks.yml`

#### `php`

Matrice `['8.2', '8.4']`.

- syntaxe ;
- composer install ;
- PHPStan ;
- PHPUnit ;
- reports.

**8.2 et non 8.1** : c'est le plancher que `composer.json` déclare
(`"php": ">=8.2"`). La matrice existe pour que le plancher annoncé reste vrai :
une montée de dépendance qui remonterait le minimum réel sans que personne ne
s'en aperçoive est exactement ce qu'elle attrape. Un échec en 8.2 est une
information, pas un obstacle à contourner — on corrige, ou on remonte le
plancher déclaré, jamais les deux en silence.

La couverture Clover et le JUnit consommés par SonarCloud ne sont produits que
par la version de référence (8.4) : l'analyse n'en consomme qu'un jeu, et deux
artefacts de même nom se refusent mutuellement.

#### `static-analysis`

- `npm run typecheck`.

La moitié PHP de l'analyse statique — PHPStan — n'est pas ici mais dans le
travail `php`, où elle est jouée sur les **deux** versions supportées ; un
travail unique n'en verrait qu'une.

#### `database`

- MySQL service ;
- migrations ;
- DB tests.

#### `javascript`

- npm ci ;
- Vitest coverage.

#### `e2e`

- setup PHP/Node ;
- DB ;
- fake providers ;
- Playwright, **un exécuteur par navigateur** (`SECONDSTAY_E2E_PROJECT`) ;
- collecte de la couverture PHP des contrôleurs ;
- upload report/traces.

Séparer les deux projets divise la durée par deux et **améliore** l'isolement :
chacun installe la sienne, au lieu de partager une installation unique.

Le rapport Playwright et les traces sont téléversés `if: always()`, mode preuve
ou non : c'est la première chose que l'on regarde quand la campagne passe au
rouge.

#### `dast`

- `npm run dast` : scan dynamique passif d'une instance jetable, servie en
  HTTPS, pilotée par la campagne Playwright à travers OWASP ZAP ;
- `timeout-minutes: 40`, service MySQL, extensions `openssl` et `pcntl` en
  plus, récupération explicite de l'image ZAP.

**La campagne est la surface d'attaque, pas l'araignée de ZAP.** Un crawler
pointé sur SecondStay verrait la page d'accueil et s'arrêterait ; la campagne
traverse l'installation, l'administration derrière sa session, une réservation
complète, les paiements factices, l'espace client, le mode séjour et les états
des lieux.

Conséquence assumée : **une campagne en échec fait échouer le scan**, même
sans le moindre constat de sécurité. Un scan ne vaut que le trafic qu'on lui a
donné.

Seul `desktop-chromium` est rejoué (plus sa dépendance `install`) : WebKit
derrière un proxy et un certificat auto-signé apporte de la fragilité sans
surface supplémentaire — le même serveur répond aux deux. Les délais sont
multipliés par quatre, parce que chaque requête traverse désormais une poignée
de main TLS **et** un proxy.

Seuil d'échec : **Medium et au-dessus**. Pas de `security-events: write` : le
*code scanning* rattache un résultat à un chemin du dépôt, alors qu'un constat
DAST décrit une instance en cours d'exécution sur un port choisi à l'exécution.
Le code de sortie est le garde-fou.

#### `security`

- composer audit ;
- éventuellement checks secrets/config.

#### `release-artifact`

- construction et inspection du ZIP de production ;
- démarrage réel de l'archive : `/api/version` répond, et une installation
  neuve conduit à l'assistant d'installation dans la langue demandée.

### 12.3 `sonarcloud`

Dernier travail du **même** workflow — `ci.yml` — déclenché par `needs` une
fois les gates terminées. Il ne rejoue rien : il récupère les couvertures déjà
produites, lance le scan, puis la Quality Gate bloquante.

L'analyse vivait auparavant dans un workflow séparé qui rejouait la campagne
E2E pour son propre compte — vingt minutes de calcul pour un résultat déjà
obtenu. Le retour complet est passé d'environ quarante minutes à moins de
quinze, sans qu'aucun test ne disparaisse.

La couverture PHP vient des **trois** campagnes :

| Rapport | Produit par | Ce qu'il couvre |
|---|---|---|
| `clover-unit.xml` | `php` | logique pure, formats, règles |
| `clover-database.xml` | `database` | dépôts et services, sur une vraie base |
| `clover-e2e-desktop.xml`, `clover-e2e-mobile.xml` | `e2e` (un par navigateur) | contrôleurs, traversés par Playwright |

N'en compter qu'une faisait apparaître comme non couvert du code qui l'est
réellement : la mesure décrivait l'outillage, pas le produit.

La couverture E2E est collectée par `scripts/coverage-bootstrap.php`, chargé
par le routeur du serveur de développement **et seulement** si
`SECONDSTAY_COVERAGE_DIR` est défini — comme les fournisseurs factices, la
collecte s'active par variable d'environnement et n'est jamais sélectionnable
depuis l'interface. Le serveur intégré tourne avec plusieurs processus : chaque
requête écrit donc son propre fichier, qu'un fichier partagé aurait corrompu.
`scripts/coverage-merge.php` les fusionne ensuite en un seul Clover. Ces deux
scripts vivent sous `scripts/`, exclu de l'archive de release : ils ne partent
jamais en production.

Ce qui est écrit est réduit au strict nécessaire : les seules lignes
réellement exécutées, fichier par fichier. Écrire l'objet de couverture entier
— filtre, caches d'analyse statique, lignes exécutables des trois cents
fichiers du filtre — coûtait cinquante fois plus d'octets à chaque requête, et
c'est ce qui rendait la campagne instrumentée trois fois plus lente que la
campagne nue.

```bash
SECONDSTAY_COVERAGE_DIR="$PWD/build/coverage/e2e" ./scripts/check.sh --e2e
php scripts/coverage-merge.php build/coverage/e2e build/coverage/clover-e2e.xml
```

`SECONDSTAY_E2E_PROJECT` restreint la campagne à un navigateur ; la CI s'en
sert pour jouer les deux projets en parallèle.

La détection de copier-coller exclut `translations/**` et `migrations/**`
(`sonar.cpd.exclusions`). I18N.md exige que les quatre langues portent
exactement les mêmes clés, et `TranslationCatalogueTest` fait échouer la
construction sinon : mesurer la duplication de ces fichiers reviendrait à
mesurer une exigence, et la seule façon d'y répondre serait de casser la règle
qu'ils servent. L'exclusion porte sur la **portée de la mesure**, jamais sur la
règle, qui reste appliquée au code PHP, au JavaScript et aux gabarits.

### 12.4 Preuves SonarCloud

`php scripts/sonar-evidence.php <répertoire>` récupère l'**analyse complète** :
la Quality Gate condition par condition, toutes les mesures du projet, les
mêmes par fichier, tous les constats ouverts et tous les *security hotspots* —
plus une page de garde en Markdown.

La Quality Gate seule dit « passé » ou « échoué ». Elle ne dit pas ce qui a été
trouvé, ni quelle part du code a été couverte, et un pack de preuves dont le
lecteur doit ouvrir un compte SonarCloud pour l'apprendre n'est pas une preuve.

Les hotspots sont récupérés **délibérément** : ils vivent derrière leur propre
endpoint, et un pack construit sur `issues/search` seul a l'air complet tout en
omettant en silence la catégorie qu'un relecteur sécurité ouvre en premier.

**Sans `SONAR_TOKEN`, le script écrit un marqueur `INDISPONIBLE` et sort en 0.**
Un fichier manquant se lit comme un oubli ; un fichier qui dit « pas disponible,
et voici pourquoi » se lit comme un fait — et une pull request issue d'un fork
ne peut pas lire les secrets du dépôt.

La clé de projet et l'organisation sont lues dans `sonar-project.properties`,
jamais codées en dur : deux endroits où écrire la même chose finissent par ne
plus dire la même.

### 12.5 `codeql`

CodeQL vit dans son propre workflow (`.github/workflows/codeql.yml`) et reste
propriétaire de l'onglet Sécurité du dépôt.

CodeQL pour JavaScript/TypeScript et GitHub Actions, ou autres langages supportés utilisés par le dépôt.

PHP n’étant pas couvert par CodeQL de la même façon, PHPStan + SonarCloud + tests restent essentiels.

## 13. Dependabot

Activer pour :

- Composer ;
- npm ;
- GitHub Actions.

Les alertes sécurité ouvertes bloquent une release selon `RELEASE.md`.

## 14. SonarCloud

Importer :

- PHP coverage ;
- PHPUnit JUnit ;
- JS LCOV.

Quality Gate obligatoire.

Les Security Hotspots doivent être examinés avant release.

## 15. Mobile

Les E2E critiques utilisent au moins :

- viewport desktop ;
- viewport iPhone moderne ;
- viewport Android raisonnable si utile.

État des lieux et Mon séjour sont toujours testés mobile.

## 16. Definition of Done

Une fonctionnalité est terminée si :

- tests unitaires pertinents ;
- tests intégration pertinents ;
- E2E pertinent ;
- i18n FR/EN/NL/DE ;
- docs à jour ;
- security impact traité ;
- PHPStan/Vitest/Playwright verts ;
- SonarCloud vert ;
- CodeQL applicable vert ;
- pas d’alerte dépendance bloquante.

## 17. Itération indépendante

Chaque itération doit fournir :

- fixtures/données test ;
- fresh install testable ;
- migration depuis N-1 si applicable ;
- scénario E2E propre ;
- ZIP installable ;
- rollback/restore testé lorsque pertinent.

## 18. Mise en œuvre effective

### 18.1 Couverture de code

PHPUnit produit la couverture Clover via Xdebug (`XDEBUG_MODE=coverage`) en
local et via pcov en CI. `./scripts/check.sh --full` détecte l'absence de driver
et exécute alors les tests sans couverture plutôt que d'échouer faussement.

### 18.2 Ce qui coûte cher dans la suite base de données

Cinq cent quatre-vingt-huit tests contre une vraie base : la durée vient
presque entièrement de deux gestes répétés à chaque test, et non des
assertions.

**Rendre la base à l'état initial.** Supprimer une cinquantaine de tables puis
rejouer quatorze migrations coûtait 370 ms par test. Or aucune migration
n'insère de donnée : l'état après migration est entièrement décrit par « toutes
les tables vides, plus le suivi des migrations ». `TRUNCATE` le reproduit
exactement, compteurs d'auto-incrément compris, pour 150 ms. La reconstruction
complète reste faite au premier test, et **refaite dès que le schéma ne
correspond plus** à ce qui est attendu : un test qui crée ou supprime une table
se répare tout seul.

**Écrire de façon synchrone.** En intégration continue, la base est jetable :
un incident d'exécuteur se répare en relançant la CI, pas en relisant un
journal. Les travaux `database` et `e2e` désactivent donc
`innodb_flush_log_at_trx_commit` et `sync_binlog` avant de commencer, faute de
quoi la remise à zéro entre chaque test est dominée par des `fsync`. Le réglage
vit dans le workflow, jamais dans le produit ni dans les tests.

**Hacher un mot de passe.** `password_hash` coûte 225 ms — c'est sa raison
d'être, et le produit doit la payer. Un test qui a seulement besoin d'un compte
utilisable, non : `DatabaseTestCase::passwordHash()` mémorise l'empreinte par
mot de passe. La valeur reste une vraie empreinte, vérifiable par
`password_verify` ; seul le nombre de calculs change, jamais leur force. Les
tests qui portent sur le hachage lui-même appellent `PasswordHasher`
directement.

### 18.3 Base de données de test

`./scripts/check.sh` source `scripts/test-env.local.sh` s'il existe (fichier non
versionné, modèle : `scripts/test-env.local.sh.dist`). Les variables attendues
sont :

```text
SECONDSTAY_TEST_DB_HOST
SECONDSTAY_TEST_DB_PORT
SECONDSTAY_TEST_DB_NAME
SECONDSTAY_TEST_DB_USER
SECONDSTAY_TEST_DB_PASSWORD
```

La suite `database` refuse de s'exécuter sans base de test explicitement
configurée : la base de production ne doit jamais être touchée.

### 18.4 Serveur utilisé par Playwright

Le serveur est démarré par `tests/e2e/global-setup.js` et arrêté par
`tests/e2e/global-teardown.js`, **pas** par l'option `webServer` de Playwright.
Deux raisons :

1. `dev-server.sh` détache le serveur puis rend la main, alors que `webServer`
   attend un processus qui reste au premier plan. En local, l'option
   `reuseExistingServer` masquait le problème ; en intégration continue, où il
   n'y a aucun serveur à réutiliser, la campagne échouait sur « Process from
   config.webServer exited early » ;
2. le serveur doit tourner avec les fournisseurs factices — courriel, push,
   paiement, IMAP, modèle de langage, fixtures HTTP —, et c'est la préparation
   globale qui les connaît. Deux endroits démarrant le même serveur avec deux
   environnements différents finissent toujours par diverger.

Le serveur PHP intégré tourne derrière `scripts/router.php`. Ce routeur
applique la politique de chemins privés de `PublicPathPolicy`, ce qui rend les
tests de sécurité représentatifs du comportement Apache en production.

### 18.5 Attendre que le JavaScript soit câblé

Les modules ES sont différés : entre l'affichage d'un bouton et l'attachement
de son écouteur, il s'écoule le temps de charger `app.js` et ses imports. Un
clic délivré dans cet intervalle est **perdu sans erreur** — le test échoue
alors sur une conséquence, jamais sur la cause.

Tout scénario qui clique sur un contrôle piloté par JavaScript attend donc le
signal que le produit publie lui-même :

```js
await page.waitForSelector('html[data-js-ready="true"]');
```

Ce n'est pas une précaution théorique : la campagne instrumentée pour la
couverture, plus lente, a fait apparaître la course sur les clés d'accès.

### 18.6 Matrice de navigateurs

| Projet Playwright | Moteur | Viewport |
|---|---|---|
| `desktop-chromium` | Chromium | Desktop Chrome |
| `mobile-safari` | WebKit | iPhone 14 |

Les parcours « Mon séjour » et « états des lieux » doivent toujours être
exécutés sur le projet mobile.

### 18.7 Contrôle de l'artefact

`./scripts/check.sh --full` construit et inspecte le ZIP de production à chaque
exécution. La CI ajoute une vérification de démarrage réel de l'artefact
extrait : `/api/version` répond avec la version, et `/de/` conduit à
l'assistant d'installation **en allemand**.

C'est bien l'assistant qui est attendu, et non une page publique : une archive
fraîchement déployée n'est pas installée. Exiger une page d'accueil localisée
revenait à exiger un état que le ZIP ne peut pas avoir — la vérification
échouait donc à chaque exécution. L'assistant prouve à la fois que
l'application démarre et que les catalogues de traduction voyagent dans
l'archive.

## 19. Organisation E2E (itération 1)

### 19.1 Projets Playwright

| Projet | Rôle |
|---|---|
| `install` | joue l'installation neuve dans un navigateur réel et produit `tests/e2e/.auth/admin.json` |
| `desktop-chromium` | scénarios sur viewport desktop, dépend de `install` |
| `mobile-safari` | scénarios sur iPhone 14 (WebKit), dépend de `install` |

`globalSetup` exécute `scripts/e2e-reset.php`, qui vide la base de test,
supprime `config/local.php` et nettoie `storage/`. `globalTeardown` supprime la
configuration locale générée (elle contient une clé de chiffrement).
`SECONDSTAY_KEEP_INSTALL=1` conserve l'installation pour inspection manuelle.

### 19.2 Exécution en série

Une installation SecondStay représente **un seul logement** : réglages,
maintenance et sauvegardes sont un état global partagé. Les scénarios E2E
s'exécutent donc en série (`workers: 1`). Les scénarios doivent rester
rejouables : ils ne supposent jamais l'absence de données créées par un projet
précédent.

### 19.3 Contextes anonymes

`browser.newContext()` hérite du `storageState` déclaré par `test.use`. Pour
simuler un visiteur, utiliser `anonymousContext(browser)`
(`tests/e2e/helpers/fixtures.js`), qui force un état vide.

### 19.4 Suites PHP

| Suite | Contenu |
|---|---|
| `unit` | logique pure + noyau sur une racine temporaire **non installée** |
| `database` | migrations, dépôts, services, et application réellement installée |

`KernelTestCase` et `InstalledAppTestCase` construisent une racine de projet
temporaire (liens symboliques vers `templates/`, `translations/`,
`migrations/`, `public/`, `vendor/`). Les tests ne dépendent donc jamais de
l'état du dépôt de travail, même après une campagne E2E.

## 20. Itération 3 — comptes, e-mails et clés d’accès

### 20.1 Couverture des scénarios critiques

| Scénario critique | Fichier |
|---|---|
| 4 — signup / confirmation / login client | `tests/e2e/account.spec.js` |
| 10 — SMTP fake | `tests/e2e/account.spec.js`, `tests/php/Unit/SmtpMailTransportTest.php` |

`account.spec.js` couvre le cycle complet : inscription, e-mail de
confirmation, refus de connexion avant confirmation, activation, unicité du
lien, non-divulgation d’un compte existant, réinitialisation de bout en bout,
préférence de langue, changement de mot de passe, appareils connectés, export
RGPD, suppression de compte et exigence de jeton CSRF.

`passkeys.spec.js` couvre l’enregistrement et la connexion sans mot de passe
avec l’authentificateur virtuel de Chromium. WebKit ne fournit pas cette API :
le scénario y est explicitement ignoré, et la vérification serveur des
assertions (ES256 et RS256) est couverte en PHP par `WebAuthnServiceTest`, qui
s’appuie sur un authentificateur simulé produisant de véritables structures
CBOR et de véritables signatures.

### 20.2 Boîte e-mail de test

Le transport `fake` est activé par `SECONDSTAY_MAIL_TRANSPORT=fake` ; les
messages sont déposés en JSON dans `storage/temp/mail` et lus par
`/api/dev/mailbox`, qui renvoie 404 dans toute autre configuration. Les
helpers `waitForMail()` et `linkFrom()` (`tests/e2e/helpers/mailbox.js`)
extraient le lien applicatif d’un message. `globalSetup` relance le serveur de
développement avec ce transport et vérifie que la boîte répond : un scénario
de compte qui échoue signale un vrai défaut, jamais une configuration locale
incorrecte.

### 20.3 Client SMTP

`SmtpMailTransportTest` fait dialoguer le client avec un serveur SMTP factice
local (`tests/php/Support/bin/smtp-stub.php`) qui écrit la transcription
reçue : la conversation réellement parlée est vérifiée, y compris le repli
`AUTH PLAIN` → `AUTH LOGIN`, les refus de destinataire et de message, et
l’absence de fuite de détail système dans les erreurs. Aucun réseau sortant,
aucun identifiant réel.

### 20.4 Limitation de débit et déterminisme

Les deux projets Playwright partagent l’installation **et** l’adresse IP : les
compteurs d’inscription sont donc communs. Les scénarios de compte remettent
ces compteurs à zéro via l’action d’administration réelle
(`/admin/diagnostics`, helper `clearRateLimits()`), jamais par une porte
dérobée réservée aux tests.

### 20.5 Domaine de test

Les campagnes E2E s’exécutent sur `http://localhost:8123` et non sur une
adresse IP : une adresse IP n’est pas une « relying party » WebAuthn valide et
les clés d’accès seraient refusées par le navigateur.

## 21. Itération 4 — notifications, push et application installable

### 21.1 Couverture des scénarios critiques

| Scénario critique | Fichier |
|---|---|
| 10 — SMTP fake + push fake | `tests/e2e/pwa-notifications.spec.js` |
| 12 — PWA | `tests/e2e/pwa-notifications.spec.js` |

`pwa-notifications.spec.js` couvre le manifeste dans les quatre langues, les
icônes générées, le service worker et son périmètre, la page hors ligne
localisée, l'activation des clés push par l'administrateur, le diagnostic
SPF/DKIM/DMARC, l'événement « compte confirmé » qui produit e-mail **et**
notification dans la langue du compte, l'abonnement d'un appareil par
l'endpoint réel, l'envoi de vérification, les préférences de canal et le refus
des abonnements anonymes ou mal formés.

### 21.2 Chiffrement réellement vérifié

`WebPushTest` ne se contente pas de produire des octets : il **déchiffre** ce
que le serveur émet, en jouant le rôle du navigateur abonné (accord ECDH,
dérivation HKDF, AES-128-GCM). Une implémentation plausible mais illisible par
un navigateur échoue donc. Il vérifie aussi la signature du JWT VAPID avec la
clé publique correspondante.

### 21.3 Aucune dépendance réseau

- `MailDnsChecker` reçoit sa résolution DNS par injection : aucun test ne
  dépend d'un domaine réel.
- `UrlGuard` accepte également une résolution injectable ; les tests de push
  exercent donc la garde SSRF sans réseau.
- Le fournisseur de push factice est activé par `SECONDSTAY_PUSH_PROVIDER=fake`
  et dépose ses envois dans `storage/temp/push`, lus par
  `/api/dev/notifications` — endpoint qui renvoie 404 dans toute autre
  configuration.
- Le fournisseur factice n'invente pas de clé publique : il expose celle de
  l'installation, de sorte que le parcours d'abonnement du navigateur est
  réellement exercé.

### 21.4 Abonnement E2E

L'abonnement E2E n'utilise pas de clé fabriquée à la main : le scénario
génère une vraie paire P-256 avec WebCrypto dans la page, puis appelle
l'endpoint réel. La validation serveur est donc exercée telle qu'en
production.

### 21.5 Interactions avec le menu mobile

Le menu replié recouvre le contenu. Les scénarios qui l'ouvrent le referment
avec `closeNavigation()` avant de saisir un formulaire situé en dessous.

## 22. Itération 5 — disponibilités et prix

### 22.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 6 — calendrier et tarifs | `tests/e2e/pricing.spec.js` |

`pricing.spec.js` joue le scénario demandé par la feuille de route : un séjour
de sept nuits dont trois en haute saison, dont le total exact est vérifié
**dans les quatre langues**. Le test contrôle aussi que le total n'est pas une
moyenne : sept fois le prix moyen ne tombe pas sur le même montant.

### 22.2 Convention de nuits

`DateRangeTest` vérifie explicitement la convention « arrivée incluse, départ
exclu » : un départ le jour d'une arrivée n'est pas un chevauchement, un séjour
d'une nuit est valide, et un changement d'heure d'été, une fin d'année ou un
29 février ne décalent jamais le compte de nuits.

### 22.3 Déterminisme sur une installation partagée

Chaque projet Playwright travaille sur un **mois différent** : les tarifs et
les blocages posés par l'un ne perturbent pas l'autre, et les scénarios
restent rejouables sans remise à zéro.

### 22.4 Une seule source de vérité

`pricing.spec.js` compare le total affiché dans la page et celui renvoyé par
`/api/quote` : la page et l'API doivent donner exactement le même montant en
centimes, sinon le total affiché pendant la sélection cesserait d'être celui
qui sera facturé.

## 23. Itération 6 — réservation sans paiement

### 23.1 Couverture des scénarios critiques

| Scénario critique | Fichier |
|---|---|
| 7 — réservation | `tests/e2e/booking.spec.js` |
| 8 — double booking concurrent | `tests/e2e/booking.spec.js`, `tests/php/Database/BookingServiceTest.php` |

### 23.2 Concurrence réellement exercée

Le scénario E2E ouvre **deux navigateurs** et poste les deux verrous en
parallèle, sans attendre la réponse de l'autre : `Promise.all` sur les deux
clics. Il vérifie ensuite qu'il y a exactement un gagnant et un perdant, et
que le perdant se voit proposer la liste d'attente plutôt qu'une erreur brute.

Côté PHP, `BookingServiceTest` va plus loin : il ouvre **deux connexions et
deux transactions distinctes**, insère les nuits de la première, valide, puis
constate que la seconde échoue sur la contrainte d'unicité avec le SQLSTATE
attendu. Deux appels successifs ne prouveraient rien ; deux transactions
entrelacées prouvent la garantie.

### 23.3 Ce que les tests interdisent

- qu'un formulaire impose son propre total, son acompte ou sa remise ;
- qu'une transition non déclarée soit acceptée ;
- qu'un verrou expiré soit finalisé ;
- qu'une remise rende un total négatif ;
- qu'un code promotionnel dépasse sa limite d'usage ;
- qu'une nuit réservée révèle l'identité de son occupant.

## 24. Itération 7 — paiements

### 24.1 Couverture des scénarios critiques

| Scénario critique | Fichier |
|---|---|
| 9 — paiement fake + webhook | `tests/e2e/payment.spec.js`, `tests/php/Database/PaymentServiceTest.php` |

Le scénario 9 est couvert dans les deux sens : l'aller-retour complet
« acompte → webhook confirmé → réservation confirmée » en E2E, et les cas
limites — rejeu, désordre, montant inattendu, cycle de la caution — dans
`PaymentServiceTest`.

### 24.2 Le parcours de paiement joué en entier, sans compte marchand

`FakePaymentProvider` reproduit le déroulé réel — création, redirection,
encaissement, notification, relecture, remboursement — sans réseau ni clé. Il
n'est atteignable que par `SECONDSTAY_PAYMENT_PROVIDER=fake` : c'est la même
règle que pour le transport e-mail et le push factices, et elle interdit
qu'un visiteur puisse confirmer un séjour sans avoir payé.

Le scénario E2E suit exactement le chemin réel :

1. le voyageur réserve, puis ouvre le paiement de l'acompte ;
2. la page de retour n'affirme rien — elle affiche « en cours » ;
3. le test fait évoluer l'état **chez le fournisseur**, et vérifie que
   l'application ne l'a pas encore constaté ;
4. la notification arrive sur `/webhook/payment`, sans jeton CSRF et avec le
   seul identifiant, comme le ferait Mollie ;
5. le séjour passe alors à « confirmé » et l'acompte à « payé » ;
6. la même notification rejouée renvoie `duplicate` et ne change rien.

### 24.3 L'encodeur QR vérifié par un décodeur indépendant

`tests/php/Support/QrDecoder.php` ne partage aucune ligne avec l'encodeur : il
relit la matrice comme le ferait un lecteur — informations de format,
démasquage, parcours en zigzag, désentrelacement, décodage du mode octet. Un
aller-retour réussi prouve donc que la matrice est réellement lisible, et pas
seulement bien formée. Les vingt versions supportées sont parcourues, et la
correction d'erreur est comparée au vecteur de référence publié en annexe I de
l'ISO/IEC 18004.

### 24.4 Ce que les tests interdisent

- qu'un corps de webhook fasse changer un paiement sans relecture chez le
  fournisseur ;
- qu'un webhook rejoué produise un second effet ;
- qu'une notification tardive défasse un encaissement déjà constaté ;
- qu'un montant différent de celui attendu soit accepté ;
- qu'un virement ou un encaissement manuel confirme seul une réservation ;
- qu'un remboursement dépasse le montant encaissé ;
- qu'une caution non reçue passe à « à restituer » ;
- qu'un IBAN dont la clé de contrôle est fausse soit enregistré ;
- qu'un QR code de virement soit lisible sans être authentifié.

## 25. Itération 8 — contrats, documents, courrier entrant

### 25.1 Couverture des scénarios critiques

| Scénario critique | Fichier |
|---|---|
| 11 — IMAP mail + attachment → Documents | `tests/e2e/documents.spec.js`, `tests/php/Database/InboundMailServiceTest.php` |

### 25.2 Le PDF relu par un lecteur indépendant

`tests/php/Support/PdfReader.php` ne partage aucune ligne avec le générateur :
il suit la table `xref` depuis `startxref`, résout chaque objet par son
décalage, vérifie les longueurs de flux, décompresse et relit les chaînes
affichées. Un décalage faux ou une longueur incohérente font échouer la
construction du lecteur : la seule lecture du fichier valide donc toute sa
structure.

Les quatre langues font l'aller-retour, y compris `ß`, `œ`, `€` et les
guillemets français ; un caractère hors codage doit être translittéré, jamais
produire un fichier corrompu.

### 25.3 Le client IMAP confronté à un vrai serveur

`tests/php/Support/bin/imap-stub.php` ouvre une socket sur la boucle locale et
parle IMAP. Le test fait dialoguer `ImapClient` avec lui, puis relit la
**transcription** pour vérifier ce qui a réellement été émis : commandes,
étiquettes distinctes, échappement du nom de boîte, absence du mot de passe
ailleurs qu'en argument de `LOGIN`.

Un cas mérite d'être nommé : un serveur IMAP renvoie toujours au moins un
message à `UID n:*`, même quand tous sont plus anciens. Le test vérifie que le
client filtre lui-même, sans quoi il réimporterait le dernier message à chaque
relève.

### 25.4 Le parcours complet, sans serveur de messagerie

`FakeImapProvider` dépose les messages dans un répertoire, un fichier par UID :
le scénario E2E compose un vrai message MIME avec pièce jointe, le dépose,
déclenche la relève depuis l'administration, et vérifie que le contrat signé
apparaît dans les documents du séjour — côté administration **et** côté
voyageur.

### 25.5 Ce que les tests interdisent

- qu'un contrat existant soit réécrit par un changement de tarif ou de texte ;
- qu'une acceptation ne conserve pas la version et la langue lues ;
- qu'une substitution du PDF accepté passe inaperçue ;
- qu'un tiers accepte le contrat d'autrui, ou l'accepte deux fois ;
- qu'un fichier soit jugé sur son extension plutôt que sur son contenu ;
- qu'un document soit écrit sous le document root ou lu hors du stockage ;
- qu'un document de séjour soit lisible sans être authentifié et titulaire ;
- qu'une adresse de réponse forgée rattache un message ;
- qu'un même message soit importé deux fois ;
- qu'un message imbriqué sans fin épuise l'analyseur ;
- qu'un HTML reçu conserve ses scripts ou ses attributs d'événement.

## 26. Itération 9 — responsable local et opérations

### 26.1 Couverture du scénario critique

Le scénario demandé par la feuille de route — « affectation → ICS → révocation
token » — est joué en entier par `tests/e2e/operations.spec.js` : un
responsable local est créé, un séjour lui est affecté, une ligne de checklist
est cochée, un lien de calendrier est délivré, le flux est relu, puis le lien
est révoqué et l'accès coupe aussitôt.

### 26.2 Le flux relu par un lecteur indépendant

`tests/php/Support/IcsReader.php` ne partage aucune ligne avec le générateur :
il déplie les lignes, sépare nom, paramètres et valeur, et déséchappe. Un
`BEGIN` sans `END`, une ligne sans séparateur ou une version inattendue font
échouer la lecture — la seule relecture valide donc toute la structure.

Deux points sont attaqués explicitement :

- **la date de fin exclusive.** Un départ le 11 juillet doit produire
  `DTEND;VALUE=DATE:20260711`, sans quoi l'agenda occuperait une nuit de trop ;
- **le pliage à 75 octets.** Une valeur longue et accentuée doit rester de
  l'UTF-8 valide sur chaque ligne, et revenir intacte après dépliage.

### 26.3 Ce que les tests interdisent

- qu'une checklist affirme un état que le séjour dément ;
- qu'une ligne sans objet compte comme en retard ;
- qu'un code de checklist inventé soit écrit en base ;
- qu'un client soit affecté comme responsable local ;
- que la suppression d'un compte emporte un séjour ;
- qu'un flux de responsable porte un montant ;
- qu'un flux de voyageur montre un autre séjour que le sien ;
- qu'un jeton de calendrier soit stocké en clair ;
- qu'un jeton révoqué ou inventé donne encore accès à un flux ;
- qu'un séjour annulé apparaisse dans un calendrier ou dans la préparation.

## 27. Itération 10 — mon séjour et invités

### 27.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 13 — guest link | `tests/e2e/stay.spec.js`, `tests/php/Database/StayServiceTest.php` |

Le scénario demandé — « mobile offline → informations utiles dans langue
choisie » — est joué en entier : le livret est rempli en français et en
allemand, le voyageur ouvre « Mon séjour », un lien invité est délivré, puis
`context.setOffline(true)` coupe **réellement** le réseau du navigateur et la
page est rechargée.

### 27.2 Ce que le test vérifie hors ligne

- le livret reste lisible, dans la langue choisie ;
- le contact sur place reste affiché ;
- **rien** de la réservation n'a été mis en cache : le test énumère toutes les
  entrées de tous les caches et vérifie qu'aucune ne contient `/booking/`,
  `/payment/` ni `/document/`, mais qu'une entrée `/guest/` existe bien ;
- la fiche de réservation, faute de cache, ne se charge pas du tout — la
  navigation échoue, ce qui est la bonne réponse plutôt qu'une page de secours
  trompeuse.

### 27.3 Ce que les tests interdisent

- qu'un code d'accès sorte avant l'arrivée ou après le départ ;
- qu'un code d'accès soit stocké en clair, ou réaffiché par l'administration ;
- qu'un code inconnu soit accepté par le dépôt des secrets ;
- qu'un bloc non publié ou vide s'affiche ;
- qu'un bloc d'arrivée s'affiche en plein séjour ;
- qu'une traduction manquante fasse disparaître l'information ;
- qu'un lien invité survive à sa révocation, à son expiration, ou à
  l'annulation du séjour ;
- qu'un jeton invité soit stocké en clair ;
- qu'un invité voie la référence, les finances ou la réservation ;
- que la fiche de réservation, les paiements ou les documents entrent dans un
  cache d'appareil.

## 28. Itération 11 — états des lieux et incidents

### 28.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 14 — état des lieux mobile | `tests/e2e/inspection.spec.js`, `tests/php/Database/InspectionServiceTest.php`, `tests/php/Unit/InspectionTest.php` |

Le scénario demandé — « workflow mobile arrivée/départ » — est joué en entier
sur les deux moteurs : le propriétaire configure les zones et celles qui
exigent une photo, dépose une photo de référence, puis un voyageur réserve,
remplit son état des lieux d'arrivée depuis la page « Mon séjour », transforme
une anomalie en incident urgent, et enfin clôt son départ.

### 28.2 Le refus vient du serveur

Le test ne se contente pas de constater qu'un bouton est grisé : il **clique**
sur « Terminer » alors que deux photos manquent, et vérifie que la réponse est
un refus. C'est la seule façon de tester la règle plutôt que son affichage.
Le gabarit garde donc le bouton actif, et c'est
`InspectionService::complete()` qui tranche.

### 28.3 Ce que les tests interdisent

- qu'un état des lieux de départ se clôture alors qu'une zone requise n'a pas
  sa photo ;
- qu'une zone déclarée conforme bloque une clôture parce qu'une autre zone
  exige une photo ;
- qu'un PDF passe pour une photo, que ce soit sur un constat ou sur une photo
  de référence ;
- qu'un état des lieux clos soit encore modifiable, ou clos deux fois ;
- qu'une deuxième ouverture crée un second état des lieux pour le même moment
  du séjour ;
- qu'un incident s'ouvre sur une zone déclarée conforme ;
- qu'un incident change d'état par une transition non prévue, ou qu'une
  réouverture laisse une date de résolution derrière elle ;
- qu'un incident soit confié à un client ;
- qu'un incident non urgent réveille qui que ce soit, ou qu'une alerte
  d'exploitation parte au voyageur ;
- qu'une photo d'état des lieux soit servie à un anonyme ;
- qu'une photo d'incident devienne visible du voyageur ;
- qu'un état des lieux s'ouvre sans compte, ou pour un type inventé.

## 29. Itération 12 — France et conformité

### 29.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 15 — conformité et versionnage | `tests/e2e/compliance.spec.js`, `tests/php/Database/ComplianceServiceTest.php`, `tests/php/Unit/ComplianceTest.php` |

Le scénario demandé — « réservation historique conserve version et langue du
texte légal accepté » — est joué en entier : une version est publiée dans les
quatre langues, un voyageur réserve **en allemand**, les conditions sont
ensuite entièrement réécrites et republiées sous un nouveau numéro, et la
réservation d'origine cite toujours la version et la langue qu'elle a
acceptées — vue côté administration comme côté voyageur.

### 29.2 Prouver que la preuve tient

Le test ne se contente pas de constater qu'une version est enregistrée. Il
modifie le texte source **après** la réservation, ce qui est exactement le cas
où un consentement non versionné se met à mentir. La vérification porte donc
sur ce qui n'a pas bougé, pas sur ce qui a été écrit.

Côté PHP, `ComplianceServiceTest` va plus loin : il vérifie que le corps et
l'empreinte d'une version publiée sont inchangés après réécriture du texte
éditorial, et qu'une republication sous le même numéro est refusée.

### 29.3 Ce que les tests interdisent

- qu'une version publiée soit réécrite, ou republiée sous le même numéro ;
- qu'une acceptation perde sa version ou sa langue après une nouvelle
  publication ;
- qu'une adresse IP de consentement soit stockée en clair ;
- qu'une seconde acceptation remplace la première ;
- qu'un consentement soit enregistré alors qu'aucun texte n'est publié ;
- qu'un sujet de conformité soit déclaré conforme sans date de vérification ;
- qu'une « source officielle » qui n'est pas une adresse web consultable soit
  acceptée — le test le provoque depuis l'interface, avec une adresse que le
  navigateur laisse passer ;
- qu'un sujet dont la revue est dépassée disparaisse du tableau « À faire » ;
- qu'un barème voté après coup change le montant d'un séjour déjà engagé ;
- qu'un barème dont la fin précède le début soit enregistré ;
- que deux barèmes qui se recouvrent passent inaperçus ;
- qu'une fiche de police existe alors que l'obligation est désactivée, ou que
  sa page reste atteignable en tapant son adresse ;
- qu'une fiche de police soit lisible en base, ou survive à sa durée de
  conservation.

## 30. Itération 13 — contenu local généré

### 30.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 16 — contenu local | `tests/e2e/local-content.spec.js`, `tests/php/Database/LocalContentServiceTest.php`, `tests/php/Unit/LocalContentTest.php` |

Le scénario demandé — « fixtures HTML + fake LLM » — est joué en entier : de
vraies pages HTML sont déposées puis servies au produit, extraites, placées
dans le prompt gardé, lues par le modèle factice, validées, stockées, et enfin
filtrées sur les dates exactes du séjour.

### 30.2 Deux pièces qui prouvent quelque chose

`FakeLlmProvider` ne renvoie pas une réponse préenregistrée : il lit les
sources du prompt. Si l'extraction cassait, si les marqueurs disparaissaient,
si la page n'arrivait pas jusqu'au prompt, le test échouerait. Une réponse
figée, elle, passerait quoi qu'il arrive.

`FixtureHttpFetcher` sert les pages depuis le disque **et délègue tout le
reste** : la source qui pointe vers un hôte inexistant part réellement et se
fait refuser, ce qui prouve que le garde est bien dans le chemin.

### 30.3 Ce que les tests interdisent

- qu'un script, un style ou un commentaire d'une page atteigne le prompt ;
- qu'une phrase impérative trouvée dans une page sorte de la zone « donnée » ;
- qu'une activité citant une source jamais consultée soit stockée ;
- qu'une activité sans titre, sans date lisible, ou dont la fin précède le
  début, survive à la validation ;
- qu'une activité hors des dates du séjour soit affichée ;
- qu'un nom, un e-mail, un téléphone ou une référence de séjour figure dans le
  prompt ;
- qu'une adresse interne soit acceptée comme source, ou consultée ;
- qu'une source désactivée soit lue ;
- qu'un séjour annulé, ou hors fenêtre, déclenche une génération ;
- qu'un rafraîchissement accumule des doublons ;
- que du contenu soit produit sans fournisseur, ou sans aucune source.

## 31. Itération 14 — clôture de l'exploitation

### 31.1 Couverture du scénario critique

| Scénario critique | Fichier |
|---|---|
| 17 — suite transverse de clôture | `tests/e2e/closing.spec.js` |
| Import ICS, reporting, litiges | `tests/php/Database/OperationsClosingTest.php` |
| Lecture ICS | `tests/php/Unit/IcsParserTest.php` |
| Classeur XLSX | `tests/php/Unit/XlsxWriterTest.php` |
| Périodes et indicateurs | `tests/php/Unit/ReportingTest.php` |
| Cycle de vie d'un litige | `tests/php/Unit/DisputeTest.php` |
| Quotas de stockage | `tests/php/Unit/QuotaServiceTest.php` |

### 31.2 Le classeur relu par un lecteur indépendant

`tests/php/Support/XlsxReader.php` complète la série ouverte par `QrDecoder`,
`PdfReader` et `IcsReader` (§24.3, §25.2, §26.2) : il ouvre l'archive, suit les
relations du classeur, retrouve l'ordre des feuilles et résout chaque cellule
par sa référence (`B7`), sans partager une ligne avec `XlsxWriter`. Un
aller-retour réussi prouve que le fichier est réellement lisible par un
tableur, et pas seulement qu'il contient les bonnes chaînes.

Le déterminisme est vérifié explicitement : deux écritures des mêmes données
produisent les mêmes octets.

### 31.3 Ce que le scénario E2E prouve vraiment

`closing.spec.js` ne se contente pas de cliquer sur « Synchroniser » : il
dépose un vrai flux iCalendar servi par `FixtureHttpFetcher`, l'importe, puis
**va vérifier sur le calendrier public**, en visiteur anonyme, que les nuits
concernées sont réellement fermées et que la nuit suivant `DTEND` reste libre.
Le chemin complet — HTTP, lecture ICS, blocages, disponibilité publique — est
donc éprouvé, pas seulement l'écran d'administration.

Trois régressions dangereuses y sont interdites explicitement :

- un flux qui répond 503 **ne rouvre pas** les nuits déjà bloquées ;
- une page HTML servie à la place d'un calendrier n'est pas prise pour un
  calendrier vide ;
- supprimer le flux libère ses nuits **et laisse** celles que le propriétaire
  avait bloquées lui-même.

### 31.4 Ce que les tests interdisent

- qu'un événement importé crée une réservation plutôt qu'une indisponibilité ;
- qu'une synchronisation efface un blocage saisi par le propriétaire, ou celui
  d'un autre flux ;
- qu'une erreur réseau rende disponibles des nuits vendues ailleurs ;
- qu'un flux pointant vers le réseau interne soit accepté ou consulté ;
- qu'un fin d'événement antérieur à son début, ou une date impossible, produise
  un blocage ;
- qu'une caution ou une taxe de séjour soit comptée comme un revenu ;
- qu'un séjour annulé apparaisse dans le reporting ;
- qu'un séjour à cheval sur deux mois soit compté deux fois en entier, ou une
  seule ;
- qu'un taux d'occupation soit calculé sans nuit ouverte ;
- qu'un litige réclame plus que la caution réellement détenue ;
- qu'un litige se clôture sans explication, ou avec un règlement supérieur à ce
  qui était réclamé ;
- qu'un litige rouvert conserve sa date de résolution ;
- qu'un second litige de même nature écrase le premier ;
- qu'une écriture dépassant un quota soit acceptée ;
- qu'un quota non réglé bloque quoi que ce soit ;
- qu'un fichier encore référencé par un autre document soit effacé.

### 31.5 Rejouabilité sur l'installation partagée

Les deux projets Playwright partagent une seule installation. Ce scénario pose
donc lui-même son état de départ et le rend : il crée son propre flux (une URL
par projet), son propre blocage propriétaire, son propre séjour, et remet le
quota des documents à sa valeur d'origine avant de rendre la main. Le mois
utilisé — le dernier de l'horizon de réservation — porte des jours distincts de
ceux des autres scénarios qui l'occupent.

## 32. Consolidation — ce que la campagne doit continuer d'interdire

### 32.1 Planificateur

- qu'une tâche s'exécute deux fois en parallèle : le verrou est vérifié pris,
  respecté, et **libéré même après une exception** ;
- qu'une tâche en échec emporte les autres du même passage ;
- qu'une tâche ignorée — module désactivé, aucun flux déclaré — se lise comme
  une tâche réussie ;
- qu'une tâche déclarée n'ait pas de traitement branché : une tâche non
  enregistrée ne lève rien, elle ne fait simplement rien pendant que l'écran
  affiche une liste rassurante ;
- qu'un message d'exception atteigne l'écran d'exploitation plutôt que le
  journal ;
- qu'un passage cron rapproché relance une tâche quotidienne ;
- qu'un verrou abandonné par un processus tué condamne sa tâche pour toujours.

### 32.2 Porte HTTP du planificateur

- qu'elle réponde autre chose que 404 sans jeton enregistré ;
- qu'un jeton faux se distingue d'un jeton absent ;
- qu'un jeton trop court soit accepté à la saisie ;
- qu'un balayage à jetons variables échappe à la limitation de débit — le
  compteur porte sur l'appelant, pas sur le jeton présenté ;
- qu'un cron légitime, appelant très souvent avec le bon jeton, finisse par se
  limiter lui-même.

### 32.3 Rappels de séjour

- qu'un rappel parte deux fois pour le même séjour ;
- qu'un cron rattrapant plusieurs jours de retard envoie une rafale de rappels
  pour des dates déjà passées ;
- qu'un séjour annulé soit annoncé ;
- qu'un séjour sans compte associé soit compté comme prévenu.

### 32.4 Pages ouvertes depuis un QR

- qu'un bloc soit lisible publiquement sans décision explicite ;
- qu'un bloc dépublié du livret reste lisible par une adresse oubliée ;
- **qu'un repli de langue rouvre ce que le propriétaire vient de fermer.**
  Retirer le bloc allemand du web ouvert doit fermer l'adresse allemande, pas
  la faire répondre avec le bloc français. C'est ce qui donne son sens au
  réglage langue par langue, et le scénario le vérifie en fermant **une** des
  deux langues ; la lacune, elle, se comble toujours — un bloc jamais traduit,
  ou vidé, retombe sur la langue du logement ;
- qu'un QR ouvre une page vide ;
- qu'un code d'accès ou un mot de passe Wi-Fi apparaisse dans la réponse ;
- que le texte saisi par le propriétaire soit interprété comme du balisage ;
- que l'adresse encodée dans le QR imprimé diffère de celle qui répond — le QR
  est relu par un décodeur écrit indépendamment de l'encodeur, et l'adresse
  décodée est ensuite demandée au produit.

### 32.5 Ce qu'une campagne en série impose aux scénarios

Un groupe joué en série est **rejoué depuis le début** quand l'un de ses tests
échoue. Deux conséquences, qui se paient en diagnostics trompeurs :

- **une identité consommable ne doit pas être fixe.** Une inscription rejouée
  avec la même adresse ne recrée pas de compte, n'envoie pas de nouveau
  courrier de confirmation, et échoue sur un jeton déjà utilisé — la panne
  affichée n'est alors plus celle qui a tout déclenché. L'adresse porte donc le
  numéro de tentative ;
- **une page d'arrivée s'attend.** Cliquer sur « se connecter » puis lire
  aussitôt le DOM exécute le script sur la page encore affichée. Sur un petit
  écran, c'est aussi ce qui déplace une case à cocher sous le doigt ;
- **un interrupteur se bascule puis se confirme sur place.** Sur une page
  longue — l'écran du livret porte huit blocs de six champs — le navigateur
  remet en page pendant que le script clique : Playwright rapporte « l'élément
  n'est pas stable », ou pire, un clic qui ne change rien. Attendre le marqueur
  de fin de script, amener le contrôle sous le doigt, puis vérifier qu'il a
  bougé rend l'interaction déterministe **et** fait échouer le scénario là où
  le problème se produit, au lieu de trois lignes plus bas sur une assertion
  qui n'a rien à voir ;
- **une écriture se confirme là où elle se produit.** Un scénario qui bascule
  un interrupteur, enregistre, puis interroge le site public trois lignes plus
  bas, ne dit pas pourquoi il échoue : il montre un « 200 au lieu de 404 » sans
  révéler que la case n'avait pas été décochée. L'état de la case et le message
  de confirmation s'affirment sur place.

### 32.6 Illustrations du livret

- qu'un média privé ou dépublié illustre un bloc, à la sélection comme à
  l'affichage ;
- qu'une image parte sans texte alternatif : il retombe sur la légende
  traduite, puis sur le titre du bloc ;
- que supprimer un média emporte le texte du bloc avec l'illustration.

### 32.7 Carte et source d'un bloc

- qu'une adresse qui n'est pas en `http` ou `https` devienne un lien du livret
  — `javascript:` dans un `href` serait une injection, pas une carte ;
- qu'une adresse trop longue soit tronquée en silence : un lien coupé est un
  lien mort qui a l'air bon, il est refusé ;
- qu'un refus laisse le livret à moitié enregistré — les huit blocs sont
  validés avant la première écriture, et un rejet ne change rien ;
- qu'une source soit affichée sans date de vérification, ou qu'une date sans
  source laisse croire à une vérification qui n'a pas eu lieu ;
- qu'un lien externe rende la main à la page d'origine : la carte et la source
  portent toutes deux `rel="noopener noreferrer"` ;
- que la carte d'une langue déborde sur une autre : les quatre champs vivent
  par bloc **et** par langue ;
- qu'un champ vidé laisse subsister l'ancien lien ;
- **qu'un refus emporte la saisie.** L'écran porte huit zones de texte : les
  renvoyer à leur état enregistré parce qu'une adresse comportait une faute de
  frappe punit le propriétaire d'une erreur sans rapport avec ce qu'il a écrit.
  Le refus revient en 422, avec sa saisie, le champ fautif marqué et le message
  posé à côté de **ce** champ — une erreur affichée au mauvais endroit ne vaut
  pas mieux qu'une erreur absente ;
- **qu'une adresse hostile entrée autrement que par le formulaire devienne un
  lien.** Le contrôle de schéma est rejoué à l'affichage.

### 32.8 Rappels et notifications

- **qu'une panne de courrier consomme le rappel.** Une tentative en échec n'est
  pas une décision : elle se rejoue, et relancer la tâche une fois le serveur
  rétabli renvoie réellement le message. Le compromis est assumé — un courrier
  parti mais rapporté en échec sera envoyé deux fois, ce qui vaut mieux que pas
  du tout ;
- qu'un canal volontairement désactivé soit réessayé chaque nuit : c'est un
  choix du voyageur, pas un incident ;
- qu'une demande encore en attente de réponse reçoive « votre séjour commence
  dans sept jours ».

### 32.9 Coût des écrans les plus fréquentés

Ce qui se mesure ici n'est pas un rendu mais un **nombre de requêtes**. Une
résolution bloc par bloc ne casse aucun test fonctionnel : la page devient
simplement plus lente à chaque illustration ajoutée, et personne ne s'en
aperçoit avant que ce soit le voyageur, debout devant une porte, avec une
barre de réseau.

- huit blocs illustrés coûtent deux requêtes, pas seize ;
- un livret sans illustration n'en coûte aucune.

### 32.10 Diagnostics

- qu'un secret apparaisse dans un résultat ;
- qu'afficher la page ouvre une connexion sortante ;
- qu'une ligne disparaisse de l'écran faute d'avoir quelque chose à dire : une
  ligne absente se confond avec un contrôle qui n'existe pas, et l'on ne
  cherche pas ce qu'on ne voit pas.
