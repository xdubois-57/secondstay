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

## 29. Décisions de mise en œuvre (itération 6)

### 29.1 Intégrité des réservations

- La non-superposition des séjours est garantie par une contrainte de base de
  données, pas par une vérification applicative : deux transactions
  concurrentes ne peuvent pas obtenir les mêmes nuits, quel que soit l'ordre
  d'exécution.
- Les montants enregistrés sont ceux calculés par le serveur. Un formulaire
  qui envoie son propre total, son acompte ou sa remise est ignoré.
- Les transitions d'état sont déclarées dans le modèle : une transition non
  prévue est refusée, même demandée par un administrateur.
- Le compteur d'usage d'un code promotionnel est incrémenté sous condition :
  deux réservations simultanées ne peuvent pas dépasser ensemble la limite.

### 29.2 Confidentialité

- Une référence de réservation n'est **pas un secret** : le détail d'un séjour
  exige d'être authentifié et d'en être le titulaire, ou d'avoir un rôle
  opérationnel.
- La référence évite les caractères que l'on confond en la dictant, ce qui
  évite de multiplier les tentatives de lecture.
- Le calendrier public affiche une nuit réservée comme « occupée » sans jamais
  dire par qui : ni nom, ni adresse, ni référence.
- Le message laissé au propriétaire et les coordonnées ne sortent pas de
  l'espace client et de l'administration.

### 29.3 Verrou temporaire

- Un verrou expire seul : une session abandonnée ne bloque pas les dates
  indéfiniment.
- Un verrou expiré ne peut pas être finalisé : le parcours redémarre plutôt
  que de confirmer un séjour dont les nuits ont pu être reprises.

## 30. Paiements

### 30.1 Ce qui fait autorité

- Une notification de paiement n'apporte qu'un **identifiant**. L'état est
  ensuite relu chez le fournisseur : le corps reçu n'est jamais cru, quel que
  soit ce qu'il annonce.
- Un montant différent de celui attendu est journalisé et refusé, jamais
  accepté silencieusement.
- Une notification tardive ne défait pas un encaissement déjà constaté : un
  paiement encaissé ne change d'état que par un remboursement explicite.
- L'idempotence repose sur une contrainte d'unicité en base
  (`webhook_event(provider, external_id)`), pas sur une vérification
  applicative qui se laisserait doubler par deux notifications simultanées.

### 30.2 Fournisseur factice

- `FakePaymentProvider` n'est activable que par
  `SECONDSTAY_PAYMENT_PROVIDER=fake`, jamais depuis l'interface
  d'administration.
- Sans clé utilisable, c'est `NullPaymentProvider` qui est en place : le
  paiement en ligne n'est pas proposé du tout. Un fournisseur factice à cette
  place permettrait de confirmer un séjour sans avoir rien payé.
- Une clé Mollie est reconnue par son préfixe de mode : une clé de test n'est
  jamais confondue avec une clé de production.

### 30.3 CSRF et point d'entrée webhook

- `/webhook/payment` est exempté de CSRF : il est authentifié par la relecture
  de l'état chez le fournisseur, pas par une session de navigateur. C'est la
  seule exemption, et elle est portée par le préfixe `/webhook/`.
- Toutes les actions de paiement du navigateur — ouvrir un paiement,
  enregistrer un encaissement, rembourser, faire avancer une caution — passent
  par POST avec jeton CSRF et contrôle de rôle serveur.

### 30.4 Confidentialité

- Un identifiant de paiement n'est pas un secret : l'appartenance du séjour est
  vérifiée à chaque accès, y compris pour l'image du QR code.
- Le QR code EPC et les coordonnées de virement sont servis derrière
  l'authentification et avec `Cache-Control: private, no-store`.
- L'IBAN et le BIC de l'installation sont des réglages de l'installation, pas
  des valeurs du dépôt. La clé d'API du fournisseur est un secret chiffré au
  repos et jamais réaffiché.

## 31. Contrats, documents et courrier entrant

### 31.1 Documents

- Aucun document n'est écrit sous le document root. Chaque octet est servi par
  l'application, après contrôle de rôle **et** d'appartenance du séjour.
- Un identifiant de document n'est pas un secret : l'appartenance est vérifiée
  à chaque accès, et une absence de droit se présente comme une absence de
  document.
- Le nom sur disque est dérivé de l'empreinte SHA-256, jamais du nom fourni :
  un nom venu d'un e-mail n'a aucune raison d'être sûr.
- Le chemin lu en base est confronté à la racine du stockage avant toute
  lecture : une valeur corrompue ne peut pas faire lire un fichier arbitraire.
- Le type est déduit du **contenu**, pas de l'extension : un script renommé en
  `.pdf` est refusé. La liste des types acceptés est fermée, et la taille est
  bornée.
- Les réponses portent `Cache-Control: private, no-store` et
  `X-Content-Type-Options: nosniff` : un document de séjour ne doit finir ni
  dans un cache partagé, ni interprété comme autre chose que ce qu'il est.

### 31.2 Acceptation du contrat

- L'acceptation fige la version, la langue et l'**empreinte du PDF accepté** :
  remplacer le fichier ensuite se voit immédiatement, et l'administration
  affiche l'état de cette vérification.
- L'adresse IP du client n'est conservée que sous forme d'empreinte : elle
  suffit à recouper deux traces sans conserver la donnée personnelle.
- Seul le titulaire du séjour peut accepter son contrat, et une seule fois.

### 31.3 Courrier entrant

- Un message reçu vient d'Internet : l'analyse MIME borne la profondeur
  d'imbrication et le nombre de parties, et ne croit aucun jeu de caractères
  annoncé.
- Le HTML est **nettoyé avant d'être stocké**, pas au moment de l'affichage :
  le contenu hostile ne dort jamais en base sous sa forme d'origine.
- Le rattachement automatique le plus fort repose sur un HMAC : une adresse de
  réponse forgée, ou signée par une autre installation, ne rattache rien.
- Une référence citée dans un corps de message ne fait pas autorité : elle
  rattache, mais l'administration voit par quelle règle et peut corriger.
- Le mot de passe IMAP est un secret chiffré au repos et jamais réaffiché ; le
  nom de boîte est échappé avant d'entrer dans une commande IMAP, de sorte
  qu'un nom contenant un guillemet ne puisse pas en injecter une seconde.
- La boîte factice n'est activable que par `SECONDSTAY_IMAP_PROVIDER=fake`,
  jamais depuis l'interface.

## 32. Exploitation et calendriers privés

### 32.1 Jetons de calendrier

- Un flux ICS est accessible **sans session** : c'est la nature d'un abonnement
  d'agenda. Le jeton est donc long (32 octets), unique, et n'est stocké que
  sous forme d'empreinte SHA-256 — une fuite de la base ne donne accès à aucun
  calendrier.
- Le jeton en clair n'est affiché qu'une fois, à sa création, et transite par
  la session plutôt que par l'URL, où il resterait dans l'historique.
- Régénérer un lien **révoque** le précédent : c'est exactement ce qu'on
  attend après un partage par erreur.
- Une révocation prend effet immédiatement. Un jeton inconnu et un jeton
  révoqué donnent la même réponse : une adresse qui n'existe pas, ce qui
  n'apprend rien d'utile à qui essaie.
- Les réponses portent `Cache-Control: private, no-store`,
  `X-Robots-Tag: noindex, nofollow` et `X-Content-Type-Options: nosniff`.

### 32.2 Ce que chaque portée expose

| Portée | Séjours | Voyageur | Montants | Responsable |
|---|---|---|---|---|
| Administration | tous | oui | oui | — |
| Responsable local | tous | oui | **non** | — |
| Voyageur | le sien seul | — | **non** | oui |

Un flux abonné dans un agenda tiers finit souvent par être partagé sans y
penser : chaque portée ne montre donc que ce dont son destinataire a besoin.

### 32.3 Responsable local et checklists

- Seul un compte opérationnel peut être responsable d'un séjour : affecter un
  client lui donnerait une visibilité qu'il n'a pas. La vérification est faite
  côté serveur, jamais par la seule liste déroulante.
- Un responsable local ne révoque que ses propres liens de calendrier ; la
  portée « administration » n'est délivrable qu'à un administrateur.
- Une ligne de checklist n'accepte que les codes déclarés : un code arbitraire
  est refusé plutôt qu'écrit en base.
- Supprimer un compte n'emporte aucun séjour : seule l'affectation disparaît.

## 33. Mon séjour, invités et hors ligne

### 33.1 Codes d'accès

- Les codes — Wi-Fi, boîte à clés, alarme, portail — sont chiffrés au repos
  avec le mécanisme des secrets de l'installation. Un code d'alarme en clair
  dans une sauvegarde serait un incident.
- Ils ne sortent que pendant la fenêtre du séjour : arrivée, séjour, départ.
  Hors de cette fenêtre le modèle d'affichage ne les porte pas du tout, de
  sorte qu'aucun gabarit ne puisse les révéler.
- L'administration n'en affiche qu'un aperçu masqué ; un champ laissé vide
  conserve la valeur existante, l'interface ne peut donc pas renvoyer un
  secret qu'elle n'a jamais montré.
- Le journal d'audit enregistre **quels** codes ont changé, jamais leur valeur.

### 33.2 Liens invité

- Le jeton fait 32 octets et n'est stocké que haché ; il n'est affiché qu'une
  fois et transite par la session, pas par l'URL.
- Le lien expire deux jours après le départ, et l'expiration est évaluée en
  base : une horloge d'appareil faussée ne prolonge rien.
- Un lien révoqué, expiré ou inconnu donne la même réponse : une adresse qui
  n'existe pas.
- Un invité ne voit ni référence de séjour, ni montants, ni documents, ni
  partage, et ne peut pas remonter à la réservation.
- Un séjour annulé ne délivre plus de lien.

### 33.3 Hors ligne

- La liste des chemins jamais mis en cache s'est étendue à `/booking/`,
  `/payment/`, `/document/` et `/calendar/` : la fiche de réservation porte
  désormais l'échéancier et les documents, elle n'a plus sa place sur le
  disque d'un appareil.
- Seules les pages de séjour et de lien invité sont conçues pour le cache, et
  elles ne portent par construction ni montant ni document.
- Les pages propres à un séjour sont servies avec `noindex, nofollow`.

## 34. États des lieux et incidents

**Le refus de clôture est une règle serveur.** `InspectionService::complete()`
recalcule les zones bloquantes à partir de la base et refuse tant qu'il en
reste. Le gabarit affiche ce qui manque mais laisse le bouton actif : une
règle appliquée par l'interface n'est pas une règle, c'est une suggestion.

**Un état des lieux clos est une preuve.** Aucune écriture n'est acceptée
après la clôture — ni constat, ni photo, ni seconde clôture. Sans cela, la
photo produite au moment de discuter d'une caution ne vaudrait rien.

**Une photo est une photo.** Le type est déduit du **contenu** par
`DocumentService::detectMime()`, jamais de l'extension ni de ce que le
navigateur annonce, et seuls les types `image/*` sont acceptés là où la
spécification exige une photo — constat comme photo de référence.

**Les photos restent hors document root.** Elles suivent le circuit ordinaire
des documents : nommées par leur empreinte, servies par l'application après
contrôle d'accès, jamais par le serveur web. Une photo de constat
(`inventory`) est visible de son voyageur ; une photo d'incident (`incident`)
ne l'est pas.

**Le code de zone est normalisé, pas cru.** `AdminInspectionController` réduit
le code saisi à `[a-z0-9_]`, borné à 32 caractères : c'est un identifiant
technique, pas du texte libre, et il finit dans des clés de traduction.

**Les transitions d'incident sont fermées.** `IncidentStatus::canMoveTo()`
décide, et un statut arrivé par formulaire qui ne correspond à aucune
transition permise est refusé sans rien écrire. L'historique est en ajout
seul : ni modification, ni suppression.

**Un incident ne se confie qu'à un rôle opérationnel.** Confier un incident à
un client lui donnerait une responsabilité sans lui donner l'accès qui va
avec ; `IncidentService::assign()` le refuse.

**L'état des lieux du voyageur peut être coupé.** `inspection.guest_enabled`
est vérifié côté serveur à chaque requête, pas seulement au moment d'afficher
un lien : un propriétaire qui préfère remplir lui-même les constats n'est pas
protégé par un lien caché.

**Ces pages ne sont jamais mises en cache.** `/inspection/` et `/incident/`
ont rejoint la liste `NEVER_CACHED` du service worker : elles écrivent, et une
version servie depuis le disque ferait croire à un constat enregistré ou à une
photo partie.

## 35. Conformité, textes légaux et rétention

**Une version publiée est immuable.** `LegalDocumentRepository::publish()`
refuse d'écraser une version existante, et `LegalDocument::isIntact()` compare
le corps stocké à son empreinte. Un consentement pointe vers cette version :
sans immuabilité, la preuve d'acceptation ne prouverait rien.

**Un consentement enregistre la langue, pas seulement la version.** Accepter
des conditions en néerlandais et se voir opposer la version française serait
sans valeur. La langue retenue est celle de la page où la case a été cochée.

**L'adresse IP n'est conservée que hachée**, comme pour l'acceptation d'un
contrat : elle sert de preuve, pas de moyen de retrouver quelqu'un.

**Une acceptation ne se réécrit pas.** `UNIQUE(booking_id, type)` fait qu'une
seconde acceptation ne remplace pas la première : c'est la première qui a eu
lieu.

**La fiche de police ne collecte rien tant qu'elle n'est pas activée.** Le
réglage est vérifié côté serveur à chaque requête — la route de saisie répond
404 quand l'obligation est désactivée — et non seulement au moment d'afficher
un lien. Le contenu est chiffré au repos avec un contexte dédié
(`police:record`) et ne comporte que les champs réglementaires.

**La purge d'une fiche ne la déchiffre pas.** Elle se fait en base sur la date
d'échéance : il n'y a aucune raison de lire une fiche pour l'effacer.

**Une fiche illisible ne fait pas tomber l'écran.** Après un retrait de clé ou
une rotation ratée, la fiche apparaît vide — ce qui est précisément
l'information utile — plutôt que de provoquer une erreur.

**La source d'un sujet de conformité doit être une adresse web.** Un champ
libre y stockerait « demandé par téléphone », qui n'est pas vérifiable ; seuls
`http` et `https` sont acceptés.

**Le contexte de taxe est figé.** Un barème voté après coup, même rétroactif,
ne peut pas changer le montant d'une réservation déjà engagée : le montant
facturé reste explicable.

**La purge est auditée.** `RetentionService::purge()` inscrit le détail de ce
qui a disparu dans la piste d'audit : effacer sans trace serait un trou dans
cette piste.

**Les pièces contractuelles ne sont jamais purgées automatiquement.** Séjours,
paiements, contrats acceptés, états des lieux et consentements survivent à
toute rétention : leur suppression est une décision humaine, jamais un effet de
bord d'un réglage.

## 36. Contenu local généré

**Toute sortie passe par le garde SSRF.** Le fournisseur de modèle appelle
l'API à travers `HttpFetcher`, comme la récupération des sources : il n'existe
pas de second chemin réseau dans l'application. Une URL de source est contrôlée
à la saisie — schéma, adresse littérale privée — et surtout **à chaque
requête**, redirections comprises.

**Un nom qui ne se résout pas est accepté à la saisie, jamais à la sortie.** Un
site peut être injoignable une minute ; refuser de l'enregistrer ne protégerait
rien. La barrière est au moment de la requête, où elle est effective.

**Le contenu récupéré est une donnée.** Il est réduit à du texte — scripts,
styles et commentaires supprimés — puis enfermé entre marqueurs, et la consigne
système déclare explicitement qu'aucune instruction ne doit en être suivie.
C'est la défense contre l'injection de prompt exigée par SPECIFICATIONS.md §59.

**La sortie du modèle est revalidée.** Le schéma est envoyé au fournisseur
**et** appliqué au retour : une activité citant une source jamais consultée,
sans titre, ou dont la fin précède le début, est écartée. Un fournisseur qui
n'appliquerait pas la contrainte ne peut donc pas peupler la base.

**La date de vérification est celle de la consultation**, pas celle que le
modèle annonce : elle est écrite par le produit.

**Aucune donnée personnelle n'atteint le modèle.** Ni nom, ni e-mail, ni
téléphone, ni référence de séjour : un lieu, une saison, des dates et du texte
public. Un test le vérifie champ par champ.

**La clé d'API est un secret de configuration** : chiffrée au repos, jamais
réaffichée, et jamais écrite dans un journal — les journaux d'appel ne portent
que l'empreinte du prompt.

**Le modèle factice et les fixtures HTTP ne s'activent que par variable
d'environnement.** Ni l'un ni l'autre n'est sélectionnable depuis l'interface :
une installation ne peut pas se retrouver à afficher du contenu de test à un
voyageur.

## 37. Calendriers externes, reporting et quotas

**Un flux importé traverse le garde SSRF, à chaque synchronisation.** L'URL est
contrôlée à la saisie — ce qui est certainement interdit est refusé tout de
suite — mais la barrière effective est au moment de la requête, redirections
comprises (§16). Une adresse du réseau interne n'est donc jamais consultée,
même si elle a été enregistrée avant qu'un DNS ne change d'avis.

**Un flux ne peut pas rouvrir des nuits.** Une réponse absente, un code HTTP
autre que 200 ou un corps qui n'est pas un calendrier laissent les blocages en
place. C'est une propriété de sûreté avant d'être une propriété technique :
libérer des nuits déjà vendues ailleurs provoquerait une double réservation
réelle, avec un voyageur devant une porte fermée.

**Un flux ne crée jamais de réservation.** Il pose des indisponibilités, avec
leur provenance. Un flux distant ne peut donc pas fabriquer de client, de
montant ni d'engagement contractuel.

**Une synchronisation ne peut effacer que ses propres lignes.** La suppression
est bornée par `source_id` à l'intérieur d'une transaction : aucune requête
n'efface un blocage qui n'appartient pas à la source traitée.

**La lecture d'un flux est bornée.** Deux mégaoctets, deux mille événements, et
seulement quatre propriétés retenues. Un flux hostile ou simplement énorme ne
peut pas épuiser la mémoire d'un hébergement mutualisé.

**Le classeur exporté n'est jamais mis en cache partagé** (`no-store, private`)
et n'est accessible qu'à un compte opérationnel : c'est un état financier
nominatif.

**Le classeur porte sa propre mise en garde.** Le rapport ne constitue ni un
conseil fiscal ni une déclaration ; le fichier étant destiné à être transmis,
l'avertissement voyage avec lui.

**Un quota refuse avant d'écrire.** Un disque plein casse aussi la sauvegarde
qui aurait permis de s'en sortir : le contrôle est donc en amont de
l'écriture, pas un constat après coup. Un quota non réglé — la valeur par
défaut — ne bloque rien.

**Un fichier partagé n'est effacé qu'une fois orphelin.** Les documents sont
nommés par leur empreinte et peuvent être référencés par plusieurs séjours :
la suppression vérifie qu'aucun enregistrement ne les référence plus, tous
séjours confondus, avant de toucher au disque.

**Un litige ne peut pas réclamer plus que la caution détenue**, ni se clore
sans montant borné et sans explication. L'historique est en ajout seul : une
étape ne peut pas être réécrite après coup.

**La purge reste auditée.** L'ajout des indisponibilités passées à la rétention
ne change pas la règle : ce qui est effacé automatiquement laisse une trace,
et les pièces contractuelles — séjours, paiements, contrats acceptés, états des
lieux — ne sont jamais purgées sans décision humaine.

## 38. Tâches périodiques et pages d'information publiques

**Le point d'entrée du planificateur n'est pas atteignable par le web.**
`src/Scheduler/cron.php` vit sous `src/`, refusé à la fois par le `.htaccess` de
la racine et par `PublicPathPolicy`, et refuse lui-même de répondre hors CLI.
Trois mécanismes indépendants, parce qu'un planificateur devenu URL par accident
donnerait à n'importe qui le pouvoir de déclencher une sauvegarde, une purge et
une relève de courrier en boucle. La campagne vérifie que ce chemin est refusé,
au même titre que `src/Core/Kernel.php`.

**La porte HTTP existe, fermée.** Une partie des hébergements mutualisés ne
propose de cron que par URL ; sans porte, ces installations n'auraient ni
sauvegarde, ni purge, ni relève — et personne ne verrait cette absence.
`GET /tasks/run` est donc servie, mais :

- **elle n'existe pas sans jeton.** Tant qu'aucun jeton n'est enregistré, elle
  répond 404, comme un chemin inventé : elle ne signale pas sa présence, et il
  n'y a rien à forcer sur une installation qui ne s'en sert pas ;
- **le jeton fait au moins trente-deux caractères**, refusé plus court à la
  saisie, et il est comparé en temps constant ;
- **la limitation de débit porte sur l'adresse d'appel**, et non sur le jeton
  présenté. Celui qui balaie essaie précisément un jeton différent à chaque
  coup : un compteur indexé sur le jeton lui ouvrirait un compteur neuf à
  chaque essai, et ne limiterait rien. L'adresse, elle, ne change pas — et la
  table des compteurs ne porte alors rien d'autre qu'une adresse IP, jamais la
  liste des secrets tentés. Un appel valide remet le compteur à zéro, faute de
  quoi un cron appelé toutes les cinq minutes finirait par se limiter
  lui-même ;
- **un jeton faux se lit comme un jeton absent** — 404 dans les deux cas ;
- **le jeton s'envoie de préférence dans un en-tête**, `X-Scheduler-Token`.
  C'est le seul risque résiduel de cette porte, et il faut le nommer : une URL
  est écrite dans le journal d'accès du serveur web, que le produit ne contrôle
  pas. Un hébergement dont le cron par URL sait poser un en-tête garde donc le
  secret hors des journaux. Le paramètre d'URL reste accepté parce que beaucoup
  n'offrent qu'un champ « adresse à appeler », et fermer la porte à ceux-là
  reviendrait à les priver de sauvegarde et de purge. Le jeton se régénère
  depuis les réglages, ce qui reste la réponse à un journal partagé par erreur.

**Une tâche ne s'exécute jamais deux fois en parallèle.** Le verrou est pris en
base par un `UPDATE` conditionnel, seule primitive atomique disponible sans
dépendance. C'est une propriété de sûreté : deux relèves simultanées
importeraient deux fois les mêmes pièces jointes, et deux purges concurrentes
travailleraient sur un état qu'elles modifient l'une pour l'autre. Le verrou est
à échéance, pour qu'un processus tué par l'hébergeur ne condamne pas sa tâche.

**Une tâche en échec ne dit rien de plus que nécessaire.** Le message
d'exception peut porter un hôte, un chemin ou un identifiant : il part au
journal, déjà assaini, et l'écran d'exploitation ne reçoit qu'une clé de
traduction. Le déclenchement manuel d'une tâche est audité.

**Les diagnostics n'ouvrent aucune connexion sortante à l'affichage.** Paiement,
modèle, cron, sauvegarde et mise à jour sont lus localement. Une page de
diagnostics qui interroge trois services externes à chaque visite devient une
page qui tombe quand l'un d'eux tombe — et une page qu'on cesse de consulter.
Les sondes SMTP et IMAP restent déclenchées explicitement.

**Une page d'information publique est refusée par défaut.** Les adresses
`/{langue}/info/{bloc}` sont lisibles sans compte ni séjour : c'est leur raison
d'être, un QR collé sur une machine à laver s'adresse à quelqu'un qui n'a rien
ouvert. La publication se décide donc bloc par bloc et langue par langue, et
jamais globalement — le livret contient des choses qui n'ont rien à faire sur le
web ouvert, à commencer par un code de boîte à clés recopié dans le texte d'un
bloc. Trois conditions doivent tenir ensemble : bloc marqué public, publié dans
le livret, et non vide.

**Aucun secret ne transite par ces pages.** Les codes d'accès vivent chiffrés
dans `stay_secret` et ne sont rendus que dans « Mon séjour », pendant la fenêtre
du séjour. Le contrôleur des pages publiques ne les lit pas, et la campagne le
vérifie en enregistrant un mot de passe Wi-Fi puis en le cherchant dans les
réponses.

**Un lien de bloc reste un lien, jamais une récupération.** La carte et la
source d'un bloc du livret (§55) sont saisies par le propriétaire, stockées
telles quelles et **jamais demandées par le serveur** : elles ne deviennent
qu'un `href` rendu avec `rel="noopener noreferrer"`. Il n'y a donc pas de
surface SSRF, et `UrlGuard` — qui protège les récupérations sortantes — n'a pas
à intervenir. En revanche le schéma est contrôlé : seuls `http` et `https` sont
acceptés, parce que `javascript:` ou `data:` dans un `href` serait une injection
déguisée en commodité. Une adresse trop longue est refusée plutôt que tronquée :
une URL coupée est un lien mort qui a l'air bon. Le contrôle de schéma est
refait **à l'affichage** et pas seulement à la saisie : Twig échappe le contenu
d'un attribut mais pas son schéma, et une ligne écrite autrement que par le
formulaire — reprise de base, migration — ne doit pas pouvoir produire un
`href` exécutable.

**Le repli de langue comble une lacune, il ne défait pas une décision.** Un
bloc jamais traduit — ou traduit puis vidé — est servi dans la langue du
logement : une information dans la mauvaise langue vaut mieux qu'une page
absente devant quelqu'un qui cherche comment faire marcher un appareil. Mais un
bloc **renseigné** que le propriétaire a retiré du web ouvert, ou dépublié du
livret, répond 404 dans cette langue-là, et le repli ne s'applique pas. C'est
ce qui donne son sens au réglage langue par langue : celui qui s'aperçoit que
son texte allemand contient le code de la boîte à clés le retire, et l'adresse
allemande se ferme — au lieu de rouvrir sur le texte français.

**Une illustration de bloc ne contourne pas la visibilité des médias.** Seuls
les médias publiés et non privés sont proposés à la sélection, et la
résolution les revérifie à l'affichage : un média rendu privé après coup cesse
d'illustrer le bloc, il ne devient pas lisible parce qu'un bloc le référence.
Le contrôle d'accès reste celui de l'endpoint média, qui n'a pas changé.

**Le texte d'un bloc n'est jamais interprété.** Il est saisi par le
propriétaire et rendu échappé, comme dans « Mon séjour ». Les pages portent
`noindex` et `robots.txt` refuse `/{langue}/info/` : publiques par nécessité,
elles n'ont pas à être trouvées depuis un moteur de recherche.

## 39. HSTS et transport

`Strict-Transport-Security` est émis **uniquement lorsque la requête est
arrivée en HTTPS**, avec une durée configurable (`security.hsts_max_age`,
six mois par défaut ; `0` désactive l'en-tête).

La condition n'est pas une prudence excessive, c'est le seul comportement
correct pour un produit qui s'installe sur un hébergement mutualisé
quelconque :

- **en clair, l'en-tête ne protégerait rien.** Un attaquant capable de
  modifier la réponse peut tout aussi bien retirer l'en-tête. Une protection
  qui ne tient que si l'attaquant coopère n'en est pas une ;
- **en clair, il ferait des dégâts.** Une installation servie en HTTP qui
  annoncerait HSTS deviendrait injoignable pour la durée annoncée, depuis les
  navigateurs qui l'ont vu passer une fois. Le propriétaire n'aurait aucun
  moyen de revenir en arrière avant l'échéance.

Ni `includeSubDomains` ni `preload` : sur un hébergement mutualisé, les
sous-domaines appartiennent souvent à autre chose, et `preload` est une
décision qu'on ne défait pas en un jour.

L'en-tête suit `Request::isSecure()`, qui accepte `$_SERVER['HTTPS']` **et**
`X-Forwarded-Proto`. Le second est ce que pose un répartiteur ou un
terminateur TLS ; l'accepter est ce qui rend l'en-tête correct derrière un
proxy, où l'application ne voit jamais le TLS elle-même.

Le risque associé mérite d'être dit exactement, en séparant ce que
l'**application émet** de ce que le **navigateur en fait**. Un client qui forge
`X-Forwarded-Proto: https` sur une connexion en clair fait bien émettre
l'en-tête par l'application. Mais il le reçoit sur du HTTP, et la RFC 6797 §7.2
est explicite : un agent utilisateur **doit ignorer** un
`Strict-Transport-Security` reçu sur un transport non sécurisé. Il n'y a donc
pas d'enfermement — l'en-tête arrive et n'est pas retenu. Le drapeau `Secure`
d'un cookie suit la même logique : un navigateur refuse un `Set-Cookie; Secure`
posé en clair.

Ce mécanisme est d'ailleurs la raison d'être de cette règle de la RFC : il
existe précisément pour qu'un attaquant capable de parler en clair ne puisse
pas fixer, prolonger ou détruire une politique HSTS.

Sur une installation derrière un répartiteur, c'est au répartiteur — et à lui
seul — de poser cet en-tête : il doit écraser toute valeur venant du client,
faute de quoi n'importe qui pourrait la dicter. C'est ce que fait le
terminateur du harnais de scan, qui retire toute copie envoyée par le client
avant de poser la sienne. Rien n'y dépend de la bienveillance du navigateur,
et c'est ce qu'on attend d'une défense en profondeur.

### 39.1 Le cookie de session

Le cookie de session porte `Secure` dès que la requête est arrivée en HTTPS, et
jamais en clair — où le drapeau rendrait le cookie inutilisable, donc la
connexion impossible. Il suit `Request::isSecure()`, donc le
`X-Forwarded-Proto` d'un répartiteur : c'est précisément le cas d'un
hébergement mutualisé derrière un terminateur, où le TLS est réellement là et
où la protection compte.

Cette protection **manquait**. `Services` construisait la session avec
`secure: false` en dur : le drapeau existait, et valait toujours faux. Sur une
installation entièrement servie en TLS, le cookie de session voyageait sans ce
qui interdit de le renvoyer en clair.

## 40. Scan dynamique : pourquoi il exige du HTTPS

Deux protections de SecondStay dépendent du transport : l'en-tête HSTS
ci-dessus et le drapeau `Secure` du cookie de session. Un scan joué contre une
instance en clair rapporterait « HSTS absent » et « cookie sans Secure » :
**deux constats faux, à propos de code correct.**

La correction tentante est un filtre d'alertes qui fait taire les deux règles.
C'est précisément ainsi qu'un rapport cesse d'être lu : deux règles muettes
pour un défaut de harnais sont deux règles que personne ne regarde le jour où
l'une d'elles se déclenche pour de bon. **On répare le harnais, pas le
rapport.**

Le harnais tient en deux pièces, toutes deux sous `scripts/` — donc exclues de
l'archive de release, et exécutées par aucun déploiement :

- `dast-tls-proxy.php` termine TLS devant le serveur de test avec un
  certificat généré pour la durée de la campagne, et pose
  `X-Forwarded-Proto: https` **après avoir retiré toute copie envoyée par le
  client**. Un terminateur qui relaierait l'en-tête du client serait lui-même
  la vulnérabilité ;
- `dast-https-prepend.php`, chargé par `auto_prepend_file` pour le seul
  processus de test, traduit cet en-tête en `$_SERVER['HTTPS']`.

La preuve qui précède la campagne (`dast-support.php assert-https`) a rendu
**deux** verdicts faux, pour la même raison à chaque fois : elle regardait à
côté de ce qu'elle surveillait. Elle acceptait n'importe quel `Set-Cookie`
contenant « secure », et la préférence de langue en pose un ; puis elle
cherchait `max-age` comme sous-chaîne, si bien qu'un
`Strict-Transport-Security: not-max-age=31536000` — que le navigateur ignore,
RFC 6797 §6.1 — passait pour une politique effective. Les deux rendaient le
silence rassurant. `tests/php/Unit/DastSupportTest.php` couvre désormais ces
deux jugements.

Le certificat est émis pour **`localhost`** et non pour une adresse IP : une IP
n'est pas une *relying party* WebAuthn valide, et les parcours de clés d'accès
de la campagne seraient refusés par le navigateur.

Il est auto-signé et sert de **sa propre ancre de confiance**. Deux clients
distincts doivent l'accepter, et aucun des deux ne le fait par une dérogation
générale : le navigateur par l'épinglage de sa clé publique
(`--ignore-certificate-errors-spki-list`), et le client HTTP de Playwright —
du Node, que l'épinglage de Chromium ne concerne pas — parce que le certificat
lui est donné comme ancre via `NODE_EXTRA_CA_CERTS`. L'alternative aurait été
`ignoreHTTPSErrors`, qui désarme la vérification pour tout le contexte,
navigateur compris, et rendrait l'épinglage décoratif.

**La campagne de scan ajoute une seconde ancre, et n'en relâche aucune.** ZAP
est un intercepteur par construction : le pair TLS du navigateur n'y est plus
le terminateur mais ZAP, qui ré-signe chaque connexion avec une autorité qu'il
génère à son démarrage. Épingler la seule clé du terminateur ne suffit donc
pas — le navigateur refuse alors chaque connexion, et la carte du site de ZAP
ressort vide.

La tentation est de relâcher la vérification pour ce cas. Elle ne marche pas,
et l'échec est instructif : `ignoreHTTPSErrors` traverse l'avertissement sans
rendre l'origine **sûre** aux yeux de Chromium, si bien que le service worker
refuse de s'enregistrer et que deux scénarios de la campagne tombent — sans
rapport avec le produit. Les deux pannes ont été constatées en intégration
continue, l'une après l'autre.

`scripts/dast.sh` récupère donc la racine de ZAP par son API, en calcule
l'empreinte et l'épingle **à côté** de celle du terminateur. Le navigateur fait
exception pour ces deux clés, et pour aucune autre ; l'origine redevient sûre,
et le service worker s'enregistre. Le client HTTP de Node reçoit les deux
certificats comme ancres, dans un même paquet. Il couvre aussi
`host.docker.internal`, nom par lequel un ZAP conteneurisé joint l'hôte hors
Linux : c'est alors l'origine que le navigateur demande au proxy, et elle doit
rester valide de son côté.

Le câblage est **prouvé vivant avant tout scan** : une requête, et l'assertion
que la réponse porte l'en-tête HSTS et un cookie de session `Secure`. Si la
preuve échoue, la campagne s'arrête là. Un scan sur un harnais mal câblé
produit un rapport faux, ce qui est pire que pas de rapport.

La preuve **suit les redirections**, et ce détail n'en est pas un : sur une
instance fraîche, l'application n'est pas encore installée et toute page
publique redirige vers l'assistant — lequel est la première page à ouvrir une
session, donc à poser le cookie. S'arrêter au premier 302 n'observerait jamais
ce cookie, et la preuve échouerait en annonçant un défaut de l'application là
où il n'y en a pas. Seules les redirections vers la même origine sont suivies,
et au plus trois : suivre une redirection sortante ferait porter la preuve sur
un autre serveur.

La preuve porte sur le cookie **nommé**, et c'est le sujet. Elle acceptait
d'abord n'importe quel `Set-Cookie` contenant « secure » ; la préférence de
langue en pose un, si bien qu'elle était verte alors que le cookie de session
n'était pas protégé du tout. Un garde-fou qui regarde à côté de ce qu'il
surveille est plus dangereux que pas de garde-fou : il rend le silence
rassurant. Elle distingue en outre « cookie jamais posé » de « cookie posé sans
le drapeau » — deux pannes différentes, qui envoient chercher à deux endroits
différents.

## 41. Le jeton de l'assistant d'installation

Une instance neuve a une fenêtre pendant laquelle l'assistant crée le premier
administrateur sans qu'aucune authentification ne soit possible — par
construction, puisqu'il n'y a encore personne à authentifier. Sur un hébergement
public, cette fenêtre appartient à qui arrive le premier : **celui qui charge
`/install` avant le propriétaire choisit la base de données, le mot de passe
administrateur, et devient l'exploitant du site.**

Ce n'est pas une hypothèse de laboratoire. Entre le moment où les fichiers
arrivent par FTP et celui où le propriétaire ouvre son navigateur, il peut
s'écouler des minutes ; un scanner d'index de nouveaux domaines en met moins.

### 41.1 Ce qui referme la fenêtre

`bootstrap/bootstrap.php` génère 32 octets aléatoires et les écrit dans
`token.php`, à la racine du site. L'adresse de l'assistant n'est affichée
qu'avec ce jeton. Seul quelqu'un disposant d'un accès FTP au site — donc son
propriétaire — peut le lire.

`Installer\InstallToken` lit ce fichier **comme du texte**, jamais par
inclusion : son contenu vient du disque d'un hébergement dont l'application ne
sait rien, et l'exécuter reviendrait à faire tourner ce que le premier fichier
déposé à la racine contient. Le fichier écrit par l'installeur est du PHP valide
qui répond 404 et s'arrête : un fichier de secret doit rester inoffensif même
exécuté, et pas seulement inaccessible.

La comparaison passe par `hash_equals()`. Un jeton tronqué ou hors de
l'alphabet attendu n'est pas un jeton : accepter une valeur plus courte
reviendrait à accepter un secret plus faible que celui qui a été généré.

### 41.2 Ce qui reste ouvert, et pourquoi

**En l'absence de `token.php`, l'assistant reste ouvert.** Une installation
faite à la main — clone du dépôt, développement, campagne de tests — n'a jamais
eu de jeton à présenter, et refuser tout accès dans ce cas transformerait
l'absence d'un fichier en verrou définitif, sans aucun recours. Un `token.php`
présent mais illisible ou sans marqueur exploitable compte de la même façon :
enfermer dehors le propriétaire d'un fichier corrompu n'apprendrait rien à
personne d'autre.

La protection n'est donc pas *que l'assistant soit fermé* : c'est que
`bootstrap.php` le ferme sur les installations qu'il fait — celles, précisément,
où personne n'était présent pour surveiller la fenêtre.

### 41.3 Le jeton ne reste pas dans l'URL

Présenté une fois, il est mémorisé en session et l'assistant redirige vers la
même adresse **sans** le paramètre. Une URL finit dans l'historique du
navigateur, dans le `Referer` de chaque ressource externe et dans les journaux
d'accès de l'hébergeur ; c'est la même raison qui fait préférer
`X-Scheduler-Token` au paramètre d'URL pour le planificateur (§38).

Les essais infructueux sont comptés dans la session : au cinquième, l'accès est
refusé pendant quinze minutes, y compris avec le bon jeton. Une visite **sans**
jeton ne consomme pas d'essai — la première ouverture de l'assistant se fait
sans, et la compter épuiserait le budget avant que l'opérateur n'ait rien tenté.

Ce verrouillage vaut ce que vaut une session : qui jette son cookie repart à
zéro. **Ce n'est pas un oubli.** Un verrou porté par un état partagé serait, sur
une instance non installée, un moyen de bloquer l'installation depuis
l'extérieur — le propriétaire, lui, n'aurait alors plus aucun recours. Le
compteur n'est pas là pour arrêter une force brute : 256 bits d'entropie s'en
chargent. Il est là pour qu'une telle tentative coûte quelque chose.

### 41.4 Le jeton est supprimé, pas oublié

Dès que l'installation aboutit et qu'un administrateur existe, `token.php` est
supprimé. La fenêtre qu'il protégeait est fermée ; le laisser en place serait un
secret de plus sur le disque, pour rien.

## 42. L'installeur autonome : ce qu'il refuse

`bootstrap/bootstrap.php` tourne **avant** l'application : pas de `vendor/`, pas
de noyau, pas de session. Rien de ce que SECURITY.md décrit ailleurs ne le
protège, et il écrit à la racine du document d'un hébergement dont personne ici
ne sait rien. Les protections ci-dessous sont donc les siennes.

### 42.1 L'archive ne quitte jamais HTTPS, et elle est attestée

Le téléchargement suit les redirections **lui-même**, un saut à la fois, et
contrôle le schéma avant chacun. `follow_location => 1` suivait une redirection
vers `http://` sans rien signaler : les options `ssl` du contexte de flux ne
protègent que les sauts restés en TLS, elles n'ont rien à dire d'un saut qui
n'en fait plus partie. L'archive pouvait donc arriver en clair, et seuls les
contrôles de forme — la signature `PK`, la structure du ZIP — la séparaient de
l'extraction.

Les octets reçus sont ensuite comparés à l'empreinte SHA-256 que l'API de
GitHub publie pour l'asset. Cette empreinte arrive par `api.github.com`, sur une
connexion distincte de celle qui sert l'archive et de la chaîne de redirections
qui y mène : c'est ce qui en fait une preuve, elle ne vient pas de la même
source que ce qu'elle atteste. Une empreinte qui ne correspond pas interrompt
l'installation.

Une release qui n'en publie pas — le repli `zipball_url`, une release ancienne —
est rapportée comme **non vérifiée** dans le journal de l'opérateur. « Non
vérifiée » et « vérifiée » ne sont pas la même réponse, et les confondre serait
la version installeur du vert qui ne prouve rien.

### 42.2 Les trois actions POST refusent une origine étrangère

`step` copie, `gate-report` décide de l'annulation, `abort` supprime. Aucune
n'est protégée par le jeton d'installation : il n'existe pas encore quand les
premières partent, et il voyage de toute façon dans une URL — ce n'est pas un
jeton anti-CSRF. Sans contrôle d'origine, un site tiers visité par l'opérateur
pendant l'installation pouvait soumettre `POST bootstrap.php?action=abort` et
faire effacer les fichiers déjà copiés, l'état et le verrou.

La comparaison porte sur l'**hôte** (`Origin`, à défaut `Referer`, contre
`Host`), pas sur le schéma. L'installeur tourne souvent derrière un terminateur
TLS qui ne renseigne ni `HTTPS` ni `X-Forwarded-Proto` ; exiger l'égalité des
schémas ferait refuser des requêtes légitimes sans que l'opérateur puisse
comprendre pourquoi. Ce qu'une falsification inter-site ne contourne pas, c'est
l'hôte : un attaquant ne sert pas de contenu depuis le nom de domaine de sa
victime.

### 42.3 Le jeton et l'état ne sont lisibles que par leur propriétaire

`token.php` et `.bootstrap-state.php` sont ramenés à `0600` après chaque
écriture, et l'échec de cette restriction interrompt l'installation. Sur un
hébergement mutualisé, une umask permissive les laisserait lisibles par les
autres comptes de la machine — et `token.php` porte le secret qui ouvre
l'assistant (§41).

Le fichier d'état est du PHP inerte dont les données vivent dans un
commentaire. Aucune valeur ne peut le refermer : les barres obliques sont
échappées à l'encodage, si bien que la séquence de fermeture ne peut pas être
construite. Sans cela, un chemin ou un message d'hébergeur contenant cette
séquence aurait transformé le fichier d'état lui-même en injection de code.

### 42.4 Une installation ne démarre jamais deux fois

Le verrou est créé par une **création exclusive** (`fopen($path, 'x')`), en une
opération noyau. Tester l'existence, lire la date, puis écrire laissait deux
requêtes simultanées constater toutes deux l'absence du verrou et le poser
toutes deux : deux installations sur le même dossier, copies entrelacées, état
divergent du disque, annulation partielle. La reprise d'un verrou périmé est
sérialisée par un `flock()` exclusif et la date est relue **sous** ce verrou :
la requête qui arrive seconde voit une date fraîche et renonce.

### 42.5 Une copie interrompue reste annulable

Les entrées déjà écrites à la racine remontent avec l'échec, et non par la
valeur de retour d'une fonction qui a levé. C'est ce qui permet à l'annulation
— automatique ou déclenchée par le bouton « Annuler l'installation et
nettoyer » — de les nommer, donc de les retirer. Sans cela, une copie
interrompue au milieu de `vendor/` laissait `src/` en place, la reprise butait
sur « déjà installé », le bouton d'annulation relisait un état vide et
répondait « ok » sans rien faire : il ne restait que le FTP.
