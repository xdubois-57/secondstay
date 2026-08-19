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

## 24. Décisions de mise en œuvre (itération 1)

### 24.1 Installation

- L'assistant d'installation n'est accessible que si `config/local.php`
  n'existe pas. Dès qu'une instance a été installée, il renvoie 404 **même si
  la base est injoignable** : une panne ne doit jamais permettre de réinstaller
  une instance existante. Dans ce cas le site public répond 503 et l'incident
  est journalisé en `critical`.
- `config/local.php` est écrit de façon atomique avec les permissions `0600` et
  contient les identifiants de base ainsi que le trousseau de clés de
  chiffrement. Il n'est jamais versionné ni servi.
- Les erreurs de connexion sont traduites par clé (`install.database.error.*`)
  et ne contiennent jamais d'identifiant.

### 24.2 Chiffrement

- `SecondStay\Security\Encryptor` : AEAD XChaCha20-Poly1305 (libsodium).
- Format `ss1.<key_id>.<nonce>.<ciphertext>` : le trousseau permet la rotation
  sans réécriture immédiate, et `SettingsService::rotateSecrets()` rechiffre à
  la demande avec la clé active.
- Le contexte (`setting:<clé>`) est authentifié : un secret ne peut pas être
  déplacé d'un réglage à un autre.
- Les mots de passe utilisent exclusivement `password_hash()`.

### 24.3 Sessions et CSRF

- Session PHP `HttpOnly`, `SameSite=Lax`, `use_strict_mode`, identifiant
  régénéré après authentification.
- Chaque session active possède une ligne `user_session` : liste des appareils,
  révocation individuelle ou globale, expiration. Une session révoquée coupe
  l'accès à la requête suivante.
- Toute mutation navigateur exige un jeton CSRF valide comparé en temps
  constant. Les webhooks fournisseurs sont exclus : ils sont authentifiés par
  signature, jamais par CSRF.
- Limitation de débit sur l'authentification, par adresse IP et par compte ; la
  réponse d'échec est identique qu'un compte existe ou non, et un hash factice
  est comparé pour égaliser le temps de réponse.

### 24.4 Journaux et audit

- `LogSanitizer` masque toute clé contenant `password`, `token`, `secret`,
  `api_key`, `session`, `iban`… et rédige les motifs de clés privées et de
  jetons porteurs, y compris dans les messages.
- Le journal d'audit est distinct du journal technique et couvre configuration
  sensible, comptes, rôles, sauvegardes, restaurations, mises à jour et
  maintenance.

### 24.5 Sauvegardes

- Manifeste avec SHA-256 par entrée ; la restauration refuse toute archive dont
  une empreinte diverge.
- L'identifiant de sauvegarde est validé par expression régulière stricte : la
  traversée de chemin est impossible sur le téléchargement, la vérification, la
  restauration et la suppression.
- La restauration n'écrit que dans `storage/media`, `storage/documents`,
  `storage/inspections` et `storage/mail-attachments`. Toute autre entrée est
  refusée.
- Le téléchargement passe par un endpoint contrôlé réservé aux administrateurs,
  avec `Cache-Control: private, no-store`.

### 24.6 Mises à jour

- Seul un artefact conforme à `ReleaseArtifactPolicy` est installable : la
  présence de tests, de `storage/`, de `config/local.php`, de `node_modules/`
  ou de matériel cryptographique invalide l'archive avant toute écriture.
- L'extraction refuse la traversée de chemin et n'écrit que dans les
  répertoires gérés.
- Échec = restauration du snapshot + `VERSION` précédente + audit.

### 24.7 Sorties HTTP

- Toute requête sortante passe par `HttpFetcher`. `UrlGuard` bloque les
  protocoles hors HTTP(S), localhost, loopback, link-local, RFC1918, CGNAT,
  IPv6 uniques locales et mappées, ainsi que les noms d'hôtes internes. La
  cible est vérifiée à chaque redirection, pas seulement sur l'URL initiale.
