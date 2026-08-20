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
