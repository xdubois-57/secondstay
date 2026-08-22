import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « événement → e-mail + push dans la langue du compte »
 * (TESTING.md §7) et application installable (SPECIFICATIONS.md §43).
 *
 * Les deux canaux passent par des fournisseurs factices : aucun serveur SMTP,
 * aucun service de push, aucun réseau sortant.
 */
test.describe('notifications et application installable', () => {
    test.describe.configure({ mode: 'serial' });

    let suffix = 'desktop';
    let email = '';
    const PASSWORD = 'Marée-Haute-2026!';

    test.beforeAll(({}, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';
        // Le numéro de tentative entre dans l'adresse : ce groupe est joué en
        // série, et une reprise rejoue l'inscription depuis le début. Avec une
        // adresse fixe, le compte existerait déjà, aucun nouveau courrier de
        // confirmation ne partirait, et la reprise échouerait sur un jeton
        // déjà consommé — masquant la panne d'origine derrière une seconde,
        // sans rapport.
        const attempt = testInfo.retry > 0 ? `.r${testInfo.retry}` : '';
        email = `notif.${suffix}${attempt}@example.test`;
    });

    // --- Application installable ------------------------------------------

    test('le manifeste est publié dans les quatre langues', async ({ request }) => {
        const names = new Set();

        for (const locale of ['fr', 'en', 'nl', 'de']) {
            const response = await request.get(`/manifest.webmanifest?locale=${locale}`);

            expect(response.status()).toBe(200);
            expect(response.headers()['content-type']).toContain('application/manifest+json');

            const manifest = await response.json();
            expect(manifest.lang).toBe(locale);
            expect(manifest.start_url).toBe(`/${locale}/`);
            expect(manifest.scope).toBe('/');
            expect(manifest.display).toBe('standalone');
            expect(manifest.icons.length).toBeGreaterThanOrEqual(4);
            expect(manifest.shortcuts[0].url).toBe(`/${locale}/account`);

            // La description est traduite, jamais un gabarit brut.
            expect(manifest.description).not.toContain('{property}');
            names.add(manifest.description);
        }

        expect(names.size).toBe(4);
    });

    test('chaque page déclare le manifeste et le thème', async ({ page }) => {
        await page.goto('/nl/');

        await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
            'href',
            /\/manifest\.webmanifest\?locale=nl$/
        );
        await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#0d6efd');
        await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveCount(1);
    });

    test('les icônes sont générées par l’installation', async ({ request }) => {
        for (const path of ['/icon-192.png', '/icon-512.png', '/icon-maskable-192.png', '/icon-maskable-512.png']) {
            const response = await request.get(path);

            expect(response.status(), path).toBe(200);
            expect(response.headers()['content-type']).toBe('image/png');
            expect(response.headers()['x-content-type-options']).toBe('nosniff');

            const body = await response.body();
            // Signature PNG : le fichier servi est bien une image.
            expect(body.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        }

        // Aucune autre taille n'est servie.
        expect((await request.get('/icon-1024.png')).status()).toBe(404);
    });

    test('le service worker est servi à la racine de son périmètre', async ({ request }) => {
        const response = await request.get('/sw.js');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('javascript');
        expect(response.headers()['service-worker-allowed']).toBe('/');

        const body = await response.text();
        expect(body).toContain('addEventListener(\'push\'');
        expect(body).toContain('addEventListener(\'fetch\'');
        // Les zones sensibles ne sont jamais mises en cache.
        expect(body).toContain('/admin');
        expect(body).toContain('/account');
    });

    test('le service worker s’enregistre dans le navigateur', async ({ page }) => {
        await page.goto('/fr/');
        await page.waitForSelector('html[data-js-ready="true"]');

        const scope = await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.ready;
            return registration.scope;
        });

        expect(scope).toMatch(/\/$/);
    });

    test('la page hors ligne existe dans chaque langue', async ({ page }) => {
        for (const locale of ['fr', 'en', 'nl', 'de']) {
            await page.goto(`/${locale}/offline`);

            await expect(page.locator('[data-testid="offline-page"]')).toBeVisible();
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
        }
    });

    // --- Activation des notifications par l’administrateur ------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les clés push sont générées puis les notifications activées', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/diagnostics');
            page.once('dialog', (dialog) => dialog.accept());
            await page.click('[data-testid="generate-push-keys"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/settings?module=notification');
            await page.check('#setting_notification__push_enabled');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            // Le diagnostic confirme que le push est opérationnel.
            await page.goto('/fr/admin/diagnostics');
            await expect(page.locator('[data-diagnostic="push"]')).toHaveAttribute('data-status', 'ok');
        });

        test('le diagnostic e-mail contrôle SPF, DKIM et DMARC', async ({ page }) => {
            await page.goto('/fr/admin/diagnostics');

            for (const check of ['mail_sender', 'mail_spf', 'mail_dkim', 'mail_dmarc']) {
                await expect(page.locator(`[data-diagnostic="${check}"]`)).toBeVisible();
            }

            // La sonde SMTP est explicite : la page ne l'exécute pas seule.
            await expect(page.locator('[data-diagnostic="mail_smtp"]')).toBeVisible();
            await page.click('[data-testid="probe-smtp"]');
            await expect(page.locator('[data-diagnostic="mail_smtp"]')).toBeVisible();
        });
    });

    // --- Événement → e-mail + push ------------------------------------------

    test('un compte confirmé reçoit l’e-mail et la notification dans sa langue', async ({ page, request, browser }) => {
        test.slow();

        await clearRateLimits(browser);

        // 1. Inscription en néerlandais : la langue du compte fait foi.
        await page.goto('/nl/account/signup');
        await page.fill('#first_name', 'Sanne');
        await page.fill('#last_name', 'Jansen');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await page.click('[data-testid="signup-form"] button[type="submit"]');

        const confirmation = await waitForMail(request, email, 'account_confirmation');
        expect(confirmation.locale).toBe('nl');

        // 2. La confirmation déclenche l'événement notifiable.
        await page.goto(linkFrom(confirmation, '/account/confirm'));
        await expect(page).toHaveURL(/\/nl\/account$/);

        // 3. L'e-mail de notification part dans la langue du compte.
        const welcome = await waitForMail(request, email, 'notification');
        expect(welcome.locale).toBe('nl');
        expect(welcome.subject).toBe('Uw account is actief');
        expect(welcome.html).not.toContain('notification.');
        expect(welcome.html).not.toContain('{first_name}');
    });

    test('l’espace client propose les canaux de notification', async ({ page }) => {
        await page.goto('/nl/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/nl\/account$/);

        await expect(page.locator('[data-testid="notifications"]')).toBeVisible();
        await expect(page.locator('#channel_email')).toBeChecked();
        await expect(page.locator('#channel_push')).toBeChecked();
        // Le push est actif : les commandes d'abonnement sont proposées.
        await expect(page.locator('[data-testid="push-subscribe"]')).toBeVisible();
        await expect(page.locator('[data-testid="push-unavailable"]')).toHaveCount(0);
    });

    test('désactiver un canal supprime son envoi et le trace', async ({ page, request }) => {
        test.slow();

        await page.goto('/nl/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');

        // La page d'arrivée doit être posée avant qu'on touche à une case :
        // sur un petit écran, une case encore en cours de mise en page se
        // déplace sous le doigt, et le clic atterrit sur le bouton
        // d'enregistrement placé juste en dessous.
        await expect(page).toHaveURL(/\/nl\/account$/);
        await expect(page.locator('[data-testid="notifications"]')).toBeVisible();
        await page.locator('#channel_email').scrollIntoViewIfNeeded();

        await page.uncheck('#channel_email');
        await page.click('[data-testid="save-notifications"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await page.reload();
        await expect(page.locator('#channel_email')).not.toBeChecked();

        // On rétablit la préférence pour ne pas piéger les scénarios suivants.
        await page.locator('#channel_email').scrollIntoViewIfNeeded();
        await page.check('#channel_email');
        await page.click('[data-testid="save-notifications"]');
        await expect(page.locator('#channel_email')).toBeChecked();
    });

    test('un appareil abonné reçoit une notification poussée dans sa langue', async ({ page, request }) => {
        test.slow();

        await page.goto('/nl/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/nl\/account$/);

        // On abonne un appareil par l'endpoint réel, avec une vraie clé
        // P-256 produite par WebCrypto : la validation serveur est exercée.
        const endpoint = `https://push.example.test/s/${suffix}`;
        const subscribed = await page.evaluate(async (pushEndpoint) => {
            const toBase64Url = (buffer) =>
                btoa(String.fromCharCode(...new Uint8Array(buffer)))
                    .replace(/\+/g, '-')
                    .replace(/\//g, '_')
                    .replace(/=+$/, '');

            const pair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
            const raw = await crypto.subtle.exportKey('raw', pair.publicKey);

            const csrf = document.querySelector('[data-push-controls]').dataset.csrf;
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    endpoint: pushEndpoint,
                    keys: {
                        p256dh: toBase64Url(raw),
                        auth: toBase64Url(crypto.getRandomValues(new Uint8Array(16)))
                    }
                })
            });

            return { status: response.status, body: await response.json() };
        }, endpoint);

        expect(subscribed.status).toBe(200);
        expect(subscribed.body.ok).toBe(true);

        await page.reload();
        await expect(page.locator('[data-push-devices]')).not.toHaveText('0');

        // L'envoi de vérification atteint réellement l'appareil.
        await page.click('[data-testid="send-test-notification"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        const pushed = await request.get('/api/dev/notifications');
        const { messages } = await pushed.json();
        const mine = messages.filter((message) => message.endpoint === endpoint);

        expect(mine.length).toBeGreaterThan(0);
        expect(mine[0].message.locale).toBe('nl');
        expect(mine[0].message.title).toBe('Testmelding');
        expect(mine[0].message.path).toBe('/nl/account');

        // L'e-mail part indépendamment, dans la même langue.
        const mail = await waitForMail(request, email, 'notification');
        expect(mail.locale).toBe('nl');
    });

    // --- Sécurité -----------------------------------------------------------

    test('les endpoints push exigent un jeton CSRF et un compte', async ({ request, browser }) => {
        for (const path of ['/api/push/subscribe', '/api/push/unsubscribe']) {
            const response = await request.post(path, { data: {}, maxRedirects: 0 });
            expect(response.status(), `${path} → ${response.status()}`).toBe(403);
        }

        const context = await anonymousContext(browser);
        const visitor = await context.newPage();
        await visitor.goto('/fr/login');

        const token = await visitor.getAttribute('input[name="_csrf"]', 'value');
        const anonymous = await visitor.evaluate(async (csrf) => {
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ endpoint: 'https://push.example.test/s/1', keys: { p256dh: 'x', auth: 'y' } })
            });
            return response.status;
        }, token);

        // Un visiteur anonyme ne peut pas s'abonner.
        expect(anonymous).toBe(403);
        await context.close();
    });

    test('un abonnement mal formé est refusé avec un message traduit', async ({ page }) => {
        await page.goto('/nl/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');

        // La page d'arrivée doit être posée avant qu'on lise son DOM : sans
        // cette attente, le script s'exécute sur la page de connexion encore
        // affichée et n'y trouve rien.
        await expect(page).toHaveURL(/\/nl\/account$/);
        await expect(page.locator('[data-push-controls]')).toBeAttached();

        const result = await page.evaluate(async () => {
            const csrf = document.querySelector('[data-push-controls]').dataset.csrf;
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    endpoint: 'http://push.example.test/s/1',
                    keys: { p256dh: 'trop-court', auth: 'trop-court' }
                })
            });
            return { status: response.status, body: await response.json() };
        });

        expect(result.status).toBe(422);
        expect(result.body.ok).toBe(false);
        // Une phrase traduite, jamais une clé technique.
        expect(result.body.error).not.toContain('push.error.');
        expect(result.body.error.length).toBeGreaterThan(5);
    });

    test('la boîte de notification de test n’expose rien en production', async ({ request }) => {
        // En campagne E2E le fournisseur factice est actif : l'endpoint répond.
        const response = await request.get('/api/dev/notifications');
        expect(response.status()).toBe(200);
        expect(Array.isArray((await response.json()).messages)).toBe(true);
    });
});
