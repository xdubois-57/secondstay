import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext } from './helpers/fixtures.js';
import { pngBuffer } from './helpers/image.js';

/**
 * Galerie : téléversement, légendes multilingues, visionneuse et diffusion
 * contrôlée des fichiers.
 *
 * Les scénarios sont rejouables : chaque projet Playwright travaille sur sa
 * propre catégorie et sa propre image, et remet l'état public en place.
 */
test.describe('galerie', () => {
    test.describe.configure({ mode: 'serial' });

    const captions = {
        fr: 'Terrasse au soleil',
        en: 'Sunny terrace',
        nl: 'Zonnig terras',
        de: 'Sonnige Terrasse'
    };

    let category = 'exterieur';
    let colour = [40, 90, 160];

    test.beforeAll(({}, testInfo) => {
        category = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'exterieur';
        // Une couleur distincte produit une empreinte distincte : les deux
        // projets ne se partagent pas le même média.
        colour = testInfo.project.name === 'mobile-safari' ? [200, 30, 60] : [40, 90, 160];
    });

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('téléversement, légendes multilingues et publication', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/media');
            const before = await page.locator('[data-media-item]').count();

            await page.setInputFiles('#media', {
                name: 'terrasse.png',
                mimeType: 'image/png',
                buffer: pngBuffer(320, 240, colour)
            });
            await page.fill('#category', category);
            await page.selectOption('#upload_season', 'all');
            await page.click('[data-testid="upload-form"] button[type="submit"]');

            await expect(page).toHaveURL(/\/fr\/admin\/media\/\d+$/);

            for (const [locale, caption] of Object.entries(captions)) {
                await page.fill(`#caption_${locale}`, caption);
                await page.fill(`#alt_${locale}`, caption);
            }
            await page.click('[data-testid="save-media"]');

            await expect(page).toHaveURL(/\/fr\/admin\/media$/);
            await expect(page.locator('[data-media-item]')).toHaveCount(before + 1);

            for (const locale of ['fr', 'en', 'nl', 'de']) {
                await expect(
                    page.locator(`[data-locale-status="${locale}"][data-complete="1"]`).first()
                ).toBeVisible();
            }
        });

        test('un fichier non image est refusé', async ({ page }) => {
            await page.goto('/fr/admin/media');
            const before = await page.locator('[data-media-item]').count();

            await page.setInputFiles('#media', {
                name: 'payload.png',
                mimeType: 'image/png',
                buffer: Buffer.from('<?php echo "pwned"; ?>')
            });
            await page.click('[data-testid="upload-form"] button[type="submit"]');

            await expect(page.locator('[data-testid="upload-error"]')).toBeVisible();
            await expect(page.locator('[data-media-item]')).toHaveCount(before);
        });
    });

    test('la galerie publique affiche la légende traduite', async ({ page }) => {
        await page.goto(`/de/gallery?category=${category}`);

        await expect(page.locator('[data-testid="gallery-grid"] img')).toHaveCount(1);
        await expect(page.locator('[data-testid="gallery-grid"] img')).toHaveAttribute('alt', captions.de);
        await expect(page.locator('[data-testid="gallery-empty"]')).toHaveCount(0);
    });

    test('la visionneuse s’ouvre, affiche la légende et se ferme', async ({ page }) => {
        await page.goto(`/fr/gallery?category=${category}`);
        await expect(page.locator('[data-lightbox]')).toBeHidden();

        await page.click('[data-lightbox-open="0"]');
        await expect(page.locator('[data-lightbox]')).toBeVisible();
        await expect(page.locator('[data-lightbox-caption]')).toHaveText(captions.fr);

        await page.keyboard.press('Escape');
        await expect(page.locator('[data-lightbox]')).toBeHidden();
    });

    test('les images sont servies par l’endpoint applicatif', async ({ page, request }) => {
        await page.goto(`/fr/gallery?category=${category}`);
        const src = await page.locator('[data-testid="gallery-grid"] img').first().getAttribute('src');

        expect(src).toMatch(/^\/media\/thumb\/[a-z0-9]{16}\.(png|jpg|webp|avif)$/);

        const response = await request.get(src);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('image/');
        expect(response.headers()['x-content-type-options']).toBe('nosniff');
    });

    test('un média privé n’est pas servi au public', async ({ page, browser }) => {
        await page.goto(`/fr/gallery?category=${category}`);
        const src = await page.locator('[data-testid="gallery-grid"] img').first().getAttribute('src');

        const context = await browser.newContext({ storageState: ADMIN_STATE_FILE });
        const admin = await context.newPage();
        await admin.goto('/fr/admin/media');
        const card = admin.locator('[data-media-item]').filter({ has: admin.locator(`img[src="${src}"]`) });
        await card.locator('a.btn-outline-primary').click();
        await admin.check('#is_private');
        await admin.click('[data-testid="save-media"]');

        const visitor = await anonymousContext(browser);
        const forbidden = await visitor.request.get(src);
        expect(forbidden.status()).toBe(403);
        await visitor.close();

        await page.goto(`/fr/gallery?category=${category}`);
        await expect(page.locator('[data-testid="gallery-empty"]')).toBeVisible();

        // On rétablit l'état public pour ne pas piéger les scénarios suivants.
        await admin.goto('/fr/admin/media');
        await admin
            .locator('[data-media-item]')
            .filter({ has: admin.locator(`img[src="${src}"]`) })
            .locator('a.btn-outline-primary')
            .click();
        await admin.uncheck('#is_private');
        await admin.click('[data-testid="save-media"]');
        await context.close();

        const restored = await page.request.get(src);
        expect(restored.status()).toBe(200);
    });

    test('les chemins de médias ne permettent aucune traversée', async ({ request }) => {
        for (const path of [
            '/media/thumb/..%2F..%2Fconfig%2Flocal.php',
            '/media/original/../../../etc/passwd',
            '/media/unknown/abcdef0123456789.png',
            '/media/thumb/abcdef0123456789.php'
        ]) {
            const response = await request.get(path, { maxRedirects: 0 });
            expect([403, 404].includes(response.status()), `${path} → ${response.status()}`).toBeTruthy();
        }
    });
});
