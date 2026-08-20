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

### Disponibilités et tarifs

Le calendrier public affiche le **prix réel de chaque nuit** et l'état de
chacune. Sélectionner une arrivée puis un départ affiche le total en direct,
calculé par le serveur : c'est exactement le montant qui sera facturé.

Le calcul est fait nuit par nuit. Un séjour à cheval sur deux saisons
additionne les tarifs réels, jamais une moyenne.

```text
/fr/availability    calendrier, règles de séjour, total en direct
/fr/rates           tarif de référence, ménage, acompte, caution, règles
/fr/admin/pricing   tarifs par plage de nuits et indisponibilités
```

Les règles de séjour — durée minimale et maximale, tranches de nuits, jour
d'arrivée imposé ou samedi-samedi, capacité, délai de prévenance, horizon de
réservation — sont configurables et **vérifiées côté serveur**.

## Réservation

Le parcours suit les dates, les voyageurs, le prix, l'authentification, les
informations, les règles puis la confirmation. Dès le récapitulatif, les nuits
sont **réellement tenues** par un verrou temporaire : deux visiteurs ne
peuvent pas remplir le même formulaire pour n'apprendre qu'à la fin qu'un seul
obtient le séjour.

La non-superposition est garantie par la base de données, pas par une
vérification applicative : deux clients simultanés ne peuvent pas obtenir les
mêmes nuits.

```text
/fr/booking            récapitulatif : dates, voyageurs, code promo, total
/fr/booking/finalise   informations et acceptation des règles
/fr/booking/<réf>      détail du séjour et historique
/fr/admin/bookings     suivi, validation et codes promotionnels
```

Si les dates sont déjà prises, le visiteur peut demander à être prévenu
lorsqu'elles se libèrent.

## Notifications

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

## Installation

Une installation SecondStay se fait en copiant l'archive de release à la racine
web (FTP possible), puis en ouvrant le site : l'assistant d'installation prend
la main.

```text
/            → redirige vers /fr/install tant que l'installation n'est pas faite
/fr/install  → prérequis serveur, base de données, premier administrateur,
               logement et langue par défaut
```

L'assistant :

1. vérifie les prérequis (PHP, extensions, droits d'écriture, espace disque) ;
2. teste la connexion à la base sans jamais divulguer les identifiants ;
3. écrit `config/local.php` (permissions `0600`, jamais versionné) contenant les
   identifiants de base et la clé de chiffrement générée ;
4. applique les migrations ;
5. crée le premier administrateur et le connecte ;
6. enregistre les réglages initiaux.

Une fois l'installation terminée, l'assistant renvoie 404 — y compris si la base
devient injoignable, auquel cas le site répond 503. Une panne ne peut jamais
rouvrir l'installation d'une instance existante.

## Site public

L'installation crée un site complet, traduit dans les quatre langues :

```text
/fr/                 accueil éditorial + données structurées LodgingBusiness
/fr/property         le logement
/fr/availability     disponibilités
/fr/rates            tarifs et conditions
/fr/gallery          galerie avec filtres par catégorie et visionneuse
/fr/activities       activités
/fr/access           accès
/fr/contact          contact
/fr/legal-notice     mentions légales
/fr/privacy          confidentialité
/fr/terms            conditions générales
/sitemap.xml         toutes les pages dans les quatre langues
/robots.txt
```

Les segments d'URL sont neutres et stables ; seule la langue varie par le
préfixe. Le menu est construit à partir de l'arborescence des pages et accepte
plusieurs niveaux. La présentation s'adapte à la saison configurée
(automatique, été ou hiver).

## Administration

```text
/fr/admin              tableau de bord et « À faire »
/fr/admin/content      pages éditoriales, traductions FR/EN/NL/DE, publication
/fr/admin/media        galerie : téléversement, catégories, légendes, saison
/fr/admin/settings     réglages typés par module, avec aide et validation
/fr/admin/users        plusieurs administrateurs et responsables locaux
/fr/admin/backups      création, vérification, téléchargement, restauration
/fr/admin/updates      vérification et installation des GitHub Releases
/fr/admin/diagnostics  plateforme, stockage, base, chiffrement, exploitation,
                       remise à zéro des compteurs de limitation de débit
/fr/admin/logs         journal technique filtrable et purgeable
/fr/admin/audit        journal des actions sensibles
```

Le mode maintenance ferme le site public (503) tout en laissant l'administration
et les endpoints techniques accessibles.

## Espace client

```text
/fr/account/signup           création de compte (prénom, nom, e-mail, téléphone)
/fr/account/confirm          confirmation de l'adresse e-mail
/fr/account/forgot-password  demande de réinitialisation
/fr/account/reset            nouveau mot de passe
/fr/account                  profil, langue préférée, mot de passe, appareils,
                             clés d'accès, export et suppression RGPD
```

L'inscription envoie un e-mail de confirmation dans la langue choisie. Une
inscription sur une adresse déjà connue produit exactement la même réponse
qu'une inscription neuve : c'est le titulaire réel qui est prévenu.

Les clés d'accès (passkeys) remplacent le mot de passe lorsque le navigateur
les prend en charge et que le site est servi depuis un domaine — une
installation accessible uniquement par adresse IP ne peut pas les proposer.

La suppression de compte anonymise les données identifiantes plutôt que de les
effacer : les obligations comptables et contractuelles restent honorées.

## Envoi d'e-mails

SecondStay embarque son propre client SMTP : ni Composer ni extension
supplémentaire ne sont nécessaires sur l'hébergement. Les réglages
(`/fr/admin/settings`, module « E-mail ») couvrent l'hôte, le port, le
chiffrement (STARTTLS ou TLS implicite), l'authentification et l'adresse
d'expédition. La signature DKIM reste à la charge du fournisseur SMTP.

En développement et en CI, `SECONDSTAY_MAIL_TRANSPORT=fake` remplace l'envoi
réel par un dépôt de messages inspectable : aucun réseau sortant n'est requis
pour tester les parcours de compte.

## Disponibilités et tarifs

Le calendrier public affiche le **prix réel de chaque nuit** et l'état de
chacune. Sélectionner une arrivée puis un départ affiche le total en direct,
calculé par le serveur : c'est exactement le montant qui sera facturé.

Le calcul est fait nuit par nuit. Un séjour à cheval sur deux saisons
additionne les tarifs réels, jamais une moyenne.

```text
/fr/availability    calendrier, règles de séjour, total en direct
/fr/rates           tarif de référence, ménage, acompte, caution, règles
/fr/admin/pricing   tarifs par plage de nuits et indisponibilités
```

Les règles de séjour — durée minimale et maximale, tranches de nuits, jour
d'arrivée imposé ou samedi-samedi, capacité, délai de prévenance, horizon de
réservation — sont configurables et **vérifiées côté serveur**.

## Réservation

Le parcours suit les dates, les voyageurs, le prix, l'authentification, les
informations, les règles puis la confirmation. Dès le récapitulatif, les nuits
sont **réellement tenues** par un verrou temporaire : deux visiteurs ne
peuvent pas remplir le même formulaire pour n'apprendre qu'à la fin qu'un seul
obtient le séjour.

La non-superposition est garantie par la base de données, pas par une
vérification applicative : deux clients simultanés ne peuvent pas obtenir les
mêmes nuits.

```text
/fr/booking            récapitulatif : dates, voyageurs, code promo, total
/fr/booking/finalise   informations et acceptation des règles
/fr/booking/<réf>      détail du séjour et historique
/fr/admin/bookings     suivi, validation et codes promotionnels
```

Si les dates sont déjà prises, le visiteur peut demander à être prévenu
lorsqu'elles se libèrent.

## Paiements

Chaque séjour porte un échéancier explicite : acompte, solde, caution, ménage,
taxe de séjour et ajustements sont des lignes distinctes, avec leur montant,
leur échéance et leur état.

Deux moyens de paiement coexistent :

- **en ligne**, via un fournisseur (Mollie en premier). Un acompte constaté
  chez le fournisseur confirme automatiquement la réservation ;
- **par virement SEPA**, avec un QR code EPC que la banque du voyageur lit
  pour préremplir IBAN, montant et référence. Un virement ne confirme jamais
  seul un séjour : c'est une validation manuelle explicite.

Le QR code est fabriqué localement, sans dépendance supplémentaire ni service
externe : l'hébergement mutualisé visé n'a ni l'un ni l'autre, et une
référence de virement n'a rien à faire chez un tiers.

```text
/fr/payment/<id>/transfer   coordonnées de virement et QR code EPC
/fr/admin/payments          échéances, cautions détenues, notifications reçues
```

Les notifications de paiement sont idempotentes et ne sont jamais crues sur
parole : SecondStay y lit un identifiant, puis relit l'état chez le
fournisseur avant de changer quoi que ce soit.

Sans clé de fournisseur configurée, le paiement en ligne n'est simplement pas
proposé ; le virement et l'encaissement manuel restent disponibles.

## Notifications

Les événements du séjour sont notifiés par **e-mail et notification push**,
indépendamment : si le push est actif, les deux partent, et l'échec de l'un
n'empêche jamais l'autre. Chaque message est rendu dans la langue du compte
destinataire.

Le push est natif : SecondStay implémente Web Push (VAPID et chiffrement de
bout en bout) sans dépendance supplémentaire. L'administrateur génère les clés
depuis `/fr/admin/diagnostics`, puis active les notifications dans les
réglages. Chaque client choisit ses canaux depuis son espace, abonne ses
appareils et peut s'envoyer une notification de vérification.

`/fr/admin/diagnostics` contrôle également SPF, DKIM et DMARC du domaine
d'expédition, et propose une sonde SMTP explicite.

## Application installable

SecondStay s'installe sur iPhone et Android : manifeste localisé dans les
quatre langues, icônes générées à partir du nom du logement, service worker et
mode plein écran.

Hors ligne, seules les pages déjà consultées et les informations pratiques
restent accessibles ; la réservation, le paiement et les documents personnels
exigent une connexion. Le socle mis en cache est récupéré sans cookie : rien
de personnel ne se retrouve dans le cache d'un appareil partagé.

## Hébergement et sécurité

Le dépôt est public. Les données du logement, secrets, médias et fichiers de production ne doivent jamais être publiés.

L’hébergement cible peut exposer physiquement tout le dépôt sous la racine web. La protection `.htaccess` est donc obligatoire et testée en E2E. `src/`, `config/`, `storage/`, `vendor/`, tests, migrations, `.env`, fichiers Composer, docs et secrets ne doivent jamais être directement accessibles.

Voir `SECURITY.md`.

## Développement

### Préparation

```bash
composer install
npm install
npx playwright install --with-deps chromium webkit
cp config/local.php.dist config/local.php          # configuration locale, jamais versionnée
cp scripts/test-env.local.sh.dist scripts/test-env.local.sh   # base de test dédiée
```

### Serveur local

```bash
./scripts/dev-server.sh start      # http://127.0.0.1:8123
./scripts/dev-server.sh stop
```

Le serveur PHP intégré passe par `scripts/router.php`, qui applique la même
politique de chemins privés que le `.htaccess` racine : les tests de sécurité
sont donc représentatifs de la production.

### Validation

Commande locale de référence :

```bash
./scripts/check.sh              # tout (défaut)
./scripts/check.sh --fast       # syntaxe + PHPStan + PHPUnit + i18n
./scripts/check.sh --php
./scripts/check.sh --db
./scripts/check.sh --js
./scripts/check.sh --e2e
./scripts/check.sh --security
```

Elle couvre :

- syntaxe PHP ;
- PHPStan (niveau 8, aucune erreur tolérée) ;
- PHPUnit + couverture Clover ;
- contrôle i18n FR/EN/NL/DE ;
- tests d’intégration base de données ;
- Vitest + couverture LCOV ;
- Playwright (desktop + mobile) ;
- audit Composer ;
- absence de secrets versionnés ;
- conformité de l’artefact de release.

GitHub ajoute CodeQL, Dependabot et SonarCloud. Les mêmes validations sont
utilisables depuis Claude Code sur macOS et déclenchables dans GitHub Actions
depuis mobile.

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

```bash
./scripts/release.sh patch --dry-run   # vérifie toutes les gates sans rien écrire
./scripts/release.sh minor             # publication complète
./scripts/build-release-zip.sh --verify-only   # construit et inspecte le ZIP
```

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
