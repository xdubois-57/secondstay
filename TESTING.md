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

- récupère coverage PHP + JS ;
- scan ;
- Quality Gate bloquante.

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

Playwright démarre `./scripts/dev-server.sh start`, qui lance le serveur PHP
intégré derrière `scripts/router.php`. Ce routeur applique la politique de
chemins privés de `PublicPathPolicy`, ce qui rend les tests de sécurité
représentatifs du comportement Apache en production.

### 18.4 Matrice de navigateurs

| Projet Playwright | Moteur | Viewport |
|---|---|---|
| `desktop-chromium` | Chromium | Desktop Chrome |
| `mobile-safari` | WebKit | iPhone 14 |

Les parcours « Mon séjour » et « états des lieux » doivent toujours être
exécutés sur le projet mobile.

### 18.5 Contrôle de l'artefact

`./scripts/check.sh --full` construit et inspecte le ZIP de production à chaque
exécution. La CI ajoute une vérification de démarrage réel de l'artefact extrait
(`/api/version` et une page publique localisée).

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
