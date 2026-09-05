<?php

declare(strict_types=1);

/**
 * Serveur IMAP factice pour les tests du client `ImapClient`.
 *
 * Il n'implémente que ce que SecondStay parle réellement — LOGIN, SELECT,
 * UID SEARCH, UID FETCH, LOGOUT — et écrit la transcription reçue afin que le
 * test vérifie le protocole effectivement émis, et pas seulement le résultat.
 * Il n'écoute que sur la boucle locale et n'est jamais livré en production.
 *
 * Usage : php imap-stub.php <port> <transcript> <scénario>
 *
 * Le port réellement écouté est écrit dans `<transcript>.port` dès l'ouverture
 * de la socket : le test n'a donc aucune course au démarrage.
 */

$port = (int) ($argv[1] ?? 0);
$transcriptPath = (string) ($argv[2] ?? '');
$scenario = (string) ($argv[3] ?? 'ok');

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
if ($server === false) {
    fwrite(STDERR, 'stub: ' . $errorMessage . "\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
file_put_contents($transcriptPath . '.port', substr($name, (int) strrpos($name, ':') + 1));

$client = @stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}
stream_set_timeout($client, 10);

/** Messages servis par le faux serveur, indexés par UID. */
$messages = [
    11 => "From: Claire <claire@example.test>\r\nSubject: Premier\r\n\r\nCorps un.\r\n",
    12 => "From: Paul <paul@example.test>\r\nSubject: Deuxième\r\n\r\nCorps deux.\r\n",
    17 => "From: Claire <claire@example.test>\r\nSubject: Troisième\r\n\r\nCorps trois.\r\n",
];

$transcript = [];

$write = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$write($scenario === 'refuse_greeting' ? '* BYE serveur indisponible' : '* OK SecondStay IMAP factice');

while (!feof($client)) {
    $line = fgets($client, 8192);
    if ($line === false) {
        break;
    }

    $line = rtrim($line, "\r\n");
    if ($line === '') {
        continue;
    }

    // Le mot de passe ne doit jamais atterrir dans une transcription de test.
    $transcript[] = (string) preg_replace('/^(\S+ LOGIN "[^"]*") ".*"$/i', '$1 "***"', $line);

    $space = strpos($line, ' ');
    $tag = $space === false ? $line : substr($line, 0, $space);
    $command = $space === false ? '' : substr($line, $space + 1);
    $verb = strtoupper(explode(' ', $command)[0] ?? '');

    if ($verb === 'LOGIN') {
        $write($scenario === 'refuse_login' ? $tag . ' NO identifiants refusés' : $tag . ' OK connecté');
        continue;
    }

    if ($verb === 'SELECT') {
        $write('* 3 EXISTS');
        $write('* OK [UIDVALIDITY ' . ($scenario === 'renumbered' ? '99' : '42') . '] UIDs valides');
        $write('* OK [UIDNEXT 18] Prochain');
        $write($tag . ' OK [READ-WRITE] SELECT terminé');
        continue;
    }

    if ($verb === 'UID' && str_starts_with(strtoupper($command), 'UID SEARCH')) {
        preg_match('/UID (\d+):\*/i', $command, $match);
        $from = (int) ($match[1] ?? 1);

        $found = array_values(array_filter(array_keys($messages), static fn (int $uid): bool => $uid >= $from));
        // Un vrai serveur renvoie toujours au moins le dernier message, même
        // si tous sont plus anciens que la borne demandée.
        if ($found === []) {
            $found = [max(array_keys($messages))];
        }

        $write('* SEARCH ' . implode(' ', $found));
        $write($tag . ' OK SEARCH terminé');
        continue;
    }

    if ($verb === 'UID' && str_starts_with(strtoupper($command), 'UID FETCH')) {
        preg_match('/UID FETCH (\d+)/i', $command, $match);
        $uid = (int) ($match[1] ?? 0);

        if (!isset($messages[$uid])) {
            $write($tag . ' OK FETCH terminé');
            continue;
        }

        $body = $messages[$uid];
        $write(sprintf('* 1 FETCH (UID %d BODY[] {%d}', $uid, strlen($body)));
        fwrite($client, $body);
        $write(')');
        $write($tag . ' OK FETCH terminé');
        continue;
    }

    if ($verb === 'LOGOUT') {
        $write('* BYE au revoir');
        $write($tag . ' OK LOGOUT terminé');
        break;
    }

    $write($tag . ' BAD commande inconnue');
}

// Écriture atomique : `file_put_contents` crée le fichier vide puis écrit,
// et le test peut observer l'instant qui sépare les deux. Il lisait alors une
// transcription vide et échouait sans que rien ne soit cassé.
$temporary = $transcriptPath . '.partial';
file_put_contents($temporary, implode("\n", $transcript));
rename($temporary, $transcriptPath);

fclose($client);
fclose($server);
