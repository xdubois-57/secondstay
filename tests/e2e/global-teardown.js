import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Le dépôt de travail ne doit jamais conserver la configuration locale générée
 * par la campagne E2E : elle contient une clé de chiffrement et des
 * identifiants de base.
 */
export default async function globalTeardown() {
    // SECONDSTAY_KEEP_INSTALL=1 conserve l'installation pour inspecter
    // manuellement l'application après une campagne.
    if (process.env.SECONDSTAY_KEEP_INSTALL === '1') {
        return;
    }

    stopTlsTerminator();

    // La configuration locale disparaît : le serveur qui la lisait n'a plus
    // rien à servir, et le laisser tourner ne ferait qu'un processus orphelin
    // pointant vers une installation effacée.
    const tlsEnabled = process.env.SECONDSTAY_E2E_TLS === '1';
    const publicPort = process.env.SECONDSTAY_PORT || '8123';
    const backendPort = tlsEnabled
        ? (process.env.SECONDSTAY_BACKEND_PORT || String(Number(publicPort) + 1))
        : publicPort;

    try {
        execFileSync(resolve(root, 'scripts/dev-server.sh'), ['stop'], {
            cwd: root,
            stdio: 'inherit',
            env: {
                ...process.env,
                SECONDSTAY_HOST: tlsEnabled ? '127.0.0.1' : (process.env.SECONDSTAY_HOST || 'localhost'),
                SECONDSTAY_PORT: backendPort
            }
        });
    } catch {
        // Un serveur déjà arrêté n'est pas une erreur de campagne.
    }

    const localConfig = resolve(root, 'config/local.php');
    if (existsSync(localConfig)) {
        rmSync(localConfig);
    }
}

/**
 * Arrête le terminateur TLS et les fils qu'il a forkés.
 *
 * Le terminateur forke un processus par connexion : tuer le seul parent
 * laisserait ces fils tenir leurs sockets, et le port suivant serait pris.
 * Le groupe de processus est donc visé, ce que permet le `detached: true` de
 * la préparation.
 */
function stopTlsTerminator() {
    if (process.env.SECONDSTAY_E2E_TLS !== '1') {
        return;
    }

    const port = process.env.SECONDSTAY_PORT || '8123';
    const pidFile = resolve(root, 'storage/temp', `tls-proxy-${port}.pid`);
    if (!existsSync(pidFile)) {
        return;
    }

    const pid = Number(readFileSync(pidFile, 'utf8').trim());
    rmSync(pidFile, { force: true });
    if (!Number.isInteger(pid) || pid <= 0) {
        return;
    }

    try {
        process.kill(-pid, 'SIGTERM');
    } catch {
        // Déjà parti : rien à arrêter.
    }
}
