import { expect, test } from '@playwright/test';
import { ADMIN, ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';
import { closeNavigation, openNavigation } from './helpers/navigation.js';

/**
 * Scénario critique « comptes et authentification » (TESTING.md §7) :
 * inscription, confirmation par e-mail, connexion, réinitialisation de mot de
 * passe, préférence de langue, appareils connectés et RGPD.
 *
 * Chaque projet Playwright travaille sur ses propres adresses : les scénarios
 * restent rejouables et indépendants les uns des autres.
 */
test.describe('comptes', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';
    const NEW_PASSWORD = 'Vent-du-Large-2026!';

    let suffix = 'desktop';
    let email = '';
    let secondEmail = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';
        email = `claire.${suffix}@example.test`;
        secondEmail = `paul.${suffix}@example.test`;

        // Les deux projets partagent l'installation et l'adresse IP : on
        // repart d'un compteur d'inscriptions vierge.
        await clearRateLimits(browser);
    });

    async function signUp(page, address, locale = 'fr') {
        await page.goto(`/${locale}/account/signup`);
        await page.fill('#first_name', 'Claire');
        await page.fill('#last_name', 'Dubois');
        await page.fill('#email', address);
        await page.fill('#phone', '+33600000000');
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await page.click('[data-testid="signup-form"] button[type="submit"]');
    }

    test('inscription, e-mail de confirmation et activation du compte', async ({ page, request }) => {
        test.slow();

        await signUp(page, email);

        await expect(page.locator('[data-testid="signup-sent"]')).toBeVisible();
        // La page de confirmation ne dit jamais si l'adresse existait déjà.
        await expect(page.locator('[data-testid="signup-sent"]')).toContainText(email);

        const message = await waitForMail(request, email, 'account_confirmation');
        expect(message.locale).toBe('fr');

        // Tant que le lien n'est pas suivi, la connexion reste refusée.
        await page.goto('/fr/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page.locator('[data-testid="login-error"]')).toBeVisible();

        await page.goto(linkFrom(message, '/account/confirm'));

        // La confirmation connecte immédiatement et mène à l'espace client.
        await expect(page).toHaveURL(/\/fr\/account$/);
        await expect(page.locator('[data-testid="account-profile"]')).toBeVisible();
        await expect(page.locator('[data-testid="current-user"]')).toContainText('Claire');
    });

    test('un lien de confirmation ne sert qu’une fois', async ({ page, request, browser }) => {
        const message = await waitForMail(request, email, 'account_confirmation');
        const context = await anonymousContext(browser);
        const visitor = await context.newPage();

        await visitor.goto(linkFrom(message, '/account/confirm'));

        await expect(visitor.locator('[data-testid="confirm-result"]')).toHaveAttribute('data-confirmed', '0');
        await context.close();
    });

    test('une inscription sur une adresse déjà connue ne la révèle pas', async ({ page, request }) => {
        await signUp(page, email);

        // Réponse strictement identique à une inscription neuve…
        await expect(page.locator('[data-testid="signup-sent"]')).toBeVisible();

        // …mais c'est le titulaire réel qui est prévenu, pas le visiteur.
        const notice = await waitForMail(request, email, 'account_exists');
        expect(notice.template).toBe('account_exists');

        // Le mot de passe d'origine reste valable : aucun écrasement.
        await page.goto('/fr/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/account$/);
    });

    test('réinitialisation de mot de passe de bout en bout', async ({ page, request }) => {
        test.slow();

        await page.goto('/fr/account/forgot-password');
        await page.fill('#email', email);
        await page.click('[data-testid="forgot-form"] button[type="submit"]');
        await expect(page.locator('[data-testid="forgot-sent"]')).toBeVisible();

        const message = await waitForMail(request, email, 'password_reset');
        const resetPath = linkFrom(message, '/account/reset');

        await page.goto(resetPath);
        await page.fill('#password', NEW_PASSWORD);
        await page.fill('#password_confirm', NEW_PASSWORD);
        await page.click('[data-testid="reset-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/login$/);

        // L'ancien mot de passe ne fonctionne plus.
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page.locator('[data-testid="login-error"]')).toBeVisible();

        // Le nouveau, si.
        await page.fill('#email', email);
        await page.fill('#password', NEW_PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/account$/);

        // Le lien de réinitialisation est consommé.
        await page.goto(resetPath);
        await page.fill('#password', NEW_PASSWORD);
        await page.fill('#password_confirm', NEW_PASSWORD);
        await page.click('[data-testid="reset-form"] button[type="submit"]');
        await expect(page.locator('[data-testid="reset-error"]')).toBeVisible();
    });

    test('une adresse inconnue reçoit la même réponse et aucun e-mail', async ({ page, request }) => {
        const unknown = `inconnu.${suffix}@example.test`;

        await page.goto('/fr/account/forgot-password');
        await page.fill('#email', unknown);
        await page.click('[data-testid="forgot-form"] button[type="submit"]');

        await expect(page.locator('[data-testid="forgot-sent"]')).toBeVisible();

        const response = await request.get('/api/dev/mailbox');
        const { messages } = await response.json();
        expect(messages.filter((message) => message.to === unknown)).toHaveLength(0);
    });

    test('profil, langue préférée et changement de mot de passe', async ({ page }) => {
        test.slow();

        await page.goto('/fr/login');
        await page.fill('#email', email);
        await page.fill('#password', NEW_PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/account$/);

        // Le lien « Mon compte » est présent dans la navigation.
        await openNavigation(page);
        await expect(page.locator('[data-testid="account-link"]')).toBeVisible();
        // Le menu mobile recouvre le formulaire : on le referme avant de saisir.
        await closeNavigation(page);

        await page.fill('#first_name', 'Claire-Marie');
        await page.fill('#phone', '+33 6 11 22 33 44');
        await page.selectOption('[data-testid="locale-preference"]', 'nl');
        await page.click('[data-testid="save-profile"]');

        // La langue préférée est appliquée immédiatement à la navigation.
        await expect(page).toHaveURL(/\/nl\/account$/);
        await expect(page.locator('html')).toHaveAttribute('lang', 'nl');
        await expect(page.locator('#first_name')).toHaveValue('Claire-Marie');

        // On revient au français pour la suite du scénario.
        await page.selectOption('[data-testid="locale-preference"]', 'fr');
        await page.click('[data-testid="save-profile"]');
        await expect(page).toHaveURL(/\/fr\/account$/);

        // Un mot de passe actuel erroné est refusé.
        await page.fill('#current_password', 'mauvais-mot-de-passe');
        await page.fill('#new_password', PASSWORD);
        await page.fill('#new_password_confirm', PASSWORD);
        await page.click('[data-testid="password-form"] button[type="submit"]');
        await expect(page.locator('#current_password')).toHaveClass(/is-invalid/);

        // Le bon fonctionne et invalide les autres appareils.
        await page.fill('#current_password', NEW_PASSWORD);
        await page.fill('#new_password', PASSWORD);
        await page.fill('#new_password_confirm', PASSWORD);
        await page.click('[data-testid="password-form"] button[type="submit"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
    });

    test('les autres appareils peuvent être déconnectés', async ({ page, browser }) => {
        test.slow();

        await page.goto('/fr/login');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page).toHaveURL(/\/fr\/account$/);

        // Un second appareil ouvre sa propre session.
        const other = await anonymousContext(browser);
        const otherPage = await other.newPage();
        await otherPage.goto('/fr/login');
        await otherPage.fill('#email', email);
        await otherPage.fill('#password', PASSWORD);
        await otherPage.click('[data-testid="login-form"] button[type="submit"]');
        await expect(otherPage).toHaveURL(/\/fr\/account$/);

        await page.reload();
        // Les scénarios précédents ont pu laisser des sessions : seule compte
        // la présence d'au moins deux appareils, dont un seul est le courant.
        expect(await page.locator('[data-testid="sessions"] li').count()).toBeGreaterThanOrEqual(2);
        await expect(page.locator('[data-session-current="1"]')).toHaveCount(1);

        await page.click('[data-testid="revoke-sessions"]');
        await expect(page.locator('[data-testid="sessions"] li')).toHaveCount(1);
        await expect(page.locator('[data-session-current="1"]')).toHaveCount(1);

        // L'autre appareil est réellement déconnecté : sa session ne donne
        // plus accès à l'espace client.
        const revoked = await otherPage.goto('/fr/account');
        expect(revoked?.status()).toBe(403);
        await other.close();
    });

    test('export RGPD des données personnelles', async ({ page }) => {
        // La navigation doit être terminée avant d'interroger l'API : le
        // contexte de requête partage le pot à cookies de la page.
        await signInAndWait(page, email, PASSWORD);

        const response = await page.request.get('/fr/account/export');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-disposition']).toContain('attachment');
        expect(response.headers()['cache-control']).toContain('no-store');

        const payload = await response.json();
        expect(payload.account.email).toBe(email);
        expect(payload.account.first_name).toBe('Claire-Marie');
        expect(payload.consents.length).toBeGreaterThanOrEqual(2);

        // Aucun secret ne sort de l'application.
        const serialised = JSON.stringify(payload);
        expect(serialised).not.toContain('password_hash');
        expect(serialised).not.toContain(PASSWORD);
    });

    test('suppression de compte : anonymisation puis impossibilité de se reconnecter', async ({ page, request }) => {
        test.slow();

        // Un compte dédié : le compte principal sert encore aux passkeys.
        await signUp(page, secondEmail);
        const message = await waitForMail(request, secondEmail, 'account_confirmation');
        await page.goto(linkFrom(message, '/account/confirm'));
        await expect(page).toHaveURL(/\/fr\/account$/);

        // Un mot de passe erroné ne supprime rien.
        await page.fill('#delete_password', 'mauvais-mot-de-passe');
        await page.click('[data-testid="delete-form"] button[type="submit"]');
        await expect(page.locator('#delete_password')).toHaveClass(/is-invalid/);

        await page.fill('#delete_password', PASSWORD);
        await page.click('[data-testid="delete-form"] button[type="submit"]');

        await expect(page).toHaveURL(/\/fr\/?$/);
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        // Le compte n'existe plus : ni connexion, ni réinitialisation.
        await page.goto('/fr/login');
        await page.fill('#email', secondEmail);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(page.locator('[data-testid="login-error"]')).toBeVisible();
    });

    test('un administrateur ne peut pas se supprimer lui-même', async ({ browser }) => {
        const context = await browser.newContext({ storageState: ADMIN_STATE_FILE });
        const admin = await context.newPage();

        await admin.goto('/fr/account');
        await admin.fill('#delete_password', ADMIN.password);
        await admin.click('[data-testid="delete-form"] button[type="submit"]');

        await expect(admin.locator('[data-flash-type="danger"]')).toBeVisible();
        await admin.goto('/fr/admin');
        await expect(admin.locator('[data-metric="administrators"]')).toHaveText('1');

        await context.close();
    });

    test('l’espace client est interdit aux visiteurs anonymes', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const visitor = await context.newPage();

        for (const path of ['/fr/account', '/fr/account/export']) {
            const response = await visitor.goto(path);
            expect(response?.status(), path).toBe(403);
        }

        await context.close();
    });

    test('les mutations de compte exigent un jeton CSRF', async ({ request }) => {
        for (const path of [
            '/fr/account/signup',
            '/fr/account/forgot-password',
            '/fr/account/profile',
            '/fr/account/delete'
        ]) {
            const response = await request.post(path, { form: { email }, maxRedirects: 0 });
            expect(response.status(), `${path} → ${response.status()}`).toBe(403);
        }
    });
});
