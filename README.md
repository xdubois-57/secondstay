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

- PHP 8.2 minimum (`composer.json`), 8.4 recommandé — la CI joue les tests sur
  les deux
- MySQL 8 / MariaDB compatible
- PDO
- Twig
- Bootstrap 5
- JavaScript sans build de production obligatoire
- PHPUnit
- PHPStan
- Vitest
- TypeScript, comme vérificateur du JavaScript (jamais comme étape de build)
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

## Conformité France

La réglementation d'une location saisonnière n'est pas la même d'une commune à
l'autre, et elle change. Le produit ne prétend donc pas savoir à votre place :
il décrit chacun des dix-huit sujets — meublé de tourisme, déclaration, SIRET,
statut, classement, DPE, changement d'usage, taxe de séjour, fiche de police,
contrat, annulation, médiation, assurances, risques locaux, débroussaillement,
équipement hiver, déchets — et vous laisse constater, avec la source officielle
et la date de vérification.

```text
/fr/admin/compliance   assistant conformité et textes légaux versionnés
/fr/admin/tax          barèmes de taxe de séjour, datés
/fr/admin/police       fiches de police et durées de conservation
```

Ce que vous déclarez conforme est daté et reçoit une échéance de revue. Quand
elle arrive à terme, le sujet réapparaît dans « À faire ». Ce ne sont pas des
conseils juridiques : ce sont vos constats, gardés au bon endroit.

### Textes légaux versionnés

Publier une version de vos conditions ou de votre politique de confidentialité
en fige le texte de chaque langue. Une réservation conserve alors la **version**
et la **langue** réellement acceptées : réécrire vos conditions l'an prochain
ne change rien à ce qu'un voyageur a accepté cette année.

### Taxe de séjour

Les barèmes sont datés : territoire, classement, période de validité. Le calcul
d'un séjour est figé au moment de la réservation et reste explicable — par
adulte, par nuit, avec les exonérations et le plafond — même après un
changement de barème.

### Fiche de police et rétention

La fiche individuelle n'est proposée que si vous activez l'obligation. Tant
qu'elle est désactivée, aucune donnée d'identité n'est collectée. Activée, elle
est chiffrée et effacée automatiquement à l'échéance que vous fixez.

Les durées de conservation des journaux, notifications, jetons et liens invité
sont visibles au même endroit, et s'appliquent d'un geste. Les séjours,
paiements, contrats et états des lieux, eux, ne sont jamais purgés
automatiquement : ce sont des pièces contractuelles.

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

### Clôture de l'exploitation

Import des calendriers externes (Airbnb, Booking, Abritel ou tout flux ICS
public) sans jamais effacer les blocages du propriétaire, reporting mensuel et
annuel avec export XLSX, litiges adossés aux pièces déjà collectées, quotas de
stockage et purge des données échues.

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

### Tâches périodiques

Une dernière étape reste manuelle parce qu'elle vit chez l'hébergeur : la ligne
cron. Sans elle, le produit fonctionne — mais rien de ce qui doit arriver
*seul* n'arrive : le courrier n'est pas relevé, les calendriers externes ne se
synchronisent pas, les rappels ne partent pas, les sauvegardes ne se font pas,
et les verrous de réservation abandonnés continuent d'occuper des nuits.

```cron
*/10 * * * * php /chemin/vers/secondstay/src/Scheduler/cron.php >/dev/null 2>&1
```

Une seule entrée suffit : le produit porte lui-même le calendrier de chaque
tâche et n'exécute que ce qui est dû. Un passage manqué n'a pas de conséquence,
deux passages simultanés non plus — la tâche est verrouillée le temps de son
exécution.

**Une fois par heure au minimum.** Toutes les dix minutes est mieux : c'est
l'intervalle auquel les verrous de réservation abandonnés sont rendus à la
vente. En deçà d'un passage horaire, les diagnostics signalent le cron comme
silencieux — et ils ont raison de le faire : un cron quotidien laisse une nuit
verrouillée par un panier oublié invendable toute la journée, et la boîte de
réception relevée une seule fois par jour.

L'écran **Exploitation** liste les tâches, leur dernière exécution, leur
résultat, et permet de lancer chacune à la demande — c'est ainsi qu'on vérifie
qu'une tâche fonctionne avant de compter dessus. Une tâche qui accuse trois
intervalles de retard y est signalée, et les diagnostics le disent aussi.

Sur les hébergements dont le cron n'appelle que des URLs, enregistrez un jeton
dans **Réglages → Planificateur** et faites appeler
`https://votre-site/tasks/run?token=…`. Tant qu'aucun jeton n'est enregistré,
cette adresse répond 404 comme n'importe quel chemin inexistant.

Si votre hébergeur sait poser un en-tête sur cet appel, préférez-le au
paramètre d'URL : `X-Scheduler-Token: votre-jeton`. Une URL est écrite dans le
journal d'accès du serveur web, un en-tête ne l'est pas.

### Le tableau « À faire »

Le tableau de bord et l'écran d'exploitation affichent la même liste, et elle
ne contient que ce qui réclame une décision : une demande à valider, une
échéance dépassée, une caution à restituer, un contrat non signé, un courrier
qu'aucune règle n'a su rattacher, un incident ou un litige ouvert, un sujet de
conformité à vérifier, une sauvegarde absente ou trop ancienne, une erreur des
dernières vingt-quatre heures, une mise à jour disponible, une migration en
attente, le site fermé pour maintenance.

Rien de tout cela ne demande d'appel réseau : ces deux écrans sont les plus
fréquentés de l'administration, et les rendre dépendants d'un service distant
les rendrait aussi lents et aussi fragiles que lui.

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
/fr/admin/diagnostics  plateforme, stockage, base, chiffrement, e-mail, DNS,
                       boîte de réception, paiement, IA, tâches périodiques,
                       sauvegardes, mise à jour ; remise à zéro des compteurs
                       de limitation de débit
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

## Mon séjour

Chaque séjour a sa page, adaptée au moment : avant l'arrivée, le jour J,
pendant, au départ, après. On y trouve le livret d'accueil, le Wi-Fi, les
accès, le tri des déchets, les règles, la sécurité et le contact sur place.

```text
/fr/stay/<réf>     mon séjour, pour le voyageur
/guest/<jeton>     lien invité, sans compte
```

Les codes d'accès — Wi-Fi, boîte à clés, alarme — sont chiffrés au repos et
n'apparaissent que **pendant** le séjour : un code publié un mois à l'avance
ou resté lisible après le départ n'est plus un code d'accès.

Chaque section du livret accepte une illustration choisie dans la médiathèque :
une photo explique le tri des déchets, l'emplacement du local ou la manœuvre
d'un appareil mieux qu'un paragraphe, et se lit dans toutes les langues. Seuls
les médias publiés et non privés sont proposés — le livret est lu par des
voyageurs, pas par des administrateurs.

Chaque section accepte aussi un **lien ouvrable** et une **source datée**. Le
lien mène là où le texte ne suffit pas — la carte du local à poubelles, un plan
d'accès, les horaires officiels d'un service : « au bout de la rue à gauche » ne
se suit pas depuis un téléphone, dans le noir, avec une valise. La source dit
d'où vient l'information et quand elle a été vérifiée : les jours de collecte et
les arrêtés municipaux changent, et un livret qui affirme sans le dire vieillit
sans prévenir. Les deux se règlent par bloc et par langue ; une adresse qui
n'est pas en `http` ou `https` est refusée.

Cette page est la seule conçue pour fonctionner **hors ligne** : elle ne porte
ni montant, ni document, ni action d'écriture. Le voyageur qui cherche le code
de la boîte à clés devant la porte, sans réseau, le trouve quand même.

Un lien invité donne accès à ces informations pratiques — et à rien d'autre.
Il expire peu après le départ, se révoque, et s'accompagne d'un QR à imprimer
pour l'afficher dans le logement.

### QR physiques

Un lien invité expire ; un autocollant collé sur la machine à laver, non.
Chaque bloc du livret peut donc être publié à une adresse qui ne change jamais,
faite pour être imprimée en QR et posée là où la question se pose :

```text
/fr/info/waste       le tri, sur le local à poubelles
/fr/info/appliances  les appareils, sur la machine à laver
/fr/info/wifi        le Wi-Fi, dans le salon
```

Cette publication est **refusée par défaut** et se décide bloc par bloc, dans
chaque langue, depuis l'écran du livret — qui affiche alors l'adresse et le QR
à imprimer. C'est délibéré : la page est lisible par quiconque en connaît
l'adresse, et le livret contient des choses qui n'ont rien à faire sur le web
ouvert. N'y mettez ni code de boîte à clés, ni mot de passe Wi-Fi : ceux-là
restent chiffrés et réservés à « Mon séjour ».

Ces pages fonctionnent hors ligne une fois vues, ne sont pas indexées, et
tombent sur la langue du logement quand le bloc n'existe pas encore dans celle
du visiteur.

Ce repli comble une lacune, il ne défait pas une décision : si vous **retirez**
un bloc du web ouvert dans une langue, cette adresse-là se ferme pour de bon.
C'est ce qui rend le réglage utile — si vous vous apercevez que votre texte
allemand contient un code d'accès, le retirer suffit.

## États des lieux et incidents

Le propriétaire décrit son logement zone par zone : l'ordre du parcours, les
consignes, celles qui exigent une photo au départ, et les photos de référence
qui montrent l'état attendu. Rien n'est figé dans le code — une installation
neuve part d'un parcours courant, que l'on modifie entièrement.

```text
/fr/stay/<réf>/inspection/checkin    état des lieux d'arrivée
/fr/stay/<réf>/inspection/checkout   état des lieux de départ
/fr/admin/inspections                zones et photos de référence
/fr/admin/incidents                  suivi des incidents
```

Les deux moments n'ont pas les mêmes exigences :

- **à l'arrivée**, le voyageur signale ce qui ne va pas dans le délai
  configuré. S'il ne signale rien, tout est réputé conforme : personne n'est
  bloqué à 23 h par une photo ;
- **au départ**, les photos des zones requises sont obligatoires. C'est la
  seule preuve dont disposeront les deux parties au moment de discuter de la
  caution, et le serveur refuse de clore tant qu'il en manque une.

Les formulaires sont pensés pour un téléphone tenu d'une main : une carte par
zone, un bouton par état, l'appareil photo directement accessible.

Une anomalie constatée devient un incident en un geste. Un incident porte le
séjour, la zone, l'urgence, la description, des photos, un statut et un
historique en ajout seul : signalé, pris en charge, résolu. Seuls les
incidents **urgents** préviennent immédiatement ; les autres apparaissent dans
« À faire ».

## Contenu local

Ce que les voyageurs demandent le plus souvent est aussi ce qui change le plus
vite : le marché du mardi, la fête du village, l'exposition de l'été. Le produit
peut préparer ces suggestions à partir de pages que **vous** désignez — agenda
de la commune, office de tourisme — et les afficher sur « Mon séjour ».

```text
/fr/admin/local     sources, consigne, essai
```

Trois gestes : coller des URL, écrire une consigne — ou en faire proposer une à
partir de la localisation du logement — et lancer un essai. Le système ajoute
seul la localisation, la saison, les dates exactes du séjour, les sources et le
format attendu.

Les garde-fous comptent autant que la fonction :

- **rien n'est inventé.** Une activité qui ne cite pas une des pages réellement
  consultées est écartée, comme une activité sans date lisible ;
- **le web est une donnée, pas une consigne.** Les pages sont réduites à leur
  texte et présentées au modèle comme du contenu à lire, jamais à obéir ;
- **rien de personnel ne sort.** Le modèle reçoit un lieu, des dates et du
  texte public — pas un nom, pas une adresse, pas même la référence du séjour ;
- **sans clé, rien ne se produit.** La fonction est facultative : une
  installation sans fournisseur configuré n'affiche simplement aucune
  suggestion.

Le voyageur ne voit que les activités qui tombent dans ses dates, groupées en
« à réserver à l'avance » et « à faire pendant votre séjour », chacune avec sa
source et sa date de vérification.

## Responsable local et exploitation

Plusieurs comptes peuvent être responsables locaux. Un séjour reçoit un
responsable, ou hérite de celui par défaut de l'installation. Le voyageur voit
alors ses coordonnées, et le responsable voit les séjours qui le concernent —
sans jamais accéder aux montants.

Chaque séjour porte deux checklists : avant l'arrivée (contrat, acompte,
solde, caution, responsable, ménage, accès) et au départ (état des lieux,
incidents, ménage, caution). Les lignes qui découlent de l'état du séjour sont
suivies automatiquement ; seules celles qui demandent une action humaine se
cochent.

Le tableau « À faire » ne montre que ce qui réclame une décision : demandes à
valider, échéances dépassées, cautions à restituer, contrats non signés,
courriers non rattachés, séjours proches encore à préparer, incidents et
litiges ouverts, conformité à vérifier, sauvegarde absente ou trop ancienne,
erreurs récentes, mise à jour disponible. C'est la même liste que sur le
tableau de bord.

L'écran d'exploitation porte aussi l'état des tâches périodiques : dernière
exécution, résultat, retard éventuel, et un bouton pour lancer chacune à la
demande — c'est ainsi qu'on vérifie qu'une tâche fonctionne avant de compter
dessus.

## Calendriers privés

SecondStay publie des flux iCalendar privés, à abonner dans n'importe quel
agenda :

```text
/calendar/<jeton>.ics   flux privé, sans mot de passe
/fr/admin/operations    délivrance et révocation des liens
```

Chaque flux montre exactement ce dont son destinataire a besoin :
l'administration voit tout, le responsable local voit les séjours sans les
montants, le voyageur voit le sien avec le contact du responsable.

Les liens sont longs, uniques et révocables. Un lien n'est affiché qu'une fois,
n'est jamais stocké en clair, et le révoquer coupe l'accès immédiatement.

## Calendriers externes importés

Les nuits vendues sur une autre plateforme deviennent des indisponibilités :

```text
/fr/admin/operations    déclaration, synchronisation et suppression des flux
```

Trois règles gouvernent l'import :

- **un flux bloque, il ne réserve pas.** Un événement distant ne crée jamais de
  séjour, de montant ni d'engagement ;
- **une synchronisation ne touche que ses propres lignes.** Ce que vous avez
  bloqué à la main survit à n'importe quel import, et deux flux ne s'effacent
  pas l'un l'autre ;
- **un flux muet ne libère rien.** Une erreur réseau, une page de connexion
  renvoyée à la place du calendrier : les blocages restent. Rendre disponibles
  des nuits déjà vendues serait le pire résultat possible.

Chaque adresse est contrôlée à la saisie **et** à chaque requête sortante.
Supprimer un flux emporte les blocages qui en venaient, et laisse les vôtres.

## Reporting et quotas

```text
/fr/admin/reports              indicateurs du mois ou de l'année
/fr/admin/reports/export.xlsx  classeur comptable de la période affichée
```

Le reporting **compte**, il ne conseille pas : encaissé, attendu, reste à
encaisser, remboursé, cautions détenues, taxe de séjour, nuits vendues, taux
d'occupation et prix moyen de la nuit. La caution n'est pas un revenu ; la taxe
de séjour est comptée à part. Un séjour à cheval sur deux mois est compté dans
chacun, pour les nuits qu'il y occupe.

L'export est un vrai `.xlsx`, écrit en PHP pur — aucune dépendance ajoutée — et
traduit dans la langue de la page. Ces chiffres ne constituent ni un conseil
fiscal ni une déclaration : l'avertissement est affiché et voyage dans le
fichier.

La même page mesure l'espace occupé par les médias, les documents, les
sauvegardes et les pièces jointes. Un quota atteint **refuse l'écriture avant
de la tenter** : sur un hébergement mutualisé, un disque plein casse aussi la
sauvegarde qui aurait permis de s'en sortir. Un quota laissé à zéro ne limite
rien.

## Litiges

```text
/fr/admin/disputes      litiges ouverts, en discussion, résolus
```

Un litige rassemble ce que le produit a **déjà** collecté — caution détenue,
état des lieux de départ et ses anomalies, photos, incidents, contrat accepté —
pour que la discussion s'appuie sur des faits datés.

La retenue réclamée ne peut pas dépasser la caution réellement détenue, et
clore un litige exige un montant réglé et une explication. L'historique est en
ajout seul.

## Contrats et documents

Chaque séjour reçoit un **contrat PDF** dans sa langue, produit par
l'application elle-même : pas de dépendance supplémentaire, pas de service
externe, et le contenu d'un contrat ne sort jamais du serveur.

Le contrat est un instantané : une fois établi, il n'est plus réécrit, même si
les tarifs ou les textes changent. Son acceptation conserve la version, la
langue, la date et l'empreinte du document accepté — de sorte qu'on sache
durablement ce que le client a lu.

Tous les documents d'un séjour — contrat, contrat signé, justificatifs, reçus,
pièces jointes — vivent hors du document root et ne sont servis qu'à leur
titulaire.

## Courrier entrant

SecondStay relève périodiquement une boîte dédiée au logement et rattache
chaque réponse au bon séjour. Les e-mails sortants annoncent une adresse de
réponse signée, propre au séjour : la réponse du voyageur revient donc au bon
endroit, même si son logiciel de messagerie perd les en-têtes de fil.

Toute pièce jointe d'un message rattaché apparaît automatiquement dans les
documents du séjour, avec un classement proposé.

```text
/fr/admin/mailbox      messages reçus, rattachement manuel, relève à la demande
/fr/admin/documents    tous les documents, avec leur nature et leur provenance
```

La relève est périodique, jamais une connexion maintenue, et le client IMAP est
écrit sur socket : l'extension `imap` de PHP, absente de la plupart des
hébergements mutualisés, n'est pas nécessaire.

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
- PHPStan (niveau 8, aucune erreur tolérée, aucune baseline) ;
- PHPUnit + couverture Clover ;
- contrôle i18n FR/EN/NL/DE ;
- tests d’intégration base de données ;
- Vitest + couverture LCOV ;
- `tsc`, vérificateur du JavaScript navigateur (`npm run typecheck`) — rien
  n'est compilé, la production sert le JavaScript tel qu'il est écrit ;
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
