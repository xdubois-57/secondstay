<?php

declare(strict_types=1);

/**
 * Serveur SMTP factice pour les tests du client `SmtpMailTransport`.
 *
 * Il n'implémente que ce qui est nécessaire pour rejouer une session complète
 * et écrit la transcription reçue afin que le test vérifie le protocole
 * réellement parlé. Il n'ouvre jamais qu'une seule connexion, sur la boucle
 * locale, et n'est jamais livré dans l'artefact de production.
 *
 * Usage : php smtp-stub.php <port> <transcript> <scénario>
 *
 * Le port réellement écouté est écrit dans `<transcript>.port` dès que la
 * socket est ouverte : le test n'a ainsi aucune course au démarrage.
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

$transcript = [];
$inData = false;

$reply = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$reply('220 stub.localhost ESMTP');

while (($line = fgets($client, 4096)) !== false) {
    $transcript[] = rtrim($line, "\r\n");

    if ($inData) {
        if (rtrim($line, "\r\n") === '.') {
            $inData = false;
            $reply($scenario === 'reject-data' ? '554 message refusé' : '250 OK message accepté');
        }
        continue;
    }

    $command = strtoupper(strtok(trim($line), ' ') ?: '');

    switch ($command) {
        case 'EHLO':
        case 'HELO':
            $reply('250-stub.localhost');
            $reply('250-SIZE 10485760');
            $reply('250 AUTH PLAIN LOGIN');
            break;
        case 'AUTH':
            if (str_contains(strtoupper($line), 'AUTH PLAIN')) {
                $reply(match ($scenario) {
                    'plain-unsupported' => '504 mécanisme non supporté',
                    'bad-credentials' => '535 identifiants refusés',
                    default => '235 authentifié',
                });
                break;
            }
            // AUTH LOGIN : nom d'utilisateur puis mot de passe.
            $reply('334 VXNlcm5hbWU6');
            $transcript[] = rtrim((string) fgets($client, 4096), "\r\n");
            $reply('334 UGFzc3dvcmQ6');
            $transcript[] = rtrim((string) fgets($client, 4096), "\r\n");
            $reply($scenario === 'bad-credentials' ? '535 identifiants refusés' : '235 authentifié');
            break;
        case 'MAIL':
            $reply('250 OK');
            break;
        case 'RCPT':
            $reply($scenario === 'reject-recipient' ? '550 destinataire inconnu' : '250 OK');
            break;
        case 'DATA':
            $inData = true;
            $reply('354 Envoyez le message');
            break;
        case 'QUIT':
            $reply('221 Au revoir');
            break 2;
        default:
            $reply('502 commande inconnue');
    }
}

if ($transcriptPath !== '') {
    file_put_contents($transcriptPath, implode("\n", $transcript));
}

fclose($client);
fclose($server);
