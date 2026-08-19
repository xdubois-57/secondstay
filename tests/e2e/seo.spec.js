import { expect, test } from '@playwright/test';

test.describe('SEO multilingue', () => {
    test('sitemap.xml liste les quatre langues', async ({ request }) => {
        const response = await request.get('/sitemap.xml');
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('xml');

        const xml = await response.text();
        for (const locale of ['fr', 'en', 'nl', 'de']) {
            expect(xml).toContain(`/${locale}/property`);
            expect(xml).toContain(`hreflang="${locale}"`);
        }
        expect(xml).toContain('hreflang="x-default"');
    });

    test('robots.txt protège les zones privées', async ({ request }) => {
        const body = await (await request.get('/robots.txt')).text();

        expect(body).toContain('Disallow: /admin');
        expect(body).toContain('Disallow: /login');
        expect(body).toContain('Disallow: /install');
    });

    test('la page d’accueil publie des données structurées', async ({ page }) => {
        await page.goto('/fr/');
        const jsonLd = await page.locator('script[type="application/ld+json"]').textContent();

        const data = JSON.parse(jsonLd);
        expect(data['@type']).toBe('LodgingBusiness');
        expect(data.inLanguage).toBe('fr');
    });

    test('chaque page publie canonical, hreflang et Open Graph', async ({ page }) => {
        await page.goto('/nl/rates');

        await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', '/nl/rates');
        for (const locale of ['fr', 'en', 'nl', 'de']) {
            await expect(page.locator(`link[rel="alternate"][hreflang="${locale}"]`)).toHaveAttribute(
                'href',
                `/${locale}/rates`
            );
        }
        await expect(page.locator('meta[property="og:locale"]')).toHaveAttribute('content', 'nl');
        await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', /Tarieven/);
    });

    test('les métadonnées suivent la langue', async ({ page }) => {
        await page.goto('/de/property');
        await expect(page).toHaveTitle(/Das Objekt/);

        await page.goto('/en/property');
        await expect(page).toHaveTitle(/The property/);
    });
});
