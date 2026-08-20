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

## 25. Décisions de mise en œuvre (itération 2)

### 25.1 Contenus riches

- Tout corps de page passe par `HtmlSanitizer` **à l'enregistrement** : la base
  ne contient jamais de HTML non assaini, et le rendu reste `|raw` sur une
  valeur déjà nettoyée.
- Liste blanche de balises et d'attributs ; `script`, `style`, `iframe`,
  `object`, `embed`, `form`, `input`, `link` et `meta` sont supprimés avec leur
  contenu ; les balises inconnues sont dépliées en conservant le texte.
- Schémas d'URL refusés : `javascript:`, `vbscript:`, `file:`, `about:` et
  toute `data:` qui n'est pas une image.

### 25.2 Médias

- Le type MIME déclaré par le client est ignoré : seul le contenu réel décide
  (`getimagesize`), et seuls JPEG, PNG, WebP et AVIF sont acceptés.
- Toute image est ré-encodée : une charge utile dissimulée dans un fichier
  « image » ne survit pas au traitement. Les métadonnées, dont la
  géolocalisation, disparaissent.
- Le nom de fichier est généré par le serveur (16 caractères hexadécimaux) ;
  le nom d'origine n'est conservé que comme libellé.
- Le stockage est hors racine web. La route de diffusion valide le nom de
  fichier et la variante par expression régulière stricte, puis applique la
  visibilité : un média privé ou dépublié exige le rôle responsable local.
- Taille maximale de 12 Mo, appliquée avant tout traitement.

### 25.3 Diffusion et cache

- Les médias publics sont immuables (nom aléatoire) : `Cache-Control: public,
  max-age=2592000, immutable`. Les médias privés sont servis en
  `private, no-store`.
- `X-Content-Type-Options: nosniff` sur toute réponse de fichier.

## 26. Décisions de mise en œuvre (itération 3)

### 26.1 Non-divulgation des comptes

- Inscription, réinitialisation et confirmation renvoient toujours la même
  réponse, qu'un compte existe ou non. Une inscription sur une adresse déjà
  connue n'écrase jamais le mot de passe existant : elle notifie le titulaire
  réel par un message `account_exists`.
- Les erreurs d'authentification restent génériques ; le hachage de mot de
  passe est exécuté même pour un compte inexistant (condensat constant), afin
  de ne pas révéler l'existence d'un compte par le temps de réponse.

### 26.2 Jetons

- Les jetons de confirmation et de réinitialisation sont stockés **hachés** :
  une fuite de base ne permet pas de les rejouer.
- Un jeton est à usage unique et invalide les précédents du même type.
- Durées de vie : confirmation 7 jours, réinitialisation 1 heure, changement
  d'adresse 24 heures.
- Une réinitialisation réussie révoque **toutes** les sessions du compte ; un
  changement de mot de passe depuis l'espace client conserve l'appareil
  courant et révoque les autres.

### 26.3 E-mails

- Aucun retour de ligne ne peut être injecté dans un en-tête : sujets,
  noms d'affichage et en-têtes personnalisés sont expurgés des caractères de
  contrôle, et le `Message-ID` n'est jamais dupliqué.
- Le nom d'une pièce jointe est réduit à `[A-Za-z0-9._-]`, borné à 120
  caractères, et retombe sur un nom neutre s'il ne contient aucun caractère
  alphanumérique : il ne peut pas s'échapper de `Content-Disposition`.
- Les erreurs SMTP sont exposées sous forme de clés de traduction ; ni l'hôte,
  ni le port, ni le message système ne remontent à l'interface.
- Les adresses sont masquées dans les journaux (`c*****@example.test`) et le
  corps des messages n'est jamais stocké en base.
- Le transport factice n'existe que si `SECONDSTAY_MAIL_TRANSPORT=fake` ; la
  boîte de test `/api/dev/mailbox` renvoie 404 dans toute autre configuration.

### 26.4 Clés d'accès

- Vérification complète de l'assertion : origine exacte, `rpIdHash`, défi
  stocké en session et expirant en 5 minutes, type d'opération, refus des
  requêtes inter-origines, signature ECDSA/RSA et **compteur strictement
  croissant** (un compteur qui recule signale une clé clonée).
- L'identifiant utilisateur transmis à l'authentificateur est un condensat
  opaque : ni l'adresse e-mail ni l'identifiant interne n'y figurent.
- Une clé déjà enregistrée est refusée ; les clés existantes sont exclues à
  l'enregistrement.
- Les endpoints `/api/passkeys/*` exigent le jeton CSRF (en-tête
  `X-CSRF-Token`) et sont limités en débit par adresse.
- Sur un domaine non enregistrable (adresse IP), la fonction est masquée et
  les endpoints renvoient 404 plutôt que d'échouer silencieusement.

### 26.5 RGPD

- L'export personnel ne contient ni condensat de mot de passe ni jeton de
  session ; il est servi en `private, no-store`.
- La suppression **anonymise** le compte plutôt que de l'effacer : l'intégrité
  comptable et contractuelle est préservée, les données directement
  identifiantes disparaissent, les sessions sont révoquées et le compte ne
  peut plus s'authentifier.
- Un administrateur doit d'abord transmettre son rôle avant de supprimer son
  compte.
- Les consentements sont horodatés avec leur version, leur langue et
  l'adresse IP d'acceptation.

### 26.6 Limitation de débit

- Inscriptions : 5 par adresse IP et par fenêtre de 15 minutes.
- Réinitialisations : 5 par compte et par fenêtre.
- Clés d'accès : 20 tentatives d'authentification par adresse IP et par
  fenêtre.
- Un administrateur peut remettre les compteurs à zéro depuis
  `/admin/diagnostics` ; l'action est tracée (`security.rate_limits_cleared`).

## 27. Décisions de mise en œuvre (itération 4)

### 27.1 Push chiffré de bout en bout

- La charge utile est chiffrée pour l'abonnement destinataire (RFC 8291) : le
  service de push relaie un conteneur qu'il ne peut pas lire, et le serveur
  lui-même ne peut pas relire un message déjà émis.
- Chaque envoi utilise un sel et une paire de clés éphémères distincts : deux
  notifications identiques produisent des octets différents.
- La charge utile reste minimale — titre, texte court, chemin applicatif.
  Aucune donnée sensible ne transite par le service de push, même chiffrée.
- Les clés VAPID sont générées par l'installation ; la clé privée est chiffrée
  au repos comme tout secret et n'est jamais réaffichée.
- Les erreurs des services de push sont exposées en clés de traduction : ni
  l'hôte, ni le corps de la réponse distante ne remontent à l'interface.
- Les endpoints de push sont soumis à la garde SSRF comme toute requête
  sortante ; une requête POST n'est jamais suivie en redirection.

### 27.2 Abonnements

- Un abonnement appartient toujours à un compte authentifié ; un visiteur
  anonyme ne peut pas s'abonner.
- L'endpoint doit être une URL HTTPS absolue de moins de 2 000 caractères, et
  les clés doivent avoir exactement la forme attendue (point non compressé de
  65 octets, secret de 16 octets) — la validation a lieu à la construction,
  jamais au moment de l'envoi.
- Un même endpoint ne peut jamais être enregistré deux fois, et un compte est
  limité à 10 appareils.
- Un abonnement révoqué par le navigateur (404 / 410) ou durablement en échec
  est supprimé plutôt que réessayé indéfiniment.
- On ne supprime que ses propres abonnements : l'endpoint d'un autre compte
  n'est jamais touché.

### 27.3 Session paresseuse et cookies

- Aucun cookie de session n'est posé tant que rien n'est écrit : sitemap,
  robots, manifeste, service worker, icônes et pages publiques anonymes n'en
  reçoivent plus. Une installation ne crée donc plus une session par passage
  de robot, et ces réponses restent cachables.
- Le jeton CSRF n'est calculé que si un gabarit l'écrit réellement : afficher
  une page sans formulaire n'ouvre pas de session.

### 27.4 Cache hors ligne

- Le service worker n'intercepte jamais `/admin`, `/account`, `/api/`, la
  connexion, la déconnexion ni les médias originaux.
- Le socle mis en cache est récupéré **sans cookie** : le cache d'un appareil
  partagé ne peut pas contenir le nom, les messages ou la réservation d'un
  utilisateur.
- Le service worker embarque la version installée : une mise à jour
  applicative invalide automatiquement les caches précédents.

### 27.5 Messages flash

- Un message flash n'est consommé que par une navigation de document. Une
  requête annexe — préchargement, appel JSON, socle récupéré par un service
  worker — ne peut plus faire disparaître une confirmation avant qu'elle
  n'ait été lue.

### 27.6 Diagnostic d'expédition

- SPF, DKIM et DMARC sont vérifiés pour le domaine d'expédition. La clé
  publique DKIM n'est jamais réaffichée : seuls les paramètres le sont.
- Un SPF permissif (`+all`) et un DMARC en observation seule (`p=none`) sont
  signalés comme insuffisants, pas comme conformes.
- La sonde SMTP n'est jamais déclenchée par le simple affichage de la page :
  elle demande une action explicite de l'administrateur.

## 28. Décisions de mise en œuvre (itération 5)

### 28.1 Règles appliquées côté serveur

- Durée, jour d'arrivée, multiples, capacité, horizon et délai de prévenance
  sont vérifiés par `StayRules` à chaque devis. Le calendrier guide la saisie
  mais ne fait autorité sur rien.
- Une date malformée produit une erreur traduite, jamais une exception
  visible : l'API de devis répond toujours en 200 avec un verdict explicite.
- Une plage est bornée à un an : une saisie aberrante ne peut pas déclencher
  le parcours de centaines de milliers de nuits.

### 28.2 Informations internes

- Le motif d'une indisponibilité (« séjour propriétaire », note interne d'un
  tarif) n'est jamais publié : le calendrier public n'expose que le **type**
  de blocage.
- Les notes internes de tarif restent en administration.

### 28.3 Montants

- Les montants sont des entiers de centimes de bout en bout ; la saisie
  tolérante (virgule, espace fine) est normalisée par un seul point d'entrée,
  `Money::parse()`, partagé par les réglages et l'écran des tarifs.
- L'acompte est arrondi au centime supérieur : le solde restant ne peut
  jamais dépasser le total.

### 28.4 Analyse de secrets

- Le contrôle porte désormais sur les fichiers **suivis et non encore
  commités** : un secret est détecté avant d'entrer dans l'historique, pas
  après.
