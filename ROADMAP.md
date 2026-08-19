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
| 5 | Disponibilités et prix | ⏳ à venir |
| 6 | Réservation sans paiement | ⏳ à venir |
| 7 | Paiements | ⏳ à venir |
| 8 | Contrats, documents, IMAP | ⏳ à venir |
| 9 | Responsable local et opérations | ⏳ à venir |
| 10 | Mon séjour et invités | ⏳ à venir |
| 11 | États des lieux et incidents | ⏳ à venir |
| 12 | France et conformité | ⏳ à venir |
| 13 | Contenu local IA | ⏳ à venir |
| 14 | ICS externes, reporting, consolidation | ⏳ à venir |

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
