# SPECIFICATIONS.md

# Cahier des charges fonctionnel et technique

## 1. Vision

Créer un site de location complet pour **un seul meublé de tourisme en France**.

Objectif : simplifier la vie du propriétaire, des voyageurs et du responsable local, malgré les contraintes administratives françaises.

Le logiciel est réutilisable pour un autre logement via une autre installation/configuration, pas via un moteur multi-propriétés.

## 2. Langues

Support obligatoire dès le début :

- Français (`fr`)
- Anglais (`en`)
- Néerlandais (`nl`)
- Allemand (`de`)

Tous les parcours utilisateur, e-mails, notifications, contenus de séjour, contrats et textes fonctionnels doivent respecter cette architecture multilingue.

Le français est la langue par défaut initiale, configurable.

Voir `I18N.md`.

## 3. UX

Principes :

- ne montrer que ce qui est utile maintenant ;
- privilégier les tâches aux états techniques ;
- préremplir ce qui est connu ;
- ne pas demander deux fois la même information ;
- automatiser ce qui peut l’être de façon fiable ;
- limiter les réglages aux vraies différences de déploiement ;
- mobile-first pour l’expérience séjour ;
- accessibilité WCAG 2.2 AA.

## 4. Rôles

### Public

- consulter site ;
- disponibilité ;
- tarifs/règles ;
- galerie ;
- contenus ;
- créer compte ;
- initier réservation.

### Client identifié

- compte ;
- sessions ;
- passkey ;
- réservations ;
- paiements ;
- documents ;
- séjour ;
- états des lieux ;
- incidents ;
- export/suppression données selon retention.

### Responsable local

- réservations opérationnelles ;
- états des lieux ;
- incidents ;
- informations nécessaires au séjour ;
- calendrier ICS.

Pas de pouvoir financier/administratif global.

### Administrateur

Tous les droits applicatifs. Plusieurs administrateurs possibles. Hérite du rôle opérationnel responsable local.

## 5. Navigation

Menu multi-niveaux inspiré de ScoutMagic.

Desktop : navigation + sous-menus + compte en haut à droite.

Mobile : hamburger + sections + compte intégré.

Le sélecteur de langue est accessible sans perturber la navigation.

## 6. Site public

Pages possibles :

- Accueil
- Le logement
- Disponibilités
- Tarifs
- Galerie
- Activités
- Accès
- Contact
- Mentions légales
- Confidentialité/RGPD
- CGV/conditions

Contenus éditoriaux administrables et traduisibles.

Les labels système/sécurité restent en catalogues de traduction dans le code.

## 7. Été / hiver

Présentation adaptable par saison configurée :

- hero ;
- photos ;
- textes ;
- activités ;
- messages séjour.

## 8. Galerie

Admin : upload, catégories, légendes multilingues, ordre, saison, visibilité.

Public : grille responsive, lightbox, navigation tactile.

Traitement : thumbnails, optimisation, EXIF orientation, suppression GPS pour médias privés, WebP/AVIF si robuste.

## 9. SEO multilingue

Support par langue :

- title ;
- meta description ;
- Open Graph ;
- canonical ;
- sitemap ;
- robots ;
- structured data ;
- `hreflang` si URL locales.

## 10. Comptes

Inscription minimale : prénom, nom, e-mail, téléphone.

Confirmation e-mail avant activation.

Auth : email/password, reset, changement, passkeys/WebAuthn.

Password strength dynamique, compatible gestionnaires de mots de passe.

HTML inputs appropriés : email, tel, autocomplete.

Erreur formulaire : message clair + focus premier champ invalide.

Sessions : liste, appareils, révocation, logout autres appareils.

Rate limiting et anti-abus.

## 11. Préférence langue utilisateur

Le compte peut stocker la langue préférée FR/EN/NL/DE.

Les notifications utilisent cette langue.

Une langue choisie avant login est conservée ou reprise au moment opportun.

## 12. Configuration site

Page d’administration structurée, avec aide propriétaire.

Sections :

- logement ;
- contenu ;
- réservation ;
- tarifs ;
- paiement ;
- e-mail ;
- IMAP ;
- push ;
- PWA ;
- conformité France ;
- IA ;
- backup ;
- update ;
- diagnostics.

## 13. DB

Installation : host, port, DB, username, password, charset, test connexion.

Secrets non réaffichés.

## 14. Settings typés

Types : string, text, bool, integer, decimal, money, enum, date, time, duration, email, URL, secret, JSON structuré.

Chaque setting possède validation et aide.

## 15. Chiffrement

Service central. Rotation de clé prévue.

Secrets typiques chiffrés : SMTP, IMAP, Mollie, VAPID, LLM.

Passwords = hash uniquement.

## 16. Logs

Niveaux : debug/info/warning/error/critical.

Champs : timestamp, catégorie, message, contexte, user/reservation si pertinent, correlation ID.

Admin : filtres, recherche, pagination, retention, rotation.

Aucune stack trace publique.

## 17. Audit

Journal distinct pour actions sensibles : prix, réservation, rôles, caution, remboursement, restore, documents critiques, config sensible.

## 18. Diagnostics

Vérifier : PHP, extensions, DB, permissions, disque, ZIP, crypto, SMTP, IMAP, SPF/DKIM/DMARC, push, Mollie, LLM, cron, backup, update.

## 19. Disponibilité

Public : libre/occupé + règles.

Admin : tarif défaut, overrides, indisponibilités, blocs propriétaire, maintenance.

## 20. Règles séjour

Configurables : arrivée, départ, samedi-samedi optionnel, durée minimale, multiples éventuels, capacité.

Exemple initial : samedi 16 h → samedi 10 h, désactivable.

## 21. Prix

Calcul nuit par nuit.

Calendrier affiche prix journalier.

Sélection de plage montre total live.

Montants formatés selon locale, logique financière canonique indépendante de la locale.

## 22. Voyageurs

Adultes, enfants, bébés selon configuration.

Capacité max configurable.

## 23. Codes promo

Montant fixe ou pourcentage, actif/inactif, dates, limite usage.

## 24. Ménage

Modes : aucun, optionnel, obligatoire.

Défaut : obligatoire, 100 EUR.

## 25. Réservation

Parcours : dates → voyageurs → prix → auth → infos → règles/consentements → paiement requis → confirmation.

Timeline de toutes les étapes importantes.

## 26. Statuts

Principaux : Demande, À confirmer, Confirmée, Séjour en cours, Terminée, Annulée, Refusée.

Sous-états séparés : contrat, paiements, caution, ménage, check-in, check-out.

## 27. Anti-double-booking

Garanti transactionnellement.

E2E obligatoire avec concurrence de deux navigateurs.

## 28. Liste d’attente

Client peut demander une alerte sur dates indisponibles.

Notification lorsque dates libérées.

## 29. Paiement

Abstraction `PaymentProvider`, Mollie premier provider.

Composants distincts : hébergement, acompte, solde, caution, ménage, taxe séjour, remboursements, ajustements.

Chaque objet a montant, échéance, méthode, statut, historique.

## 30. Acompte

Le paiement confirmant la réservation doit être automatiquement confirmé par provider par défaut.

Le virement classique n’auto-confirme pas sauf option manuelle explicite.

## 31. Solde

Règle type : un mois avant, immédiat si réservation tardive. Configurable.

## 32. Caution

Cycle : à payer, reçue, à restituer, restituée, partiellement retenue.

Initialement paiement puis remboursement plutôt que préautorisation longue.

## 33. QR EPC

Support IBAN, montant, référence réservation.

## 34. Webhooks

Idempotents, sécurisés, robustes aux retries/out-of-order, journalisés.

## 35. SMTP

Envoi SMTP authentifié.

DKIM provider-side.

Templates FR/EN/NL/DE.

## 36. IMAP

Sync périodique boîte dédiée.

Pas d’IMAP IDLE long-running par défaut.

Rattachement : Reply-To/token, headers thread, référence réservation, email client, manuel.

## 37. Timeline communication

Mails envoyés/reçus, événements pertinents et documents associés.

HTML mail sanitizé.

## 38. Pièces jointes mail

Toute pièce jointe d’un mail rattaché apparaît automatiquement dans Documents.

Conserver nom, MIME, taille, expéditeur, date, mail source, hash si pertinent.

Classement : contrat signé, justificatif, reçu, autre.

## 39. Contrats

PDF généré localisé FR/EN/NL/DE.

Contenu : parties, dates, logement, capacité, prix, acompte, solde, caution, ménage, taxe, horaires, annulation, état des lieux, conditions, version CGV.

Snapshot immuable version+locale.

## 40. Signature/acceptation

Version initiale : acceptation traçable, timestamp, version, locale, utilisateur, preuve technique raisonnable.

## 41. Documents

Contrat, contrat signé, descriptif, reçu, facture si applicable, justificatif, état des lieux, incident, pièce jointe, autre.

## 42. Notifications

Canaux : e-mail + push.

Si push actif, les deux sont envoyés indépendamment.

Événements : signup, réservation, paiement, rappel, arrivée, départ, incident, tâches.

## 43. PWA

Installable iPhone/Android : manifest, icons, service worker, standalone, push.

## 44. Offline

Autorisé : welcome book, Wi-Fi, règles, déchets, sécurité, contact local.

Pas de paiement/booking write/documents sensibles offline.

## 45. Mon séjour aujourd’hui

Avant : contrat/paiements/préparation.

Arrivée : accès/contact/check-in.

Pendant : Wi-Fi/activités/règles/déchets/incidents.

Départ : horaires/photos/check-out.

Après : caution/documents/incidents.

Toujours dans la langue choisie.

## 46. Lien invité

Token fort, révocable, expirant, limité au séjour, sans finances ni compte complet.

## 47. QR physiques

URLs stables vers Wi-Fi, déchets, appareils, règles, etc.

## 48. Responsable local

Plusieurs comptes, responsable par défaut, affectation par réservation, modifiable par admin.

## 49. Checklists

Avant séjour : contrat, acompte, solde, caution, ménage, responsable, accès.

Départ : état des lieux, incidents, ménage, caution.

## 50. Tableau À faire

Admin voit les éléments nécessitant action : paiement, contrat, incident, caution, conformité, backup, erreur, update.

## 51. ICS

Flux privés admin/responsable/client.

Tokens longs, uniques, révocables, régénérables.

Client ICS inclut contact responsable local.

## 52. Import ICS externe

Airbnb/Booking/Abritel/autres plus tard. Les événements bloquent les dates et gardent provenance.

## 53. États des lieux

Admin définit zones/objets/photos référence/ordre/obligation photo départ.

Arrivée : signalement dans X heures, facultatif si conforme.

Départ : photos obligatoires de toutes zones requises.

## 54. Incidents

Ticket avec réservation, zone, urgence, description, photos, statut, historique.

Statuts : signalé, pris en charge, résolu.

## 55. Déchets

Section séjour configurable et sourcée : types, lieux, carte, horaires, photos, consignes.

Règles locales jamais figées en code.

## 56. Contenu local IA

Admin : URLs simples, prompt libre, bouton « Générer le prompt à partir de la localisation », test, fréquence, activation.

Système ajoute localisation, saison, dates, sources, contraintes et schema.

## 57. Fenêtre IA

Commence X semaines avant réservation, typiquement 4–6, puis refresh hebdomadaire par défaut jusqu’au séjour.

## 58. Activités

Afficher uniquement celles disponibles pendant les dates exactes du séjour.

Groupes : à réserver à l’avance / à faire cette semaine.

Toujours source + date vérification.

## 59. Sécurité LLM

SSRF, redirects privés, prompt injection, validation schema, aucune PII client.

## 60. France

Séparer Configuration opérationnelle et Conformité France.

## 61. Assistant conformité

Pour chaque item : définition, applicabilité, où trouver, impact, statut, source, date vérification, échéance.

Statuts : conforme, à vérifier, non applicable.

## 62. Sujets conformité

Meublé tourisme, déclaration/enregistrement, SIRET, statut propriétaire, résidence principale/secondaire, classement, DPE, changement usage, taxe séjour, fiche police, contrat, annulation, médiation, assurances, risques locaux, débroussaillement si applicable, équipement hiver, déchets.

## 63. Taxe de séjour

Moteur dédié versionné : territoire, date, classification, adultes, mineurs, exemptions, nuits, taux/plafonds/règles.

Historiser le contexte de calcul.

## 64. Fiche de police

Seulement si applicable. Données chiffrées. Retention automatique selon règle légale documentée/configurée.

## 65. RGPD

Minimisation, consentements versionnés, export, suppression/anonymisation, retention, purge, audit, photos, e-mails, documents.

## 66. Reporting

Revenus reçus/attendus, cautions détenues, taxe séjour, occupation, prix moyen nuit, mois/année, XLSX comptable.

Pas de conseil fiscal automatisé présenté comme certain.

## 67. Backups

Pure PHP. Inclure DB + médias + documents + pièces jointes + inspections.

Fonctions : create, integrity, restore, test restore, retention, disk usage, audit.

## 68. Maintenance

Mode maintenance pour update, restore et migrations sensibles.

## 69. Auto-update

Source GitHub Releases. Bouton « Vérifier maintenant » + auto-update configurable.

Flux : download → validate → backup → maintenance → install → migrations → VERSION → health → rollback.

## 70. Tests

Local Mac et GitHub partagent les mêmes commandes.

`./scripts/check.sh` couvre PHP syntax, PHPStan, PHPUnit, DB, Vitest, Playwright, Composer audit.

GitHub ajoute CodeQL, Dependabot, SonarCloud.

## 71. Fake providers

Paiement, SMTP, IMAP, push, LLM.

## 72. E2E critiques

Install, auth, rôles, chemins sensibles, backup/restore, update, pricing, réservation, double booking, paiement, SMTP/IMAP, attachments→Documents, PWA, guest link, état des lieux mobile, conformité/versioning, FR/EN/NL/DE.

## 73. Release

`scripts/release.sh` inspiré de ScoutMagic et bloqué par les gates qualité/sécurité.

## 74. Artefact production

Inclut code/assets/vendor. Exclut git/github/tests/storage runtime/secrets/config locale/node_modules/coverage/IDE/état agents.

## 75. Documentation vivante

Une modification n’est pas terminée si elle rend faux les documents de référence.

## 76. Licence

AGPL-3.0-or-later.
