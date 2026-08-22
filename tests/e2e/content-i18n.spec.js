import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE } from './helpers/fixtures.js';

/**
 * Scénario E2E de l'itération 2 (ROADMAP.md) :
 * édition admin en quatre langues → publication → rendu public correct.
 */
test.describe('contenus éditoriaux multilingues', () => {
    test.describe.configure({ mode: 'serial' });

    // Chaque projet Playwright travaille sur sa propre page : les scénarios
    // restent rejouables sur les deux viewports sans interférer.
    let slug = 'welcome-book';
    test.beforeAll(({}, testInfo) => {
        slug = `welcome-book-${testInfo.project.name}`;
    });
    const titles = {
        fr: 'Livret d’accueil',
        en: 'Welcome book',
        nl: 'Welkomstboekje',
        de: 'Willkommensmappe'
    };
    const bodies = {
        fr: 'Le code du portail vous sera communiqué avant votre arrivée.',
        en: 'The gate code will be sent to you before your arrival.',
        nl: 'De poortcode wordt vóór uw aankomst toegestuurd.',
        de: 'Der Torcode wird Ihnen vor Ihrer Ankunft mitgeteilt.'
    };

    test.describe('avec un administrateur connecté', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('création, traduction et publication d’une page', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/content');

            // Les pages du socle sont déjà traduites dans les quatre langues.
            await expect(
                page.locator('[data-page-slug="property"] [data-locale-status="de"][data-complete="1"]')
            ).toBeVisible();

            await page.fill('#new_slug', slug);
            await page.selectOption('#new_kind', 'page');
            await page.click('[data-testid="create-page-form"] button[type="submit"]');

            await expect(page).toHaveURL(/\/fr\/admin\/content\/\d+$/);

            // Une page fraîchement créée n'est traduite dans aucune langue.
            for (const locale of ['fr', 'en', 'nl', 'de']) {
                await page.click(`[data-locale-tab="${locale}"]`);
                await page.fill(`#title_${locale}`, titles[locale]);
                await page.fill(`#body_${locale}`, `<p>${bodies[locale]}</p>`);
            }

            await page.check('#is_published');
            await page.click('[data-testid="save-page"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/content');
            for (const locale of ['fr', 'en', 'nl', 'de']) {
                await expect(
                    page.locator(`[data-page-slug="${slug}"] [data-locale-status="${locale}"][data-complete="1"]`)
                ).toBeVisible();
            }
        });
    });

    for (const locale of ['fr', 'en', 'nl', 'de']) {
        test(`rendu public en ${locale}`, async ({ page }) => {
            const response = await page.goto(`/${locale}/${slug}`);
            expect(response?.status()).toBe(200);

            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText(titles[locale]);
            await expect(page.locator('[data-testid="page-body"]')).toContainText(bodies[locale]);
            await expect(page.locator('[data-testid="fallback-notice"]')).toHaveCount(0);
            await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `/${locale}/${slug}`);
        });
    }

    test('la page apparaît dans le menu traduit', async ({ page }) => {
        await page.goto('/nl/');
        await expect(page.locator(`[data-menu-slug="${slug}"]`)).toHaveText(titles.nl);
    });

    test('le HTML injecté est neutralisé', async ({ page, browser }) => {
        const context = await browser.newContext({ storageState: ADMIN_STATE_FILE });
        const admin = await context.newPage();

        await admin.goto('/fr/admin/content');
        await admin.click(`[data-page-slug="${slug}"] a`);
        await admin.fill('#body_fr', '<p>Contenu sûr</p><script>window.__pwned = true;</script>');
        await admin.click('[data-testid="save-page"]');
        await context.close();

        await page.goto(`/fr/${slug}`);
        await expect(page.locator('[data-testid="page-body"]')).toContainText('Contenu sûr');
        expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
    });

    test.describe('dépublication', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('une page dépubliée disparaît du site public', async ({ page }) => {
            await page.goto('/fr/admin/content');
            await page.click(`[data-page-slug="${slug}"] a`);

            // Le formulaire doit être posé avant qu'on touche à l'interrupteur,
            // et l'enregistrement confirmé avant qu'on interroge le site
            // public : sans ces deux points d'appui, une page restée publiée
            // ne se manifeste que trois lignes plus bas, par un 200 qui ne dit
            // pas pourquoi.
            await expect(page.locator('[data-testid="page-form"]')).toBeVisible();
            await page.locator('#is_published').scrollIntoViewIfNeeded();
            await page.uncheck('#is_published');
            await expect(page.locator('#is_published')).not.toBeChecked();

            await page.click('[data-testid="save-page"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            const response = await page.goto(`/fr/${slug}`);
            expect(response?.status()).toBe(404);
        });
    });

    test('les pages légales sont accessibles depuis le pied de page', async ({ page }) => {
        await page.goto('/de/');

        await expect(page.locator('[data-legal-slug="legal-notice"]')).toBeVisible();
        await page.click('[data-legal-slug="privacy"]');

        await expect(page).toHaveURL(/\/de\/privacy$/);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Datenschutz');
    });
});
