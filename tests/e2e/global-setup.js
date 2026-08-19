import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

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
    const env = { ...process.env, SECONDSTAY_MAIL_TRANSPORT: 'fake' };
    execFileSync(resolve(root, 'scripts/dev-server.sh'), ['restart'], {
        cwd: root,
        stdio: 'inherit',
        env
    });

    const baseURL = config.projects[0]?.use?.baseURL
        || process.env.SECONDSTAY_BASE_URL
        || `http://${process.env.SECONDSTAY_HOST || 'localhost'}:${process.env.SECONDSTAY_PORT || 8123}`;

    const response = await fetch(`${baseURL}/api/dev/mailbox`);
    if (!response.ok) {
        throw new Error(
            `La boîte e-mail de test est indisponible (${response.status}). `
            + 'Le serveur doit tourner avec SECONDSTAY_MAIL_TRANSPORT=fake.'
        );
    }
}
