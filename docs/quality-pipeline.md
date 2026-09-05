# La chaîne d'assurance

Tout ce qui se tient entre une modification et la production : ce qui tourne,
où, ce que cela prouve réellement, et la configuration GitHub sans laquelle
rien de tout cela ne fonctionne.

Ce document **pointe**, il ne recopie pas. `AGENTS.md`, `SPECIFICATIONS.md`,
`ARCHITECTURE.md`, `SECURITY.md`, `I18N.md`, `TESTING.md` et `RELEASE.md`
restent les sources de vérité des règles elles-mêmes — une règle réénoncée ici
dériverait de son original, et c'est l'original qui gagne. Ce qui suit est la
carte : quelle couche attrape quoi, et ce que chacune ne voit pas.

## Les couches, et ce que chacune ne voit pas

| Couche | Tourne | Attrape | Aveugle à |
|---|---|---|---|
| **Analyse statique** | localement ; CI, deux versions de PHP | Dérive de signature, types impossibles, code mort | Tout ce qui ne se manifeste qu'à l'exécution |
| **Tests unitaires** | localement ; CI | Comportement, calculs, i18n, primitives de sécurité | L'application qui ne démarre pas du tout |
| **Tests base de données** | localement ; CI, deux moteurs | Migrations, requêtes, autorisations bout en bout | Ce qu'un navigateur fait |
| **Matrice d'autorisation** | avec les tests base de données | Une route dont l'accès réel ne correspond pas à ce qu'elle déclare | Les POST dans le sens « trop strict » (voir plus bas) |
| **End-to-end** | CI, deux projets ; gate de release | L'application qui ne démarre pas, un service worker cassé, un parcours rompu | Tout ce que le navigateur ne traverse pas |
| **Scan dynamique** | CI ; gate de release | Ce que l'application servie répond réellement, en-têtes compris | La logique que le scan n'atteint pas |
| **CodeQL** | CI, géré par GitHub | Flux de données vers des puits exploitables | Ce qui n'est pas un motif connu |
| **SonarCloud** | CI ; gate de release | Qualité, duplication, points chauds de sécurité | L'intention |
| **Revue IA** | pull requests | Raisonnement inter-fichiers, documentation périmée, intention qui ne colle pas | Rien de façon fiable — c'est un lecteur, pas une gate |
| **Gates de release** | `scripts/release.sh` | État de la CI, avis de sécurité, fraîcheur des dépendances, Sonar, `check.sh --full`, conformité de l'artefact et des notes | Ce que lisent les relecteurs : l'intention, la documentation périmée |

Aucune couche n'est crue seule, et celles qui se recouvrent le font exprès :
`database` et `database-mariadb` jouent la même suite sur deux moteurs, et
cette différence **est** le point.

## Tests

### PHP — PHPUnit, deux suites et deux versions

`phpunit.xml.dist` déclare deux suites : `unit` (`tests/php/Unit`) et
`database` (`tests/php/Database`). **Un nouveau répertoire de tests doit être
ajouté à ce fichier dans la même modification** — `vendor/bin/phpunit` joue les
suites que ce fichier liste et rien d'autre, donc un répertoire non listé est
un répertoire que personne n'exécute.

La CI joue la suite `unit` sur **PHP 8.2 et 8.4**. 8.4 est la version de
référence, celle qui produit les couvertures ; 8.2 est le plancher déclaré par
`composer.json`, et c'est elle qui attrape ce que la machine de développement
ne peut pas voir. Elle l'a déjà fait : `bootstrap_acquire_lock()` a refusé, sur
8.2, un verrou vieux de dix minutes que 8.4 accordait, sur du code identique.
La cause n'a pas pu être établie — aucun binaire 8.2 ici pour reproduire — et
le correctif le dit : il retire la dépendance au cache de `stat()` plutôt que
de prétendre corriger une cause démontrée.

### Les deux moteurs de base

La production cible **MySQL 8 ou MariaDB** (README § *Stack cible*,
ARCHITECTURE.md §6). La CI joue donc les tests deux fois, sur les deux
moteurs :

- `database` — MySQL 8, le groupe `database`, avec couverture, qui alimente
  SonarCloud ;
- `database-mariadb` — MariaDB 10.11, **toute** la suite (`unit` comprise),
  sans couverture.

L'asymétrie qui compte : **du code correct sur MySQL et faux sur MariaDB
atteint la production**, parce que le premier travail est vert. L'inverse
serait attrapé tout de suite. C'est pourquoi la seconde jambe ne passe aucun
`--testsuite` : le jour où quelqu'un ajoute un test adossé à la base ailleurs
que dans `tests/php/Database`, une version restreinte au groupe le manquerait
en silence — et ce paragraphe aurait annoncé une couverture qui n'existait
pas.

### Une base éteinte n'est pas une suite verte

Les tests adossés à la base **échouent** quand la base est injoignable, ils ne
s'ignorent pas. Avoir dit où elle se trouve et ne pas l'y trouver est une
panne, pas une absence de configuration.

Quand aucune base n'est configurée du tout — quelqu'un lance `phpunit` sur un
portable — le comportement reste un `skip`, sauf si
`SECONDSTAY_TEST_DB_REQUIRED=1` est posée. La CI et `scripts/check.sh --db` la
posent : aucune exécution automatisée ne peut donc être verte sans avoir touché
une base.

Ce second garde-fou couvre un trou **latent**, pas actuel : aujourd'hui, 34
erreurs incidentes rendent la suite rouge sans base. Qu'on assainisse ces
`tearDown()` — un nettoyage raisonnable — et il resterait 739 tests verts
n'ayant rien prouvé. Voir l'en-tête de `tests/php/Support/DatabaseTestCase.php`.

### La matrice d'autorisation

`tests/php/Database/AuthorizationMatrixTest.php` rejoue **chaque route avec
chaque rôle** — visiteur anonyme, client, responsable local, administrateur —
et compare la réponse au niveau que la route déclare via `Core\Access`.

Elle existe parce que SecondStay vérifie les rôles **dans le corps de chaque
action** : une action ajoutée sans l'appel n'est protégée par rien, et rien ne
le signalait — il n'y avait aucune déclaration à confronter au comportement. Un
test couvrant la nouvelle action l'aurait couverte connecté, donc en passant à
côté du trou.

Trois choses à savoir :

1. **Elle interroge l'application, elle ne lit pas le code.** Une version
   statique a été écrite d'abord et s'est trompée sur huit routes :
   `InspectionController` place sa garde dans un helper privé, invisible à qui
   ne lit que le corps de l'action.
2. **La comparaison va dans les deux sens.** Trop permissif est un trou ; trop
   strict fait mentir la table des routes sur qui accède à quoi.
   `Access::Public` étant la valeur par défaut, une route d'administration
   ajoutée sans y penser refuse un visiteur tout en se déclarant publique, et
   la gate refuse — un oubli devient bruyant.
3. **Les POST ne sont rejoués que dans le sens du refus.** Un POST autorisé
   agit : vérifier que `/admin/users/{id}/delete` n'est pas refusé en
   administrateur supprimerait un compte. Le sens dangereux — celui qui laisse
   passer — reste entièrement couvert.
4. **Une erreur serveur n'est pas une autorisation.** Le verdict se lisait sur
   le seul 403 : une route qui plantait répondait 500, la matrice lisait « non
   refusée », et une route cassée passait pour une route correctement ouverte —
   sur toute la moitié qui vérifie l'accès **accordé**. Le contrôle ajouté a
   trouvé son premier cas le jour même : `GET /admin/backups/{id}/download`
   rendait 500 sur un identifiant inconnu, ce que la rétention produit dès
   qu'un écran resté ouvert propose un lien caduc.

### JavaScript — Vitest et `tsc`

`tests/js/`, un fichier par module de `public/assets/js/`. Les specs importent
le fichier de production, jamais une copie de sa logique.

`tsc` vérifie ce JavaScript sans rien compiler (`TESTING.md` §6.1). **Ni
PHPStan ni `tsc` ne portent de baseline** : vert signifie *aucun constat*, et
non *aucun constat nouveau*.

### End-to-end — Playwright

`tests/e2e/`, deux projets (`desktop-chromium`, `mobile-safari`), joués **en
HTTPS** derrière un terminateur TLS local. Le HTTPS n'est pas un détail de
confort : HSTS et le drapeau `Secure` du cookie de session dépendent du
transport, et une campagne en clair les rapporterait absents (SECURITY.md §40).

### Scan dynamique — OWASP ZAP, passif

`scripts/dast.sh` rejoue la campagne Playwright à travers ZAP et applique ses
règles passives. Il exige Docker et une image de 1,2 Go : il est délibérément
**hors** de `check.sh` (SPECIFICATIONS.md §70) et se lance avec `npm run dast`.

Deux garde-fous portent le sens de cette gate :

- `assert-sitemap` refuse un plan de site vide. Un scan qui n'a rien vu et un
  scan qui n'a rien trouvé se ressemblent exactement ;
- la campagne doit être **verte**. « Aucun constat de sécurité, mais la
  campagne a échoué » est traité comme un échec : un scan ne vaut que le trafic
  qu'on lui a donné.

Cette exigence a une contrepartie : la campagne doit être **rejouable**. Les
scénarios groupés en `serial` sont repris en entier au premier échec, et un
groupe qui réinscrit la même adresse ou réserve les mêmes dates ne repart pas
de zéro — il repart sur un compte déjà créé et un séjour déjà pris. La reprise
ne réparait alors rien : elle remplaçait un échec net par une attente de six
minutes sur un bouton que la page ne propose plus. Chaque tentative repart donc
d'une identité et d'un mois neufs (`tests/e2e/stay.spec.js`).

Et quand elle échoue quand même, le travail conserve les traces, captures et
vidéos de Playwright (`dast-campaign-failure`). Sans elles, le journal donne le
nom du scénario tombé et rien de ce qui s'est passé dans le navigateur — c'est
le seul endroit où l'on voit ce que le proxy change à la campagne.

## Intégration continue

`.github/workflows/checks.yml` définit les gates ; `ci.yml` et `release.yml`
les appellent. La seule différence entre les deux appels est l'entrée
`evidence`, qui fait téléverser à chaque travail ce que son outil émet
nativement.

| Travail | Ce qu'il joue |
|---|---|
| `PHP 8.2 / 8.4` | syntaxe, PHPStan, PHPUnit `unit` |
| `Analyse statique — tsc` | `npm run typecheck` |
| `Base de données` | suite `database`, MySQL 8, avec couverture |
| `Base de données — MariaDB 10.11` | la suite entière (`unit` + `database`), MariaDB |
| `JavaScript — Vitest` | tests unitaires du navigateur |
| `E2E — desktop-chromium / mobile-safari` | Playwright en HTTPS |
| `Scan dynamique (passif)` | `scripts/dast.sh` |
| `Sécurité — audit et fuites` | `composer audit`, contrôle de secrets versionnés |
| `Artefact de release` | construction et inspection du ZIP |
| `SonarCloud` | scanner et Quality Gate, consommant les couvertures |
| `CodeQL` | `.github/workflows/codeql.yml` |

## Revue de code

Trois lecteurs, dont aucun ne bloque une fusion sur son seul jugement.

**CodeRabbit** — configuré par `.coderabbit.yaml`. Relit **une fois, à
l'ouverture** : une pull request qui répond à sa propre revue prend cinq ou six
poussées correctives, et relire chacune consomme le quota horaire du plan
gratuit. Demander une passe avec `@coderabbitai review`.
`request_changes_workflow` est désactivé pour qu'il ne puisse pas soumettre une
approbation qui satisferait une revue exigée.

**Claude review** — `.github/workflows/claude-review.yml`, à chaque poussée.
Un second travail, **`Claude review status`**, poste un commentaire — réécrit
sur place — disant si la revue a réellement tourné. Sans lui, un check vert
sans commentaire est ambigu entre « a lu, rien trouvé » et « a refusé de
lire », et seul le journal d'exécution tranche. C'est la durée qui les sépare :
un refus sort en `success` en une quinzaine de secondes, une vraie revue prend
des minutes.

**SonarCloud** — pose une Quality Gate sur chaque pull request, sauf celles
venues d'un fork : `SONAR_TOKEN` n'est pas exposé à ces exécutions. L'absence
du commentaire n'y est pas un échec.

## Releases

`scripts/release.sh patch|minor|major`, vingt-quatre étapes, toutes fermées par
défaut. Les gates rapides passent d'abord et la release s'arrête à la première
qui refuse : découvrir une dépendance en retard après vingt minutes de
`check.sh --full` coûterait ces vingt minutes pour rien.

| Gate | Ce qu'elle vérifie |
|---|---|
| **CI GitHub** | la dernière exécution sur ce commit est verte |
| **Sécurité** | alertes CodeQL et Dependabot ouvertes |
| **SonarCloud** | Quality Gate du commit publié |
| **Fraîcheur** | `composer outdated --direct`, `npm outdated`, bibliothèques vendorisées contre leur amont |
| **Tests** | `./scripts/check.sh --full` |
| **Artefact** | construction puis inspection du ZIP |
| **Notes** | cinq sections obligatoires, dans l'ordre |

La gate de fraîcheur distingue ce que les outils distinguent eux-mêmes : une
montée que les contraintes autorisent déjà est à un `composer update` de
distance et **refuse** la release ; une montée qui exige de changer la
contrainte est une décision et **avertit**. Inventer un seuil — « plus de six
mois » — aurait produit un chiffre indéfendable.

Elle ne conclut jamais sur une mesure qu'elle n'a pas faite. Un code de sortie
inattendu ou une sortie qui n'est pas du JSON exploitable font **échouer** la
gate, en nommant la commande fautive. Auparavant, tout ce qui n'était pas du
JSON donnait une liste vide, les appelants imprimaient « à jour » et la release
continuait : Composer absent, `node_modules` non installé, réseau coupé, JSON
tronqué — tous rendaient un vert. La seule exception est le code 1 de `npm
outdated`, qui est son verdict et non une panne.

Chaque `--skip-*` produit un avertissement nommant exactement ce qui n'a pas
été vérifié, et la liste des dérogations est réaffichée avant la publication.

## La configuration GitHub dont tout cela dépend

Deux catégories, et la différence compte. **`.github/CODEOWNERS` et les
workflows sont dans le dépôt** — une pull request qui les touche est relue
comme n'importe quelle autre. **Le reste n'y est pas** : les secrets, les
applications installées, le ruleset de branche, les étiquettes et la liste des
checks exigés vivent dans les réglages, où aucun diff ne les montre et aucune
gate ne les rapporte.

Ce que les deux partagent : **rien ne prévient quand l'un manque ou est faux.**

### Secrets de dépôt

| Secret | Utilisé par | Sans lui |
|---|---|---|
| `SONAR_TOKEN` | le travail SonarCloud, la gate de release | pas de Quality Gate ; la gate de release refuse |
| `CLAUDE_CODE_OAUTH_TOKEN` | `claude-review.yml` | la revue est **sautée**, et le commentaire de statut le dit en toutes lettres |

`CLAUDE_CODE_OAUTH_TOKEN` se génère avec `claude setup-token` et dépense un
abonnement Claude plutôt qu'une clé facturée à l'appel. Il est lié à la
personne qui l'a généré.

Tant qu'il n'existe pas, le travail de revue est **sauté** plutôt que rouge :
un rouge permanent qu'on apprend à ignorer désarme tous les autres. Le
commentaire de statut porte alors la vérité — « aucune revue n'a eu lieu,
voici pourquoi » — et c'est exactement le rôle pour lequel ce second travail
existe.

### Étiquettes

`claude-review` — la poser sur une pull request demande une passe de revue sans
pousser de commit. La garde du workflow correspond exactement à ce nom.

### CODEOWNERS

`.github/CODEOWNERS` doit nommer un compte qui a réellement l'accès en
écriture. **GitHub ignore en silence une entrée nommant quelqu'un d'autre** :
pas d'erreur, pas d'avertissement, aucun check rouge. Une règle peut donc être
activée, paraître active, et ne correspondre à rien.

`Require review from Code Owners` **n'est pas activé**, et ne peut pas l'être
tant que ce dépôt n'a qu'un collaborateur : GitHub interdit d'approuver sa
propre pull request, donc l'unique propriétaire de code qui est aussi l'unique
auteur ne pourrait jamais satisfaire la règle. Activer le jour où une deuxième
personne obtient l'accès en écriture — le jour où la protection a quelque chose
à protéger.

### Ce qu'un fork ne peut pas avoir

GitHub retire les secrets des exécutions déclenchées par une pull request venue
d'un fork. Sur une telle pull request, **SonarCloud est ignoré** et **la revue
Claude ne peut pas tourner**. Si `Claude review` devenait un check exigé, une
pull request venue d'un fork deviendrait définitivement non fusionnable — c'est
une décision de gouvernance sur l'ouverture aux contributions extérieures, pas
un détail de configuration.

## Le mode de défaillance que ce dépôt rencontre vraiment

Le rouge n'est pas le danger : toutes les couches ci-dessus sont bruyantes
quand elles échouent. Ce qui a réellement mal tourné ici, plusieurs fois, c'est
**quelque chose de vert qui ne prouvait rien** :

- **la preuve HTTPS acceptait n'importe quel `Set-Cookie` contenant « secure »**,
  et la préférence de langue en pose un. Le garde-fou regardait à côté de ce
  qu'il surveillait — et pendant ce temps le cookie de session n'était jamais
  `Secure`, sur du code que ce même garde-fou déclarait sain ;
- **un scan dynamique sans plan de site** ressemble exactement à un scan sans
  constat. `assert-sitemap` refuse désormais ce silence ;
- **une campagne qui plante à mi-parcours** rapportait « aucun constat au
  niveau Medium ou au-dessus » — ce qui était vrai, et ne voulait rien dire :
  dix tests n'avaient pas été joués ;
- **une base éteinte** ignorait les tests qui en avaient besoin, sous un
  message parlant d'une propriété non initialisée ;
- **une entrée CODEOWNERS nommant un non-collaborateur** est ignorée en
  silence, si bien qu'une règle de protection peut être activée, paraître
  active, et ne correspondre à rien ;
- **`Claude review` sort en `success`** quand il refuse de tourner sur une
  pull request qui modifie son propre workflow — donc vert en quinze secondes,
  sur exactement la pull request qu'on voudrait faire lire.

L'habitude qui attrape tout cela est bon marché : **se demander à quoi
ressemblerait un vert si la chose n'avait pas tourné du tout.** Quand la
réponse est « pareil », le signal n'en est pas un. Là où cette distinction est
connue, elle est écrite à côté de la chose qu'elle concerne — dans l'en-tête de
`DatabaseTestCase`, dans celui de `claude-review.yml`, et ici.
