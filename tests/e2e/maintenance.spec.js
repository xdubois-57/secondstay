import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext } from './helpers/fixtures.js';

test.describe('mode maintenance', () => {
    test.use({ storageState: ADMIN_STATE_FILE });
    test.describe.configure({ mode: 'serial' });

    test('activation, fermeture du site public puis désactivation', async ({ page, browser }) => {
        await page.goto('/fr/admin');
        await expect(page.locator('[data-testid="maintenance-state"]')).toHaveAttribute('data-active', '0');

        await page.click('[data-testid="maintenance-toggle"]');
        await expect(page.locator('[data-testid="maintenance-state"]')).toHaveAttribute('data-active', '1');

        // Un visiteur non authentifié reçoit 503 avec un message localisé.
        const visitor = await anonymousContext(browser);
        const visitorPage = await visitor.newPage();
        const response = await visitorPage.goto('/de/');
        expect(response?.status()).toBe(503);
        await expect(visitorPage.getByRole('heading', { level: 1 })).toHaveText('Wartung läuft');

        // Les endpoints techniques restent disponibles.
        const health = await visitorPage.request.get('/api/health');
        expect(health.status()).toBe(200);
        await visitor.close();

        // L'administrateur garde l'accès complet.
        await expect(page.locator('[data-testid="todo-list"]')).toBeVisible();

        await page.click('[data-testid="maintenance-toggle"]');
        await expect(page.locator('[data-testid="maintenance-state"]')).toHaveAttribute('data-active', '0');
    });
});
