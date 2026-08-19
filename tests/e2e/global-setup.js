import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Avant chaque campagne E2E, l'application est remise à l'état d'une archive
 * fraîchement déployée : c'est la seule façon de tester réellement le parcours
 * d'installation (TESTING.md §7).
 */
export default async function globalSetup() {
    execFileSync('php', [resolve(root, 'scripts/e2e-reset.php')], {
        cwd: root,
        stdio: 'inherit',
        env: process.env
    });
}
