# ROADMAP.md

# Découpage en itérations testables indépendamment

## Definition of Done commune

Une itération est terminée seulement si :

- `./scripts/check.sh` est vert ;
- GitHub Actions est vert ;
- PHPStan est vert ;
- PHPUnit est vert ;
- Vitest est vert ;
- Playwright est vert ;
- Composer audit est vert ;
- CodeQL applicable est vert ;
- SonarCloud Quality Gate est vert ;
- FR/EN/NL/DE sont traités pour toutes les fonctions utilisateur de l’itération ;
- installation neuve testée ;
- migration N-1 → N testée si applicable ;
- ZIP release contrôlé ;
- documentation mise à jour.

## État d’avancement

| Itération | Intitulé | État |
|---|---|---|
| 0 | Squelette et chaîne de confiance | ✅ livrée |
| 1 | Installation, configuration, backups, updater | ✅ livrée |
| 2 | Site public et contenu | ✅ livrée |
| 3 | Comptes et auth | ✅ livrée |
| 4 | Notifications et PWA | ✅ livrée |
| 5 | Disponibilités et prix | ✅ livrée |
| 6 | Réservation sans paiement | ✅ livrée |
| 7 | Paiements | ✅ livrée |
| 8 | Contrats, documents, IMAP | ✅ livrée |
| 9 | Responsable local et opérations | ✅ livrée |
| 10 | Mon séjour et invités | ✅ livrée |
| 11 | États des lieux et incidents | ✅ livrée |
| 12 | France et conformité | ✅ livrée |
| 13 | Contenu local IA | ✅ livrée |
| 14 | ICS externes, reporting, consolidation | ✅ livrée |

Le numéro de version mineure suit l’itération livrée : l’itération N correspond
à la série `0.(N+1).x`.

## Itération 0 — Squelette et chaîne de confiance

Livrer :

- structure repo ;
- AGPL ;
- Twig/Bootstrap ;
- front controller ;
- VERSION ;
- docs ;
- infrastructure i18n FR/EN/NL/DE ;
- sélecteur locale minimal ;
- `check.sh` ;
- `release.sh` initial ;
- PHPUnit ;
- PHPStan ;
- Vitest ;
- Playwright ;
- CI ;
- CodeQL ;
- SonarCloud ;
- 404/500 ;
- sécurité `.htaccess`.

E2E :

- `/` ;
- `/api/version` ;
- chemins privés 403/404 ;
- home dans les quatre langues ;
- fallback locale ;
- rendu mobile/desktop.

## Itération 1 — Installation, configuration, backups, updater

Livrer :

- installation ;
- DB ;
- premier admin ;
- plusieurs admins ;
- settings typés ;
- langue par défaut configurable parmi FR/EN/NL/DE ;
- chiffrement ;
- logs ;
- audit ;
- diagnostics ;
- maintenance ;
- backup pur PHP ;
- restore ;
- update GitHub Release ;
- migration ;
- rollback.

E2E : installation → config → backup → modification → restore → état retrouvé.

## Itération 2 — Site public et contenu

Livrer :

- home ;
- pages ;
- contenus éditoriaux traduisibles ;
- galerie ;
- saisons ;
- menu multi-niveau ;
- light/dark ;
- SEO multilingue ;
- légal/RGPD de base ;
- état de complétude des traductions.

E2E : édition admin en quatre langues → publication → rendu public correct.

## Itération 3 — Comptes et auth

Livrer :

- signup ;
- confirmation mail localisée ;
- login ;
- reset ;
- passkeys ;
- sessions ;
- rôles ;
- préférence langue compte ;
- export/suppression ;
- rate limiting ;
- accessibilité.

E2E : cycle compte complet et permissions dans matrice de locales.

### Livré (0.4.0)

- inscription, confirmation d'adresse, connexion et réinitialisation, avec
  e-mails rendus dans la langue du destinataire ;
- transport SMTP maison (STARTTLS / TLS implicite, `AUTH PLAIN` puis
  `AUTH LOGIN`) et transport factice pour les tests ;
- clés d'accès WebAuthn complètes (CBOR, COSE ES256/RS256, vérification
  d'assertion et compteur anti-clonage), masquées lorsque le domaine ne le
  permet pas ;
- espace client : profil, langue préférée, mot de passe, appareils connectés
  et révocation ;
- RGPD : consentements horodatés, export JSON, anonymisation du compte ;
- limitation de débit sur inscription, réinitialisation et clés d'accès, avec
  remise à zéro par un administrateur depuis `/admin/diagnostics` ;
- E2E `account.spec.js` et `passkeys.spec.js`, tests PHP `AccountServiceTest`,
  `WebAuthnServiceTest`, `SmtpMailTransportTest`, `MailMessageTest` et suite
  Vitest `passkey.test.js`.

## Itération 4 — Notifications et PWA

Livrer :

- SMTP ;
- diagnostic SPF/DKIM/DMARC ;
- Web Push ;
- e-mail + push ;
- templates FR/EN/NL/DE ;
- PWA ;
- service worker ;
- cache localisé.

E2E : événement → fake mail + fake push dans la langue du compte.

### Livré (0.5.0)

- `NotificationService` : e-mail et push indépendants, journalisés séparément,
  rendus dans la langue du compte ;
- Web Push natif (VAPID ES256, chiffrement `aes128gcm` de bout en bout), sans
  dépendance supplémentaire, avec fournisseur factice pour les tests ;
- clés VAPID générées et renouvelables depuis l'administration ;
- préférences de canal par compte, abonnement d'appareils et envoi de
  vérification depuis l'espace client ;
- diagnostic SPF / DKIM / DMARC du domaine d'expédition et sonde SMTP
  explicite ;
- application installable : manifeste localisé, icônes générées, service
  worker versionné, page hors ligne dans les quatre langues ;
- session paresseuse : plus aucun cookie sur les réponses publiques ;
- E2E `pwa-notifications.spec.js`, tests PHP `WebPushTest`,
  `NotificationServiceTest`, `PushSubscriptionRepositoryTest`,
  `MailDnsCheckerTest`, `PwaTest` et suite Vitest `push.test.js`.

## Itération 5 — Disponibilités et prix

Livrer :

- prix/jour ;
- overrides ;
- blocs ;
- règles séjour ;
- horaires ;
- capacité ;
- calendriers ;
- formatage localisé dates/montants.

E2E : séjour traversant plusieurs tarifs → total exact dans quatre locales.

### Livré (0.6.0)

- `DateRange` : convention « arrivée incluse, départ exclu », insensible aux
  fuseaux et aux changements d'heure ;
- tarifs par nuit avec exceptions par plage, calcul nuit par nuit, ménage
  selon le mode configuré, acompte arrondi au centime supérieur ;
- indisponibilités d'exploitation (propriétaire, entretien, calendrier
  externe) et états de nuit ;
- règles de séjour : durée, multiples, jour d'arrivée, samedi-samedi,
  capacité, délai de prévenance et horizon ;
- calendrier public avec prix réel de chaque nuit et total en direct issu de
  l'API de devis ;
- écran d'administration des tarifs et des blocages ;
- pages « disponibilités » et « tarifs » devenues fonctionnelles sans perdre
  leur contenu éditorial ;
- formatage localisé des mois, jours et montants dans les quatre langues ;
- E2E `pricing.spec.js`, tests PHP `DateRangeTest` et `PricingTest`, suite
  Vitest `calendar.test.js`.

## Itération 6 — Réservation sans paiement

Livrer :

- sélection ;
- voyageurs ;
- résumé ;
- promo ;
- ménage ;
- hold ;
- workflow ;
- timeline ;
- liste attente ;
- anti-double-booking.

E2E : deux clients concurrents → un seul succès.

### Livré (0.7.0)

- anti-double-réservation garanti par la clé primaire de `booking_night`,
  vérifié par deux transactions réellement concurrentes ;
- verrou temporaire posé avant la finalisation, expirant seul ;
- parcours complet : dates → voyageurs → prix → authentification →
  informations → règles → confirmation ;
- workflow à transitions déclarées et six sous-états indépendants ;
- montants figés à la réservation, insensibles à un changement de tarif ;
- codes promotionnels (montant fixe ou pourcentage, dates, limite d'usage) ;
- timeline horodatée et attribuée ;
- liste d'attente avec alerte par e-mail dans la langue de l'inscription ;
- suivi des réservations en administration ;
- E2E `booking.spec.js` avec deux navigateurs concurrents, tests PHP
  `BookingServiceTest`.

## Itération 7 — Paiements

Livrer :

- PaymentProvider ;
- Mollie ;
- acompte ;
- solde ;
- caution ;
- ménage ;
- taxe ;
- refund ;
- EPC QR ;
- webhook ;
- FakePaymentProvider ;
- UI et notifications localisées.

E2E : acompte → webhook confirmé → réservation confirmée.

### Livré (0.8.0)

- échéancier par composant : acompte, solde, caution, ménage, taxe de séjour
  et ajustements, chacun avec son montant, son échéance et son historique ;
- abstraction `PaymentProvider` et premier fournisseur réel (Mollie), doublé
  d'un fournisseur factice activable par variable d'environnement seulement,
  et d'un fournisseur nul lorsqu'aucune clé n'est utilisable ;
- webhooks idempotents par contrainte d'unicité, robustes aux rejeux et au
  désordre, ne croyant jamais le corps reçu ;
- acompte confirmé par le fournisseur qui confirme le séjour ; virement et
  encaissement manuel qui ne le confirment que sur décision explicite ;
- cycle complet de la caution et remboursements totaux ou partiels ;
- QR code EPC produit sans dépendance ni service externe, avec encodeur QR et
  correction Reed-Solomon maison, IBAN vérifié par sa clé de contrôle ;
- taxe de séjour par adulte et par nuit, plafonnée, mineurs exonérés ;
- suivi financier en administration et échéancier dans l'espace client ;
- E2E `payment.spec.js` (acompte → webhook → confirmation, rejeu, virement,
  caution), tests PHP `PaymentServiceTest`, `PaymentProviderTest`,
  `TouristTaxTest`, `QrCodeTest` et `EpcQrBuilderTest`.

## Itération 8 — Contrats, documents, IMAP

Livrer :

- contrat PDF FR/EN/NL/DE ;
- snapshot version+locale ;
- documents ;
- SMTP ;
- IMAP ;
- thread linking ;
- attachments → Documents ;
- classement.

E2E : réponse mail avec contrat signé → document réservation.

### Livré (0.9.0)

- générateur PDF maison, sans dépendance ni binaire externe, aux polices
  standard du format et couvrant les quatre langues ;
- contrat rendu en FR/EN/NL/DE depuis les catalogues de traduction, avec
  parties, logement, séjour, montants, échéancier et clauses ;
- instantané immuable : un contrat établi n'est jamais réécrit, une
  régénération explicite produit un nouveau document ;
- acceptation traçable — version, langue, horodatage, empreinte du PDF et
  empreinte de l'adresse — avec contrôle d'intégrité affiché ;
- documents de séjour stockés hors document root, typés d'après leur contenu
  réel, nommés par leur empreinte et servis uniquement à leur titulaire ;
- client IMAP écrit sur socket, sans `ext-imap`, avec relève périodique
  reprenant au dernier UID et repartant de zéro après renumérotation ;
- analyse MIME défensive : profondeur et nombre de parties bornés, jeux de
  caractères convertis, HTML nettoyé avant stockage ;
- rattachement au séjour par jeton de réponse signé, en-têtes de fil,
  référence citée ou adresse d'expéditeur, dans cet ordre de confiance ;
- pièces jointes versées automatiquement dans les documents du séjour, avec
  classement proposé ;
- timeline de communication unique, entrants et sortants mêlés ;
- diagnostics de la boîte de réception, sonde IMAP sur demande explicite ;
- E2E `documents.spec.js` (contrat lu, accepté, réponse avec contrat signé →
  document du séjour), tests PHP `PdfDocumentTest`, `MimeParserTest`,
  `ImapClientTest`, `ReplyTokenTest`, `DocumentServiceTest`,
  `ContractServiceTest` et `InboundMailServiceTest`.

## Itération 9 — Responsable local et opérations

Livrer :

- plusieurs responsables ;
- défaut ;
- affectation ;
- checklists ;
- “À faire” ;
- ICS admin/responsable/client ;
- libellés localisés.

E2E : affectation → ICS → révocation token.

### Livré (0.10.0)

- plusieurs responsables locaux, responsable par défaut de l'installation et
  affectation par séjour, modifiable par un administrateur ;
- seul un compte opérationnel peut être responsable ; supprimer un compte
  n'emporte jamais un séjour, seulement son affectation ;
- checklists d'avant-séjour et de départ, mêlant lignes dérivées de l'état du
  séjour — jamais recopiées — et lignes cochées par un humain ;
- tableau « À faire » unique, partagé par le tableau de bord et la page
  d'exploitation ;
- générateur iCalendar maison : pliage à 75 octets, échappement des
  séparateurs, date de fin exclusive alignée sur la convention `DateRange` ;
- flux privés par portée — administration, responsable, voyageur — chacun
  limité à ce que son destinataire doit voir, le flux du voyageur portant le
  contact du responsable ;
- jetons de 32 octets stockés hachés, affichés une seule fois, régénérables et
  révocables avec effet immédiat ;
- E2E `operations.spec.js` (affectation → checklist → ICS → révocation), tests
  PHP `IcsCalendarTest` et `OperationsServiceTest`.

## Itération 10 — Mon séjour et invités

Livrer :

- Mon séjour aujourd’hui ;
- guest link ;
- QR ;
- Wi-Fi ;
- accès ;
- déchets ;
- sécurité ;
- contact local ;
- offline PWA ;
- contenu FR/EN/NL/DE.

E2E : mobile offline → informations utiles dans langue choisie.

### Livré (0.11.0)

- « Mon séjour aujourd'hui » : phase déduite des dates et du fuseau du
  logement, jamais stockée ;
- livret d'accueil réellement traduit — un enregistrement par bloc et par
  langue — avec état de complétude et repli sur la langue par défaut ;
- codes d'accès chiffrés au repos, publiés uniquement pendant la fenêtre du
  séjour, jamais réaffichés par l'administration ;
- liens invité expirants, révocables, à jeton haché, ouvrant les informations
  pratiques et rien d'autre, avec QR rendu en ligne pour l'affichage dans le
  logement ;
- hors ligne conforme à la spécification : livret, Wi-Fi, règles, déchets,
  sécurité et contact mis en cache ; réservation, paiements et documents
  définitivement exclus ;
- stratégie réseau d'abord avec délai court sur les pages de séjour, pour ne
  jamais afficher une page périmée après une action ;
- E2E `stay.spec.js` avec coupure réseau réelle et inspection des caches,
  tests PHP `StayServiceTest`.

## Itération 11 — États des lieux et incidents

Livrer :

- zones ;
- photos référence ;
- check-in ;
- anomalies ;
- check-out photos obligatoires ;
- incidents ;
- formulaires localisés.

E2E : workflow mobile arrivée/départ.

### Livré (0.12.0)

- zones du logement définies par le propriétaire — ordre du parcours,
  consignes, activation, obligation de photo au départ — jamais figées dans le
  code, et amorcées à l'installation avec un parcours réel ;
- libellés de zone traduisibles par langue, avec repli sur le libellé intégré
  FR/EN/NL/DE quand aucun nom propre n'est saisi ;
- photos de référence par zone, stockées comme documents ordinaires hors
  document root, refusant tout ce qui n'est pas une image ;
- état des lieux d'arrivée : signalement facultatif dans le délai configuré,
  clôture possible sans aucune photo ;
- état des lieux de départ : photos obligatoires pour chaque zone requise, la
  clôture étant refusée **par le serveur** tant qu'il en manque une ;
- unicité (séjour, type) portée par la base : deux ouvertures simultanées ne
  produisent qu'un état des lieux, et une zone ajoutée après coup apparaît
  tant qu'il reste ouvert ;
- un état des lieux clos ne se modifie plus, et fait avancer le sous-état
  d'arrivée ou de départ du séjour ;
- incidents : réservation, zone, urgence, description, photos, statut et
  historique en ajout seul, avec transitions explicites signalé → pris en
  charge → résolu et réouverture nommée ;
- une anomalie constatée devient un incident en un geste ; un incident urgent
  prévient immédiatement les rôles opérationnels, les autres attendent le
  tableau « À faire », qui les compte ;
- formulaires pensés pour un téléphone, jamais mis en cache : un constat et
  une photo s'écrivent, ils ne se relisent pas depuis le disque ;
- E2E `inspection.spec.js` (workflow mobile arrivée/départ sur les deux
  moteurs), tests PHP `InspectionServiceTest` et `InspectionTest`.

## Itération 12 — France et conformité

Livrer :

- assistant conformité ;
- taxe séjour ;
- déclaration/enregistrement ;
- SIRET ;
- classement ;
- DPE/changement usage si applicable ;
- médiation ;
- assurance ;
- fiche police si applicable ;
- CGV/RGPD versionnés par langue ;
- consentements ;
- rétention.

E2E : réservation historique conserve version et langue du texte légal accepté.

### Livré (0.13.0)

- assistant conformité couvrant les dix-huit sujets de la spécification : pour
  chacun, définition, applicabilité, où chercher et impact en FR/EN/NL/DE ;
  statut, valeur, source officielle, date de vérification, échéance de revue
  et pièce justificative saisis par le propriétaire ;
- aucun conseil juridique automatisé : le produit dit ce qu'il sait, date ce
  que le propriétaire constate, et affiche cette limite en toutes lettres ;
- une source doit être une adresse web consultable ; un sujet déclaré conforme
  est daté d'office et reçoit une échéance de revue ;
- sujets à vérifier et revues dépassées remontent dans le tableau « À faire » ;
- textes légaux **versionnés par langue** : publier fige un instantané de
  chaque langue, avec son empreinte ; une version publiée ne se réécrit jamais ;
- consentements versionnés : une réservation enregistre la version, la langue
  et l'empreinte du texte réellement accepté, plus l'empreinte de l'adresse IP ;
  réécrire les conditions ensuite ne change rien à ce qui a été accepté ;
- l'inscription enregistre elle aussi la version publiée, et l'installation
  publie une version initiale — le produit n'est jamais livré sans texte
  opposable ;
- moteur de taxe de séjour **daté** : règles par territoire, classement et
  période, contexte de calcul figé avec le séjour, explication lisible sur la
  fiche de réservation, recouvrements de barèmes signalés ;
- fiche de police : rien n'est collecté tant que l'obligation n'est pas
  activée, contenu chiffré au repos, purge automatique à l'échéance de
  conservation configurée ;
- rétention appliquée en un seul endroit — journaux, notifications, jetons,
  liens invité, liste d'attente, webhooks, fiches de police — et auditée ; les
  pièces contractuelles ne sont jamais purgées automatiquement ;
- E2E `compliance.spec.js` (réservation en allemand, réécriture des conditions,
  nouvelle version, preuve inchangée), tests PHP `ComplianceServiceTest` et
  `ComplianceTest`.

## Itération 13 — Contenu local IA

Livrer :

- LlmProvider ;
- URLs ;
- SSRF ;
- prompt ;
- génération prompt localisation ;
- scheduler ;
- contenu structuré ;
- dates exactes ;
- sources ;
- rendu FR/EN/NL/DE.

E2E : fixtures HTML + fake LLM.

### Livré (0.14.0)

- `LlmProvider` comme les autres frontières externes du produit : implémentation
  Claude appelée en HTTP direct — donc à travers le garde SSRF —, fournisseur
  nul par défaut, fournisseur factice activable par la seule variable
  d'environnement ;
- sources : URL simples saisies par le propriétaire, contrôlées à la saisie et
  surtout **à chaque sortie**, avec leur dernier état visible ;
- extraction : les pages sont réduites à du texte avant d'approcher le prompt —
  scripts, styles et commentaires ne peuvent pas y cacher d'instruction ;
- prompt gardé : le propriétaire écrit sa consigne, le système ajoute la
  localisation, la saison, les dates exactes, les sources et le schéma ; le
  contenu récupéré est enfermé entre marqueurs et déclaré donnée, jamais
  instruction ;
- bouton « Générer le prompt à partir de la localisation », essai à blanc et
  rafraîchissement à la demande ;
- sortie contrainte par un schéma JSON **et revalidée** : une activité sans
  source consultée, sans titre ou aux dates impossibles est écartée ;
- fenêtre de génération configurable — cinq semaines avant l'arrivée par
  défaut, rafraîchie chaque semaine jusqu'au séjour ;
- affichage limité aux dates exactes du séjour, groupé en « à réserver à
  l'avance » et « à faire pendant votre séjour », chaque activité citant sa
  source et sa date de vérification, dans les quatre langues ;
- aucune donnée personnelle n'atteint le modèle : un lieu, des dates et du
  texte public ;
- E2E `local-content.spec.js` (fixtures HTML servies au produit, modèle
  factice, filtrage sur les dates), tests PHP `LocalContentServiceTest` et
  `LocalContentTest`.

## Itération 14 — ICS externes, reporting, consolidation

Livrer :

- import ICS ;
- reporting ;
- XLSX ;
- litiges ;
- purge ;
- quotas ;
- campagne complète upgrade/restore/security/mobile/PWA/i18n.

E2E : suite transverse complète.

### Livré (0.15.0)

- **import ICS** : flux externes déclarés par le propriétaire, synchronisés à la
  demande, chaque blocage gardant sa provenance. Un flux bloque mais ne réserve
  jamais ; une synchronisation ne touche que ses propres lignes ; un flux muet
  — erreur réseau, code HTTP, page de connexion à la place du calendrier — ne
  libère aucune nuit ; supprimer le flux emporte ses blocages et laisse ceux du
  propriétaire ;
- **lecteur iCalendar** écrit indépendamment du générateur, borné à 2 Mo et
  2000 événements, `DTEND` exclusif, UID dérivé quand le flux n'en publie pas ;
- **reporting** mensuel et annuel : encaissé, attendu, reste à encaisser,
  remboursé, cautions détenues, taxe de séjour, nuits vendues, taux
  d'occupation, prix moyen de la nuit. La caution n'est pas un revenu, la taxe
  est comptée à part, et les nuits sont comptées nuit par nuit — un séjour à
  cheval sur deux mois compte dans les deux ;
- **XLSX** écrit en PHP pur, déterministe, traduit dans la langue de la page,
  portant l'avertissement « ni conseil fiscal ni déclaration », relu dans les
  tests par un lecteur indépendant ;
- **litiges** : ouverture depuis le séjour, discussion, clôture avec montant
  réglé et explication, historique en ajout seul, et un dossier de pièces
  agrégé depuis ce que le produit avait déjà — caution détenue, état des lieux
  de départ, anomalies, photos, incidents, contrat accepté ;
- **quotas** : quatre catégories mesurées, refus **avant** écriture, alerte à
  80 %, zéro signifiant « pas de limite », suppression de document offerte dans
  l'interface et fichier partagé effacé seulement une fois orphelin ;
- **purge** consolidée : les indisponibilités passées rejoignent la rétention
  appliquée en un seul endroit, toujours auditée, sans jamais toucher aux
  pièces contractuelles ;
- E2E `closing.spec.js` (suite transverse : import ICS vérifié sur le calendrier
  public en visiteur anonyme, reporting, export, litige complet, quota atteint
  puis relevé), tests PHP `OperationsClosingTest`, `IcsParserTest`,
  `XlsxWriterTest`, `ReportingTest`, `DisputeTest`, `QuotaServiceTest`.

## Consolidation — audit de fin de feuille de route

### Livré (0.16.0)

Une fois les quatorze itérations livrées, la relecture des spécifications
contre le code a fait apparaître des exigences énoncées mais jamais servies.
Elles sont traitées ici, dans l'ordre où leur absence se voit.

Ce que ces manques avaient en commun : aucun ne produisait d'erreur. Un
planificateur absent ne lève rien, il laisse simplement le courrier
s'accumuler ; une clé de traduction inventée affiche un mot anglais dans les
quatre langues ; un contraste à 4,45 s'affiche parfaitement. Ce sont des
défauts que seule une mesure trouve.

### Planificateur de tâches périodiques

`ARCHITECTURE.md §23` décrit depuis le début des travaux courts et idempotents
lancés par cron, et `SPECIFICATIONS.md §18` demande un diagnostic « cron ». Ni
l'un ni l'autre n'existait : la relève IMAP, l'import ICS, la génération de
contenu local et la purge n'étaient joignables que par un clic dans
l'administration, les sauvegardes n'étaient jamais automatiques, les rappels de
séjour — pourtant traduits dans les quatre langues — ne partaient jamais, et
`releaseExpiredHolds()` n'était appelé par personne : un panier abandonné
gardait ses nuits indéfiniment.

Livré :

- `ScheduledTask`, `Scheduler`, `TaskState` et `TaskStateRepository` : le
  calendrier et l'état vivent dans le produit, l'hébergeur ne porte qu'une
  ligne cron ;
- verrou à échéance pris par `UPDATE` conditionnel : deux passages qui se
  chevauchent ne relèvent pas deux fois la même boîte, un processus tué
  n'immobilise pas sa tâche ;
- isolement des échecs : une boîte injoignable n'empêche pas la sauvegarde ;
- huit tâches branchées — verrous expirés, IMAP, ICS, contenu local, rappels,
  purge, sauvegarde, contrôle de mise à jour ;
- `StayReminderService` : rappel avant l'arrivée, arrivée et départ, une fois
  chacun, sans rattrapage rétroactif ;
- `src/Scheduler/cron.php`, hors de portée du serveur web par trois mécanismes
  indépendants, et porte HTTP `GET /tasks/run?token=…` fermée par défaut pour
  les hébergements sans cron en ligne de commande ;
- écran d'exploitation listant l'état de chaque tâche, exécution à la demande,
  retard signalé ;
- tests `SchedulerStateTest`, `SchedulerTest`, `StayReminderServiceTest`, et
  E2E dans `operations.spec.js` et `security-paths.spec.js`.

### Diagnostics complets

`SPECIFICATIONS.md §18` énumère seize contrôles ; cinq manquaient — Mollie,
LLM, cron, sauvegarde et mise à jour. Ils sont d'autant plus utiles qu'ils
portent sur ce dont l'absence ne se voit pas autrement : un fournisseur de
paiement choisi mais sans clé, une génération de contenu activée sans modèle,
un cron qui a cessé de passer, une sauvegarde qui date de trois semaines.

`OperationsDiagnostics` les rend tous **sans un seul appel sortant** : ouvrir
la page ne parle ni au fournisseur de paiement, ni au modèle, ni à GitHub. Une
page de diagnostics qui interroge le monde extérieur devient lente, puis
inutilisée, puis rouge le jour où le monde extérieur tombe — alors que
l'installation, elle, va bien.

Le cron donne lieu à deux contrôles et non un seul : « le cron passe-t-il ? »
et « une tâche est-elle en souffrance ? ». Les confondre afficherait vert une
installation dont une tâche échoue en boucle, ou rouge une installation neuve
dont la ligne cron n'est simplement pas encore posée.

### Tableau « À faire » complet

`SPECIFICATIONS.md §50` énumère huit sujets ; quatre manquaient — contrat,
sauvegarde, erreur, mise à jour. Ils rejoignent le tableau :

- **contrat** : les séjours confirmés ou en cours, non terminés, dont le
  contrat n'est pas signé. Le décompte porte sur la table entière et non sur
  une page, sans quoi une installation active annoncerait toujours « 100 » ;
- **sauvegarde** : l'absence de toute sauvegarde et le vieillissement de la
  plus récente, à des gravités différentes — n'en avoir aucune est une bombe à
  retardement, en avoir une trop ancienne est une perte bornée ;
- **erreur** : les entrées de gravité au moins « erreur » des dernières
  vingt-quatre heures, une panne critique comptant parmi elles ;
- **mise à jour** : lue dans le résultat de la tâche périodique, jamais
  demandée à GitHub — ce tableau s'affiche sur deux écrans très fréquentés, et
  le rendre dépendant du réseau le rendrait aussi lent et aussi fragile que lui.

Le tableau de bord tenait en réalité **sa propre liste** en plus de celle du
service, avec ses propres libellés : « aucune sauvegarde », « mise à jour
disponible » et « migrations en attente » y étaient recalculées d'une façon qui
divergeait de l'écran d'exploitation, et la mise à jour y était vérifiée par un
appel à GitHub **à chaque affichage** — pour alimenter une variable de vue que
plus aucun gabarit ne lisait. Les deux écrans partagent désormais la liste et
les identifiants ; le tableau de bord n'ajoute que le nombre de diagnostics en
erreur, qu'il est seul à connaître puisqu'il calcule déjà ce résumé pour ses
indicateurs. Le site fermé pour maintenance et le logement sans nom rejoignent
la liste commune, et se voient donc sur les deux écrans.

Au passage, `LogRepository` remplace le SQL écrit directement dans le
contrôleur des journaux — deux endroits interrogeant `app_log` auraient dérivé
l'un de l'autre — et corrige la recherche : un `%` saisi par l'humain n'est plus
interprété comme un joker SQL.

### QR physiques

`SPECIFICATIONS.md §47` demande des URLs stables vers le Wi-Fi, les déchets,
les appareils, les règles. Seul le lien invité existait — et il ne peut pas
tenir ce rôle : il est nominatif et il expire, alors qu'un autocollant collé
sur la machine à laver, non.

Chaque bloc du livret est donc publiable à `/{langue}/info/{bloc}`, adresse
dérivée du seul code du bloc. La publication est **refusée par défaut** et se
décide bloc par bloc et langue par langue : la page est lisible par quiconque
en connaît l'adresse, et le livret contient des choses qui n'ont rien à faire
sur le web ouvert — à commencer par un code d'accès recopié dans le texte d'un
bloc. Un réglage global aurait transformé une commodité en fuite.

Trois conditions doivent tenir pour qu'une adresse réponde — bloc public,
publié, non vide — et aucun secret n'y transite : les codes d'accès restent
chiffrés et réservés à « Mon séjour ». Un bloc absent dans la langue demandée
est servi dans celle du logement, en le disant. L'écran du livret affiche
l'adresse et le QR à imprimer, la page n'est pas indexée, et le service worker
la garde hors ligne — c'est un texte que `§44` autorise, et le réseau est
souvent mauvais dans une buanderie.

Le QR imprimé est relu dans les tests par un décodeur écrit indépendamment de
l'encodeur : un QR juste à un caractère près est un lien mort découvert un
dimanche par un voyageur.

### Accessibilité mesurée, et non supposée

`TESTING.md §10` demande une analyse automatisée via axe, avec l'objectif
WCAG 2.2 AA. La dépendance `@axe-core/playwright` était installée depuis le
début et n'était appelée nulle part : l'outil était présent, la mesure absente.

La campagne l'exécute désormais sur les trois familles de pages — contenu,
formulaire, administration — dans les deux thèmes. Elle a immédiatement trouvé
un défaut réel et généralisé : les variantes « outline » de Bootstrap écrivent
leur texte dans la couleur de marque, ce qui donne 4,45 pour le gris sur fond
tertiaire et 1,63 pour le jaune. Les liens du pied de page étaient dans le même
cas. L'écart ne se voit pas depuis un écran de développeur ; il se voit d'un
téléphone en plein soleil, ou d'un œil de plus de cinquante ans — c'est-à-dire
dans les conditions réelles où l'on cherche le code du portail.

La correction réutilise les jetons `--bs-*-text-emphasis` de Bootstrap plutôt
que d'inscrire des couleurs en dur : ils passent le seuil et basculent avec le
thème sombre, qui est le thème par défaut d'une bonne partie des téléphones.

### Illustrations du livret

`SPECIFICATIONS.md §55` demande une section déchets « configurable et sourcée :
types, lieux, carte, horaires, **photos**, consignes ». Le livret était
entièrement textuel : les photos manquaient, partout, depuis l'itération 10.

Chaque bloc accepte désormais une illustration choisie dans la médiathèque
existante — le traitement d'image, la suppression des métadonnées GPS, les
variantes et les légendes traduites y sont déjà faits une fois pour toutes.
Elle s'affiche dans « Mon séjour », derrière un lien invité et sur la page
ouverte depuis un QR.

Deux règles la tiennent :

- **seuls les médias publiés et non privés** sont proposés. Le livret est lu
  par un voyageur qui n'est pas administrateur, et par un visiteur anonyme sur
  les pages QR : un média privé y produirait une image cassée, c'est-à-dire une
  illustration qui n'illustre rien ;
- **le texte alternatif retombe** sur la légende traduite, puis sur le titre du
  bloc. Une image sans alternative textuelle n'existe pas pour qui ne la voit
  pas — et une photo de local à poubelles s'adresse justement à quelqu'un qui
  cherche.

Supprimer un média retire l'illustration et laisse le texte : c'est lui qui
porte l'essentiel de l'information.

## Relecture intégrale des spécifications

### Livré (0.17.0)

La consolidation précédente avait été guidée par la feuille de route. Celle-ci
part du texte des spécifications, section par section, des 76. Soixante-treize
étaient servies. Trois ne l'étaient pas, et aucune ne se signalait par une
erreur — c'est la marque des exigences qu'on n'implémente pas : rien ne casse,
il manque seulement quelque chose à quelqu'un qui n'est pas là pour le dire.

### Le premier champ invalide prend le focus

`SPECIFICATIONS.md §10` demande que la première erreur d'un formulaire reçoive
le focus. Le produit marquait les champs en rouge et affichait le message, mais
laissait le curseur là où il était : sur un formulaire long, l'erreur pouvait
se trouver hors de l'écran, et le message annonçait un problème invisible.

`focusFirstInvalid()` place le focus sur le premier champ invalide au chargement
et pose `aria-invalid="true"` sur les contrôles concernés — un lecteur d'écran
annonce alors l'erreur, il ne se contente pas d'une bordure rouge. Le focus
n'est jamais volé : si l'utilisateur a déjà commencé à saisir ailleurs, la
fonction ne fait rien. Le document est déduit de la racine reçue, jamais pris
dans le global, de sorte que la fonction se teste sans navigateur.

### Le journal fichier tourne

`SPECIFICATIONS.md §16` fixe une rétention au journal. Elle était appliquée aux
lignes en base, mais le fichier `storage/logs/app.log` grossissait sans limite :
sur un hébergement mutualisé, un quota disque saturé par un journal met le site
hors service — et le fait au pire moment, celui où quelque chose journalise
beaucoup.

Le fichier tourne désormais à 2 Mio, sur trois générations, et la purge de
rétention emporte les générations trop anciennes en même temps que les lignes en
base. Rien n'est supprimé sans avoir été conservé : la rotation décale avant
d'écrire, jamais l'inverse.

### Carte et source des blocs du livret

`SPECIFICATIONS.md §55` demande une section séjour « configurable et **sourcée**
: types, lieux, **carte**, horaires, photos, consignes ». Les photos étaient
arrivées avec la consolidation précédente. Le texte libre portait les types, les
lieux, les horaires et les consignes. Deux mots restaient sans réponse.

Chaque bloc porte désormais un **lien ouvrable** et une **source datée** :

- le lien mène là où le texte ne suffit pas — carte du local à poubelles, plan
  d'accès, horaires officiels d'un service. « Au bout de la rue à gauche » ne se
  suit pas depuis un téléphone, dans le noir, avec une valise ;
- la source dit d'où vient l'information et quand elle a été vérifiée. Les jours
  de collecte, les horaires de déchèterie et les arrêtés municipaux changent ;
  un livret qui affirme sans le dire vieillit sans prévenir. C'est déjà
  l'exigence des activités locales (§58) et de la conformité (§12).

Trois règles les tiennent :

- **seuls `http` et `https` sont acceptés.** Une adresse saisie devient un
  `href` : `javascript:` ou `data:` y serait une injection déguisée en
  commodité. Une adresse trop longue est refusée, jamais tronquée — une URL
  coupée est un lien mort qui a l'air bon ;
- **les huit blocs sont validés avant la première écriture.** Un livret à moitié
  enregistré, dont un bloc porte l'ancienne carte et le suivant la nouvelle, est
  plus difficile à rattraper qu'un refus net ;
- **rien n'est jamais récupéré par le serveur.** Ces adresses ne deviennent
  qu'un lien rendu avec `rel="noopener noreferrer"` : il n'y a pas de surface
  SSRF ici, et `UrlGuard` n'a pas à intervenir.

Les quatre champs vivent par bloc **et par langue**, comme le reste du livret :
une commune néerlandophone et une commune francophone ne publient pas la même
page de collecte, et un lien vers la mauvaise est pire qu'aucun lien.

## Revue de code de la relecture

### Livré (0.17.1)

Relecture adverse du code écrit pendant les deux passes précédentes : non pas
« est-ce que ça marche », mais « qu'est-ce qui casse en silence ». Douze
défauts, dont trois sérieux — et aucun ne se signalait par une erreur, ce qui
est exactement pourquoi ils avaient traversé la validation.

### Un rappel perdu par une panne de courrier ne repartait jamais

`hasBeenSent()` comptait une tentative **en échec** comme un envoi. Une panne
SMTP d'une heure au moment du passage nocturne perdait donc le rappel
définitivement — et, plus grave, le rendait irrattrapable : relancer la tâche
une fois le serveur rétabli ne faisait plus rien du tout, puisque le message
était réputé parti.

Une tentative ratée n'est pas une décision. Seuls comptent désormais l'envoi
réussi et le canal volontairement désactivé. Le compromis est assumé : un
courrier effectivement remis mais rapporté en échec — un délai d'attente
dépassé — sera envoyé deux fois. Recevoir deux fois le rappel de son séjour est
un désagrément ; ne pas le recevoir du tout est une porte close devant laquelle
quelqu'un attend.

### Dépublier un bloc dans une langue ne fermait pas son adresse

Le repli de langue s'appliquait dès que la langue demandée ne rendait rien —
**y compris quand le propriétaire avait explicitement retiré ce bloc-là du web
ouvert**. Le scénario est concret : il s'aperçoit que son texte allemand
contient le code de la boîte à clés, décoche « adresse publique » sur
l'allemand, et `/de/info/access` continue de répondre 200 avec le bloc
français. Il croit avoir fermé une porte.

Le repli comble désormais une **lacune** — bloc jamais traduit, ou traduit puis
vidé — et ne défait pas une **décision**. Un bloc renseigné puis retiré répond
404 dans sa langue. C'est ce qui donne son sens au réglage langue par langue,
que `SECURITY.md` annonçait sans que le code le tienne.

### Une adresse fautive emportait tout le livret saisi

L'écran du livret porte huit zones de texte. Une faute de frappe dans une
adresse de carte redirigeait avec un message d'erreur, et la page revenait à
son état enregistré : toute la saisie perdue, pour une erreur sans rapport avec
ce qui avait été écrit. Le refus revient maintenant en 422 avec la saisie
intacte, le champ fautif marqué et le message posé **à côté de ce champ** — une
erreur affichée au mauvais endroit ne vaut pas mieux qu'une erreur absente. Le
focus du premier champ invalide (§10), inopérant sur cet écran faute
d'`is-invalid`, y fonctionne enfin.

### Les autres

- **un rappel partait pour une demande non confirmée.** « Votre séjour commence
  dans sept jours » engageait le propriétaire à sa place ;
- **le schéma d'un lien n'était garanti que par le formulaire.** Twig échappe le
  contenu d'un attribut, pas son schéma : le contrôle est rejoué à l'affichage,
  de sorte qu'aucun chemin d'écriture futur ne puisse produire un `href`
  exécutable ;
- **les illustrations coûtaient seize requêtes** là où le commentaire en
  promettait une. Un test compte désormais les requêtes réellement servies :
  deux pour huit blocs, zéro pour un livret sans image ;
- **`robots.txt` codait les quatre langues en dur**, seul endroit de
  `SeoBuilder` à ne pas dériver de `Locales::ALL` — une cinquième langue serait
  devenue indexable sans que rien ne le signale ;
- **la pastille du courrier non rattaché plafonnait à cinquante**, alors que la
  règle posée est qu'un compte est un nombre d'éléments à traiter ;
- **`lastSuccessfulRun()` ne rendait pas la dernière réussite** mais la dernière
  exécution — le comportement était le bon, le nom mentait ;
- **`abandon()` n'était appelé de nulle part**, et `Migrator::pending()` était
  interrogé deux fois par affichage du tableau « À faire ».

### Un cron horaire ne doit pas allumer un voyant rouge

`README.md` promettait la fréquence de cron « que l'hébergement autorise ». Le
diagnostic, lui, exigeait un passage toutes les trente minutes — trois
intervalles de `booking_holds`. Sur une bonne partie des hébergements mutualisés,
qui n'offrent qu'un cron horaire, l'écran de diagnostics affichait donc « cron
silencieux » la moitié de chaque heure, sur une installation qui fonctionne.

Un écran rouge en permanence est un écran qu'on cesse de lire, ce qui coûte plus
cher que l'absence de diagnostic. `staleAfterMinutes()` porte désormais un
plancher de trois heures : au-delà, même un cron horaire a réellement cessé de
passer. Les tâches lentes gardent leurs trois intervalles. Et la documentation
dit maintenant ce que le produit attend vraiment — une fois par heure au
minimum, toutes les dix minutes de préférence — au lieu de promettre une
tolérance qu'il n'avait pas.

### Le jeton du planificateur, dit franchement

Le commentaire affirmait que le jeton n'était « jamais journalisé ». C'est vrai
du produit, faux du serveur web de l'hébergeur, qui écrit l'URL complète dans
son journal d'accès. L'en-tête `X-Scheduler-Token` est donc accepté et préféré ;
le paramètre d'URL reste admis parce que beaucoup d'hébergements n'offrent qu'un
champ « adresse à appeler », et que leur fermer la porte reviendrait à les
priver de sauvegarde et de purge. Le risque résiduel est nommé dans
`SECURITY.md` plutôt que passé sous silence.

## Portabilité de la translittération

### Livré (0.17.2)

Trois endroits du produit ramenaient du texte accentué à l'ASCII par
`iconv('UTF-8', 'ASCII//TRANSLIT', …)` : les initiales de l'icône PWA, les
slugs et le dernier recours du générateur PDF. Cette fonction délègue à la
bibliothèque C de l'hôte, et les deux implémentations répandues ne donnent pas
le même résultat : la glibc rend « Été » par « Ete », la libiconv des BSD et de
macOS par « 'Et'e ».

Le défaut ne se voit pas en intégration continue, qui tourne sur Linux. Il se
voit sur toute machine dont le PHP est lié à la libiconv des BSD — un poste de
développement macOS, et tout hébergement de cette famille : l'icône de
l'application installée y affiche « EE » pour « Été Indien », les apostrophes
ajoutées par la translittération coupant le premier mot en deux, de sorte que
la deuxième initiale est prise dans la moitié restante du premier mot.
`PwaTest` disait vrai depuis toujours ; c'était le code qui dépendait de
l'hôte.

Livré :

- `Support\Ascii::fold()`, table explicite couvrant Latin-1 Supplement, Latin
  Extended-A, les ligatures (`œ`, `æ`, `ĳ`, `ß`) et la ponctuation
  typographique des quatre langues. Ce qu'elle ne connaît pas est retiré, non
  approximé : perdre un caractère est acceptable, en introduire un que la
  source ne portait pas ne l'est pas ;
- les trois appels à `iconv` remplacés. `Slugger` n'utilise plus non plus la
  translittération ICU : un slug entre dans des URLs et des noms de fichiers,
  et deux installations du même produit ne peuvent pas en produire deux
  versions selon que `intl` est chargé ou non ;
- `AsciiTest`, qui vérifie notamment qu'aucune apostrophe n'apparaît là où la
  source n'en portait pas, et que le filtre final ne vide pas une chaîne en
  UTF-8 invalide.

Corrigé au passage : le tableau d'état d'avancement annonçait encore
l'itération 14 « à venir » alors que la section qui la suit la documente comme
livrée en 0.15.0.

### Le même défaut, côté campagne

La recherche a fait apparaître une seconde hypothèse d'hôte, dans le harnais
cette fois. `DatabaseTestCase` posait son bac à sable sous
`sys_get_temp_dir()` sans le résoudre. Sur macOS cette valeur est
`/var/folders/…`, un lien symbolique vers `/private/var/folders/…` :
`DocumentService::absolutePath()` rendait la forme résolue — il appelle
`realpath()` avant de vérifier que le fichier ne sort pas de la racine du
stockage — et le test comparait deux écritures du même dossier.

Le produit avait raison, le test avait tort, et l'intégration continue ne
pouvait pas le dire : `/tmp` n'est pas un lien sur l'exécuteur Linux. Le bac à
sable est désormais canonique dès sa création.

### Et une troisième, dans la commande de validation elle-même

`check.sh` construisait la liste d'arguments de Playwright dans un tableau
vide lorsque `SECONDSTAY_E2E_PROJECT` n'est pas défini, puis l'expansait par
`"${project[@]}"`. Bash 4 accepte ; bash 3.2 — celui que macOS livre encore,
et le seul disponible sans installation supplémentaire — considère l'expansion
d'un tableau vide comme une variable non définie, et `set -u` interrompt alors
la commande entière. La campagne E2E ne démarrait pas du tout, sans message
autre que `project[@]: unbound variable`.

C'est le même schéma que les deux précédents : une hypothèse d'hôte invisible
depuis un exécuteur Linux. `${project[@]+"${project[@]}"}` fonctionne sur les
deux versions.

### Une transcription lue avant d'être écrite

Rejouer la suite unitaire plusieurs fois de suite a fait tomber
`ImapClientTest::testAFullSessionSpeaksTheExpectedProtocol` environ une fois
sur cinq, sur une transcription vide. Le bouchon IMAP publiait la sienne par
`file_put_contents`, qui crée le fichier puis écrit : deux opérations, et le
test attendait la première en croyant attendre la seconde.

Une suite qui échoue une fois sur cinq sans rien casser est pire qu'une suite
rouge, parce qu'on prend l'habitude de la relancer. Le bouchon publie
désormais par `rename()`, en une seule opération visible, et l'attente du test
porte sur un contenu non vide plutôt que sur l'existence du fichier — une
attente qui accepte zéro octet ne prouve rien.
