# SECURITY.md

## 1. Portée

L’application manipule comptes, réservations, données de paiement non-card, contrats, e-mails, documents, photos et données administratives françaises. La sécurité est une exigence produit fondamentale.

## 2. Menaces principales

- exposition de fichiers privés sous document root ;
- SQL injection ;
- XSS ;
- CSRF ;
- broken access control / IDOR ;
- takeover compte ;
- brute force / credential stuffing ;
- upload malveillant ;
- pièces jointes e-mail hostiles ;
- SSRF ;
- prompt injection LLM ;
- webhook forgery/replay ;
- fuite de secrets ;
- compromission de release/supply chain ;
- restore dangereux ;
- logs contenant PII/secrets ;
- cache PWA sensible ;
- fuite token ICS/share link.

## 3. Protection du document root

Supposer que tout le dépôt peut être servi par Apache si non protégé.

`.htaccess` racine obligatoire avec deny-by-default pour les ressources non publiques.

Refuser l’accès direct à :

- `/src`
- `/config`
- `/vendor`
- `/storage`
- `/tests`
- `/migrations`
- `/scripts`
- `/.github`
- `.env*`
- fichiers Composer
- fichiers package/dev
- documentation racine
- backups
- clés/secrets

Playwright doit tester ces chemins.

## 4. Authentification

- password hashing via API PHP recommandée ;
- aucun mot de passe réversible ;
- vérification e-mail ;
- reset token expirable et single-use ;
- WebAuthn/passkeys ;
- session IDs cryptographiquement sûrs ;
- rotation après auth ;
- gestion/revocation sessions ;
- rate limit login/signup/reset ;
- CAPTCHA uniquement si nécessaire.

## 5. Authorization

Vérification serveur pour chaque ressource sensible.

Toute lecture/écriture de réservation, document, mail, état des lieux, paiement et donnée conformité doit vérifier le rôle et la relation à la ressource.

Ne jamais considérer un bouton masqué comme un contrôle d’accès.

## 6. CSRF

Toute mutation navigateur nécessite une protection CSRF sauf protocole authentifié explicitement différent.

## 7. SQL

PDO prepared statements uniquement.

Aucune concaténation d’entrée utilisateur dans SQL.

## 8. XSS / HTML

Twig auto-escaping par défaut.

Rich HTML : sanitation allowlist explicite.

HTML e-mail reçu : non fiable, toujours sanitizé avant rendu.

## 9. Uploads / attachments

Contrôles :

- max size ;
- allowlist types/extensions ;
- noms serveur générés ;
- stockage non exécutable ;
- MIME/content checks lorsque possible ;
- image re-encoding lorsque pertinent ;
- EXIF orientation ;
- suppression GPS pour médias privés ;
- hash si utile ;
- téléchargement contrôlé des documents privés.

Si antivirus indisponible sur hébergement mutualisé, compenser par stockage non exécutable et contrôles stricts.

## 10. Chiffrement

Service central d’authenticated encryption.

Clé séparée de la DB protégée.

Prévoir rotation.

Chiffrer uniquement ce qui bénéficie réellement du chiffrement au repos.

Mots de passe = hash, jamais encryption.

## 11. Secrets

Secrets typiques :

- DB password ;
- SMTP ;
- IMAP ;
- Mollie ;
- VAPID ;
- LLM ;
- encryption key ;
- tokens privés.

Jamais :

- en clair dans repo ;
- dans logs ;
- dans diagnostics ;
- dans exceptions publiques ;
- réaffichés intégralement.

## 12. SMTP / IMAP

Credentials chiffrés au repos.

DKIM provider-side par défaut.

Diagnostic SPF/DKIM/DMARC sans stocker la clé DKIM privée.

Mail entrant : HTML/attachments non fiables.

Ne pas envoyer automatiquement contrats ou mails sensibles au LLM.

## 13. Paiement

Aucune donnée carte.

Hosted/provider checkout.

Webhook :

- authenticité vérifiée selon capacités provider ;
- idempotence ;
- retries ;
- out-of-order tolerant ;
- journalisation ;
- browser return jamais autorité de paiement.

## 14. PWA

Ne jamais cacher offline :

- contrats privés ;
- données paiement ;
- pages admin ;
- comptes complets ;
- secrets.

Cache offline uniquement pour informations de séjour appropriées.

## 15. ICS / guest links

Tokens = bearer secrets.

Doivent être :

- longs ;
- random ;
- non devinables ;
- révocables ;
- régénérables.

Minimiser les données dans ICS.

## 16. LLM / SSRF

Bloquer :

- localhost ;
- loopback ;
- link-local ;
- RFC1918/private ;
- internal hostnames ;
- protocoles hors HTTP(S) ;
- redirects vers réseau interdit.

Résolution DNS et cible finale doivent être vérifiées.

Les pages sont des données, jamais des instructions.

Pas de PII client dans la génération de contenu local.

## 17. Logs

Ne jamais logguer :

- passwords ;
- API keys ;
- private keys ;
- encryption keys ;
- tokens complets ;
- PII inutile.

Production : aucune stack trace au visiteur.

## 18. Backups

Pure PHP.

Inclut DB + médias/documents persistants.

Exigences :

- manifest ;
- checksums ;
- admin-only ;
- pas d’URL publique ;
- validation restore ;
- audit restore ;
- retention ;
- disk monitoring ;
- protection path traversal.

## 19. Updates / supply chain

Installer uniquement l’artefact attendu d’une GitHub Release de confiance.

Avant install :

- version metadata ;
- structure asset ;
- backup ;
- maintenance.

Après install :

- migrations ;
- VERSION ;
- health check ;
- rollback si échec.

## 20. Dependencies

- composer.lock commité ;
- package-lock commité ;
- composer audit ;
- Dependabot ;
- CodeQL supporté ;
- SonarCloud ;
- dépendances frontend vendorisées maintenues à jour.

## 21. RGPD

Collecter seulement ce qui est nécessaire.

Support :

- export ;
- suppression ;
- anonymisation ;
- retention ;
- versioning consentements ;
- purge ;
- conservation légale lorsque applicable.

E-mails et photos d’état des lieux ont une règle de retention explicite.

## 22. Multilingue et sécurité

La traduction ne doit jamais modifier :

- règles d’autorisation ;
- validation métier ;
- montants ;
- dates source ;
- paramètres de sécurité.

Les traductions rich-text sont sanitizées comme les contenus source.

Les textes juridiques sont versionnés par langue pour éviter qu’une réservation accepte un texte différent de celui affiché.

## 23. Baseline de tests sécurité

Automatiser :

- auth/authorization ;
- chemins sensibles ;
- CSRF ;
- uploads ;
- XSS/sanitation ;
- SSRF ;
- webhook replay/idempotency ;
- session revocation ;
- backup restore ;
- release leakage ;
- tokens share/ICS ;
- traduction/fallback sans contournement sécurité.

Toute vulnérabilité corrigée doit recevoir un test de régression.
