import { expect, test } from '@playwright/test';

test.describe('API publique et pages d’erreur', () => {
    test('/api/version expose la version installée', async ({ request }) => {
        const response = await request.get('/api/version');
        expect(response.status()).toBe(200);

        const payload = await response.json();
        expect(payload.name).toBe('SecondStay');
        expect(payload.version).toMatch(/^\d+\.\d+\.\d+$/);
        expect(payload.locales).toEqual(['fr', 'en', 'nl', 'de']);
    });

    test('/api/health répond ok', async ({ request }) => {
        const response = await request.get('/api/health');
        expect(response.status()).toBe(200);
        expect((await response.json()).status).toBe('ok');
    });

    test('404 localisée', async ({ page }) => {
        const response = await page.goto('/de/seite-existiert-nicht');
        expect(response?.status()).toBe(404);
        await expect(page.locator('[data-testid="error-page"]')).toHaveAttribute('data-error-status', '404');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Seite nicht gefunden');
    });

    test('404 en néerlandais', async ({ page }) => {
        await page.goto('/nl/bestaat-niet');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Pagina niet gevonden');
    });

    test('la page d’erreur ne divulgue jamais de trace', async ({ page }) => {
        await page.goto('/fr/inexistant');
        const content = await page.content();
        expect(content).not.toContain('Stack trace');
        expect(content).not.toContain('/home/');
    });
});
