# ARCHITECTURE.md

## 1. Objectif

Application web pour louer un seul meublé de tourisme en France, conçue pour hébergement mutualisé PHP/MySQL, déploiement FTP, mises à jour par GitHub Release et développement pilotable par agents de code.

L’architecture reprend les principes utiles de ScoutMagic, mais simplifie fortement ce qui n’est pas nécessaire pour un logement unique.

## 2. Architecture générale

```text
Browser / PWA
      |
      v
public/index.php
      |
      v
Router
      |
      v
Security / Session / CSRF / RBAC
      |
      v
Controller
      |
      v
Service
      |
      v
Repository
      |
      v
PDO → MySQL/MariaDB
```

Rendu :

```text
Controller
  ├─ Twig → HTML
  └─ JSON endpoints si justifiés
```

Frontend :

```text
Bootstrap 5
+ JavaScript first-party
+ PWA/service worker
```

## 3. Responsabilités

### Controller

- parsing request ;
- validation de structure ;
- authorization ;
- appel service ;
- réponse/view.

Pas de SQL ni logique métier complexe.

### Service

- règles métier ;
- workflows ;
- transactions ;
- coordination repositories/providers ;
- audit ;
- événements métier.

### Repository

- SQL ;
- mapping persistance ;
- PDO prepared statements.

Aucune connaissance HTTP/session/Twig.

### Infrastructure / Provider

Interfaces externes courtes :

- `PaymentProvider`
- `MailTransport`
- `ImapProvider`
- `PushProvider`
- `LlmProvider`
- `HttpFetcher`
- `ReleaseProvider`
- futur `AccessProvider`

Chaque provider important possède un fake de test.

## 4. Modules logiques

```text
Core
Configuration
Authentication
I18n
Content
Gallery
Booking
Pricing
Payment
Notification
Mail
Documents
Calendar
Stay
Inspection
Incident
ComplianceFrance
TouristTax
Backup
Maintenance
Diagnostics
Audit
Logging
LlmContent
```

Ce sont des frontières logiques, pas des microservices.

## 5. Une seule propriété

Ne pas ajouter `property_id` partout par réflexe.

Les données globales représentent implicitement le logement de l’installation.

Cette règle réduit la complexité de DB, requêtes, services et UI.

## 6. Persistance

DB : MySQL/MariaDB.

Utiliser des migrations versionnées.

Les contraintes importantes de cohérence vivent en DB lorsque pertinent.

Transactions obligatoires pour :

- réservation/hold ;
- confirmation ;
- paiement déclenchant confirmation ;
- composants financiers critiques ;
- création de snapshots contractuels ;
- restauration/migrations sensibles.

## 7. Settings

Registre de settings typés :

- clé ;
- type ;
- validation ;
- enum ;
- range ;
- default ;
- secret ;
- aide ;
- module ;
- applicabilité.

Ne pas rendre chaque constante configurable.

## 8. Internationalisation

### 8.1 Langues de premier rang

- `fr`
- `en`
- `nl`
- `de`

### 8.2 Textes système

Les textes système résident dans des catalogues de traduction versionnés avec le code.

Exemple :

```text
translations/
├── fr/
├── en/
├── nl/
└── de/
```

ou structure équivalente par domaine.

### 8.3 Contenus DB

Les contenus éditoriaux utilisent un modèle traduisible explicite, par exemple :

```text
content_item
content_translation
```

avec unicité `(content_item_id, locale)`.

Même principe pour les templates e-mail, textes de séjour et autres contenus éditoriaux configurables.

### 8.4 Locale utilisateur

La langue effective peut être déterminée par :

1. préférence enregistrée compte ;
2. langue choisie explicitement dans le site ;
3. préférence navigateur compatible ;
4. fallback installation ;
5. fallback ultime `fr`.

La langue choisie doit persister.

### 8.5 Documents juridiques

Les CGV, contrats et notices légales sont versionnés par langue.

Une réservation conserve la version **et la langue** acceptées.

Voir `I18N.md`.

## 9. Contenus dynamiques

Les contenus éditoriaux vivent en DB.

Les labels fonctionnels/sécurité restent dans les traductions code.

Support :

- titre ;
- body ;
- ordre ;
- saison ;
- locale ;
- publication ;
- version/historique si utile.

## 10. Media/storage

Runtime local :

```text
storage/
├── media/
├── documents/
├── inspections/
├── mail-attachments/
├── backups/
├── logs/
├── cache/
└── temp/
```

`storage/` est persistant et exclu des release artifacts.

Les fichiers sensibles sont servis via endpoint contrôlé.

## 11. Authentification

Sous-systèmes :

- signup ;
- confirmation mail ;
- password ;
- reset ;
- WebAuthn/passkeys ;
- sessions/devices ;
- révocation.

Rôles : Public, Client, Responsable local, Administrateur.

Authorization toujours serveur.

## 12. Booking

### Pricing

Calcul nuit par nuit.

### Availability

Combine :

- réservations confirmées ;
- holds actifs ;
- blocs admin ;
- imports ICS externes.

### Status

États principaux simples ; sous-états séparés.

## 13. Paiement

`PaymentProvider` expose opérations nécessaires sans enfermer le domaine dans Mollie.

Le domaine financier reste provider-neutral.

Le webhook appelle le service métier pour les transitions.

## 14. Notifications

Événements métier → `NotificationService` → tentatives indépendantes e-mail/push.

Chaque tentative est journalisée séparément.

Les templates sont localisés selon la langue du destinataire.

## 15. E-mail et IMAP

### SMTP

Envoi SMTP authentifié. DKIM provider-side.

### IMAP

Job court et idempotent.

Modèle mail :

- external message id ;
- thread ids ;
- sender/recipients ;
- subject ;
- timestamp ;
- sanitized body ;
- reservation link ;
- confidence ;
- sync metadata.

### Attachments

Une pièce jointe liée à réservation devient automatiquement visible comme Document, sans perdre la relation vers le message source.

## 16. Documents

Entité document indépendante :

- booking ;
- catégorie ;
- source ;
- path ;
- original filename ;
- MIME ;
- size ;
- hash ;
- timestamp ;
- immutable ;
- mail source éventuel ;
- language éventuelle ;
- version.

## 17. PWA

- manifest ;
- icons ;
- service worker ;
- push ;
- offline cache limité.

Contenu offline sûr : welcome book, Wi-Fi, règles, déchets, sécurité, contact local.

## 18. Stay mode

`Mon séjour aujourd’hui` utilise date + réservation pour afficher la phase pertinente.

Ne pas multiplier les pages si une vue contextuelle suffit.

## 19. États des lieux

Configuration de zones/items et photos référence.

Check-out : completion impossible si les photos obligatoires manquent.

## 20. Conformité France

Données structurées séparées des settings opérationnels :

- applicabilité ;
- statut ;
- valeur ;
- explication ;
- source officielle ;
- last verified ;
- next review ;
- evidence.

## 21. Taxe de séjour

Service dédié avec règles versionnées et dates d’effet.

Une réservation historique conserve le contexte nécessaire à l’explication du calcul.

## 22. LLM local-content

Pipeline :

```text
Scheduler
→ upcoming stays
→ fetch URLs
→ sanitize/extract
→ construct guarded prompt
→ LlmProvider
→ validate schema
→ store structured items
→ filter by exact stay dates
```

## 23. Jobs

Pas de worker permanent requis.

Jobs courts/idempotents via cron :

- IMAP ;
- import ICS ;
- LLM ;
- rappels ;
- purge ;
- backup ;
- update check ;
- diagnostics heartbeat.

## 24. Backups

Pure PHP.

Backup : DB + storage persistant.

Code non requis.

Manifest + hash + validation.

Restore protégé et audité.

## 25. Updates

```text
Check Release
→ compare VERSION
→ download expected asset
→ validate
→ maintenance
→ backup
→ install
→ migrate
→ VERSION
→ health check
→ exit maintenance
```

Rollback si échec lorsque possible.

## 26. Diagnostics

Contrôles : PHP, extensions, DB, permissions, disque, ZIP, crypto, SMTP, IMAP, DNS mail, Mollie, push, LLM, cron, backups, updates.

## 27. CI

Jobs séparés :

- PHP static/unit ;
- DB integration ;
- JS unit ;
- Playwright ;
- dependency/security ;
- SonarCloud ;
- CodeQL ;
- release artifact validation.

Rapports : Clover/JUnit, LCOV, Playwright traces/screenshots.

## 28. Release artifact

Inclut uniquement ce dont la production a besoin et `vendor/autoload.php`.

Exclut :

- runtime `storage/` ;
- tests ;
- `.git` ;
- `.github` ;
- local config ;
- secrets ;
- coverage ;
- node_modules ;
- IDE/agent local state.

## 29. Implémentation effective

Cette section décrit l'état réel du dépôt. Elle est mise à jour à chaque itération.

### 29.1 Arborescence

```text
public/
├── index.php                 front controller unique
├── .htaccess                 racine servie
└── assets/
    ├── css/app.css
    ├── js/app.js             module ES, aucune étape de build
    ├── js/modules/*.js       modules testés par Vitest
    └── vendor/bootstrap/     Bootstrap 5 vendorisé (pas de CDN, CSP `self`)
src/
├── Core/                     Kernel, Router, Routes, Container, Config, Paths,
│                             View (Twig), RequestContext, PublicPathPolicy,
│                             Http/, Exception/
├── Controller/               AbstractController, HomeController, ApiController
├── I18n/                     Locales, Translator, LocaleResolver, Formatter
└── Release/                  ReleaseArtifactPolicy/Builder/Inspector
templates/                    layout/, public/, error/
translations/{fr,en,nl,de}/   catalogues système versionnés avec le code
config/app.php                valeurs par défaut, jamais de secret
scripts/                      check.sh, release.sh, dev-server.sh, router.php,
                              build-release-zip.sh, release-artifact.php,
                              check-secrets.sh, update-manifest.php
tests/php|js|e2e/             PHPUnit, Vitest, Playwright
```

### 29.2 Cycle de requête

```text
public/index.php
  → Request::fromGlobals()          (calcule le base path, /public est transparent)
  → Kernel::handle()
      → PublicPathPolicy            refus des chemins privés (défense en profondeur)
      → LocaleResolver              préfixe URL > compte > cookie > Accept-Language > défaut > fr
      → Translator / Formatter      liés à la locale effective
      → Router::match()
      → Controller                  reçoit RequestContext + paramètres de route
      → Response                    en-têtes de sécurité + cookie de langue fonctionnel
```

### 29.3 Politique de chemins privés

`SecondStay\Core\PublicPathPolicy` est la source de vérité unique. Elle est
appliquée par :

1. le `.htaccess` racine (production Apache) ;
2. `scripts/router.php` (serveur PHP intégré, développement et E2E) ;
3. `Kernel::handle()` (défense en profondeur applicative).

Un test PHPUnit vérifie que le `.htaccess` couvre chaque entrée déclarée dans la
politique ; un test Playwright vérifie les réponses réelles du serveur.

### 29.4 URLs et langues

Le site public utilise un préfixe de langue explicite : `/fr/…`, `/en/…`,
`/nl/…`, `/de/…`. Les endpoints techniques (`/api/*`) ne sont pas préfixés.
`/` redirige vers la langue résolue. Chaque page publie `canonical` et
`hreflang` pour les quatre langues plus `x-default`.

### 29.5 Artefact de release

`ReleaseArtifactPolicy` déclare les chemins inclus, les entrées obligatoires et
les motifs interdits. `ReleaseArtifactBuilder` construit le ZIP, régénère
l'autoloader Composer en mode `--no-dev` et élague `vendor/`.
`ReleaseArtifactInspector` valide le résultat. Les trois sont couverts par
PHPUnit, et la CI vérifie en plus que le ZIP extrait démarre réellement.

## 30. Itération 1 — installation, configuration, exploitation

### 30.1 Nouveaux modules

```text
src/
├── Database/      Database (PDO), DatabaseConfig, Migrator, SqlScriptSplitter
├── Installer/     InstallationState/Status, Installer, RequirementChecker,
│                  LocalConfigWriter
├── Settings/      SettingType, SettingDefinition, SettingRegistry,
│                  SettingValidator, SettingsRepository, SettingsService
├── Security/      Encryptor, Tokens, Csrf, RateLimiter
├── Auth/          Role, UserStatus, User, PasswordHasher, UserRepository,
│                  SessionRepository, AuthService
├── Logging/       LogLevel, LogSanitizer, Logger
├── Audit/         AuditTrail
├── Maintenance/   MaintenanceMode
├── Backup/        BackupManifest, SqlDumper, BackupService
├── Update/        ReleaseInfo, ReleaseProvider, GitHubReleaseProvider,
│                  FakeReleaseProvider, UpdateService
├── Http/          HttpFetcher, CurlHttpFetcher, FakeHttpFetcher, UrlGuard
└── Diagnostics/   DiagnosticStatus, DiagnosticResult, DiagnosticRunner
```

`src/Core/Services.php` enregistre l'ensemble dans le conteneur. Les services
dépendant de la base sont paresseux : l'application reste utilisable avant
l'installation et pendant une panne.

### 30.2 États d'installation

`InstallationStatus` distingue trois états, et le noyau agit différemment pour
chacun :

| État | Assistant d'installation | Site public | `/api/*` |
|---|---|---|---|
| `not_installed` | accessible | redirige vers l'assistant | disponible |
| `installed` | 404 | normal | disponible |
| `unavailable` | 404 | 503 | disponible |

L'état `unavailable` (configuration locale présente mais base injoignable,
schéma absent ou plus aucun administrateur actif) garantit qu'une panne ne peut
jamais rouvrir l'assistant d'installation d'une instance déployée.

### 30.3 Chaîne de requête complète

```text
public/index.php
  → PublicPathPolicy         chemins privés refusés
  → Session                  démarrage (PhpSession en production)
  → InstallationState        gate installation / indisponibilité
  → LocaleResolver           préfixe URL > compte > cookie > Accept-Language
  → MaintenanceMode          gate maintenance (503 sauf admin et /api)
  → Router                   résolution de route
  → CSRF                     obligatoire sur toute mutation navigateur
  → Controller               autorisation serveur explicite (requireRole)
  → Response                 en-têtes de sécurité + cookie de langue
```

### 30.4 Réglages typés

Un réglage déclare son type, sa validation, ses bornes, son module et son aide.
Les montants sont saisis en euros et stockés en centimes entiers. Les secrets
sont chiffrés au repos (`Encryptor`, AEAD XChaCha20-Poly1305), jamais
réaffichés, jamais audités en clair, et rechiffrables par rotation de clé.

### 30.5 Sauvegarde et restauration

`BackupService` produit une archive ZIP contenant :

```text
database.sql                 dump SQL complet, 100 % PHP
storage/media/…              médias persistants
storage/documents/…          documents
storage/inspections/…        états des lieux
storage/mail-attachments/…   pièces jointes
manifest.json                version, schéma, comptages, SHA-256 par entrée
```

La restauration vérifie chaque empreinte, s'exécute sous maintenance, refuse
toute traversée de chemin et n'écrit que dans les répertoires de données
autorisés. Elle est auditée.

### 30.6 Mise à jour

```text
check (GitHub Releases) → download → validate (ReleaseArtifactPolicy)
→ backup → maintenance → snapshot des chemins gérés → install → migrations
→ VERSION → health check → sortie de maintenance
```

En cas d'échec à n'importe quelle étape, le snapshot est restauré et `VERSION`
revient à la valeur précédente. `storage/` et `config/local.php` ne sont jamais
remplacés. `FakeReleaseProvider` permet de tester tout le flux sans réseau.

### 30.7 URLs

Les segments de chemin sont neutres et stables (`/admin/settings`,
`/login`) ; seule la langue varie via le préfixe (`/fr`, `/en`, `/nl`, `/de`).
Cela garantit des URLs durables, des `hreflang` cohérents et évite de dupliquer
la table de routage par langue.

## 31. Itération 2 — site public, contenus et médias

### 31.1 Nouveaux modules

```text
src/
├── Content/   Season, PageKind, ContentPage, PageTranslation,
│              ContentRepository, ContentService, DefaultContent, ContentSeeder
├── Media/     MediaItem, MediaTranslation, MediaRepository, ImageProcessor,
│              MediaService
├── Seo/       SeoBuilder (canonical, hreflang, sitemap, robots, JSON-LD)
└── Support/   HtmlSanitizer, Slugger
```

### 31.2 Modèle de contenu

```text
content_page          slug, kind, season, parent_id, position,
                      is_published, show_in_menu, is_system
content_translation   (content_page_id, locale) unique :
                      title, menu_label, lead, body, meta_title, meta_description
```

`kind` détermine le gabarit (`home`, `page`, `gallery`, `contact`, `legal`).
`parent_id` produit le menu multi-niveaux, sans table de menu séparée.
`season` (`all`, `summer`, `winter`) filtre l'affichage selon la saison
effective, elle-même déduite du réglage `site.season` (`auto` suit le mois :
hiver de novembre à mars).

L'installation crée onze pages, traduites dans les quatre langues, à partir des
catalogues `content.default.*` : le site est immédiatement complet et
entièrement réécrivable.

### 31.3 Médias

```text
media               filename (généré), mime, dimensions, category, season,
                    position, is_published, is_private, hash
media_translation   (media_id, locale) unique : caption, alt_text
```

Chaque téléversement est ré-encodé par GD en trois variantes (`thumb`, `large`,
`original`). Le ré-encodage supprime toutes les métadonnées, GPS compris, et
applique l'orientation EXIF. Les fichiers vivent dans `storage/media/<variante>/`
et ne sont accessibles que par la route `/media/{variante}/{fichier}`, qui
applique la visibilité côté serveur.

### 31.4 HTML éditorial

`HtmlSanitizer` applique une liste blanche explicite de balises et d'attributs,
supprime `script`/`style`/`iframe`/`form` avec leur contenu, refuse les URL
`javascript:`, `vbscript:` et `data:` non-images, et force
`rel="noopener noreferrer"` sur les liens `target="_blank"`.

### 31.5 SEO

`SeoBuilder` produit `canonical`, `hreflang` pour les quatre langues plus
`x-default`, l'Open Graph, `/sitemap.xml` (une entrée par page et par langue,
avec liens alternatifs) et `/robots.txt`. La page d'accueil publie un bloc
JSON-LD `LodgingBusiness` construit à partir des réglages du logement.

### 31.6 Routage

La route attrape-tout `/{slug}` est déclarée en dernier : les routes
techniques (`/admin`, `/login`, `/api/*`, `/media/*`, `/sitemap.xml`) sont
résolues avant elle. Les contraintes de route acceptent les quantificateurs
`{n,m}` : l'analyse des paramètres suit la profondeur des accolades.
