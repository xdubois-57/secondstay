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

## 4. PHP

### Syntaxe

Vérifier tous les fichiers PHP applicatifs.

### PHPStan

Analyse statique avec niveau strict progressif mais non régressif.

Aucune erreur acceptée dans `main`.

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

Jobs suggérés :

### `php`

- syntax ;
- composer install ;
- PHPStan ;
- PHPUnit ;
- reports.

### `database`

- MySQL service ;
- migrations ;
- DB tests.

### `javascript`

- npm ci ;
- Vitest coverage.

### `e2e`

- setup PHP/Node ;
- DB ;
- fake providers ;
- Playwright ;
- upload report/traces.

### `security`

- composer audit ;
- éventuellement checks secrets/config.

### `sonarcloud`

- récupère la couverture PHP des **deux** suites — unitaire et base de données
  — et la couverture JS ;
- scan ;
- Quality Gate bloquante.

La couverture PHP doit venir des deux suites : les tests de base de données
exercent l'essentiel des dépôts et des services, et ne compter que la suite
unitaire faisait apparaître comme non couvert du code qui l'est réellement. Le
scan a donc besoin du service MySQL, comme le travail `database` de la CI.

La détection de copier-coller exclut `translations/**` et `migrations/**`
(`sonar.cpd.exclusions`). I18N.md exige que les quatre langues portent
exactement les mêmes clés, et `TranslationCatalogueTest` fait échouer la
construction sinon : mesurer la duplication de ces fichiers reviendrait à
mesurer une exigence, et la seule façon d'y répondre serait de casser la règle
qu'ils servent. L'exclusion porte sur la **portée de la mesure**, jamais sur la
règle, qui reste appliquée au code PHP, au JavaScript et aux gabarits.

### `codeql`

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

### 18.2 Base de données de test

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

### 18.3 Serveur utilisé par Playwright

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

### 18.4 Matrice de navigateurs

| Projet Playwright | Moteur | Viewport |
|---|---|---|
| `desktop-chromium` | Chromium | Desktop Chrome |
| `mobile-safari` | WebKit | iPhone 14 |

Les parcours « Mon séjour » et « états des lieux » doivent toujours être
exécutés sur le projet mobile.

### 18.5 Contrôle de l'artefact

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
