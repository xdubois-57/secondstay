# SecondStay — location d’une résidence secondaire en France

SecondStay est une application web PHP/JavaScript dédiée à la location d’une **seule résidence secondaire / maison de vacances en France**.

Repository officiel : `https://github.com/xdubois-57/secondstay`

Le projet vise à simplifier au maximum la vie du propriétaire, des voyageurs et du responsable local, tout en prenant en charge les contraintes opérationnelles, contractuelles, administratives et réglementaires françaises.

## Principes produit

- Une installation = un logement.
- Pas de logique multi-propriété.
- Hébergement cible : mutualisé PHP + MySQL/MariaDB, avec déploiement FTP possible.
- Le serveur de production ne doit nécessiter ni Composer ni npm.
- Les GitHub Releases fournissent un ZIP prêt pour la production avec `vendor/`.
- Les données propres au logement ne sont jamais codées en dur dans le dépôt public.
- Les médias et données persistantes restent locaux à l’hébergement.
- Le logiciel est spécialisé pour la France.
- L’utilisateur ne voit que ce qui lui est utile au bon moment.
- Les fonctions avancées sont activables par configuration.
- Sauvegarde, diagnostic et mise à jour font partie du produit dès le socle.
- Les contenus et interfaces sont conçus **dès le départ** pour quatre langues : français, anglais, néerlandais et allemand.

## Langues obligatoires

Le projet supporte nativement :

- `fr` — Français
- `en` — English
- `nl` — Nederlands
- `de` — Deutsch

Le français est la langue par défaut de l’installation initiale, mais aucune fonctionnalité ne doit supposer qu’une seule langue existe.

Les textes système utilisent des clés de traduction versionnées dans le code. Les contenus éditoriaux sont traduisibles en base de données. Les documents juridiques et contrats sont versionnés par langue.

Voir `I18N.md`.

## Stack cible

- PHP 8.4+ recommandé
- MySQL 8 / MariaDB compatible
- PDO
- Twig
- Bootstrap 5
- JavaScript sans build de production obligatoire
- PHPUnit
- PHPStan
- Vitest
- Playwright
- GitHub Actions
- CodeQL pour les langages supportés
- SonarQube Cloud / SonarCloud
- Composer pour le développement et la construction des releases

## Architecture

```text
public/index.php
    ↓
Router
    ↓
Security / Session / CSRF / RBAC
    ↓
Controller
    ↓
Service
    ↓
Repository
    ↓
PDO / MySQL-MariaDB

Controller
    ↓
Twig
    ↓
Bootstrap 5 + JavaScript
```

Les contrôleurs orchestrent. Les services portent la logique métier. Les repositories portent SQL et utilisent des requêtes préparées.

Voir `ARCHITECTURE.md`.

## Rôles

- Public
- Client identifié
- Responsable local
- Administrateur

Plusieurs administrateurs et plusieurs responsables locaux sont supportés. L’administrateur possède les capacités du responsable local.

## Fonctionnalités cibles

### Site public

Accueil, présentation, galerie, contenus dynamiques, été/hiver, disponibilités, tarifs/règles, pages légales/RGPD, SEO, Open Graph, sitemap, canonical, données structurées, mode clair/sombre automatique et contenu multilingue FR/EN/NL/DE.

### Comptes

Inscription, confirmation e-mail, mots de passe robustes, réinitialisation, passkeys/WebAuthn, gestion des sessions/appareils, export/suppression de compte et protections anti-abus.

### Réservations

Calendrier avec prix journalier, règles configurables, capacité, blocages administratifs, anti-double-booking transactionnel, codes promo, ménage, liste d’attente, historique, timeline et checklists.

### Paiements

Abstraction `PaymentProvider`, Mollie en premier fournisseur, acompte, solde, caution, ménage, taxe de séjour, remboursements, QR EPC SEPA et webhooks idempotents. Aucune donnée carte n’est stockée.

### Notifications

SMTP + Web Push. Si le push est actif, **e-mail et push sont tous deux tentés**, avec journal séparé par canal.

### Messagerie de réservation

Envoi SMTP et synchronisation IMAP d’une boîte dédiée. Rattachement des conversations aux réservations via adresse/token de réponse, `Message-ID`, `In-Reply-To`, `References`, référence de réservation et adresse client.

Les pièces jointes d’un e-mail lié apparaissent automatiquement dans les Documents de la réservation.

DKIM est géré par le fournisseur SMTP par défaut. Le diagnostic vérifie SPF/DKIM/DMARC.

### Documents

Contrats, snapshots immuables, reçus, justificatifs, états des lieux et pièces jointes e-mail avec provenance.

### Séjour

PWA, « Mon séjour aujourd’hui », informations Wi-Fi/accès/déchets/sécurité/contact local, cache offline approprié, liens invités sécurisés et QR physiques.

### États des lieux

Photos de référence, anomalies à l’arrivée, photos obligatoires au départ, commentaires/photos supplémentaires et tickets d’incident. Mobile d’abord.

### France

Assistant conformité propriétaire, taxe de séjour, classement, déclaration/enregistrement, SIRET, résidence principale/secondaire, DPE/changement d’usage selon applicabilité, médiation, assurance, fiche de police si applicable, versioning légal et rétention RGPD.

### Contenu local automatisé

Abstraction LLM, sources par URLs publiques, protections SSRF et prompt injection, aucune donnée personnelle client envoyée, génération avant séjour et affichage uniquement des activités correspondant aux dates exactes du séjour.

## Hébergement et sécurité

Le dépôt est public. Les données du logement, secrets, médias et fichiers de production ne doivent jamais être publiés.

L’hébergement cible peut exposer physiquement tout le dépôt sous la racine web. La protection `.htaccess` est donc obligatoire et testée en E2E. `src/`, `config/`, `storage/`, `vendor/`, tests, migrations, `.env`, fichiers Composer, docs et secrets ne doivent jamais être directement accessibles.

Voir `SECURITY.md`.

## Développement

Commande locale de référence :

```bash
./scripts/check.sh
```

Elle doit couvrir au minimum :

- syntaxe PHP ;
- PHPStan ;
- PHPUnit ;
- tests DB ;
- Vitest ;
- Playwright ;
- audit Composer.

GitHub ajoute CodeQL, Dependabot et SonarCloud. Les mêmes validations doivent pouvoir être utilisées depuis Claude Code sur macOS et être déclenchées dans GitHub Actions depuis mobile.

Voir `TESTING.md`.

## Releases

Une release installable :

- correspond à `VERSION` ;
- passe toutes les gates ;
- produit un ZIP production ;
- inclut `vendor/` optimisé sans dépendances de dev ;
- exclut `storage/`, secrets, tests, `.github`, `node_modules`, couverture et données locales ;
- est publiée en GitHub Release ;
- est installable par l’updater intégré.

Voir `RELEASE.md` et `ROADMAP.md`.

## Documentation de référence

- `AGENTS.md` — règles obligatoires pour les agents de code
- `CLAUDE.md` — point d’entrée Claude Code
- `SPECIFICATIONS.md` — cahier des charges fonctionnel
- `ARCHITECTURE.md` — architecture et frontières
- `SECURITY.md` — exigences sécurité
- `I18N.md` — stratégie multilingue
- `TESTING.md` — stratégie de tests
- `RELEASE.md` — CI et release
- `ROADMAP.md` — découpage en itérations

## Licence

AGPL-3.0-or-later.
