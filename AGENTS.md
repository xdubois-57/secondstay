# AGENTS.md

Règles obligatoires du dépôt pour Claude Code et tout autre agent de développement.

## 1. Règles produit non négociables

1. Une installation représente **un seul logement**.
2. Ne pas introduire de modèle multi-propriété sans demande explicite.
3. Le projet est spécialisé pour la France.
4. Les données spécifiques au logement ne doivent jamais être codées en dur dans le dépôt public.
5. Les contenus, prix, médias, règles locales, informations de séjour et configuration métier vivent en DB/config/storage.
6. La production doit fonctionner sur hébergement PHP mutualisé sans Composer ni npm installés.
7. Les médias runtime restent locaux à l’hébergement ; pas de dépendance S3/cloud.
8. Les sauvegardes sont réalisées en PHP et incluent DB + médias/documents persistants.
9. Les mots de passe sont hachés, jamais chiffrés réversiblement.
10. Les secrets et données personnelles sensibles sont chiffrés au repos lorsque pertinent.
11. Aucune donnée carte bancaire n’est stockée.
12. La simplicité UX est une exigence fonctionnelle.
13. Le produit supporte dès le début **français, anglais, néerlandais et allemand**.
14. Toute modification qui rend `README.md`, `ARCHITECTURE.md`, `SECURITY.md`, `SPECIFICATIONS.md`, `I18N.md`, `TESTING.md`, `RELEASE.md`, `ROADMAP.md` ou ce fichier obsolète est incomplète.

## 2. Architecture

Utiliser un monolithe modulaire en couches :

```text
public/index.php
→ Router
→ Security / Session / CSRF / RBAC
→ Controller
→ Service
→ Repository
→ PDO
```

Rendu :

```text
Controller → Twig → Bootstrap 5 + JavaScript
```

Règles :

- Les contrôleurs n’embarquent pas de logique métier substantielle.
- Les services portent les règles métier, workflows et transactions.
- Les repositories portent SQL.
- SQL utilise PDO et requêtes préparées.
- Les vues ne requêtent jamais directement la DB.
- Éviter un framework complet sans besoin concret.
- Préférer le code explicite aux abstractions magiques.
- Utiliser de petites interfaces aux frontières externes : paiement, mail, IMAP, push, LLM, release, futur accès connecté.
- Garder le domaine spécialisé pour un logement unique.

## 3. Structure recommandée

```text
/
├── public/
├── src/
├── config/
├── migrations/
├── storage/
├── tests/php/
├── tests/js/
├── tests/e2e/
├── scripts/
├── bootstrap/bootstrap.php   installeur autonome, publié comme asset de release
├── vendor/
├── composer.json
├── package.json
├── VERSION
├── README.md
├── ARCHITECTURE.md
├── SECURITY.md
├── SPECIFICATIONS.md
├── I18N.md
├── TESTING.md
├── RELEASE.md
├── ROADMAP.md
├── AGENTS.md
└── CLAUDE.md
```

## 4. Sécurité de la racine web

Supposer que tous les fichiers du dépôt peuvent physiquement se trouver sous le document root public.

Obligatoire :

- protection racine `.htaccess` deny-by-default ;
- seuls les endpoints/assets publics voulus sont accessibles ;
- accès direct refusé à `src`, `config`, `vendor`, `storage`, `tests`, migrations, scripts, documentation, Composer, environnement et secrets ;
- tests Playwright vérifiant 403/404 sur les chemins sensibles ;
- ne jamais supposer que l’hébergeur protège implicitement un dossier.

## 5. Configuration et secrets

Utiliser des paramètres typés avec validation, enums, plages, aide et UX claire.

Secrets :

- jamais commités ;
- jamais loggés ;
- jamais affichés intégralement ;
- chiffrés au repos lorsqu’ils sont en DB ;
- masqués dans diagnostics et audit.

Centraliser le chiffrement et permettre la rotation de clé.

## 6. Internationalisation

Langues obligatoires dès l’itération 0 :

```text
fr
en
nl
de
```

Règles :

- aucun texte système utilisateur ne doit être dispersé en dur dans les contrôleurs/services/templates ;
- utiliser des clés de traduction ;
- les contenus éditoriaux DB sont traduisibles ;
- les contrats, CGV et contenus juridiques sont versionnés par langue ;
- les e-mails/push sont localisés selon la langue du destinataire ;
- fallback explicite et déterministe ;
- tests E2E au minimum sur une langue latine autre que FR et une langue germanique ;
- les quatre langues doivent être testées pour les parcours critiques avant release.

Voir `I18N.md`.

## 7. Rôles

Rôles simples :

- Public
- Client identifié
- Responsable local
- Administrateur

Plusieurs administrateurs et plusieurs responsables locaux sont supportés.

L’administrateur hérite des capacités du responsable local.

Ne pas recréer un moteur générique de permissions sans nécessité.

## 8. Réservation

Support obligatoire :

- prix par jour ;
- prix par défaut ;
- overrides de période ;
- indisponibilités ;
- blocs propriétaire/maintenance ;
- arrivée/départ configurables ;
- règle samedi-samedi optionnelle ;
- capacité et nombres de voyageurs ;
- codes promo ;
- ménage ;
- liste d’attente/alerte.

Défaut ménage : obligatoire, 100 EUR.

L’anti-double-réservation est transactionnel côté DB/business, jamais seulement visuel.

## 9. États réservation

États principaux visibles :

- Demande
- À confirmer
- Confirmée
- Séjour en cours
- Terminée
- Annulée
- Refusée

Sous-domaines séparés :

- contrat ;
- paiements ;
- caution ;
- check-in ;
- check-out ;
- ménage ;
- tâches.

Présenter ces sous-états comme des actions/checklists, pas comme jargon technique.

## 10. Paiements

Utiliser `PaymentProvider`.

Premier provider : Mollie.

Composants financiers distincts :

- hébergement ;
- acompte ;
- solde ;
- caution ;
- ménage ;
- taxe de séjour ;
- remboursements ;
- ajustements.

L’acompte requis pour confirmer doit être automatiquement confirmé par le provider par défaut.

Le virement bancaire classique ne confirme pas automatiquement sauf activation explicite d’un mode manuel.

Support QR EPC pour les virements SEPA autorisés.

Webhooks authentifiés selon capacités provider et idempotents.

## 11. SMTP, IMAP et notifications

Envoi : SMTP.

DKIM : géré par le fournisseur SMTP par défaut. L’application vérifie SPF/DKIM/DMARC dans le diagnostic.

Réception : synchronisation IMAP périodique d’une boîte dédiée.

Ne pas construire un client mail général.

Rattachement réservation, ordre de confiance :

1. adresse Reply-To/token réservation ;
2. `Message-ID`, `In-Reply-To`, `References` ;
3. référence réservation ;
4. adresse client connue ;
5. classement manuel.

Toute pièce jointe d’un mail rattaché apparaît automatiquement dans les Documents de la réservation avec provenance.

Quand le push est actif, toujours tenter **e-mail + push**. Un canal n’est pas le fallback de l’autre.

## 12. Documents

Support :

- contrat généré ;
- snapshot accepté immuable ;
- contrat signé ;
- descriptif ;
- reçu ;
- justificatif ;
- état des lieux ;
- incident ;
- pièce jointe mail.

Ne jamais écraser silencieusement un snapshot juridiquement significatif.

## 13. PWA et séjour

PWA installable.

`Mon séjour aujourd’hui` s’adapte à :

- pré-arrivée ;
- arrivée ;
- séjour ;
- départ ;
- post-séjour.

Cache offline possible :

- Wi-Fi ;
- règles ;
- déchets ;
- sécurité ;
- contact responsable local ;
- instructions d’arrivée autorisées.

Pas d’écritures métier sensibles offline tant que la résolution de conflits n’est pas conçue.

Liens invités : sécurisés, révocables, scope limité, durée limitée.

## 14. États des lieux

Check-in : fenêtre configurable, photos de référence, signalement optionnel, commentaires/photos.

Check-out : photos obligatoires pour toutes les zones configurées comme requises, commentaires et photos supplémentaires.

UX mobile prioritaire.

## 15. France / conformité

L’application est spécialisée France.

L’UI de conformité explique :

- signification ;
- applicabilité ;
- où trouver l’information ;
- impact ;
- statut ;
- date dernière vérification ;
- source officielle ;
- échéance/revue.

Séparer configuration opérationnelle et conformité France.

Ne jamais présenter une estimation fiscale ou juridique comme un avis certain.

Règles et taux locaux sont configurables, versionnés et sourcés.

## 16. LLM

Le LLM n’est jamais source de vérité.

Les sources sont des URLs publiques configurées.

Le contenu récupéré est traité comme donnée non fiable.

Protections obligatoires :

- SSRF ;
- réseaux privés ;
- redirects ;
- prompt injection ;
- validation structurée de sortie ;
- aucune PII client pour la génération de contenu local.

Mettre en cache les résultats structurés ; jamais d’appel LLM à chaque page vue.

## 17. Logs et audit

Journal technique distinct de l’audit métier/sécurité.

Ne jamais logguer :

- mots de passe ;
- clés API ;
- tokens ;
- clés de chiffrement ;
- PII inutile.

Pas de stack trace publique en production.

Auditer notamment : prix, réservation, rôles, caution, remboursement, restore et config sensible.

## 18. Tests

Commande canonique :

```bash
./scripts/check.sh
```

Doit couvrir :

- syntaxe PHP ;
- PHPStan ;
- PHPUnit ;
- tests DB ;
- Vitest ;
- Playwright ;
- Composer audit.

CI ajoute :

- CodeQL pour langages supportés ;
- Dependabot ;
- SonarCloud.

### Aucune baseline commitée

**« Vert » signifie *aucun constat*, et non *aucun constat nouveau*.** Ni
PHPStan ni `tsc` ne portent de baseline dans ce dépôt.

La mécanique reste disponible (`composer run analyse:baseline`,
`npm run typecheck:baseline`) parce que l'alternative à une baseline n'est pas
« pas de baseline » : c'est quelqu'un qui éteint le garde-fou le jour où une
montée de dépendance produit cinquante constats un vendredi soir. La régénérer
sert à **accepter sciemment une dette existante**, jamais à faire taire un
constat que sa propre modification vient d'introduire — celui-là se corrige.

Commiter une baseline exige donc la raison dans le message de commit, et sa
disparition dès que la dette est payée.

### Licences des dépendances

Ajouter une dépendance de **production** sous une licence autre que MIT, BSD,
ISC ou Apache-2.0 est **une décision à soumettre, pas à prendre en silence**.
Ce projet est AGPL-3.0-or-later : la compatibilité dépend entièrement de la
licence de ce qu'on y combine, et dans un seul sens pour certaines d'entre
elles.

L'inventaire est **généré, jamais rédigé** :

```bash
php scripts/dependency-inventory.php
```

Il lit les fichiers de verrouillage — jamais `composer.json` ni
`package.json`, où `^3.11` n'est pas une version — et balaye
`public/assets/vendor/`. Une ressource embarquée sans entrée dans sa carte de
licences est rapportée **inconnue**, pas passée sous silence : ajouter une
bibliothèque oblige donc à compléter la carte.

Ce qui n'est pas couvert par la licence du projet — polices, images, marques —
doit être nommé explicitement, et jamais décrit comme s'il l'était. La section
reste imprimée même vide : « rien » est une information, l'absence de section
n'en est pas une.

Utiliser des fake providers pour :

- SMTP ;
- IMAP ;
- paiement ;
- push ;
- LLM.

Scénarios Playwright critiques :

- chemins sensibles ;
- auth/rôles ;
- réservation concurrente ;
- confirmation paiement ;
- mail IMAP + pièce jointe → document ;
- PWA/offline ;
- état des lieux mobile ;
- parcours critiques FR/EN/NL/DE.

## 19. Discipline de release

Une GitHub Release est l’unité installable.

Ne pas publier une archive arbitraire de `main`.

`scripts/release.sh` doit :

1. vérifier working tree propre ;
2. vérifier branche/remote ;
3. vérifier CI obligatoire ;
4. vérifier CodeQL/Dependabot ;
5. vérifier SonarCloud Quality Gate ;
6. lancer les gates locales requises ;
7. vérifier la version déployée précédente si applicable ;
8. bump SemVer ;
9. mettre à jour `VERSION` ;
10. commit/tag/push ;
11. installer Composer prod ;
12. construire ZIP ;
13. inspecter ZIP ;
14. publier GitHub Release ;
15. restaurer les dépendances dev locales si modifiées.

Le ZIP contient code production + `vendor/` et exclut tests, `.github`, `node_modules`, coverage, runtime `storage/`, secrets, config locale et tooling local.

Les bypass d’urgence, s’ils existent, doivent être explicites et reportés dans les notes de release.

## 20. Documentation

Avant de terminer une tâche, vérifier :

- README.md
- ARCHITECTURE.md
- SECURITY.md
- SPECIFICATIONS.md
- I18N.md
- TESTING.md
- RELEASE.md
- ROADMAP.md
- AGENTS.md
