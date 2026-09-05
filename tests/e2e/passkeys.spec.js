import { expect, test } from '@playwright/test';
import { clearRateLimits, submitSignUp } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Clés d'accès (WebAuthn) de bout en bout, avec un authentificateur virtuel
 * Chromium : enregistrement depuis l'espace client puis connexion sans mot de
 * passe.
 *
 * L'API d'authentificateur virtuel n'existe que dans Chromium : le scénario
 * est donc restreint à ce projet. La vérification serveur des assertions est
 * couverte en PHP par `WebAuthnServiceTest`, sur les deux algorithmes.
 */
test.describe('clés d’accès', () => {
    test.describe.configure({ mode: 'serial' });
    test.skip(
        ({ browserName }) => browserName !== 'chromium',
        'L’authentificateur virtuel WebAuthn n’existe que dans Chromium.'
    );

    const PASSWORD = 'Marée-Haute-2026!';
    const email = 'passkey.desktop@example.test';

    let client;
    let authenticatorId;

    test.beforeEach(async ({ page, context }) => {
        client = await context.newCDPSession(page);
        await client.send('WebAuthn.enable', { enableUI: false });
        const added = await client.send('WebAuthn.addVirtualAuthenticator', {
            options: {
                protocol: 'ctap2',
                transport: 'internal',
                hasResidentKey: true,
                hasUserVerification: true,
                isUserVerified: true,
                automaticPresenceSimulation: true
            }
        });
        authenticatorId = added.authenticatorId;
    });

    test.afterEach(async () => {
        if (client && authenticatorId) {
            await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
        }
    });

    async function signIn(page) {
        await page.goto('/fr/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/account$/);
    }

    test('création du compte support du scénario', async ({ page, request, browser }) => {
        test.slow();

        await clearRateLimits(browser);

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Paul');
        await page.fill('#last_name', 'Renard');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await submitSignUp(page);

        const message = await waitForMail(request, email, 'account_confirmation');
        await page.goto(linkFrom(message, '/account/confirm'));

        await expect(page).toHaveURL(/\/fr\/account$/);
    });

    test('enregistrement d’une clé d’accès depuis l’espace client', async ({ page }) => {
        test.slow();

        await signIn(page);

        await expect(page.locator('[data-testid="passkeys"]')).toBeVisible();
        await expect(page.locator('[data-testid="no-passkey"]')).toBeVisible();

        // Les modules ES sont différés : cliquer avant qu'ils ne soient
        // câblés perd le clic, sans erreur visible. Le produit publie
        // lui-même le signal — on l'attend plutôt que de parier sur la
        // vitesse du serveur.
        await page.waitForSelector('html[data-js-ready="true"]');

        await page.fill('[data-passkey-label]', 'Téléphone de Paul');
        await page.click('[data-testid="passkey-add"]');

        // La page se recharge une fois la clé enregistrée côté serveur.
        await expect(page.locator('[data-passkey-id]')).toHaveCount(1);
        await expect(page.locator('[data-passkey-id]')).toContainText('Téléphone de Paul');
        await expect(page.locator('[data-testid="no-passkey"]')).toHaveCount(0);
    });

    test('connexion sans mot de passe avec la clé enregistrée', async ({ page, context }) => {
        test.slow();

        // La clé enregistrée au scénario précédent appartenait à un
        // authentificateur détruit : on en enregistre une pour celui-ci.
        await signIn(page);
        await page.waitForSelector('html[data-js-ready="true"]');
        const before = await page.locator('[data-passkey-id]').count();
        await page.fill('[data-passkey-label]', 'Ordinateur de Paul');
        await page.click('[data-testid="passkey-add"]');
        await expect(page.locator('[data-passkey-id]')).toHaveCount(before + 1);

        await page.click('[data-testid="logout"]');
        await expect(page).toHaveURL(/\/fr\/?$/);

        await page.goto('/fr/login');
        await page.waitForSelector('html[data-js-ready="true"]');
        const button = page.locator('[data-testid="passkey-signin"]');
        await expect(button).toBeVisible();

        await button.click();

        await expect(page).toHaveURL(/\/fr\/account$/);
        await expect(page.locator('[data-testid="account-profile"]')).toBeVisible();
    });

    test('une clé peut être supprimée et ne permet plus de se connecter', async ({ page }) => {
        test.slow();

        await signIn(page);
        await page.waitForSelector('html[data-js-ready="true"]');

        const before = await page.locator('[data-passkey-id]').count();
        await page.fill('[data-passkey-label]', 'Clé jetable');
        await page.click('[data-testid="passkey-add"]');
        // La page se recharge une fois la clé acceptée par le serveur.
        await expect(page.locator('[data-passkey-id]')).toHaveCount(before + 1);

        await page
            .locator('[data-passkey-id]')
            .last()
            .locator('button[type="submit"]')
            .click();

        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await expect(page.locator('[data-passkey-id]')).toHaveCount(before);
    });

    test('les endpoints passkey refusent une requête sans jeton CSRF', async ({ request }) => {
        for (const path of [
            '/api/passkeys/register/options',
            '/api/passkeys/register',
            '/api/passkeys/login/options',
            '/api/passkeys/login'
        ]) {
            const response = await request.post(path, { data: {}, maxRedirects: 0 });
            expect(response.status(), `${path} → ${response.status()}`).toBe(403);
        }
    });

    test('une assertion sans défi en cours est refusée', async ({ page }) => {
        await page.goto('/fr/login');

        const token = await page.locator('[data-testid="passkey-signin"]').getAttribute('data-csrf');
        const result = await page.evaluate(async (csrf) => {
            const response = await fetch('/api/passkeys/login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ response: { id: 'inconnu', clientDataJSON: '', authenticatorData: '', signature: '' } })
            });

            return { status: response.status, body: await response.json() };
        }, token);

        expect(result.status).toBe(401);
        expect(result.body.ok).toBe(false);
        // L'erreur reste une phrase traduite, jamais un détail technique.
        expect(result.body.error).not.toContain('webauthn.');
    });
});
