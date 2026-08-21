import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
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

    // La configuration locale disparaît : le serveur qui la lisait n'a plus
    // rien à servir, et le laisser tourner ne ferait qu'un processus orphelin
    // pointant vers une installation effacée.
    try {
        execFileSync(resolve(root, 'scripts/dev-server.sh'), ['stop'], {
            cwd: root,
            stdio: 'inherit',
            env: process.env
        });
    } catch {
        // Un serveur déjà arrêté n'est pas une erreur de campagne.
    }

    const localConfig = resolve(root, 'config/local.php');
    if (existsSync(localConfig)) {
        rmSync(localConfig);
    }
}
