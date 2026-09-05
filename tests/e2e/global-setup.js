import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Le terminateur TLS n'existe que pour la campagne de scan dynamique. */
const tlsEnabled = process.env.SECONDSTAY_E2E_TLS === '1';

/**
 * Avant chaque campagne E2E, l'application est remise à l'état d'une archive
 * fraîchement déployée : c'est la seule façon de tester réellement le parcours
 * d'installation (TESTING.md §7).
 */
export default async function globalSetup(config) {
    execFileSync('php', [resolve(root, 'scripts/e2e-reset.php')], {
        cwd: root,
        stdio: 'inherit',
        env: process.env
    });

    // Le serveur peut avoir été démarré auparavant sans le transport factice
    // (`reuseExistingServer`). On le relance systématiquement avec, puis on
    // vérifie que la boîte de test répond : un scénario de compte qui échoue
    // doit signaler un vrai défaut, jamais une mauvaise configuration locale.
    const env = {
        ...process.env,
        SECONDSTAY_MAIL_TRANSPORT: 'fake',
        SECONDSTAY_PUSH_PROVIDER: 'fake',
        SECONDSTAY_PAYMENT_PROVIDER: 'fake',
        SECONDSTAY_IMAP_PROVIDER: 'fake',
        // Modèle factice et pages servies depuis le disque : le pipeline de
        // contenu local est joué en entier, sans clé et sans réseau sortant.
        SECONDSTAY_LLM_PROVIDER: 'fake',
        SECONDSTAY_HTTP_FETCHER: 'fixtures'
    };

    // En HTTPS, le serveur d'application recule derrière un terminateur TLS :
    // il écoute en clair sur un port interne, sur 127.0.0.1, et n'est joignable
    // que par lui. `auto_prepend_file` traduit l'en-tête posé par le
    // terminateur en `$_SERVER['HTTPS']` pour ce processus seulement — c'est le
    // harnais qui ment, pas le produit (scripts/dast-https-prepend.php).
    const publicHost = process.env.SECONDSTAY_HOST || 'localhost';
    const publicPort = process.env.SECONDSTAY_PORT || '8123';
    const backendHost = tlsEnabled ? '127.0.0.1' : publicHost;
    const backendPort = tlsEnabled
        ? (process.env.SECONDSTAY_BACKEND_PORT || String(Number(publicPort) + 1))
        : publicPort;

    if (tlsEnabled) {
        env.SECONDSTAY_PHP_PREPEND = resolve(root, 'scripts/dast-https-prepend.php');
    }
    env.SECONDSTAY_HOST = backendHost;
    env.SECONDSTAY_PORT = backendPort;

    execFileSync(resolve(root, 'scripts/dev-server.sh'), ['restart'], {
        cwd: root,
        stdio: 'inherit',
        env
    });

    const internalBaseURL = `http://${backendHost}:${backendPort}`;
    const baseURL = config.projects[0]?.use?.baseURL
        || process.env.SECONDSTAY_BASE_URL
        || `http://${publicHost}:${publicPort}`;

    if (tlsEnabled) {
        await startTlsTerminator(publicHost, publicPort, backendHost, backendPort);
    }

    // Les sondes de la préparation passent par le serveur d'application
    // directement, et non par la façade : Node refuserait le certificat
    // auto-signé de la campagne, et le désactiver globalement affaiblirait
    // aussi les vérifications que ces sondes sont censées faire.
    for (const [path, variable] of [
        ['/api/dev/mailbox', 'SECONDSTAY_MAIL_TRANSPORT=fake'],
        ['/api/dev/notifications', 'SECONDSTAY_PUSH_PROVIDER=fake'],
        ['/api/dev/payments', 'SECONDSTAY_PAYMENT_PROVIDER=fake']
    ]) {
        const response = await fetch(`${internalBaseURL}${path}`);
        if (!response.ok) {
            throw new Error(
                `La boîte de test ${path} est indisponible (${response.status}). `
                + `Le serveur doit tourner avec ${variable}.`
            );
        }
    }

    // Le dépôt de fixtures HTTP n'a pas de lecture : on vérifie qu'il accepte
    // une écriture, puis on repart d'un dépôt vide.
    const fixtures = await fetch(`${internalBaseURL}/webhook/dev/http/purge`, { method: 'POST' });
    if (!fixtures.ok) {
        throw new Error(
            `Les fixtures HTTP sont indisponibles (${fixtures.status}). `
            + 'Le serveur doit tourner avec SECONDSTAY_HTTP_FETCHER=fixtures.'
        );
    }
}

/**
 * Pose le terminateur TLS devant le serveur d'application, puis **prouve** que
 * l'instance se croit réellement en HTTPS.
 *
 * La preuve n'est pas une précaution : sans elle, une campagne entière — et,
 * au-delà, un scan de vingt minutes — irait redécouvrir un défaut du harnais
 * pour le rapporter comme deux constats contre du code applicatif correct
 * (« pas de HSTS », « cookie sans Secure »). Mieux vaut échouer ici, en dix
 * secondes, en le disant.
 */
async function startTlsTerminator(host, port, backendHost, backendPort) {
    const support = resolve(root, 'scripts/dast-support.php');
    const stateDir = resolve(root, 'storage/temp');
    mkdirSync(stateDir, { recursive: true });

    const certificate = process.env.SECONDSTAY_TLS_CERT || resolve(stateDir, `tls-${port}.pem`);
    if (!existsSync(certificate)) {
        execFileSync('php', [support, 'generate-cert', certificate, host], {
            cwd: root,
            stdio: 'inherit'
        });
    }

    const terminator = spawn('php', [
        resolve(root, 'scripts/dast-tls-proxy.php'),
        `--listen=${host}:${port}`,
        `--backend=${backendHost}:${backendPort}`,
        `--cert=${certificate}`
    ], {
        cwd: root,
        detached: true,
        stdio: ['ignore', 'ignore', 'inherit']
    });
    terminator.unref();

    // Le pid est écrit sur disque plutôt que gardé en mémoire : le démontage
    // global tourne dans un autre processus Node que celui-ci.
    writeFileSync(resolve(stateDir, `tls-proxy-${port}.pid`), String(terminator.pid));

    // L'URL sondée est celle où le terminateur écoute réellement, construite
    // ici — et non `baseURL`, qui est ce que voit le **navigateur**. Les deux
    // se séparent dès que le navigateur passe par un proxy conteneurisé : ZAP
    // sous Docker Desktop joint l'hôte par un nom que l'hôte, lui, ne résout
    // pas. Sonder ce nom depuis ici échouerait sans que rien ne soit cassé.
    const probeURL = `https://${host}:${port}`;

    const ready = spawnResult('php', [support, 'wait-url', `${probeURL}/`, '30']);
    if (ready !== 0) {
        throw new Error(`Le terminateur TLS n'a pas répondu sur ${probeURL} (code ${ready}).`);
    }

    execFileSync('php', [support, 'assert-https', probeURL], { cwd: root, stdio: 'inherit' });
}

function spawnResult(command, args) {
    try {
        execFileSync(command, args, { cwd: root, stdio: 'inherit' });

        return 0;
    } catch (error) {
        return error.status ?? 1;
    }
}
