import { expect, test } from '@playwright/test';

/**
 * SECURITY.md §3 — la racine web est deny-by-default.
 * Ces chemins ne doivent jamais être servis, même si tout le dépôt se trouve
 * physiquement sous le document root.
 */
const privatePaths = [
    '/src/Core/Kernel.php',
    // Le planificateur est un exécutable : il doit rester hors de portée du
    // serveur web, même si tout le dépôt se trouve sous le document root.
    '/src/Scheduler/cron.php',
    '/src/',
    '/config/app.php',
    '/config/local.php',
    '/vendor/autoload.php',
    '/vendor/twig/twig/composer.json',
    '/storage/logs/app.log',
    '/storage/backups/',
    '/tests/php/bootstrap.php',
    '/tests/e2e/security-paths.spec.js',
    '/migrations/',
    '/scripts/check.sh',
    '/scripts/router.php',
    '/translations/fr/common.php',
    '/templates/layout/base.html.twig',
    '/.github/workflows/ci.yml',
    '/.git/config',
    '/.env',
    '/.env.local',
    '/composer.json',
    '/composer.lock',
    '/package.json',
    '/package-lock.json',
    '/phpunit.xml.dist',
    '/phpstan.neon.dist',
    '/playwright.config.js',
    '/vitest.config.js',
    '/README.md',
    '/AGENTS.md',
    '/SECURITY.md',
    '/SPECIFICATIONS.md',
    '/ROADMAP.md',
    '/MANIFEST.md',
    '/VERSION',
    '/LICENSE',
    '/node_modules/vitest/package.json',
    '/coverage/lcov.info'
];

test.describe('protection du document root', () => {
    for (const path of privatePaths) {
        test(`refuse ${path}`, async ({ request }) => {
            const response = await request.get(path, { maxRedirects: 0 });
            expect(
                [401, 403, 404].includes(response.status()),
                `${path} a répondu ${response.status()}`
            ).toBeTruthy();

            const body = await response.text();
            expect(body).not.toContain('SecondStay\\Core');
            expect(body).not.toContain('encryption_key');
            expect(body).not.toContain('autoload_real');
        });
    }

    test('les assets publics restent servis', async ({ request }) => {
        for (const asset of [
            '/assets/css/app.css',
            '/assets/js/app.js',
            '/assets/js/modules/theme.js',
            '/assets/vendor/bootstrap/css/bootstrap.min.css'
        ]) {
            const response = await request.get(asset);
            expect(response.status(), asset).toBe(200);
        }
    });

    /**
     * ARCHITECTURE.md §23 — le déclenchement HTTP du planificateur n'existe
     * que si le propriétaire a enregistré un jeton. Tant qu'il n'y en a pas,
     * l'URL ne doit pas se distinguer d'un chemin inventé : ni 200, ni 401,
     * ni 403 — rien qui signale qu'il y a là quelque chose à forcer.
     */
    test('le planificateur n’est pas déclenchable sans jeton', async ({ request }) => {
        for (const path of ['/tasks/run', '/tasks/run?token=', '/tasks/run?token=devine']) {
            const response = await request.get(path, { maxRedirects: 0 });
            expect(response.status(), path).toBe(404);
        }
    });

    test('aucun secret dans les réponses publiques', async ({ request }) => {
        const response = await request.get('/fr/');
        const body = await response.text();
        expect(body).not.toMatch(/encryption_key|SMTP_PASSWORD|MOLLIE_/i);
    });

    test('les entêtes de sécurité sont présents', async ({ request }) => {
        const response = await request.get('/fr/');
        const headers = response.headers();
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['x-frame-options']).toBe('SAMEORIGIN');
        expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
        expect(headers['content-security-policy']).toContain("default-src 'self'");
    });
});
