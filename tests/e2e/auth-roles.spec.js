import { expect, test } from '@playwright/test';
import { ADMIN, ADMIN_STATE_FILE, anonymousContext, signIn } from './helpers/fixtures.js';
import { openNavigation } from './helpers/navigation.js';

test.describe('authentification et rôles', () => {
    test('l’administration est refusée aux visiteurs', async ({ page }) => {
        for (const path of ['/fr/admin', '/fr/admin/settings', '/fr/admin/backups', '/fr/admin/users']) {
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(403);
        }
    });

    test('connexion et déconnexion', async ({ page }) => {
        await signIn(page);
        await expect(page).toHaveURL(/\/fr\/admin$/);

        await openNavigation(page);
        await expect(page.locator('[data-testid="current-user"]')).toContainText('Claire Dubois');

        await page.click('[data-testid="logout"]');
        await openNavigation(page);
        await expect(page.locator('[data-testid="login-link"]')).toBeVisible();

        const response = await page.goto('/fr/admin');
        expect(response?.status()).toBe(403);
    });

    test('un mot de passe erroné affiche une erreur localisée', async ({ page }) => {
        await signIn(page, ADMIN.email, 'mauvais-mot-de-passe', 'nl');

        await expect(page.locator('[data-testid="login-error"]')).toHaveText(
            'Onjuist e-mailadres of wachtwoord.'
        );
    });

    test('une mutation sans jeton CSRF est refusée', async ({ request }) => {
        const response = await request.post('/fr/login', {
            form: { email: ADMIN.email, password: ADMIN.password },
            maxRedirects: 0
        });

        expect(response.status()).toBe(403);
    });

    test.describe('avec un administrateur connecté', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('création d’un responsable local puis restriction de ses droits', async ({ page, browser }) => {
            await page.goto('/fr/admin/users');

            const email = `manager-${Date.now()}@example.test`;
            await page.fill('#first_name', 'Jean');
            await page.fill('#last_name', 'Martin');
            await page.fill('#new_email', email);
            await page.fill('#new_password', ADMIN.password);
            await page.selectOption('#new_role', 'local_manager');
            await page.selectOption('#new_locale', 'nl');
            await page.click('[data-testid="create-user-form"] button[type="submit"]');

            await expect(page.locator(`[data-user-email="${email}"]`)).toBeVisible();

            const context = await anonymousContext(browser);
            const managerPage = await context.newPage();
            await signIn(managerPage, email, ADMIN.password);

            // Le responsable local accède au tableau de bord opérationnel…
            await expect(managerPage.locator('[data-testid="todo-list"]')).toBeVisible();

            // … mais pas aux pages réservées à l'administrateur.
            for (const path of ['/fr/admin/settings', '/fr/admin/users', '/fr/admin/backups']) {
                const response = await managerPage.goto(path);
                expect(response?.status(), path).toBe(403);
            }

            await context.close();
        });

        test('le dernier administrateur ne peut pas être supprimé', async ({ page }) => {
            await page.goto('/fr/admin/users');
            await page.click(`[data-delete-user="${ADMIN.email}"]`);

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
            await expect(page.locator(`[data-user-email="${ADMIN.email}"]`)).toBeVisible();
        });
    });
});
