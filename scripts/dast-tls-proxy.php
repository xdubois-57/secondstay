<?php

declare(strict_types=1);

/**
 * Un terminateur TLS devant `php -S`, pour la campagne de sécurité et pour
 * rien d'autre.
 *
 * ## Pourquoi il doit exister
 *
 * Deux protections de SecondStay sont conditionnées à l'arrivée de la requête
 * en HTTPS : l'en-tête `Strict-Transport-Security` et le drapeau `Secure` du
 * cookie de session — toutes deux décidées par `Request::isSecure()`.
 *
 * `scripts/dev-server.sh` sert en clair. Un scan joué contre lui rapporterait
 * « HSTS absent » et « cookie sans Secure » : deux constats **faux**, à propos
 * de code **correct**. La correction tentante est un filtre d'alertes qui fait
 * taire les deux règles, et c'est exactement ainsi qu'un rapport cesse d'être
 * lu — deux règles muettes pour un défaut de harnais sont deux règles que
 * personne ne regarde le jour où l'une se déclenche pour de bon. On répare
 * donc le harnais, et les deux règles restent armées.
 *
 * Le serveur intégré de PHP ne parle pas TLS, et ce harnais ne dépend
 * volontairement de rien d'autre que `php` et `npm` — la même contrainte que
 * le reste de l'outillage de test. La moitié TLS est donc un script PHP,
 * comme le routeur et le bootstrap de couverture le sont déjà.
 *
 * CECI EST DU CODE DE TEST. Il ne part dans aucune release et aucun
 * déploiement ne l'exécute.
 *
 * ## Ce qu'il fait à la requête
 *
 * Il pose `X-Forwarded-Proto: https`, que `scripts/dast-https-prepend.php`
 * traduit en `$_SERVER['HTTPS']` côté serveur de test. Toute copie de cet
 * en-tête envoyée par le **client** est retirée d'abord : un terminateur qui
 * relaie un `X-Forwarded-Proto` fourni par le client est lui-même la
 * vulnérabilité, pas le correctif d'une autre.
 *
 * Rien d'autre n'est réécrit. `Host` en particulier est laissé intact, pour
 * que chaque URL absolue construite par l'application reste joignable.
 *
 * ## Pourquoi la réponse est tamponnée plutôt que relayée au fil de l'eau
 *
 * `php -S` n'envoie pas de `Content-Length` pour ce que PHP génère : il répond
 * `Connection: close` et laisse la fin de flux délimiter le corps. C'est de
 * l'HTTP/1.1 légal, et cela a deux conséquences qu'un scan ne supporte pas.
 *
 * Une connexion par réponse signifie une poignée de main TLS par ressource,
 * assez lente pour changer le comportement et pas seulement la durée : la page
 * devient interactive avant que ses scripts ne soient arrivés. Et Chromium
 * considère comme annulée une réponse délimitée par la seule fermeture de
 * connexion lorsqu'il s'agit d'un téléchargement — ce que la campagne fait
 * pour l'export de reporting.
 *
 * Chaque réponse est donc lue entièrement, mesurée, puis réémise avec un vrai
 * `Content-Length` sur une connexion réutilisable. Les octets que voit le
 * scanner restent ceux que l'application a produits ; seule la délimitation
 * est la nôtre.
 *
 * Usage (`scripts/dast.sh` le fait, jamais un humain) :
 *   php scripts/dast-tls-proxy.php --listen=127.0.0.1:8443 \
 *       --backend=127.0.0.1:8123 --cert=/chemin/server.pem
 */

const DAST_TLS_READ_CHUNK = 65536;
const DAST_TLS_HEAD_LIMIT = 262144;
const DAST_TLS_BACKEND_TIMEOUT = 120;

/**
 * Un plafond, pas un objectif : un client qui garderait une connexion pour
 * toujours immobiliserait un processus fils avec elle.
 */
const DAST_TLS_MAX_REQUESTS = 200;

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function dastTlsParseArguments(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches) !== 1) {
            fwrite(STDERR, "dast-tls-proxy : argument non reconnu « {$argument} ».\n");
            exit(1);
        }
        $options[$matches[1]] = $matches[2];
    }

    foreach (['listen', 'backend', 'cert'] as $required) {
        if (($options[$required] ?? '') === '') {
            fwrite(STDERR, "dast-tls-proxy : --{$required} est obligatoire.\n");
            exit(1);
        }
    }

    return $options;
}

/**
 * Lit jusqu'à ce que le tampon contienne au moins $minimum octets.
 *
 * Le tampon est porté par référence d'un appel à l'autre parce qu'une
 * connexion HTTP est une suite de messages délimités : lire « jusqu'à la fin
 * des en-têtes » dépasse toujours sur ce qui suit. Conserver ce dépassement
 * est ce qui rend le keep-alive possible ; sans lui, le premier octet du corps
 * de la requête — ou de la requête suivante — serait lu puis jeté.
 *
 * @param resource $stream
 */
function dastTlsFill(string &$buffer, $stream, int $minimum): bool
{
    while (strlen($buffer) < $minimum) {
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $buffer .= $chunk;
    }

    return true;
}

/**
 * Lit un bloc d'en-têtes HTTP, terminateur compris, en laissant le reste dans
 * le tampon.
 *
 * Rend `null` quand le pair a fermé avant d'avoir envoyé un bloc complet, ce
 * qui est ordinaire et non exceptionnel : un navigateur qui ouvre une
 * connexion sans s'en servir, une connexion keep-alive qui expire, un scanner
 * qui sonde le port.
 *
 * @param resource $stream
 */
function dastTlsReadHead(string &$buffer, $stream): ?string
{
    while (($position = strpos($buffer, "\r\n\r\n")) === false) {
        if (strlen($buffer) > DAST_TLS_HEAD_LIMIT) {
            return null;
        }
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }

    $head = substr($buffer, 0, $position + 4);
    $buffer = substr($buffer, $position + 4);

    return $head;
}

/** Recherche d'en-tête insensible à la casse sur un bloc brut. */
function dastTlsHeader(string $head, string $name): ?string
{
    foreach (explode("\r\n", $head) as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        if (strcasecmp(trim(substr($line, 0, $colon)), $name) === 0) {
            return trim(substr($line, $colon + 1));
        }
    }

    return null;
}

/**
 * Retire toutes les occurrences d'un ensemble d'en-têtes d'un bloc brut.
 *
 * @param list<string> $names
 */
function dastTlsStripHeaders(string $head, array $names): string
{
    $kept = [];
    foreach (explode("\r\n", rtrim($head, "\r\n")) as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $header = trim(substr($line, 0, $colon));
            foreach ($names as $name) {
                if (strcasecmp($header, $name) === 0) {
                    continue 2;
                }
            }
        }
        $kept[] = $line;
    }

    return implode("\r\n", $kept);
}

/**
 * Lit un corps de requête selon la délimitation que la requête a déclarée,
 * telle quelle.
 *
 * Le découpage en morceaux est traité aussi bien que `Content-Length` parce
 * que la campagne téléverse des médias et des documents : un terminateur qui
 * se tromperait sur l'une des deux formes tronquerait une requête que le scan
 * rapporterait ensuite comme une erreur serveur de l'application.
 *
 * @param resource $stream
 */
function dastTlsReadBody(string &$buffer, $stream, string $head): ?string
{
    $transferEncoding = dastTlsHeader($head, 'Transfer-Encoding');
    if ($transferEncoding !== null && stripos($transferEncoding, 'chunked') !== false) {
        $body = '';
        while (true) {
            while (($lineEnd = strpos($buffer, "\r\n")) === false) {
                if (!dastTlsFill($buffer, $stream, strlen($buffer) + 1)) {
                    return null;
                }
            }
            $size = (int) hexdec(trim(explode(';', substr($buffer, 0, $lineEnd))[0]));
            $needed = $lineEnd + 2 + $size + 2;
            if (!dastTlsFill($buffer, $stream, $needed)) {
                return null;
            }
            $body .= substr($buffer, 0, $needed);
            $buffer = substr($buffer, $needed);
            if ($size === 0) {
                return $body;
            }
        }
    }

    $contentLength = (int) (dastTlsHeader($head, 'Content-Length') ?? '0');
    if ($contentLength <= 0) {
        return '';
    }
    if (!dastTlsFill($buffer, $stream, $contentLength)) {
        return null;
    }
    $body = substr($buffer, 0, $contentLength);
    $buffer = substr($buffer, $contentLength);

    return $body;
}

/**
 * Écrit tout le tampon, ou rend `false`.
 *
 * `fwrite()` peut n'écrire qu'une partie de ce qu'on lui donne dès que le
 * tampon de la socket est plein, et rendre alors un compte positif plus petit
 * que la longueur demandée. Traiter « pas faux » comme « tout écrit » tronque
 * silencieusement — vers le serveur d'application comme vers le navigateur.
 *
 * Les deux sens en ont besoin, et pour la même raison : la campagne pousse un
 * envoi multipart d'environ 1,5 Mo, et l'entête de réponse a déjà annoncé un
 * `Content-Length` que le corps doit honorer. Un corps court laisse le client
 * attendre des octets qui ne viendront jamais — un scan qui se fige, pour une
 * raison étrangère à l'application.
 *
 * @param resource $stream
 */
function dastTlsWriteAll($stream, string $payload): bool
{
    $written = 0;
    $length = strlen($payload);

    while ($written < $length) {
        $chunk = @fwrite($stream, substr($payload, $written));
        if ($chunk === false || $chunk === 0) {
            return false;
        }
        $written += $chunk;
    }

    return true;
}

/**
 * Envoie une requête à `php -S` et lit toute la réponse.
 *
 * Une connexion neuve par requête, parce que le serveur intégré répond
 * `Connection: close` et n'a pas de keep-alive : la réponse est simplement
 * tout ce qu'il écrit avant la fin de flux.
 *
 * @return array{0: string, 1: string}|null en-têtes et corps, ou null en cas d'échec
 */
function dastTlsExchange(string $backend, string $head, string $body): ?array
{
    $upstream = @stream_socket_client(
        'tcp://' . $backend,
        $errorNumber,
        $errorString,
        DAST_TLS_BACKEND_TIMEOUT
    );
    if ($upstream === false) {
        return null;
    }
    stream_set_timeout($upstream, DAST_TLS_BACKEND_TIMEOUT);

    if (!dastTlsWriteAll($upstream, $head . $body)) {
        fclose($upstream);

        return null;
    }

    $raw = '';
    while (!feof($upstream)) {
        $chunk = fread($upstream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    fclose($upstream);

    $position = strpos($raw, "\r\n\r\n");
    if ($position === false) {
        return null;
    }

    return [substr($raw, 0, $position + 4), substr($raw, $position + 4)];
}

/**
 * Reconstruit les en-têtes de réponse pour que le client obtienne une
 * connexion correctement délimitée et réutilisable. Voir le bloc de tête du
 * fichier pour la raison.
 */
function dastTlsReframe(string $head, int $length, bool $keepAlive, bool $omitBody): string
{
    $head = dastTlsStripHeaders($head, ['Content-Length', 'Transfer-Encoding', 'Connection', 'Keep-Alive']);
    $head .= "\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close');
    if (!$omitBody) {
        $head .= "\r\nContent-Length: {$length}";
    }

    return $head . "\r\n\r\n";
}

/**
 * Relaie les requêtes d'une connexion cliente jusqu'à ce que l'un des deux
 * côtés ait fini.
 *
 * Exécuté dans un processus fils : un échec ici coûte une connexion, jamais
 * l'écouteur.
 *
 * @param resource $client
 */
function dastTlsHandleConnection($client, string $backend): void
{
    stream_set_timeout($client, DAST_TLS_BACKEND_TIMEOUT);
    $buffer = '';

    for ($served = 0; $served < DAST_TLS_MAX_REQUESTS; $served++) {
        $head = dastTlsReadHead($buffer, $client);
        if ($head === null) {
            return;
        }

        $requestLine = strtok($head, "\r\n");
        $method = strtoupper((string) strtok($requestLine === false ? '' : $requestLine, ' '));

        $body = dastTlsReadBody($buffer, $client, $head);
        if ($body === null) {
            return;
        }

        // La copie du client est retirée avant que la nôtre ne soit posée. Un
        // terminateur qui relaie un X-Forwarded-Proto fourni par le client est
        // la vulnérabilité, pas le correctif d'une autre.
        $forwarded = dastTlsStripHeaders($head, ['X-Forwarded-Proto'])
            . "\r\nX-Forwarded-Proto: https\r\n\r\n";

        $response = dastTlsExchange($backend, $forwarded, $body);
        if ($response === null) {
            // Le serveur applicatif n'est plus là (démontage, plantage).
            // Répondre quelque chose de bien formé plutôt qu'une coupure sèche,
            // pour que le scanner enregistre un 502 et non un échec inexpliqué.
            @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

            return;
        }
        [$responseHead, $responseBody] = $response;

        // Une réponse à HEAD, un 204 et un 304 ne portent pas de corps par
        // définition : leur déclarer une longueur serait une erreur de
        // délimitation de notre fait.
        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHead, $matches) === 1) {
            $status = (int) $matches[1];
        }
        $omitBody = $method === 'HEAD' || $status === 204 || $status === 304;

        $clientConnection = (string) (dastTlsHeader($head, 'Connection') ?? '');
        $keepAlive = stripos($clientConnection, 'close') === false
            && str_contains($requestLine === false ? '' : $requestLine, 'HTTP/1.1')
            // La **dernière** réponse servie par ce fils le dit, plutôt que de
            // laisser la connexion se fermer en silence après elle. Un client
            // qui réutilise une connexion qu'il n'avait aucune raison de croire
            // finie perd ce qu'il y avait déjà écrit, et un navigateur rejoue un
            // GET perdu mais pas nécessairement un POST — ce qui se voit comme
            // une requête qui n'obtient simplement jamais de réponse.
            && $served < DAST_TLS_MAX_REQUESTS - 1;

        $out = dastTlsReframe($responseHead, strlen($responseBody), $keepAlive, $omitBody);
        // Même exigence que vers le serveur d'application, et pour la même
        // raison : l'entête a annoncé un `Content-Length` que le corps doit
        // honorer, sans quoi le navigateur attend des octets qui ne viendront
        // pas.
        if (!dastTlsWriteAll($client, $out)) {
            return;
        }
        if (!$omitBody && $responseBody !== '' && !dastTlsWriteAll($client, $responseBody)) {
            return;
        }

        if (!$keepAlive) {
            return;
        }
    }
}

/** @var list<string> $argv */
$options = dastTlsParseArguments($argv);

foreach (['pcntl', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "dast-tls-proxy : l'extension PHP « {$extension} » est requise.\n");
        exit(1);
    }
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $options['cert'],
        'allow_self_signed' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        // Aucun ALPN annoncé : un client qui négocierait sinon HTTP/2 retombe
        // en HTTP/1.1, qui est ce que le relais ci-dessus parle et ce que ZAP
        // enregistre le plus fidèlement.
        'alpn_protocols' => '',
        'disable_compression' => true,
    ],
]);

// Écoute en TCP simple avec les options TLS attachées, la poignée de main
// étant différée au fils, pour deux raisons. Un serveur `tls://` fait la
// poignée de main à l'intérieur de `stream_socket_accept()`, ce qui bloque la
// boucle d'acceptation sur le client le plus lent ; et `fclose()` sur un flux
// TLS déjà négocié effectue un arrêt SSL, de sorte que le parent fermant sa
// propre copie après le fork couperait la connexion que le fils est en train
// de servir. Fermer un socket TCP simple dans le parent n'est qu'un
// décrément de compteur, ce que le motif de fork suppose.
$server = @stream_socket_server(
    'tcp://' . $options['listen'],
    $errorNumber,
    $errorString,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if ($server === false) {
    fwrite(STDERR, "dast-tls-proxy : écoute impossible sur {$options['listen']} : {$errorString}\n");
    exit(1);
}

// Les gestionnaires doivent aussi être servis dans les **fils**, qui
// n'appellent jamais `pcntl_signal_dispatch()` : ils passent leur vie dans une
// lecture bloquante. Sans signaux asynchrones, un fils hérite d'un
// gestionnaire qui ne s'exécute pas — et perd du même coup l'action par défaut
// de SIGTERM. Au démontage il survivrait jusqu'au délai de lecture ou jusqu'à
// `DAST_TLS_MAX_REQUESTS`, en gardant le port.
pcntl_async_signals(true);

// SIG_IGN sur SIGCHLD laisse le noyau récolter les fils. Une campagne, c'est
// des dizaines de milliers de connexions : un zombie par connexion épuiserait
// la table des processus bien avant la fin.
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGTERM, static function (): void {
    exit(0);
});
pcntl_signal(SIGINT, static function (): void {
    exit(0);
});

fwrite(STDERR, "dast-tls-proxy : écoute sur {$options['listen']}, relais vers {$options['backend']}.\n");

while (true) {
    pcntl_signal_dispatch();

    // Une acceptation qui échoue — un client qui a renoncé, une attente qui
    // expire — est un événement ordinaire pendant une campagne de sécurité, pas
    // une raison de s'arrêter.
    $client = @stream_socket_accept($server, 5);
    if ($client === false) {
        continue;
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "dast-tls-proxy : fork impossible, connexion traitée sur place.\n");
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dastTlsHandleConnection($client, $options['backend']);
        }
        fclose($client);
        continue;
    }

    if ($pid === 0) {
        fclose($server);
        // Une poignée de main qui échoue est ordinaire pendant une campagne de
        // sécurité (une sonde en clair sur le port TLS, un client qui a changé
        // d'avis) : elle coûte cette connexion et rien d'autre.
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dastTlsHandleConnection($client, $options['backend']);
        }
        fclose($client);
        exit(0);
    }

    fclose($client);
}
