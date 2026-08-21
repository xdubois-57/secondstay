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
Legal
ComplianceFrance
TouristTax
Police
Privacy
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

Le refus est prononcé par `InspectionService::complete()`, c'est-à-dire par le
serveur. L'interface montre ce qui manque, elle ne l'impose pas : un bouton
grisé cacherait la règle au lieu de l'appliquer.

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

## 32. Itération 3 — comptes, e-mails et clés d’accès

### 32.1 Nouveaux modules

```text
src/
├── Auth/
│   ├── AccountService        inscription, confirmation, réinitialisation,
│   │                         profil, export et anonymisation RGPD
│   ├── TokenType             email_confirmation | password_reset | email_change
│   ├── TokenRepository       jetons à usage unique, stockés hachés
│   ├── ConsentRepository     consentements horodatés (CGU, confidentialité)
│   └── WebAuthn/
│       ├── Cbor              décodeur CBOR minimal
│       ├── CoseKey           COSE → DER/PEM (ES256, RS256)
│       ├── AuthenticatorData analyse des données d’authentificateur
│       ├── WebAuthnCredentialRepository
│       └── WebAuthnService   options, enregistrement, vérification d’assertion
└── Mail/
    ├── MailAddress, MailAttachment, MailMessage   construction MIME
    ├── MailTransport (interface)
    ├── SmtpMailTransport     client SMTP minimal (STARTTLS / TLS implicite)
    ├── FakeMailTransport     transport de test (mémoire + dépôt JSON)
    ├── MailRepository        journal des messages, sans corps
    └── MailService           rendu du gabarit dans la langue du destinataire
```

### 32.2 Modèle de données (`0003_accounts.sql`)

```text
user_token           user_id, type, token_hash, expires_at, used_at, ip
webauthn_credential  user_id, credential_id, public_key (PEM), sign_count,
                     transports, label, last_used_at
mail_message         direction, status, template, locale, to_address, subject,
                     message_id, error, user_id, correlation_id
consent              user_id, type, version, locale, accepted_at, ip
user                 + anonymised_at, deletion_requested_at
```

Le corps des messages n’est jamais stocké : seule la trace d’envoi l’est.

### 32.3 Parcours de compte

```text
/account/signup   → AccountService::register  → mail account_confirmation
/account/confirm  → confirmEmail              → session ouverte, statut actif
/account/forgot-password → requestPasswordReset → mail password_reset
/account/reset    → resetPassword             → toutes les sessions révoquées
/account          → profil, langue, mot de passe, appareils, clés, RGPD
```

Une inscription sur une adresse déjà connue produit exactement la même
réponse qu’une inscription neuve ; c’est le titulaire réel qui reçoit un
message `account_exists`. Le mot de passe existant n’est jamais écrasé.

### 32.4 E-mails

`MailService` rend `templates/mail/<template>.html.twig` dans la langue du
destinataire, dérive le sujet de `mail.<template>.subject` et enregistre une
ligne `mail_message` avant l’envoi. L’adresse d’expédition provient du réglage
`mail.from_address` ; à défaut elle est dérivée de l’URL publique, de sorte
qu’une installation neuve peut envoyer sa première confirmation. Le transport
est choisi par `mail.transport` (`smtp` en production, `fake` en test via
`SECONDSTAY_MAIL_TRANSPORT`).

`SmtpMailTransport` est un client minimal : EHLO, STARTTLS ou TLS implicite,
`AUTH PLAIN` puis repli sur `AUTH LOGIN`, un message par session, protection
du point en début de ligne. Toutes ses erreurs sont des clés de traduction :
aucun détail d’infrastructure ne remonte à l’interface.

### 32.5 Clés d’accès (WebAuthn)

L’enregistrement accepte l’attestation `none` : SecondStay n’a pas besoin de
connaître le modèle d’authentificateur, seulement de lier une clé publique à
un compte. La vérification d’assertion est complète : origine, `rpIdHash`,
défi, type, `crossOrigin`, signature et compteur strictement croissant.

L’identifiant WebAuthn de l’utilisateur est un condensat opaque : aucune
donnée personnelle ne quitte l’application dans les options.

Les navigateurs n’acceptent une « relying party » que sur un domaine
enregistrable. `WebAuthnService::isAvailable()` détecte les installations
servies par adresse IP et la fonction est alors masquée plutôt que proposée
puis refusée à chaque tentative.

Côté navigateur, `public/assets/js/modules/passkey.js` se limite à convertir
les options JSON en structures binaires et inversement ; toute la vérification
reste serveur.

### 32.6 Limitation de débit

Les inscriptions (par adresse IP) et les réinitialisations (par compte) sont
limitées par `RateLimiter`. Un administrateur verrouillé par ses propres
tentatives peut remettre les compteurs à zéro depuis
`/admin/diagnostics` ; l’action est tracée dans le journal d’audit.

## 33. Itération 4 — notifications, push et application installable

### 33.1 Nouveaux modules

```text
src/
├── Notification/
│   ├── NotificationEvent       vocabulaire des événements notifiables
│   ├── NotificationChannel     email | push
│   ├── NotificationRepository  journal, une ligne par canal et tentative
│   ├── NotificationPreferenceRepository
│   └── NotificationService     point d'entrée unique
├── Push/
│   ├── Base64Url               encodage sans remplissage
│   ├── Vapid                   paire P-256, JWT ES256 (RFC 8292)
│   ├── PushEncryption          aes128gcm + ECDH + HKDF (RFC 8188 / 8291)
│   ├── PushSubscription        abonnement validé dès la construction
│   ├── PushMessage             charge utile minimale
│   ├── PushProvider            frontière (WebPushProvider | FakePushProvider)
│   ├── PushSubscriptionRepository
│   └── VapidKeyManager         génération et rotation des clés
├── Pwa/
│   ├── ManifestBuilder         manifeste localisé
│   └── IconGenerator           icônes dessinées par l'installation
└── Diagnostics/
    ├── MailDnsChecker          SPF / DKIM / DMARC, résolution injectable
    └── NotificationDiagnostics contrôles e-mail et push
```

### 33.2 Modèle de données (`0004_notifications.sql`)

```text
push_subscription        user_id, endpoint, endpoint_hash, public_key,
                         auth_secret, locale, user_agent, failures
notification             event, channel, status, user_id, locale, subject,
                         reference, error, correlation_id
notification_preference  (user_id, channel) : l'absence de ligne vaut « actif »
```

Le corps des notifications n'est jamais stocké, pas plus que celui des e-mails.

### 33.3 Deux canaux indépendants

`NotificationService::notify()` tente l'e-mail **et** le push séparément :
l'échec de l'un n'empêche jamais l'autre, et chaque tentative produit sa
propre ligne de journal. Le canal désactivé par le titulaire est tracé
`skipped` plutôt que silencieusement ignoré.

Tous les événements partagent un gabarit e-mail unique
(`templates/mail/notification.html.twig`) alimenté par les clés de traduction
de l'événement : ajouter un événement ne demande qu'un jeu de traductions.

### 33.4 Web Push

L'implémentation est autonome — aucune dépendance Composer supplémentaire sur
l'hébergement mutualisé visé :

- identification du serveur applicatif par JWT ES256 signé avec la clé privée
  VAPID, signature convertie du DER d'OpenSSL au format brut `r||s` du JWS ;
- charge utile chiffrée de bout en bout : accord ECDH P-256 avec la clé de
  l'abonnement, dérivation HKDF, chiffrement AES-128-GCM, en-tête
  `aes128gcm` (sel, taille d'enregistrement, clé publique éphémère) ;
- un abonnement révoqué par le navigateur (404 / 410) est supprimé, un
  abonnement qui échoue durablement également.

Les clés VAPID sont générées par l'installation et stockées comme un secret :
aucune clé n'est versionnée. Les renouveler invalide les abonnements
existants, qui sont donc supprimés dans le même geste.

### 33.5 Application installable

Le manifeste est **localisé** : nom, description, `start_url` et raccourcis
suivent la langue demandée. Les icônes sont dessinées par l'installation à
partir du nom du logement, puis mises en cache dans `storage/cache/icons` :
le dépôt public ne contient aucune image propre à une résidence.

Le service worker est rendu par l'application afin d'y injecter la version
installée : une mise à jour applicative invalide donc automatiquement les
caches précédents. Il sert le socle statique depuis le cache, garde une page
hors ligne par langue, et **n'intercepte jamais** `/admin`, `/account`,
`/api/` ni les médias privés. La page hors ligne est préchargée **sans
cookie** : aucun contenu personnel ne peut se retrouver dans le cache d'un
appareil partagé.

### 33.6 Session paresseuse

La session PHP ne s'ouvre plus qu'au premier besoin réel — c'est-à-dire à la
première écriture, jeton CSRF compris, ce dernier n'étant calculé que si un
gabarit l'écrit. Une requête qui n'écrit rien (sitemap, robots, manifeste,
service worker, icône, page publique consultée par un robot) ne reçoit donc
aucun cookie : ces réponses restent réellement cachables et une installation
ne crée plus une session par passage de robot.

### 33.7 Messages flash et service worker

Un message flash appartient à la page réellement affichée. Une requête
annexe — préchargement, appel JSON, socle récupéré par un service worker — ne
doit pas le consommer.

La détection s'appuie sur `Sec-Fetch-Mode: navigate` en priorité : lorsqu'un
service worker relaie une navigation, le mode reste `navigate` alors que
`Sec-Fetch-Dest` devient `empty`. Se fier à la seule destination ferait
disparaître les confirmations dès qu'un service worker est installé.

## 34. Itération 5 — disponibilités et prix

### 34.1 Nouveaux modules

```text
src/
├── Pricing/
│   ├── DateRange        plage de séjour : arrivée incluse, départ exclu
│   ├── RateRepository   tarifs par nuit, exceptions uniquement
│   ├── PriceCalculator  calcul nuit par nuit, ménage, acompte
│   └── Quote            devis en centimes entiers
├── Availability/
│   ├── AvailabilityBlockRepository  indisponibilités d'exploitation
│   └── AvailabilityService          états de nuit et grilles de mois
├── Booking/
│   ├── StayRules        durées, jours d'arrivée, capacité, horizon
│   └── QuoteService     règles + disponibilité + prix en une réponse
└── Support/
    └── Money            saisie tolérante → centimes entiers
```

### 34.2 Convention de nuits

`DateRange` porte une convention unique : **arrivée incluse, départ exclu**.
Un séjour du 12 au 19 compte sept nuits, du 12 au 18. Deux séjours ne se
chevauchent donc pas lorsque l'un part le jour où l'autre arrive — c'est
exactement le cas d'un enchaînement.

Les indisponibilités sont stockées par première et **dernière nuit**
(`fromNights()`), ce qui évite d'écrire « fin + 1 jour » à chaque usage.

Les dates sont des jours civils sans heure ni fuseau : un changement d'heure
d'été ne peut pas décaler un calcul de prix.

### 34.3 Modèle de données (`0005_pricing.sql`)

```text
rate_override       day (unique), price_cents, min_nights, note
availability_block  start_day, end_day (dernière nuit), kind, label
```

La table de tarifs ne contient que les **exceptions** : l'absence de ligne
signifie « tarif de référence ». Un calendrier de plusieurs années reste donc
peu coûteux à stocker comme à lire.

### 34.4 Calcul nuit par nuit

Chaque nuit porte son propre tarif. Un séjour à cheval sur deux saisons
additionne les tarifs réels et **jamais une moyenne** : le devis expose le
détail nuit par nuit, et le prix moyen n'est qu'un indicateur d'affichage.

Tous les montants sont des entiers de centimes ; aucun flottant n'intervient
dans un montant facturé. L'acompte est arrondi au centime supérieur, de sorte
que le solde ne dépasse jamais le total.

### 34.5 Un seul calcul, deux usages

`QuoteService` combine règles, disponibilité et prix. La page publique
l'appelle par `/api/quote`, et le parcours de réservation l'appellera
directement : le total affiché pendant la sélection et le total facturé
proviennent du même code.

Les règles sont vérifiées **côté serveur**. Le calendrier les applique pour
guider la saisie, mais rien ne dépend du navigateur.

### 34.6 Pages fonctionnelles

`PageKind` gagne `availability` et `rates`. Les deux pages système gardent
leur contenu éditorial — le propriétaire écrit son texte — et le gabarit y
ajoute le calendrier ou le tableau des tarifs. La migration aligne les
installations existantes.

### 34.7 Formatage localisé

Le `Formatter` gagne le nom de mois, le couple jour-mois et les noms abrégés
des sept jours, tous produits par ICU. La logique financière reste canonique :
seules les vues formatent. Côté navigateur, `Intl` fait le même travail sur
les entiers de centimes renvoyés par l'API.

## 35. Itération 6 — réservation sans paiement

### 35.1 Nouveaux modules

```text
src/Booking/
├── BookingStatus          états principaux et transitions autorisées
├── SubStatus              sous-états partagés par les six dimensions
├── Booking                séjour enregistré, montants figés
├── BookingReference       référence dictable, sans caractère ambigu
├── BookingRepository      persistance et garantie anti-double-réservation
├── BookingEventRepository timeline
├── BookingService         parcours complet et workflow
├── PromoCode / PromoCodeRepository
└── WaitlistRepository     liste d'attente
```

### 35.2 Anti-double-réservation

La garantie ne repose pas sur « vérifier puis écrire » — deux requêtes
concurrentes passeraient toutes deux la vérification — mais sur la **clé
primaire** de `booking_night` : une nuit occupée y existe une fois et une
seule.

```text
booking_night   day (clé primaire), booking_id
```

L'insertion des nuits se fait dans la même transaction que la réservation. La
seconde transaction échoue sur la contrainte, quel que soit l'ordre
d'exécution, et l'échec est traduit en « ces dates ne sont plus disponibles »
plutôt qu'en erreur technique.

Annuler, refuser ou laisser expirer un séjour supprime ses lignes : les nuits
redeviennent réservables immédiatement.

### 35.3 Verrou temporaire

Le parcours pose un verrou (`BookingStatus::Hold`) **avant** le formulaire de
finalisation : sans lui, deux visiteurs rempliraient le même formulaire en
parallèle et n'apprendraient qu'à la fin qu'un seul obtient le séjour. Le
verrou occupe réellement les nuits et expire seul au bout de
`booking.hold_minutes`.

### 35.4 Workflow

`BookingStatus` porte ses transitions autorisées : toute autre transition est
refusée par le service, quoi qu'un formulaire envoie. Les six sous-états —
contrat, paiements, caution, ménage, arrivée, départ — vivent dans leurs
propres colonnes et progressent indépendamment de l'état principal.

### 35.5 Montants figés

Les montants sont calculés par le serveur au moment du verrou et **stockés**.
Un changement de tarif ultérieur ne réécrit jamais un séjour engagé, et un
formulaire qui tenterait d'imposer son propre total est ignoré.

La remise d'un code promotionnel porte sur l'hébergement seul et est bornée à
celui-ci : le ménage et la caution ne sont pas des marges commerciales, et un
total ne peut pas devenir négatif.

### 35.6 Timeline

Chaque étape importante laisse une trace horodatée et attribuée dans
`booking_event` : c'est ce que le client et le propriétaire lisent, sans
ouvrir le journal technique.

### 35.7 Liste d'attente

Une inscription est unique par adresse et par période. Lorsque des nuits se
libèrent, les inscriptions dont la période croise ces nuits reçoivent un
e-mail dans leur propre langue. Une inscription est marquée **avant** l'envoi :
un échec d'envoi ne produit pas une rafale de rappels.

## 36. Itération 7 — paiements

### 36.1 Nouveaux modules

```text
src/Payment/
├── PaymentProvider         frontière : créer, relire, rembourser, lire un webhook
├── MolliePaymentProvider   premier fournisseur réel
├── FakePaymentProvider     fournisseur factice, activé par variable d'environnement
├── NullPaymentProvider     absence de fournisseur : le paiement en ligne n'est pas proposé
├── PaymentKind             hébergement, acompte, solde, caution, ménage, taxe, ajustement
├── PaymentStatus           états constatés chez le fournisseur
├── HoldStatus              cycle propre à la caution
├── Payment                 composant financier d'un séjour
├── PaymentRepository       persistance, échéances et historique
├── PaymentService          échéancier, encaissements, remboursements, webhooks
├── WebhookRepository       idempotence des notifications
└── EpcQrBuilder            message EPC069-12 pour le virement SEPA

src/Tax/
└── TouristTaxCalculator    volet financier de la taxe de séjour

src/Support/
├── QrCode                  encodeur QR, mode octet, niveau M, versions 1 à 20
└── ReedSolomon             correction d'erreur sur GF(256)
```

### 36.2 Un objet par composant financier

Hébergement, acompte, solde, caution, ménage, taxe de séjour et ajustements
sont des lignes distinctes de `payment`, chacune avec son montant, son
échéance, sa méthode, son état et son historique (SPECIFICATIONS.md §29). Le
montant remboursé est suivi séparément du montant dû : un remboursement
partiel reste donc lisible, et le montant réellement acquis se calcule sans
ambiguïté.

L'échéancier est construit par `PaymentService::schedule()`, qui est
**idempotent** : le rejouer ne duplique aucun composant. Il ne crée rien pour
un séjour annulé, refusé, ou encore au stade du verrou.

### 36.3 Le corps d'un webhook n'est jamais cru

Une notification de paiement n'apporte qu'un identifiant. L'application y lit
cet identifiant, puis **relit l'état chez le fournisseur** avant de changer
quoi que ce soit. Un corps qui annoncerait « payé » sans que le fournisseur ne
le confirme ne produit rien.

Trois protections se cumulent (SPECIFICATIONS.md §34) :

- **idempotence** — `UNIQUE (provider, external_id)` sur `webhook_event` : un
  événement rejoué est reconnu comme doublon par la base, pas par une
  vérification applicative ;
- **désordre** — `PaymentStatus::canBeReplacedBy()` interdit à une
  notification tardive de défaire un encaissement déjà constaté ; un paiement
  encaissé ne se défait que par un remboursement explicite ;
- **montant** — un montant différent de celui attendu n'est jamais accepté
  silencieusement : il est journalisé et refusé.

Le point d'entrée `/webhook/payment` est exempté de CSRF — il est authentifié
par la relecture chez le fournisseur, pas par une session de navigateur — et
répond 200 pour un doublon ou un identifiant inconnu, afin que le fournisseur
cesse de réessayer, mais 503 lorsqu'un nouvel essai a du sens.

### 36.4 Ce qui confirme un séjour

Seul un **acompte constaté chez le fournisseur** confirme automatiquement une
réservation (SPECIFICATIONS.md §30). Un virement ou un encaissement en espèces
est enregistré comme payé, mais ne confirme le séjour que si un administrateur
le demande explicitement, d'une case à cocher séparée.

Corollaire de sécurité : `FakePaymentProvider` n'est atteignable que par
`SECONDSTAY_PAYMENT_PROVIDER=fake`, jamais depuis l'interface. Sans clé
utilisable, c'est `NullPaymentProvider` qui est en place et le paiement en
ligne n'est simplement pas proposé — un fournisseur factice à cette place
laisserait un visiteur confirmer un séjour sans avoir rien payé.

### 36.5 Caution

La caution suit son propre cycle, indépendant de l'état du paiement : à payer,
reçue, à restituer, restituée, partiellement retenue (SPECIFICATIONS.md §32).
Les transitions sont déclarées dans `HoldStatus` et toute autre est refusée.
Le choix retenu est l'encaissement suivi d'un remboursement, plutôt qu'une
préautorisation de longue durée que peu de moyens de paiement supportent.

### 36.6 QR EPC

Le QR code de virement SEPA est fabriqué localement : l'hébergement mutualisé
visé n'a ni Composer ni service externe disponible, et une référence de
virement n'a rien à faire chez un tiers.

`EpcQrBuilder` produit le message EPC069-12 — douze lignes, UTF-8, 331 octets
au maximum — et `QrCode` l'encode. Les bornes du nom et de la référence sont
exprimées en caractères par la spécification mais en octets pour le message
entier : un nom accentué peut donc tenir dans ses 70 caractères et faire
déborder le tout. Dans ce cas le champ le plus long est rogné, jamais l'IBAN
ni le montant, qui seuls conditionnent l'exécution du virement.

L'IBAN est vérifié par la clé de contrôle ISO 7064 MOD 97-10 dès sa saisie
dans la configuration : un IBAN mal recopié ne se voit sinon qu'au moment où
le virement échoue, c'est-à-dire bien trop tard.

Le QR est servi derrière l'authentification : il porte une référence de
virement rattachée à un séjour.

### 36.7 Taxe de séjour

`TouristTaxCalculator` couvre le cas courant en France : un montant par adulte
et par nuit, plafonné, les mineurs étant exonérés (article L. 2333-31 du code
général des collectivités territoriales). Le moteur versionné complet —
territoire, classification, exemptions, historisation du contexte de calcul
(SPECIFICATIONS.md §63) — arrive à l'itération « France et conformité » et
prendra la place de ce calcul sans changer les appelants : le montant reste un
composant de paiement comme un autre.

## 37. Itération 8 — contrats, documents, courrier entrant

### 37.1 Nouveaux modules

```text
src/Pdf/
├── PdfDocument            générateur PDF : pages, titres, paires, tableaux
├── PdfFont                polices standard et métriques de largeur
└── WinAnsi                conversion UTF-8 → WinAnsiEncoding

src/Contract/
├── ContractBuilder        rend le contrat depuis le séjour et les réglages
├── ContractService        instantané, acceptation, contrôle d'intégrité
├── ContractAcceptance     trace d'acceptation
└── ContractRepository     persistance des acceptations

src/Document/
├── DocumentKind           contrat, contrat signé, reçu, justificatif…
├── DocumentSource         généré, déposé, reçu par e-mail
├── Document               document rattaché à un séjour
├── DocumentRepository     persistance et recherche par empreinte
└── DocumentService        stockage hors document root, type réel, suppression

src/Imap/
├── ImapProvider           frontière de récupération du courrier
├── ImapClient             client IMAP sur socket, sans ext-imap
├── FakeImapProvider       boîte factice, activée par variable d'environnement
├── MimeParser             analyse défensive des messages reçus
├── MimeMessage            message analysé
├── ReplyToken             adresse de réponse signée, étiquetée par séjour
├── LinkMethod             comment un message a été rattaché
├── InboundMailRepository  persistance du courrier entrant
└── InboundMailService     relève, rattachement, pièces jointes → documents

src/Diagnostics/
└── MailboxDiagnostics     contrôles de la boîte de réception
```

### 37.2 Un PDF écrit sur place

Le contrat est un PDF produit par l'application : l'hébergement mutualisé visé
n'a ni Composer ni binaire externe, et le contenu d'un contrat n'a rien à
faire chez un tiers.

`PdfDocument` s'en tient aux polices **standard** du format, en
`WinAnsiEncoding` : rien n'est embarqué, les fichiers pèsent quelques
kilo-octets, et le codage couvre l'intégralité des lettres du français, du
néerlandais, de l'allemand et de l'anglais, plus l'euro et les guillemets. Un
caractère hors table est translittéré plutôt que perdu : un contrat où « œ »
deviendrait un carré vide serait pire qu'un contrat où il devient « oe ».

La sortie est déterministe : deux générations des mêmes données donnent le
même fichier, sans quoi aucune empreinte ne serait stable.

### 37.3 Le contrat est un instantané

`ContractService::contractFor()` génère le contrat au premier appel puis
renvoie toujours le même document. Un changement de tarif, de nom du logement
ou de modèle ne réécrit jamais un contrat existant (SPECIFICATIONS.md §39).
Une régénération explicite produit un **nouveau** document et laisse
l'ancien lisible tel quel.

L'acceptation enregistre la version du modèle, la langue, l'utilisateur, la
date et l'**empreinte du PDF accepté** (SPECIFICATIONS.md §40). Rejouer
l'historique d'un séjour redonne donc ce que le client a lu, et une
substitution du fichier se voit immédiatement. L'adresse IP n'est conservée
que sous forme d'empreinte : elle suffit à recouper deux traces sans conserver
la donnée elle-même.

### 37.4 Documents

Aucun document ne vit sous le document root : chaque octet est servi par
l'application, après vérification que le demandeur est bien le titulaire du
séjour. Trois règles complètent cela :

- le **nom d'origine** est conservé pour l'affichage mais jamais utilisé comme
  nom de fichier ; le chemin sur disque est dérivé de l'empreinte SHA-256 et
  réparti en sous-répertoires ;
- le **type MIME** est déduit du contenu réel, jamais de l'extension ni de ce
  que l'expéditeur annonce ; la liste des types acceptés est fermée ;
- le chemin lu en base est **confronté à la racine du stockage** avant toute
  lecture, de sorte qu'une valeur corrompue ne fasse jamais lire un fichier
  arbitraire.

Un même fichier reçu deux fois ne crée qu'un document : l'empreinte le
reconnaît.

### 37.5 Courrier entrant

`ext-imap` est absente de la plupart des hébergements mutualisés visés et
n'est plus maintenue dans le cœur de PHP : le protocole est parlé directement
sur socket, comme l'est déjà SMTP. Pas d'IDLE : la relève est périodique
(SPECIFICATIONS.md §36), reprend au dernier UID importé, et repart de zéro si
le serveur a changé d'`UIDVALIDITY`.

Un point mérite l'attention : `UID n:*` renvoie toujours au moins un message,
même quand tous sont plus anciens que la borne. Le client filtre donc
lui-même, sans quoi il réimporterait le dernier message à chaque passage.

L'analyse MIME est défensive — profondeur d'imbrication bornée, nombre de
parties borné, jeu de caractères jamais cru sur parole — parce qu'un message
reçu vient d'Internet. Le HTML est nettoyé **avant** d'être stocké.

### 37.6 Rattachement au séjour

Quatre règles, appliquées dans l'ordre de leur solidité
(SPECIFICATIONS.md §36) :

| Rang | Règle | Ce qu'elle vaut |
|---|---|---|
| 1 | jeton signé dans l'adresse de réponse | signature HMAC : infalsifiable |
| 2 | en-têtes de fil citant un `Message-ID` émis | difficile à forger de bout en bout |
| 3 | référence citée dans le sujet ou le corps | n'importe qui peut l'écrire |
| 4 | adresse de l'expéditeur, si elle ne désigne qu'un séjour | ambiguë par nature |

Les e-mails sortants liés à un séjour annoncent un `Reply-To` de la forme
`boite+SS-2026-0001.a1b2c3d4@domaine`. Le suffixe est un HMAC tronqué de la
référence : sans lui, connaître ou deviner une référence suffirait à faire
rattacher n'importe quel courrier au séjour de quelqu'un d'autre. La clé est
dérivée de la clé de chiffrement de l'installation : aucun secret
supplémentaire à gérer, et deux installations ne signent jamais pareil.

Toute pièce jointe d'un message rattaché devient un document du séjour, avec
un classement proposé d'après son nom et le sujet (SPECIFICATIONS.md §38). Une
image intégrée au corps HTML n'en est pas une : elle est écartée.

## 38. Itération 9 — responsable local et opérations

### 38.1 Nouveaux modules

```text
src/Calendar/
├── IcsCalendar             génération iCalendar : pliage, échappement, dates
├── CalendarScope           administration, responsable, voyageur
├── CalendarToken           jeton d'accès à un flux
├── CalendarTokenRepository délivrance, révocation, dernière utilisation
└── CalendarService         construction des flux et résolution du responsable

src/Operations/
├── TaskPhase               avant le séjour, au départ
├── ChecklistItem           une ligne, dérivée ou cochée
├── ChecklistService        checklists d'un séjour
├── TaskRepository          tâches cochées par un humain
└── TodoService             tableau « À faire »
```

### 38.2 Une checklist lit, elle ne recopie pas

Les lignes de checklist sont de deux natures (SPECIFICATIONS.md §49) :

- les **dérivées** — contrat accepté, acompte encaissé, solde encaissé,
  caution reçue, responsable affecté — sont lues là où elles vivent : dans
  l'acceptation du contrat, dans l'échéancier, dans l'affectation. Elles ne
  sont jamais stockées : deux copies d'une même vérité finissent toujours par
  diverger, et une checklist qui prétendrait qu'un acompte est encaissé alors
  qu'il ne l'est pas serait pire que pas de checklist du tout ;
- les **manuelles** — ménage planifié, accès transmis, état des lieux réalisé —
  n'existent nulle part ailleurs et vivent dans `booking_task`, cochées par un
  humain, avec l'auteur et l'horodatage.

Une ligne sans objet — pas de caution demandée — n'est pas « en retard » : elle
ne concerne simplement pas ce séjour, et ne compte donc pas dans la
progression.

Seuls les codes déclarés sont acceptés : `ChecklistService::toggle()` refuse un
code inventé plutôt que d'écrire n'importe quoi en base.

### 38.3 Responsable local

Un séjour porte un `manager_id` facultatif ; à défaut, le responsable par
défaut de l'installation s'applique (SPECIFICATIONS.md §48). La résolution est
faite en un seul endroit, `CalendarService::managerOf()`, de sorte que la
fiche du séjour, la checklist et le flux ICS du voyageur donnent tous la même
réponse.

Seul un compte **opérationnel** peut être responsable : affecter un client lui
donnerait une visibilité qu'il n'a pas. Supprimer un compte n'emporte pas les
séjours, seulement l'affectation (`ON DELETE SET NULL`), et le responsable par
défaut reprend la main.

### 38.4 Flux ICS et jetons

Un agenda tiers ne présentera jamais de session : l'accès repose entièrement
sur un jeton porté par l'URL. Trois conséquences :

- le jeton fait 32 octets, n'est stocké que **haché**, et n'est montré qu'une
  fois — le régénérer révoque le précédent, ce qui est exactement ce qu'on
  attend d'un lien partagé par erreur ;
- une révocation coupe l'accès **immédiatement**, sans période de grâce ; un
  jeton inconnu et un jeton révoqué se présentent tous deux comme une adresse
  qui n'existe pas ;
- la réponse porte `Cache-Control: private, no-store` et
  `X-Robots-Tag: noindex, nofollow` : une adresse porteuse de jeton n'a rien à
  faire dans un cache partagé ni dans un index.

Chaque portée montre exactement ce dont son destinataire a besoin : le flux du
responsable nomme le voyageur mais ne porte aucun montant, celui du voyageur
se limite à son séjour et porte le contact du responsable
(SPECIFICATIONS.md §51).

### 38.5 La convention de date qui compte

`DTEND` d'un événement « toute la journée » est **exclusif**. Un départ le
11 juillet se déclare `DTEND;VALUE=DATE:20260711` : le 11 reste libre, et une
arrivée le même jour ne paraît pas impossible. C'est la même convention que
`DateRange` applique depuis l'itération 5 — arrivée incluse, départ exclu —
et l'aligner évite qu'un agenda et un calendrier de disponibilités se
contredisent.

Le pliage des lignes à 75 **octets** se fait caractère par caractère : couper
au milieu d'un caractère UTF-8 produirait un flux illisible.

### 38.6 Tableau « À faire »

`TodoService` ne liste que ce qui réclame une décision humaine : une demande à
valider, une échéance dépassée, une caution à restituer, un courrier qu'aucune
règle n'a su rattacher, un séjour proche dont la préparation traîne, une
migration en attente. Un tableau qui listerait tout ce qui existe ne serait
plus lu.

Le tableau de bord et la page d'exploitation affichent la **même** liste,
produite par le même service : deux listes concurrentes auraient fini par se
contredire.

## 39. Itération 10 — mon séjour et invités

### 39.1 Nouveaux modules

```text
src/Stay/
├── StayPhase             avant, arrivée, pendant, départ, après
├── StayInfoBlock         un bloc du livret, dans une langue
├── StayInfoRepository    livret d'accueil, un enregistrement par bloc et langue
├── StaySecretRepository  codes d'accès, chiffrés au repos
├── GuestLink             lien invité
├── GuestLinkRepository   délivrance, expiration, révocation
├── StayView              modèle d'affichage, limité à ce qui a le droit de sortir
└── StayService           « Mon séjour aujourd'hui » et liens invité
```

### 39.2 La phase n'est pas stockée

`StayPhase::of()` déduit la phase des dates du séjour et du jour courant, dans
le fuseau du logement. Une phase recopiée en base serait fausse dès le
lendemain, et il faudrait une tâche planifiée pour la corriger — pour une
information que deux comparaisons de chaînes donnent exactement.

Le jour d'arrivée et le jour de départ ont leur propre phase : ce sont les
deux moments où le voyageur a besoin d'autre chose que pendant le séjour.

### 39.3 Les codes d'accès ont une fenêtre

Un code de boîte à clés publié un mois à l'avance, ou resté lisible après le
départ, n'est plus un code d'accès. `StayService` ne les fait donc sortir que
si `StayPhase::isOnSite()` — arrivée, séjour, départ. Hors de cette fenêtre, la
page affiche qu'ils apparaîtront le jour de l'arrivée, et le modèle
d'affichage ne les porte tout simplement pas : un gabarit ne peut pas les
révéler par inadvertance.

Ils sont chiffrés au repos avec le même mécanisme que les secrets de
l'installation. Une valeur devenue illisible — clé rotée sans re-chiffrement —
ne fait pas tomber la page : elle est simplement absente.

### 39.4 Ce que porte le modèle d'affichage

`StayView` ne transporte que le séjour, sa phase, les blocs du livret, les
codes autorisés, le contact du responsable et les horaires. Aucun montant,
aucun document, aucune coordonnée d'un autre voyageur. C'est la raison pour
laquelle cette page — et elle seule — peut vivre hors ligne.

### 39.5 Liens invité

Un lien invité (SPECIFICATIONS.md §46) ouvre exactement la même page, en mode
invité : sans référence de séjour, sans partage, et sans aucun accès au reste
du produit. Il expire deux jours après le départ, se révoque, et son jeton
n'est stocké que haché. L'expiration est évaluée **en base** : une horloge
d'appareil faussée ne peut pas prolonger un lien.

Le QR code du lien est rendu **en ligne** dans la page, pas servi par une
seconde requête : le jeton n'apparaît ainsi dans aucune URL d'image, et il n'y
a rien à mettre en cache par erreur (SPECIFICATIONS.md §47).

### 39.6 Hors ligne : ce qui est permis, ce qui ne l'est pas

La spécification (§44) est explicite, et le service worker l'applique :

| Autorisé hors ligne | Interdit hors ligne |
|---|---|
| livret d'accueil, Wi-Fi, règles, déchets, sécurité, contact local | paiements, écriture de réservation, documents |

Concrètement, `/booking/`, `/payment/`, `/document/` et `/calendar/` ont
rejoint la liste des chemins jamais mis en cache — la fiche de réservation
porte désormais l'échéancier et les documents, elle n'a donc plus sa place
dans un cache d'appareil. C'est « Mon séjour » qui joue ce rôle.

Les pages de séjour suivent une stratégie **réseau d'abord, avec un délai
court**. Servir le cache d'emblée afficherait une page périmée juste après une
action — créer un lien invité et ne pas le voir apparaître. Attendre
l'expiration complète d'une requête serait tout aussi mauvais pour le voyageur
qui cherche le code de la boîte à clés avec une barre de réseau. Trois
secondes tranchent les deux cas.

## 40. Itération 11 — états des lieux et incidents

### 40.1 Nouveaux modules

```text
src/Inspection/
├── InspectionKind          arrivée ou départ, et ce que chacun exige
├── InspectionStatus        en cours, terminé
├── EntryState              à vérifier, conforme, anomalie
├── Zone                    une zone du logement, dans une langue
├── ZoneRepository          zones, libellés localisés, photos de référence
├── InspectionEntry         constat d'une zone, avec ses photos
├── Inspection              état des lieux d'un séjour
├── InspectionRepository    persistance, ouverture idempotente
└── InspectionService       ouverture, constats, photos, clôture, incidents

src/Incident/
├── IncidentSeverity        mineur, normal, urgent
├── IncidentStatus          signalé, pris en charge, résolu
├── IncidentEvent           une ligne d'historique
├── Incident                le ticket
├── IncidentRepository      persistance et historique en ajout seul
└── IncidentService         cycle de vie, affectation, photos, alertes
```

### 40.2 Deux moments, deux exigences

La spécification (§53) distingue nettement l'arrivée du départ, et le code
suit cette distinction plutôt que de la diluer :

| | Arrivée | Départ |
|---|---|---|
| Ce que fait le voyageur | il **signale** | il **prouve** |
| Photos | facultatives partout | obligatoires sur les zones requises |
| Clôture | dès que chaque zone est tranchée | seulement une fois les photos là |

`InspectionKind::requiresPhotos()` porte cette différence à elle seule ;
`Inspection::blocking()` en déduit ce qui reste à faire, et
`InspectionService::complete()` refuse tant que la liste n'est pas vide.

Un voyageur qui arrive à 23 h ne doit pas être bloqué par une photo : à
l'arrivée, ne rien signaler vaut « conforme ». Au départ, la photo est la
seule preuve dont disposeront les deux parties au moment de discuter de la
caution : elle n'est donc pas négociable.

### 40.3 Les zones décrivent **ce** logement

Rien de ce qui décrit le logement n'est figé dans le code : les zones, leur
ordre, leurs consignes, leur activation et l'obligation de photo vivent en
base. `ZoneRepository::DEFAULTS` n'est qu'un point de départ — un parcours
réel plutôt qu'une page vide — amorcé une seule fois à l'installation et
jamais réappliqué par-dessus une configuration existante.

Le **code** d'une zone est un identifiant technique stable, indépendant de la
langue ; le **nom** est traduisible, une ligne par langue. Une zone sans nom
propre retombe sur le libellé intégré, déjà disponible en FR/EN/NL/DE : une
installation neuve est donc immédiatement utilisable dans les quatre langues.

L'unicité `(booking_id, kind)` est portée par la base. Deux ouvertures
simultanées — le voyageur sur son téléphone, le responsable sur le sien — ne
peuvent pas produire deux états des lieux. Une zone ajoutée après l'ouverture
apparaît au passage suivant, tant que l'état des lieux est ouvert ; un état
des lieux clos, lui, ne bouge plus : c'est une preuve.

### 40.4 De l'anomalie à l'incident

Une anomalie constatée peut devenir un incident en un geste. Le lien est fait
une fois, dans `InspectionService::raiseIncident()`, et il refuse d'ouvrir un
incident sur une zone déclarée conforme : les deux informations se
contrediraient.

Un incident (§54) porte réservation, zone, urgence, description, photos,
statut et historique. Les transitions sont nommées — signalé → pris en charge
→ résolu, et une réouverture explicite — plutôt que libres : un champ de
formulaire ne doit pas pouvoir renvoyer un incident résolu à l'état « jamais
lu ». L'historique est en **ajout seul** ; il ne prouverait plus rien
autrement.

Seul un incident **urgent** prévient immédiatement les rôles opérationnels.
Les autres attendent le tableau « À faire », qui les compte : une alerte qui
sonne pour tout ne fait plus réagir à rien.

### 40.5 Photos et cache

Une photo d'état des lieux ou d'incident est un document ordinaire : même
stockage hors document root, même type déduit du contenu, même contrôle
d'accès au téléchargement. Seule la nature change — `inventory` pour un
constat, visible par le voyageur ; `incident` pour un ticket, qui ne l'est
pas.

Les pages d'état des lieux et d'incident **écrivent**. Elles ont donc rejoint
la liste des chemins jamais mis en cache : servir une version en cache y
afficherait un constat périmé, ou ferait croire qu'une photo est partie alors
qu'elle est restée sur le téléphone.

## 41. Itération 12 — France et conformité

### 41.1 Nouveaux modules

```text
src/Legal/
├── LegalDocumentType         conditions, confidentialité, règlement
├── LegalDocument             une version publiée, dans une langue
├── LegalDocumentRepository   publication et lecture des versions
├── BookingConsent            ce qu'un séjour a accepté
├── BookingConsentRepository  preuve d'acceptation, en ajout seul
└── LegalService              publication, version en vigueur, consentement

src/Compliance/
├── ComplianceTopic           les dix-huit sujets de la spécification
├── ComplianceStatus          conforme, à vérifier, non applicable
├── ComplianceItem            l'état d'un sujet pour ce logement
├── ComplianceRepository      persistance, sujets jamais saisis compris
└── ComplianceService         saisie, échéances, synthèse

src/Tax/
├── TouristTaxRule            un barème et sa période de validité
├── TouristTaxRuleRepository  règles datées, détection des recouvrements
├── TouristTaxContextRepository  contexte figé avec le séjour
└── TouristTaxCalculator      moteur versionné

src/Police/
├── PoliceRecord              la fiche, une fois déchiffrée
├── PoliceRecordRepository    registre chiffré, purge en base
└── PoliceRecordService       activation, validation, rétention

src/Privacy/
└── RetentionService          durées de conservation, purge, audit
```

### 41.2 Un texte accepté est un instantané

On ne peut opposer à quelqu'un que ce qu'il a réellement lu. Le texte légal
continue de vivre là où le propriétaire l'écrit — dans les pages éditoriales —
mais **publier une version** en prend une photo : corps, titre, empreinte
SHA-256, langue, date. Éditer la page ensuite ne réécrit aucune version déjà
publiée, et republier le même numéro est refusé.

Une acceptation enregistre alors quatre choses : le type de texte, la
**version**, la **langue** et l'empreinte. La langue retenue est celle de la
page où la case a été cochée, pas celle du séjour : c'est ce texte-là que le
voyageur a lu. L'adresse IP n'est conservée que hachée, comme pour
l'acceptation d'un contrat.

La publication est faite pour **toutes les langues à la fois**. Publier langue
par langue produirait des « version 3 » qui n'existent qu'en français, et un
voyageur néerlandais accepterait alors une version fantôme.

L'installation publie une version initiale : le produit n'est jamais livré sans
texte opposable, et une réservation faite le premier jour conserve donc une
version et une langue.

### 41.3 L'assistant ne conseille pas, il date

Pour chacun des dix-huit sujets (SPECIFICATIONS.md §62), le produit fournit
**du texte traduit** : définition, applicabilité, où trouver l'information,
impact. Ce qui est propre à ce logement — statut, valeur, source officielle,
date de vérification, échéance — est saisi par le propriétaire.

La séparation est volontaire et visible à l'écran : le produit ne prétend
jamais savoir si une situation est conforme. Il aide à le vérifier, garde la
source, et rappelle l'échéance. Un avertissement le dit en toutes lettres.

Trois garde-fous :

- une « source officielle » doit être une adresse web consultable ; le reste
  serait un souvenir, pas une source ;
- un sujet déclaré conforme sans date de vérification reçoit celle du jour :
  une conformité non datée n'est pas vérifiable ;
- un sujet « non applicable » ne se périme pas — il n'y a rien à revoir — mais
  un sujet conforme dont la revue est dépassée redevient une action.

Trois sujets sont pilotés ailleurs — taxe de séjour, fiche de police, contrat —
et l'assistant y renvoie plutôt que de demander une saisie qui existerait alors
deux fois.

### 41.4 Un barème est daté

Un barème de taxe de séjour est voté, prend effet à une date, puis est
remplacé. `TouristTaxRule` porte donc sa période de validité, et
`TouristTaxRuleRepository::applicableOn()` choisit celle qui s'appliquait à la
date d'arrivée, pour le classement du logement.

Le contexte de calcul est **figé avec le séjour** au moment de la réservation :
territoire, classement, période, barème, adultes, exonérés, nuits, plafond,
total. Un barème voté ensuite — même avec effet rétroactif — ne change plus le
montant d'une réservation déjà engagée, et la fiche du séjour reste capable
d'expliquer son calcul des années plus tard.

Sans règle datée saisie, la configuration tient lieu de barème courant : une
petite installation fonctionne sans jamais ouvrir cet écran.

Deux barèmes qui se recouvrent ne sont pas refusés — un barème peut être
corrigé — mais ils sont **signalés** : sans cela, le montant dépendrait de
l'ordre des lignes.

### 41.5 Ce qu'on ne collecte pas

La fiche de police n'est exigée que dans certains cas. Tant que
`compliance.police_record_enabled` est faux, le produit ne propose rien, la
route de saisie répond 404, et aucune ligne n'existe. Collecter « au cas où »
une identité, une date de naissance et un domicile serait l'inverse de la
minimisation.

Activée, la fiche est chiffrée au repos avec le contexte `police:record`, ne
comporte que les champs du formulaire réglementaire, et porte sa propre date de
purge — comptée depuis le **départ**, parce que compter depuis la création
commencerait à courir avant le séjour.

### 41.6 Rétention

`RetentionService` applique en un seul endroit les durées configurées :
journaux, journal des notifications, sessions et jetons expirés, liens invité
caducs, liste d'attente passée, notifications de paiement, compteurs de
limitation, fiches de police échues. La purge est elle-même auditée : effacer
sans trace serait un trou dans la piste d'audit.

Ce qui n'est **pas** purgé mérite d'être dit : séjours, paiements, contrats
acceptés, états des lieux et consentements sont des pièces contractuelles. Les
effacer automatiquement priverait les deux parties de leur preuve ; leur
suppression reste une décision humaine.

## 42. Itération 13 — contenu local généré

### 42.1 Nouveaux modules

```text
src/Llm/
├── LlmProvider            frontière vers un modèle de langage
├── LlmPrompt              consigne système, message, schéma attendu
├── LlmResult              réponse décodée, ou raison de l'échec
├── AnthropicLlmProvider   API Messages, appelée à travers `HttpFetcher`
├── FakeLlmProvider        lit réellement les sources du prompt (tests)
└── NullLlmProvider        aucun modèle : rien n'est produit

src/LocalContent/
├── LocalSource            une URL consultée
├── SourceDocument         une page réduite à son texte
├── PageExtractor          HTML → texte borné, sans balise
├── ActivitySchema         schéma imposé et revalidé
├── PromptBuilder          prompt gardé : système + message + sources
├── LocalActivity          une activité, ses dates, sa source
├── LocalContentRepository sources, exécutions, activités
└── LocalContentService    le pipeline

src/Http/
└── FixtureHttpFetcher     pages de test sur disque, délègue le reste
```

### 42.2 Pourquoi un appel HTTP direct plutôt qu'un SDK

Tout ce qui sort de l'application passe par `HttpFetcher`, donc par le garde
SSRF (§3, SECURITY.md §16). Un client HTTP embarqué par une bibliothèque tierce
contournerait ce point de passage unique — précisément ce que la spécification
demande de tenir. S'ajoute la contrainte d'hébergement : le ZIP embarque
`vendor/` et s'installe par FTP, chaque dépendance est du poids transféré à
chaque mise à jour.

L'appel suit la documentation de l'API Messages : version d'API dans l'en-tête,
sortie contrainte par `output_config.format`, refus de sécurité reconnu comme
tel plutôt que confondu avec une panne.

### 42.3 Le web est une donnée, jamais une consigne

C'est la propriété de sécurité centrale de cette itération, et elle tient en
trois gestes qui se renforcent :

1. **l'extraction** réduit la page à du texte : scripts, styles, commentaires et
   balises disparaissent avant d'approcher le prompt. Une instruction cachée
   dans un `<script>` n'existe déjà plus ;
2. **les marqueurs** enferment ce qui reste entre `[SOURCE n]` et
   `[FIN SOURCE n]`, et la consigne système déclare explicitement que ce
   contenu est une donnée. Une page qui écrirait « ignore les consignes
   précédentes » reste une page qui contient cette phrase ;
3. **la validation** ne fait confiance à rien : une activité dont la source
   n'est pas une des URL réellement consultées est écartée, comme une activité
   sans titre ou aux dates impossibles. Le modèle propose, la validation
   dispose.

### 42.4 Rien de personnel ne sort

Le prompt contient un lieu, une saison, des dates et du texte public. Pas de
nom, pas d'adresse e-mail, pas de téléphone, pas même la référence du séjour —
et un test le vérifie explicitement. La localisation elle-même s'arrête au code
postal et à la ville : il n'y a aucune raison d'envoyer un numéro de rue pour
trouver un marché.

### 42.5 Fenêtre et fraîcheur

La génération commence quelques semaines avant l'arrivée (cinq par défaut,
configurable) et se rafraîchit chaque semaine jusqu'au séjour
(SPECIFICATIONS.md §57). Une génération **remplace** la précédente pour le
couple séjour + langue : accumuler produirait des doublons à chaque
rafraîchissement.

L'affichage, lui, ne montre que les activités dont les dates recouvrent celles
du séjour, bornes incluses (§58) : un marché la veille de l'arrivée est stocké
mais jamais affiché. Deux groupes seulement — à réserver à l'avance, à faire
pendant le séjour — et chaque activité porte sa source et sa date de
vérification.

### 42.6 Tester sans réseau ni clé

Deux pièces rendent le pipeline entièrement jouable hors ligne :

- `FakeLlmProvider` ne renvoie pas une réponse figée : il **lit** les sources
  placées dans le prompt et en extrait les activités. Un test qui passe prouve
  donc que la page a bien traversé la chaîne ;
- `FixtureHttpFetcher` sert des pages depuis le disque et **délègue tout le
  reste** au fetcher réel. Ce qui n'a pas de fixture part vraiment, garde SSRF
  compris : une source pointant vers le réseau interne est refusée pendant le
  test exactement comme en production.

Les deux ne s'activent que par variable d'environnement, comme les autres
fournisseurs factices du produit.

## 43. Itération 14 — calendriers externes, reporting, litiges, quotas

### 43.1 Nouveaux modules

```text
src/Calendar/
├── IcsParser                 lecteur RFC 5545, écrit sans le générateur
├── ExternalCalendar          un flux déclaré, son état, sa plateforme
├── ExternalCalendarRepository
└── ExternalCalendarService   synchronisation d'un flux, ou de tous

src/Reporting/
├── ReportPeriod              un mois, ou une année
├── Report                    montants, nuits, taux, séjours
├── ReportService             agrégation, années disponibles, classeur
└── XlsxWriter                XLSX en PHP pur, déterministe

src/Dispute/
├── DisputeStatus             ouvert → discussion → résolu, et retour
├── Dispute                   nature, montants, résumé, résolution
├── DisputeEvent              historique en ajout seul
├── DisputeRepository
└── DisputeService            garde-fous et pièces au dossier

src/Quota/
└── QuotaService              mesure et refus avant écriture
```

### 43.2 Un blocage garde sa provenance

`availability_block` porte désormais `source_id` et `external_uid`. Ce n'est pas
une commodité d'affichage : c'est ce qui rend l'import **réversible** et sans
effet de bord.

1. **un flux ne réserve pas, il bloque.** Un événement distant devient une
   indisponibilité, jamais un séjour. Confondre les deux ferait apparaître des
   clients qui n'existent pas, avec des montants qui n'ont pas été convenus ;
2. **une synchronisation ne touche que ses propres lignes.**
   `replaceForSource()` supprime dans une transaction les seules lignes portant
   ce `source_id`, puis réécrit ce que le flux publie aujourd'hui. Ce que le
   propriétaire a bloqué à la main ne peut pas disparaître parce qu'une
   plateforme a changé d'avis ;
3. **un flux muet ne libère rien.** Erreur réseau, code HTTP autre que 200, ou
   corps qui n'est pas un calendrier : l'état est enregistré, les blocages
   restent. Rendre disponibles des nuits déjà vendues ailleurs serait le pire
   résultat possible — pire qu'un calendrier périmé ;
4. **supprimer le flux emporte ses blocages** (`ON DELETE CASCADE`) : sans leur
   source, ils deviendraient des indisponibilités sans provenance, que plus
   personne ne saurait justifier.

Un calendrier vide mais **valide** est, lui, une information : la plateforme
dit que plus rien n'est vendu, et les nuits sont bien libérées. C'est la
différence entre « je ne sais pas » et « je sais qu'il n'y a rien », et le
service la fait explicitement (`looksLikeCalendar()`).

### 43.3 Lire l'ICS sans le code qui l'écrit

`IcsParser` est écrit indépendamment de `IcsCalendar`, comme `QrDecoder`,
`PdfReader` et `IcsReader` le sont de leurs générateurs (TESTING.md §12). Le
lecteur d'import est du **code de production**, mais la règle vaut aussi :
réutiliser le générateur reproduirait ses erreurs.

Ce qui est lu est volontairement minimal — identifiant, début, fin, résumé — et
borné : 2 Mo, 2000 événements. Les conventions sont celles des plateformes :
`DTEND` exclusif, horodatage ramené au jour (une heure ne change pas la nuit
occupée), UID dérivé du contenu quand le flux n'en publie pas.

### 43.4 Le reporting compte, il ne conseille pas

`Report` porte des montants en centimes et rien d'autre. Trois séparations
comptent :

- **la caution n'est pas un revenu** : elle est détenue, puis rendue ou
  partiellement retenue. Elle a sa propre ligne ;
- **la taxe de séjour est encaissée pour le compte de la collectivité** : elle
  est comptée à part, jamais dans le revenu ;
- **le prix moyen de la nuit** porte sur l'hébergement rapporté aux nuits
  vendues. Y mêler ménage, taxe ou caution donnerait un chiffre qu'on ne peut
  comparer d'un mois à l'autre.

Les nuits sont comptées **nuit par nuit** : un séjour à cheval sur deux mois
compte dans les deux, pour ce qu'il y occupe. La nuit du départ ne compte pas.

L'avertissement — ces chiffres ne sont ni un conseil fiscal ni une déclaration
— est affiché à l'écran **et** écrit dans le classeur exporté : un fichier
transmis à un tiers doit porter sa propre mise en garde.

### 43.5 XLSX en PHP pur

Un `.xlsx` est une archive ZIP de documents XML : `ext-zip` suffit, et le
produit n'ajoute aucune dépendance — même contrainte d'hébergement mutualisé
que pour le PDF et le QR (§26, §30).

L'écriture est **déterministe** : mêmes données, mêmes octets. Deux exports du
même mois se comparent alors avec `diff`, ce qu'un classeur horodaté
interdirait. Les montants sont écrits en unités monétaires avec un format
numérique, jamais en chaînes « 145,50 € » qu'il faudrait reconvertir.

Côté test, `tests/php/Support/XlsxReader.php` relit l'archive comme le ferait
un tableur — relations du classeur, ordre des feuilles, cellules résolues par
leur référence — sans partager une ligne avec l'écrivain.

### 43.6 Un litige rassemble, il n'invente pas

`DisputeService::evidenceFor()` agrège ce que le produit a **déjà** collecté :
caution réellement détenue, état des lieux de départ et ses anomalies, photos
versées, incidents enregistrés, contrat accepté. La discussion s'appuie sur des
faits datés plutôt que sur des souvenirs.

Deux garde-fous refusent ce qui n'aurait pas de sens :

- la retenue réclamée ne peut pas dépasser la caution détenue — réclamer plus
  que ce que l'on tient n'est pas une réclamation ;
- clore exige un montant réglé compris entre zéro et le montant réclamé **et**
  une explication. Un litige « résolu » sans dire comment ne vaut pas mieux
  qu'un litige ouvert. Rouvrir efface la résolution : la garder ferait croire à
  un dossier clos.

Un séjour porte au plus un litige par nature (contrainte d'unicité) : la
seconde ouverture n'écrase pas la première, elle est refusée.

### 43.7 Quotas : refuser avant, pas après

Un disque plein casse tout, y compris la sauvegarde qui aurait permis de s'en
sortir. `QuotaService` mesure quatre catégories — médias, documents,
sauvegardes, pièces jointes — et `DocumentService` comme `MediaService`
l'interrogent **avant** d'écrire.

La mesure est faite à la demande plutôt qu'entretenue en continu : parcourir
quelques milliers de fichiers coûte moins cher qu'un compteur qu'il faudrait
tenir à jour à chaque écriture et qui finirait par mentir.

Un quota à zéro signifie « pas de limite » : c'est la configuration par défaut,
et le produit ne doit rien empêcher tant que le propriétaire n'a rien décidé.
Au-delà de 80 %, l'écran prévient sans encore refuser.

Libérer de la place est le remède annoncé : la suppression d'un document est
donc offerte dans l'interface, et le fichier — nommé par son empreinte, donc
partageable entre séjours — n'est effacé qu'une fois réellement orphelin.

### 43.8 Purge consolidée

`RetentionService` applique désormais aussi la rétention des indisponibilités
passées (deux ans). Un blocage échu ne prouve rien et n'explique aucun montant
— le prix payé est figé sur le séjour —, alors qu'il alourdit le calendrier et
le disque. Ce qui reste hors purge automatique n'a pas changé : séjours,
paiements, contrats acceptés et états des lieux sont des pièces contractuelles.
