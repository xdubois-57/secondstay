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
- PHPStan ;
- PHPUnit ;
- DB integration ;
- Vitest ;
- Playwright ;
- Composer audit ;
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
- PHPUnit fail ;
- Vitest fail ;
- Playwright fail ;
- i18n check fail.

### Security gate

Bloque sur alertes CodeQL/Dependabot définies comme ouvertes et bloquantes.

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
