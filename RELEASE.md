# RELEASE.md

# CI, qualité et publication des releases

## 1. Principe

Une GitHub Release est la seule unité installable officielle.

Ne jamais installer directement un ZIP arbitraire de `main` en production.

Le système de mise à jour intégré consomme les assets de GitHub Releases.

## 2. Version

Fichier racine :

```text
VERSION
```

Versioning SemVer :

```text
MAJOR.MINOR.PATCH
```

Tag correspondant :

```text
vMAJOR.MINOR.PATCH
```

## 3. CI obligatoire

Avant release, les contrôles suivants doivent être verts :

- PHP syntax ;
- PHPStan, **sans baseline** — vert signifie *aucun constat* ;
- `tsc`, vérificateur du JavaScript, aux mêmes conditions ;
- PHPUnit, sur les **deux** versions de PHP supportées ;
- DB integration ;
- Vitest ;
- Playwright, sur `desktop-chromium` et `mobile-safari` ;
- scan dynamique passif OWASP ZAP — aucune alerte au-dessus d'informatif, et
  une carte du site qui couvre les chemins attendus ;
- Composer audit ;
- absence de secret ou de donnée runtime versionné ;
- CodeQL applicable ;
- Dependabot security state ;
- SonarCloud Quality Gate ;
- i18n FR/EN/NL/DE checks ;
- release artifact validation.

### 3.1 Deux pipelines, une seule définition de « vert »

| Fichier | Rôle |
|---|---|
| `.github/workflows/checks.yml` | **toutes** les gates, réutilisable |
| `.github/workflows/ci.yml` | boucle rapide sur chaque poussée |
| `.github/workflows/release.yml` | passe lente et complète, sur tag `v*` **et** `workflow_dispatch` |

Les deux pipelines appellent le même `checks.yml`. La seule différence est
l'entrée `evidence` : à `true`, chaque travail téléverse la sortie **native**
de son outil.

`workflow_dispatch` autant que le tag : la répétition à blanc compte autant que
la vraie, et c'est le seul moyen d'éprouver la chaîne sans publier.

### 3.2 Le pack de preuves

`evidence.zip` contient, et rien d'autre :

- les JUnit PHPUnit des **deux** versions de PHP, et le JUnit Vitest ;
- le rapport HTML de Playwright ;
- PHPStan et `tsc` avec **leur périmètre** — outil, version, niveau, chemins,
  nombre de fichiers analysés. « [OK] No errors » seul ne prouve rien : une
  configuration qui n'analyse aucun fichier l'imprime tout aussi volontiers ;
- le SARIF CodeQL, capturé **comme fichier** et jamais téléversé : `codeql.yml`
  reste propriétaire de l'onglet Sécurité, et deux téléversements pour le même
  commit se marchent dessus. CodeQL ne couvre pas PHP, et les notes doivent le
  dire ;
- l'analyse SonarCloud complète, ou un marqueur `INDISPONIBLE` expliquant
  pourquoi ;
- les **quatre** couvertures Clover et le lcov, avec la note qui explique
  pourquoi ce chiffre diffère de celui de SonarCloud dans le même pack ;
- le rapport complet du scan dynamique ;
- `manifest.json`, dont **toutes** les valeurs viennent du contexte de
  l'exécuteur ;
- `SHA256SUMS`, calculé **avant** l'archivage.

**Rien n'y est écrit à la main.** Un résumé rédigé une fois est un résumé que
personne ne met à jour, et une preuve devenue fausse est pire qu'aucune preuve.

Trois refus, parce que chacune de ces pannes est silencieuse :

- **un pack vide est refusé.** Si un renommage fait que le motif de collecte ne
  correspond plus à rien, un zip vide ressemblerait exactement à une preuve ;
- **l'absence du rapport DAST est refusée**, nommément : c'est celui dont
  l'absence passerait le plus inaperçue, et celui que `SECURITY.md` promet ;
- **si une gate échoue, aucune Release n'est créée.** Le tag existe et ne pointe
  sur rien de publié : on le supprime et on le repousse.

### 3.3 L'attestation

`actions/attest-build-provenance` signe `evidence.zip`. C'est **la seule pièce
que le lecteur n'a pas à croire sur parole** : tout le reste est produit par ce
dépôt et pourrait l'être autrement par quiconque peut modifier le workflow.

```bash
gh attestation verify evidence.zip --repo xdubois-57/secondstay
```

L'URL d'exécution inscrite dans `manifest.json` joue le même rôle en plus
faible : elle mène à un journal horodaté que GitHub conserve et que personne
ayant les droits d'écriture ici ne peut modifier — contrairement à l'archive.

### 3.4 La Release est créée **au brouillon**

La preuve se lit avant d'être publique. C'est toute la raison pour laquelle les
gates tournent avant cette étape et non après.

## 4. Philosophie du script release

`scripts/release.sh` suit la philosophie de ScoutMagic : fail closed, gates avant création du tag, artefact production contrôlé, notes de release avec état des vérifications.

### 4.1 Qui crée la Release

**Le script ne la crée plus.** `release.yml` le fait, au brouillon, une fois
toutes les gates jouées et le pack de preuves constitué. Le script pose le tag,
attend ce verdict, attache le ZIP installable au brouillon, y met les notes,
puis publie.

Les deux ne peuvent pas créer la même Release : le workflow, arrivant en
dernier, repasserait une Release publiée en brouillon. Un seul chemin de
publication.

Si une gate est rouge, le workflow ne crée **aucun** brouillon et le script
s'arrête en le disant : le tag existe et ne pointe sur rien de publié. On le
supprime et on le repousse.

### 4.2 La gate SonarCloud : rien au-dessus de INFO

Plus strict que la Quality Gate de SonarCloud, qui ne juge que le code neuf et
reste donc verte pendant que des constats hérités s'accumulent.

Un constat n'est acceptable que s'il est informationnel sur **les deux**
échelles de sévérité que SonarCloud rapporte : la classique et celle du Clean
Code. Un MINOR classique porte un impact LOW — se fier à une seule échelle
laisserait passer ce que l'autre appelle un défaut.

L'analyse doit porter **sur le commit publié**. Publier quelques minutes après
une fusion est le cas normal et SonarCloud calcule encore : la gate **attend**,
dix minutes au plus (`SONAR_WAIT_ATTEMPTS`, `SONAR_WAIT_SECONDS`). Si l'analyse
n'arrive jamais, elle **refuse** — « pas encore analysé » et « analysé et
propre » sont deux réponses différentes, et une seule est un succès.

Les *security hotspots* à examiner sont vérifiés séparément : ils vivent
derrière leur propre endpoint, et une gate qui ne regarde que les constats a
l'air complète en manquant la catégorie qu'un relecteur sécurité ouvre en
premier.

Un constat qui ne vaut vraiment pas d'être corrigé se marque *won't fix* dans
SonarCloud : c'est une décision avec un nom en face, et la gate l'honore
puisqu'un constat résolu n'est plus ouvert. **Ne pas contourner la gate dans le
script.**

### 4.3 Les notes de release ne sont pas optionnelles

`RELEASE_NOTES_FILE` doit pointer sur un fichier Markdown **rédigé**. Sans lui,
le script refuse de publier : le brouillon reste, et GitHub garderait sinon une
liste de commits qui dit ce qui a été touché et jamais ce que cela signifie.

Cinq sections obligatoires, dans cet ordre, **vérifiées par le script** :

1. **Ce qui change**, dans la langue d'un utilisateur du produit. Si rien n'est
   visible, le dire exactement — c'est une information, pas une excuse ;
2. **Corrections**, chacune formulée comme *le symptôme qui a disparu*, pas
   comme le correctif ;
3. **Compatibilité** : ce qu'une installation existante doit faire, ou
   explicitement **« rien »**. Le silence est un oubli, pas une réponse ;
4. **Tests** : un tableau à trois colonnes — la gate, **ce qu'elle vérifie
   réellement en une ligne**, le résultat. La colonne du milieu n'est pas du
   remplissage : une ligne « Vitest — 111 » n'apprend rien à quelqu'un qui
   audite le projet. Nommer toute gate qui n'a pas tourné, et pourquoi ;
5. **Vérifier la release** : la commande `gh attestation verify` et le contenu
   du pack.

Ce qu'un lecteur ne doit pas manquer — changement de licence, rupture de
compatibilité, correctif de sécurité — va **tout en haut**, avec un marqueur.
Supposer que la lecture s'arrête au premier écran.

**Ne pas écrire de liste de dépendances à la main** :
`scripts/dependency-inventory.php` en ajoute une, générée depuis les fichiers de
verrouillage, donc disant ce qui est réellement parti plutôt que ce qu'une
contrainte permettait.

Les affirmations d'une note portent à conséquence : elles sont lues par des gens
qui décident de mettre à jour. **Ne jamais annoncer un nombre de tests qu'on n'a
pas vu, ni un comportement corrigé qu'aucun test ne couvre.**

Les bypass d'urgence restent explicites et sont reportés dans les notes.

## 5. Étapes du script

Ordre recommandé :

1. vérifier outils requis ;
2. vérifier working tree propre ;
3. vérifier branche attendue ;
4. fetch remote ;
5. vérifier HEAD à jour ;
6. déterminer version courante ;
7. vérifier déploiement précédent si configuré ;
8. vérifier CI du commit ;
9. vérifier CodeQL/Dependabot ;
10. vérifier SonarCloud ;
11. lancer tests locaux requis ;
12. vérifier dépendances/outdated si politique activée ;
13. calculer nouvelle version ;
14. écrire `VERSION` ;
15. commit version ;
16. push commit ;
17. créer/push tag annoté ;
18. préparer Composer prod ;
19. construire ZIP ;
20. inspecter ZIP ;
21. générer notes ;
22. publier GitHub Release + asset ;
23. restaurer l’environnement dev local si nécessaire.

## 6. Gates

### Tests gate

Bloque si :

- PHPStan erreur ;
- `tsc` erreur ;
- PHPUnit fail, sur l'une ou l'autre version de PHP ;
- Vitest fail ;
- Playwright fail ;
- i18n check fail.

### Security gate

Bloque sur alertes CodeQL/Dependabot définies comme ouvertes et bloquantes.

Bloque aussi sur une alerte du scan dynamique passif au-dessus d'informatif.
Une campagne en échec y fait échouer le scan même sans le moindre constat de
sécurité : un scan ne vaut que le trafic qu'on lui a donné.

### Sonar gate

Bloque si :

- Quality Gate != OK ;
- findings sécurité actifs selon politique ;
- hotspots non revus selon politique ;
- analyse absente pour le commit exact.

### Deployment gate

Lorsque production est configurée, vérifier :

- `/api/version` ;
- version/commit attendu ;
- page publique répond ;
- absence d’erreur fatale évidente.

### Artifact gate

Bloque si le ZIP contient des fichiers interdits ou manque des fichiers requis.

## 7. Artefact production

Doit inclure :

- code PHP production ;
- templates ;
- migrations ;
- assets runtime ;
- `vendor/` production ;
- `vendor/autoload.php` ;
- `VERSION` ;
- fichiers de config defaults non secrets nécessaires.

Doit exclure :

- `.git/` ;
- `.github/` ;
- tests ;
- fixtures ;
- `storage/` runtime ;
- `.env` ;
- secrets ;
- config locale ;
- `node_modules/` ;
- coverage ;
- rapports ;
- fichiers IDE ;
- worktrees/état agents ;
- backups ;
- médias locaux.

### 7.1 Les trois assets d'une Release

| Asset | Ce que c'est | Qui le produit |
|---|---|---|
| `secondstay-<version>.zip` | l'unité installable | `release.sh` (étape 19) |
| `evidence.zip` | la preuve que les gates ont tourné sur ce commit | `release.yml` |
| `bootstrap.php` | l'installeur autonome | publié tel quel depuis `bootstrap/` |

`bootstrap.php` est publié **non zippé**, délibérément : il se dépose par FTP à
la racine d'un hébergement vide, et demander de décompresser un fichier unique
avant de le téléverser n'ajoute qu'une occasion de se tromper.

C'est aussi lui qui, une fois exécuté, ira chercher `secondstay-<version>.zip`
sur cette même Release. **Les deux ne se publient jamais séparément** : une
Release qui ne porterait que l'installeur donnerait un installeur qui ne trouve
rien à installer.

Il n'est jamais dans l'archive installable : `bootstrap/` n'est pas dans
`ReleaseArtifactPolicy::INCLUDED_PATHS`. Un installeur livré à l'intérieur de ce
qu'il installe est un fichier de plus à supprimer à la main.

## 8. Protection des données runtime

`storage/` n’est jamais inclus dans une release publique.

Le système d’update ne remplace jamais les données persistantes.

## 9. Notes de release

Inclure :

- version ;
- changements utilisateur ;
- changements admin ;
- migrations ;
- breaking changes ;
- sécurité ;
- état des gates.

Ajouter une section :

```text
Vérifications effectuées
```

avec résultat de chaque gate.

## 10. Bypass d’urgence

Des options `--skip-*` peuvent exister uniquement pour récupération exceptionnelle.

Chaque bypass :

- affiche warning ;
- apparaît dans notes ;
- ne doit jamais être silencieux ;
- doit être audité humainement après publication.

## 11. Updater intégré

Le site :

1. lit `VERSION` ;
2. interroge GitHub Releases ;
3. choisit l’asset attendu ;
4. télécharge ;
5. valide structure ;
6. maintenance ;
7. backup ;
8. installe ;
9. migrations ;
10. écrit `VERSION` ;
11. health check ;
12. rollback si nécessaire.

## 12. Auto-update

Configurable.

L’administrateur dispose aussi d’un bouton :

```text
Vérifier maintenant
```

Le comportement exact d’auto-update peut évoluer, mais jamais contourner les validations d’artefact.

## 13. Mac et iPhone

Mac/Claude Code :

- exécution locale `./scripts/check.sh` ;
- vérification gates cloud ;
- release script.

iPhone :

- déclenchement workflows GitHub ;
- consultation résultats ;
- éventuellement déclenchement manuel d’une validation complète selon permissions GitHub.

L’iPhone n’exécute pas localement PHP/MySQL/Node/Playwright.
