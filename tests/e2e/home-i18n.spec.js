import { expect, test } from '@playwright/test';
import { openLocaleSwitcher, openNavigation } from './helpers/navigation.js';

const expectations = [
    { locale: 'fr', hero: 'Bienvenue', nav: 'Accueil' },
    { locale: 'en', hero: 'Welcome', nav: 'Home' },
    { locale: 'nl', hero: 'Welkom', nav: 'Home' },
    { locale: 'de', hero: 'Willkommen', nav: 'Startseite' }
];

test.describe('page d’accueil multilingue', () => {
    for (const { locale, hero, nav } of expectations) {
        test(`rendu en ${locale}`, async ({ page }) => {
            await page.goto(`/${locale}/`);

            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText(hero);
            await expect(page.locator('[data-testid="home-body"]')).not.toBeEmpty();
            await openNavigation(page);
            await expect(page.getByRole('link', { name: nav, exact: true }).first()).toBeVisible();

            const canonical = page.locator('link[rel="canonical"]');
            await expect(canonical).toHaveAttribute('href', `/${locale}/`);
        });
    }

    test('la racine redirige vers la langue par défaut', async ({ page }) => {
        const response = await page.goto('/');
        expect(response?.status()).toBe(200);
        expect(new URL(page.url()).pathname).toBe('/fr');
    });

    test('le sélecteur de langue conduit à la même page traduite', async ({ page }) => {
        await page.goto('/fr/');
        await openLocaleSwitcher(page);
        await page.locator('[data-locale-switcher] a[data-locale-option="de"]').click();

        await expect(page.locator('html')).toHaveAttribute('lang', 'de');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Willkommen');
    });

    test('le choix de langue persiste via cookie fonctionnel', async ({ page, context }) => {
        await page.goto('/nl/');
        const cookies = await context.cookies();
        const localeCookie = cookies.find((cookie) => cookie.name === 'ss_locale');

        expect(localeCookie?.value).toBe('nl');
        expect(localeCookie?.sameSite).toBe('Lax');

        await page.goto('/');
        await expect(page.locator('html')).toHaveAttribute('lang', 'nl');
    });

    test('hreflang est publié pour les quatre langues', async ({ page }) => {
        await page.goto('/fr/');
        for (const locale of ['fr', 'en', 'nl', 'de']) {
            await expect(page.locator(`link[rel="alternate"][hreflang="${locale}"]`)).toHaveCount(1);
        }
        await expect(page.locator('link[rel="alternate"][hreflang="x-default"]')).toHaveCount(1);
    });

    test('fallback de langue depuis Accept-Language non supporté', async ({ browser }) => {
        const context = await browser.newContext({ locale: 'es-ES' });
        const page = await context.newPage();
        await page.goto('/');
        await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
        await context.close();
    });

    test('Accept-Language néerlandais choisit nl', async ({ browser }) => {
        const context = await browser.newContext({ locale: 'nl-BE' });
        const page = await context.newPage();
        await page.goto('/');
        await expect(page.locator('html')).toHaveAttribute('lang', 'nl');
        await context.close();
    });
});
