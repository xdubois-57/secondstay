<?php

declare(strict_types=1);

/**
 * La moitié PHP du harnais de sécurité dynamique — tout ce qui ferait plus
 * d'une ligne de shell.
 *
 * La séparation existe pour la même raison que les autres aides du harnais
 * E2E : ce dépôt exige déjà un interpréteur, la campagne de sécurité n'en
 * ajoute donc aucun.
 *
 * Chaque sous-commande **échoue fermée**. Un scan qui rapporte un succès parce
 * qu'une étape n'a silencieusement rien fait est pire qu'un scan qui n'a pas
 * tourné, parce que tout le monde croit le premier. Chacune dit ce qui n'a pas
 * marché et sort en non nul, plutôt que de laisser la campagne continuer sur
 * une supposition.
 *
 * Sous-commandes (`scripts/dast.sh` et la préparation Playwright les appellent ;
 * un humain jamais) :
 *   free-port
 *   generate-cert <chemin.pem> <nom-d-hote>
 *   cert-spki <chemin.pem>
 *   wait-url <url> <secondes>
 *   assert-https <url-de-base> [nom-du-cookie-de-session]
 *   zap-plan-start <url-zap> <clé-api> <chemin-du-plan-dans-le-conteneur>
 *   zap-plan-await-delay <url-zap> <clé-api> <id-de-plan> <secondes>
 *   zap-plan-wait <url-zap> <clé-api> <id-de-plan> <secondes>
 *   assert-sitemap <url-zap> <clé-api> <url-du-site> <fichier-d-attentes>
 *   gate-alerts <url-zap> <clé-api> <url-du-site> <seuil> [chemin-du-résumé]
 */

/**
 * Les niveaux de risque que ZAP rapporte, du plus faible au plus fort.
 *
 * « Informational » et « Low » sont enregistrés et imprimés mais ne font
 * jamais échouer une campagne ; la gate est à « Medium » et au-dessus. Il n'y
 * a délibérément **aucune liste de constats acceptés** : un constat se corrige,
 * ou se filtre nommément dans `tests/dast/zap-passive.yaml` avec sa raison
 * écrite là où un relecteur la verra. C'est le choix inverse de celui des
 * baselines PHPStan et tsc, et la différence est le sujet : celles-ci
 * enregistrent des constats de typage que personne n'a introduits aujourd'hui,
 * celle-là des constats de sécurité vivants contre une instance en marche — et
 * une liste d'« acceptés » qui s'allonge est exactement la façon dont un scan
 * cesse de vouloir dire quelque chose.
 */
const DAST_RISK_ORDER = ['Informational', 'Low', 'Medium', 'High'];

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "dast-support.php est un script en ligne de commande.\n");
    exit(1);
}

function dastFail(string $message): never
{
    fwrite(STDERR, "DAST : {$message}\n");
    exit(1);
}

/**
 * Un GET simple : pas de proxy, pas de redirection suivie, délai court.
 *
 * Tout ce que ce script interroge est en boucle locale. Faire passer l'appel
 * par un `HTTPS_PROXY` ambiant — que certains environnements posent, celui-ci
 * compris — ne ferait que le suspendre : le proxy est donc désactivé
 * explicitement plutôt que laissé au hasard.
 *
 * @return array{status: int, body: string, headers: list<string>}|null null si le transport a échoué
 */
function dastHttpGet(string $url, int $timeoutSeconds = 30): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'proxy' => null,
            'request_fulluri' => false,
        ],
        'ssl' => [
            // L'instance sert un certificat généré pour cette campagne et à qui
            // personne ne fait confiance. Le vérifier supposerait d'embarquer un
            // magasin de confiance pour une clé qui vit le temps d'un scan ; la
            // connexion est de toute façon en boucle locale.
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    // `$http_response_header` est peuplée par le moteur dans la portée de
    // l'appel ; elle existe donc toujours ici, mais reste vide si la requête
    // n'a jamais atteint la couche HTTP.
    $status = 0;
    /** @var list<string> $headers */
    $headers = $http_response_header;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

/**
 * Un port TCP libre en boucle locale, choisi comme le reste du harnais choisit
 * le sien : on écoute sur le port 0 et on relit ce que le noyau a donné.
 */
function dastFreePort(): void
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if ($socket === false) {
        dastFail("aucun port libre trouvé : {$errorString}");
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    echo substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Écrit un certificat auto-signé et sa clé dans un seul fichier PEM — la forme
 * qu'attend l'option `local_cert` de `stream_socket_server()`.
 *
 * L'extension openssl de PHP plutôt que le binaire `openssl`, pour la même
 * raison d'« un seul interpréteur » que le reste du harnais. La clé ne quitte
 * pas le répertoire temporaire de la campagne et le certificat vaut un jour :
 * une identité TLS jetable qui survivrait à la campagne qui l'a produite est
 * un déchet avec une clé privée dedans.
 */
function dastGenerateCertificate(string $pemPath, string $hostname): void
{
    if (!extension_loaded('openssl')) {
        dastFail("l'extension PHP « openssl » est requise pour générer le certificat de la campagne.");
    }

    $subject = [
        'countryName' => 'FR',
        'organizationName' => 'SecondStay — harnais de sécurité',
        'commonName' => $hostname,
    ];

    // Le `subjectAltName` n'est pas décoratif : tous les navigateurs actuels
    // ignorent complètement le `commonName`, et Chromium refuse purement et
    // simplement un certificat sans SAN — y compris sous `ignoreHTTPSErrors`,
    // qui supprime l'avertissement et non un certificat malformé.
    $configFile = tempnam(sys_get_temp_dir(), 'dast-openssl-');
    if ($configFile === false) {
        dastFail("impossible de créer un fichier de configuration OpenSSL temporaire.");
    }
    file_put_contents(
        $configFile,
        "[req]\ndistinguished_name = dn\n[dn]\n[v3_req]\n"
        . "basicConstraints = CA:FALSE\n"
        . "keyUsage = digitalSignature, keyEncipherment\n"
        . "extendedKeyUsage = serverAuth\n"
        // `host.docker.internal` est le nom par lequel un ZAP conteneurisé
        // joint l'hôte hors Linux ; c'est donc l'origine que le navigateur
        // demande au proxy, et elle doit rester valide côté navigateur.
        . "subjectAltName = DNS:{$hostname}, DNS:localhost, DNS:host.docker.internal, IP:127.0.0.1\n"
    );

    $config = [
        'config' => $configFile,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'x509_extensions' => 'v3_req',
    ];

    $privateKey = openssl_pkey_new($config);
    if ($privateKey === false) {
        unlink($configFile);
        dastFail('génération de la clé privée impossible : ' . (string) openssl_error_string());
    }

    $csr = openssl_csr_new($subject, $privateKey, $config);
    if ($csr === false || $csr === true) {
        unlink($configFile);
        dastFail('génération de la demande de certificat impossible : ' . (string) openssl_error_string());
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $config);
    if ($certificate === false) {
        unlink($configFile);
        dastFail('auto-signature impossible : ' . (string) openssl_error_string());
    }

    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $keyPem, null, $config);
    unlink($configFile);

    // 0600 avant toute écriture : le fichier porte une clé privée, et une
    // fenêtre laissée au umask par défaut est une fenêtre.
    touch($pemPath);
    chmod($pemPath, 0600);
    file_put_contents($pemPath, $certificatePem . $keyPem);
}

/**
 * Empreinte SPKI du certificat, en base64 — ce qu'attend
 * `--ignore-certificate-errors-spki-list` de Chromium.
 *
 * POURQUOI CE DÉTOUR PLUTÔT QU'UN DRAPEAU GLOBAL
 * ---------------------------------------------------------------------------
 * `ignoreHTTPSErrors` de Playwright fait passer l'avertissement, mais ne rend
 * pas l'origine **sûre** aux yeux du navigateur : un service worker refuse de
 * s'enregistrer sur une origine dont le certificat est invalide, et le
 * scénario PWA reste bloqué jusqu'à son délai. C'est un défaut du harnais, pas
 * du produit.
 *
 * Les deux drapeaux plus simples ne conviennent pas :
 * `--allow-insecure-localhost` ne confère pas le statut d'origine sûre, et
 * `--ignore-certificate-errors` le confère mais fait accepter **n'importe
 * quel** certificat — au prix, constaté ici, d'un navigateur qui se ferme ou
 * se bloque à la création d'un contexte.
 *
 * Épingler la clé publique de la campagne est le compromis juste : le
 * navigateur ne fait d'exception que pour ce certificat-là, celui que le
 * harnais vient de générer et qui vivra le temps d'une campagne.
 */
function dastCertificateSpki(string $pemPath): void
{
    if (!is_file($pemPath)) {
        dastFail("le certificat {$pemPath} n'existe pas.");
    }

    $pem = (string) file_get_contents($pemPath);
    $certificate = @openssl_x509_read($pem);
    if ($certificate === false) {
        dastFail("le certificat {$pemPath} est illisible.");
    }

    $publicKey = @openssl_pkey_get_public($certificate);
    if ($publicKey === false) {
        dastFail("la clé publique de {$pemPath} est illisible.");
    }

    $details = openssl_pkey_get_details($publicKey);
    if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
        dastFail("la clé publique de {$pemPath} n'a pas pu être extraite.");
    }

    // `openssl_pkey_get_details()['key']` rend le SubjectPublicKeyInfo en PEM ;
    // Chromium veut le SHA-256 de sa forme DER, en base64.
    $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $details['key']);
    $der = base64_decode((string) $base64, true);
    if ($der === false) {
        dastFail("la clé publique de {$pemPath} n'est pas du base64 valide.");
    }

    echo base64_encode(hash('sha256', $der, true));
}

/** Interroge une URL jusqu'à réponse ou expiration. Jamais une attente fixe. */
function dastWaitUrl(string $url, int $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $response = dastHttpGet($url, 5);
        if ($response !== null && $response['status'] > 0 && $response['status'] < 500) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

/**
 * Prouver, avant de dépenser vingt minutes à scanner, que l'instance se croit
 * réellement en HTTPS.
 *
 * C'est le contrôle qui évite de gâcher toute la campagne. Si
 * `scripts/dast-https-prepend.php` ne fait pas son travail, l'application
 * n'émet ni HSTS ni cookie `Secure`, et le scan passe son temps à redécouvrir
 * un défaut du harnais pour le rapporter comme celui de l'application. Mieux
 * vaut échouer ici, bruyamment, en dix secondes.
 */
/**
 * Le cookie de session porte-t-il `Secure` ?
 *
 * Le nom compte. Une réponse en HTTPS pose plusieurs cookies, et l'un d'eux —
 * la préférence de langue — est `Secure` depuis toujours : accepter n'importe
 * quel `Set-Cookie` contenant « secure » faisait donc passer la preuve sans
 * jamais regarder le cookie de session. C'est exactement la panne que cette
 * fonction existe pour attraper, et elle y était aveugle.
 *
 * @param list<string> $headers
 */
function dastSessionCookieIsSecure(array $headers, string $sessionCookieName): bool
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }

        $cookie = trim(substr($header, strlen('Set-Cookie:')));
        $attributes = array_map('trim', explode(';', $cookie));
        $name = trim(explode('=', (string) array_shift($attributes), 2)[0]);

        if ($name !== $sessionCookieName) {
            continue;
        }

        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute, 'Secure') === 0) {
                return true;
            }
        }
    }

    return false;
}

function dastAssertHttps(string $baseUrl, string $sessionCookieName = 'secondstay_session'): void
{
    $reached = false;
    $hasHsts = false;
    $hasSecureCookie = false;
    $sessionCookieSeen = false;

    // Deux pages, parce que les deux protections ne se voient pas au même
    // endroit : l'en-tête HSTS accompagne toute réponse, le cookie de session
    // n'est posé que par une page qui ouvre une session. La racine redirige
    // vers la langue ; c'est la page de connexion qui pose le cookie.
    foreach (['/fr/login', '/fr/'] as $path) {
        $response = dastHttpGet(rtrim($baseUrl, '/') . $path, 20);
        if ($response === null) {
            continue;
        }
        $reached = true;

        foreach ($response['headers'] as $header) {
            if (stripos($header, 'Strict-Transport-Security:') === 0) {
                $hasHsts = true;
            }
        }

        foreach ($response['headers'] as $header) {
            if (stripos($header, 'Set-Cookie:') === 0
                && str_starts_with(trim(substr($header, strlen('Set-Cookie:'))), $sessionCookieName . '=')) {
                $sessionCookieSeen = true;
            }
        }

        if (dastSessionCookieIsSecure($response['headers'], $sessionCookieName)) {
            $hasSecureCookie = true;
        }

        if ($hasHsts && $hasSecureCookie) {
            echo "DAST : HSTS et cookie de session Secure confirmés — le câblage HTTPS est vivant.\n";

            return;
        }
    }

    if (!$reached) {
        dastFail("impossible de joindre {$baseUrl} en HTTPS.");
    }

    // Un cookie de session jamais vu et un cookie de session vu sans `Secure`
    // sont deux pannes différentes : la première est un scénario de preuve qui
    // n'ouvre plus de session, la seconde un vrai défaut de l'application.
    // Les confondre enverrait chercher au mauvais endroit.
    $cookieDiagnostic = $hasSecureCookie
        ? 'oui'
        : ($sessionCookieSeen ? 'NON — posé sans le drapeau' : "NON — cookie « {$sessionCookieName} » jamais posé");

    dastFail(
        "l'instance ne se comporte pas comme une instance HTTPS"
        . ' (HSTS : ' . ($hasHsts ? 'oui' : 'NON')
        . ", cookie de session Secure : {$cookieDiagnostic}).\n"
        . "      scripts/dast-https-prepend.php n'atteint pas les deux protections\n"
        . "      conditionnées au TLS. Scanner maintenant rapporterait le défaut du\n"
        . '      harnais comme deux constats contre du code applicatif correct.'
    );
}

/**
 * Appelle un endpoint de l'API ZAP, en rendant null sur tout échec plutôt
 * qu'en sortant.
 *
 * Séparé de `dastZapApi()` parce qu'un appelant — le suivi de progression d'un
 * plan — doit survivre à un refus passager sur lequel les autres doivent
 * mourir : `automation/action/runPlan` rend un `planId` avant que l'objet plan
 * ne soit enregistré, et un `planProgress` qui tombe dans cette fenêtre répond
 * `internal_error`. Une vraie réponse, et pas un vrai problème.
 *
 * @param array<string, string> $parameters
 *
 * @return array<string, mixed>|null
 */
function dastZapApiSoft(string $baseUrl, string $apiKey, string $path, array $parameters = []): ?array
{
    $query = http_build_query(['apikey' => $apiKey] + $parameters);
    $url = rtrim($baseUrl, '/') . '/JSON/' . trim($path, '/') . '/?' . $query;

    $response = dastHttpGet($url, 120);
    if ($response === null) {
        return null;
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || isset($decoded['code'])) {
        return null;
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Appelle un endpoint de l'API ZAP, en faisant échouer la campagne s'il ne
 * répond pas ou s'il refuse.
 *
 * @param array<string, string> $parameters
 *
 * @return array<string, mixed>
 */
function dastZapApi(string $baseUrl, string $apiKey, string $path, array $parameters = []): array
{
    $query = http_build_query(['apikey' => $apiKey] + $parameters);
    $url = rtrim($baseUrl, '/') . '/JSON/' . trim($path, '/') . '/?' . $query;

    $response = dastHttpGet($url, 120);
    if ($response === null) {
        dastFail("l'API ZAP n'a pas répondu sur {$baseUrl} (chemin {$path}).");
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        dastFail("l'API ZAP n'a pas rendu du JSON pour {$path} : " . substr($response['body'], 0, 200));
    }

    if (isset($decoded['code'])) {
        $message = $decoded['message'] ?? '';
        dastFail(
            "l'API ZAP a refusé {$path} : "
            . (is_scalar($decoded['code']) ? (string) $decoded['code'] : '?')
            . ' — ' . (is_scalar($message) ? (string) $message : '')
        );
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Demande à ZAP de charger et démarrer un plan Automation Framework, puis
 * imprime son identifiant.
 *
 * Démarrer et attendre sont deux sous-commandes parce que le plan n'est
 * délibérément pas joué d'un trait : son travail `delay` bloque jusqu'à ce que
 * la campagne navigateur ait fini de produire du trafic, ce qui arrive entre
 * les deux appels. Le plan est chargé depuis l'intérieur du conteneur : le
 * chemin est donc le sien.
 */
function dastStartPlan(string $baseUrl, string $apiKey, string $planPath): void
{
    $started = dastZapApi($baseUrl, $apiKey, 'automation/action/runPlan', ['filePath' => $planPath]);
    $planId = $started['planId'] ?? '';
    $planId = is_scalar($planId) ? (string) $planId : '';
    if ($planId === '') {
        dastFail('ZAP a accepté le plan mais n\'a rendu aucun planId.');
    }

    echo $planId;
}

/**
 * Attend que le plan en cours ait atteint son travail `delay` — c'est-à-dire
 * que tous ceux qui le précèdent ont réellement tourné.
 *
 * Envoyer du trafic plus tôt reviendrait à analyser des réponses avec la
 * configuration par défaut, et une alerte levée avant que sa configuration
 * n'existe reste levée. Interrogé sur le journal de progression du plan plutôt
 * qu'attendu à l'aveugle : un conteneur lent retarde alors la campagne au lieu
 * de la corrompre.
 */
function dastAwaitDelayJob(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    /** @var array<string, bool> $reported */
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dastZapApiSoft($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);
        if ($progress === null) {
            usleep(250_000);
            continue;
        }

        foreach (dastProgressLines($progress, 'error') as $line) {
            dastFail("le plan ZAP a échoué avant même que le navigateur ne tourne : {$line}");
        }

        foreach (['info', 'warn'] as $level) {
            foreach (dastProgressLines($progress, $level) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST : [zap {$level}] {$line}\n";
                }
                if (stripos($line, 'delay') !== false) {
                    return;
                }
            }
        }

        usleep(250_000);
    }

    dastFail("ZAP n'a pas atteint le travail « delay » du plan en {$timeoutSeconds} s.");
}

/**
 * Les lignes d'un niveau du journal de progression, normalisées en chaînes.
 *
 * @param array<string, mixed> $progress
 *
 * @return list<string>
 */
function dastProgressLines(array $progress, string $level): array
{
    $lines = $progress[$level] ?? [];
    if (!is_array($lines)) {
        return [];
    }

    $out = [];
    foreach ($lines as $line) {
        if (is_scalar($line)) {
            $out[] = (string) $line;
        }
    }

    return $out;
}

/**
 * Suit un plan jusqu'à son terme, en répétant ce que ZAP dit au moment où il
 * le dit, et échoue si le plan erre ou ne finit jamais.
 *
 * Interrogé plutôt qu'attendu aveuglément : un plan qui se bloque doit faire
 * échouer ce script plutôt que tenir un travail d'intégration continue ouvert
 * indéfiniment.
 */
function dastWaitPlan(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    /** @var array<string, bool> $reported */
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dastZapApiSoft($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);
        if ($progress === null) {
            usleep(500_000);
            continue;
        }

        foreach (['info', 'warn', 'error'] as $level) {
            foreach (dastProgressLines($progress, $level) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST : [zap {$level}] {$line}\n";
                }
            }
        }

        $errors = dastProgressLines($progress, 'error');
        if ($errors !== []) {
            dastFail('le plan ZAP a rapporté une erreur : ' . implode(' ; ', $errors));
        }

        $finished = $progress['finished'] ?? '';
        if (is_scalar($finished) && (string) $finished !== '') {
            return;
        }

        usleep(500_000);
    }

    dastFail("le plan ZAP n'a pas fini en {$timeoutSeconds} s.");
}

/**
 * Affirme que la carte du site de ZAP n'est pas vide et contient les pages que
 * la campagne est connue pour atteindre.
 *
 * LE CONTRÔLE LE PLUS IMPORTANT DU HARNAIS, parce que la panne qu'il attrape
 * est complètement silencieuse. Chromium contourne un proxy HTTP pour les
 * adresses de boucle locale à moins qu'on ne le lui interdise
 * (`--proxy-bypass-list=<-loopback>`). Quand cet argument disparaît, tous les
 * scénarios passent, ZAP n'enregistre rien, le scanner passif ne trouve aucun
 * problème dans ce rien, et la campagne rend un certificat de bonne santé.
 *
 * Le fichier d'attentes liste un chemin par ligne, pour que « ZAP a vu le
 * site » ne puisse pas être satisfait par la seule page d'accueil.
 */
function dastAssertSitemap(string $baseUrl, string $apiKey, string $siteUrl, string $expectationsFile): void
{
    if (!is_file($expectationsFile)) {
        dastFail("le fichier d'attentes {$expectationsFile} n'existe pas.");
    }

    $expected = [];
    foreach ((array) file($expectationsFile) as $line) {
        $line = trim((string) $line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $expected[] = $line;
        }
    }

    if ($expected === []) {
        dastFail("le fichier d'attentes {$expectationsFile} ne demande de vérifier aucun chemin.");
    }

    $response = dastZapApi($baseUrl, $apiKey, 'core/view/urls', ['baseurl' => $siteUrl]);
    $rawUrls = $response['urls'] ?? [];
    $urls = [];
    if (is_array($rawUrls)) {
        foreach ($rawUrls as $url) {
            if (is_scalar($url)) {
                $urls[] = (string) $url;
            }
        }
    }

    if ($urls === []) {
        dastFail(
            "la carte du site de ZAP pour {$siteUrl} est VIDE — le navigateur n'est pas passé par lui.\n"
            . "      La cause habituelle est Chromium contournant le proxy pour la boucle locale :\n"
            . '      vérifier que --proxy-bypass-list=<-loopback> atteint bien launchOptions.args '
            . 'dans playwright.config.js.'
        );
    }

    $paths = [];
    foreach ($urls as $url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $paths[$path] = true;
        }
    }

    $missing = [];
    foreach ($expected as $expectedPath) {
        $found = false;
        foreach (array_keys($paths) as $path) {
            if ($path === $expectedPath || str_starts_with($path, rtrim($expectedPath, '/') . '/')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing[] = $expectedPath;
        }
    }

    if ($missing !== []) {
        dastFail(
            'ZAP a enregistré ' . count($urls) . " URL pour {$siteUrl}, mais pas ces chemins que la\n"
            . '      campagne est connue pour visiter : ' . implode(', ', $missing) . "\n"
            . "      Une carte du site à laquelle ils manquent signifie que le scan n'a jamais vu\n"
            . "      l'essentiel de l'application."
        );
    }

    echo 'DAST : la carte du site de ZAP contient ' . count($urls) . " URL, dont tous les chemins attendus.\n";
}

/**
 * Lit les alertes levées par ZAP, les imprime groupées par risque, écrit
 * éventuellement un résumé par sévérité, et décide du verdict.
 *
 * Le résumé est ce que la passe de release publie **à côté** du rapport
 * complet. SecondStay est public, donc ses artefacts et ses assets de Release
 * le sont aussi, et publier le rapport entier est une décision assumée :
 * l'instance visée est jetable, sur la boucle locale, et le code qu'elle sert
 * est ouvert. Ce qui survit à cet argument, ce sont les constats d'en-têtes et
 * de cookies : ils portent sur la configuration livrée, donc sur la
 * production. Le compromis ne tient que tant que le rapport reste propre —
 * voir SECURITY.md.
 */
function dastGateAlerts(
    string $baseUrl,
    string $apiKey,
    string $siteUrl,
    string $threshold,
    string $summaryPath = ''
): never {
    $thresholdIndex = array_search($threshold, DAST_RISK_ORDER, true);
    if ($thresholdIndex === false) {
        dastFail("seuil de risque inconnu « {$threshold} » (attendu : " . implode(', ', DAST_RISK_ORDER) . ').');
    }

    $alerts = [];
    $start = 0;
    $pageSize = 500;

    // Paginé plutôt que récupéré d'un bloc : un scan peut lever des milliers
    // d'alertes informatives, que ZAP diffuserait sinon dans un seul document.
    while (true) {
        $page = dastZapApi($baseUrl, $apiKey, 'alert/view/alerts', [
            'baseurl' => $siteUrl,
            'start' => (string) $start,
            'count' => (string) $pageSize,
        ]);
        $batch = $page['alerts'] ?? [];
        $batch = is_array($batch) ? $batch : [];
        foreach ($batch as $alert) {
            if (is_array($alert)) {
                $alerts[] = $alert;
            }
        }
        if (count($batch) < $pageSize) {
            break;
        }
        $start += $pageSize;
    }

    /** @var array<string, array<string, array{count: int, urls: list<string>}>> $grouped */
    $grouped = [];
    /** @var array<string, int> $bySeverity */
    $bySeverity = array_fill_keys(DAST_RISK_ORDER, 0);

    foreach ($alerts as $alert) {
        // ZAP écrit le risque « Medium (High) » — le risque, puis la confiance.
        $riskRaw = $alert['risk'] ?? 'Informational';
        $risk = trim(explode('(', is_scalar($riskRaw) ? (string) $riskRaw : 'Informational')[0]);
        $nameRaw = $alert['alert'] ?? ($alert['name'] ?? 'sans nom');
        $name = is_scalar($nameRaw) ? (string) $nameRaw : 'sans nom';
        $urlRaw = $alert['url'] ?? '';
        $url = is_scalar($urlRaw) ? (string) $urlRaw : '';

        $grouped[$risk][$name] ??= ['count' => 0, 'urls' => []];
        $grouped[$risk][$name]['count']++;
        if (count($grouped[$risk][$name]['urls']) < 3 && $url !== '') {
            $grouped[$risk][$name]['urls'][] = $url;
        }

        if (array_key_exists($risk, $bySeverity)) {
            $bySeverity[$risk]++;
        }
    }

    $failing = 0;
    echo "\nDAST : constats par niveau de risque\n";
    foreach (array_reverse(DAST_RISK_ORDER) as $risk) {
        $entries = $grouped[$risk] ?? [];
        if ($entries === []) {
            continue;
        }

        $riskIndex = array_search($risk, DAST_RISK_ORDER, true);
        $blocking = is_int($riskIndex) && $riskIndex >= $thresholdIndex;

        echo '  ' . strtoupper($risk) . ($blocking ? ' (bloquant)' : '') . "\n";
        foreach ($entries as $name => $entry) {
            $failing += $blocking ? $entry['count'] : 0;
            echo "    - {$name} x{$entry['count']}\n";
            foreach ($entry['urls'] as $url) {
                echo "        {$url}\n";
            }
        }
    }

    if ($alerts === []) {
        echo "  (aucun)\n";
    }
    echo "\n";

    if ($summaryPath !== '') {
        $summary = [
            'seuil' => $threshold,
            'bloquants' => $failing,
            'total' => count($alerts),
            'par_severite' => $bySeverity,
            'genere_le' => gmdate('c'),
            'note' => 'Résumé par sévérité. Le rapport complet est publié à côté : '
                . 'voir SECURITY.md, scan dynamique.',
        ];
        @mkdir(dirname($summaryPath), 0755, true);
        file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        echo "DAST : résumé par sévérité écrit dans {$summaryPath}\n";
    }

    if ($failing > 0) {
        fwrite(STDERR, "DAST : {$failing} constat(s) au niveau « {$threshold} » ou au-dessus. Voir ci-dessus.\n");
        exit(1);
    }

    echo "DAST : aucun constat au niveau « {$threshold} » ou au-dessus.\n";
    exit(0);
}

/** @var list<string> $argv */
$command = $argv[1] ?? '';

switch ($command) {
    case 'free-port':
        dastFreePort();
        break;

    case 'generate-cert':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '') {
            dastFail('usage : dast-support.php generate-cert <chemin.pem> <nom-d-hote>');
        }
        dastGenerateCertificate($argv[2], $argv[3]);
        break;

    case 'cert-spki':
        if (($argv[2] ?? '') === '') {
            dastFail('usage : dast-support.php cert-spki <chemin.pem>');
        }
        dastCertificateSpki($argv[2]);
        break;

    case 'wait-url':
        if (($argv[2] ?? '') === '') {
            dastFail('usage : dast-support.php wait-url <url> <secondes>');
        }
        exit(dastWaitUrl($argv[2], (int) ($argv[3] ?? 30)) ? 0 : 1);

    case 'assert-https':
        if (($argv[2] ?? '') === '') {
            dastFail('usage : dast-support.php assert-https <url-de-base>');
        }
        dastAssertHttps($argv[2], ($argv[3] ?? '') !== '' ? $argv[3] : 'secondstay_session');
        break;

    case 'zap-plan-start':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage : dast-support.php zap-plan-start <url-zap> <clé-api> <chemin-du-plan>');
        }
        dastStartPlan($argv[2], $argv[3], $argv[4]);
        break;

    case 'zap-plan-await-delay':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage : dast-support.php zap-plan-await-delay <url-zap> <clé-api> <id-de-plan> <secondes>');
        }
        dastAwaitDelayJob($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 120));
        break;

    case 'zap-plan-wait':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage : dast-support.php zap-plan-wait <url-zap> <clé-api> <id-de-plan> <secondes>');
        }
        dastWaitPlan($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 1800));
        break;

    case 'assert-sitemap':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '' || ($argv[5] ?? '') === '') {
            dastFail('usage : dast-support.php assert-sitemap <url-zap> <clé-api> <url-du-site> <fichier>');
        }
        dastAssertSitemap($argv[2], $argv[3], $argv[4], $argv[5]);
        break;

    case 'gate-alerts':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '' || ($argv[5] ?? '') === '') {
            dastFail('usage : dast-support.php gate-alerts <url-zap> <clé-api> <url-du-site> <seuil> [résumé]');
        }
        // `dastGateAlerts()` rend `never` : c'est elle qui décide du code de
        // sortie de la campagne, il n'y a donc rien après elle.
        dastGateAlerts($argv[2], $argv[3], $argv[4], $argv[5], (string) ($argv[6] ?? ''));

    default:
        dastFail("sous-commande inconnue « {$command} ». Voir le bloc de tête de ce fichier.");
}
