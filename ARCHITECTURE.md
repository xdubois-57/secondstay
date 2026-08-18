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
