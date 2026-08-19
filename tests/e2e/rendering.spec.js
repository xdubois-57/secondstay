import { expect, test } from '@playwright/test';
import { openNavigation } from './helpers/navigation.js';

test.describe('rendu et accessibilité de base', () => {
    test('la page est utilisable au clavier', async ({ page }) => {
        await page.goto('/fr/');
        await page.keyboard.press('Tab');
        const focused = await page.evaluate(() => document.activeElement?.className || '');
        expect(focused).toContain('skip-link');
    });

    test('le JavaScript first-party s’initialise', async ({ page }) => {
        await page.goto('/fr/');
        await expect(page.locator('html')).toHaveAttribute('data-js-ready', 'true');
    });

    test('le thème peut être basculé et persiste', async ({ page }) => {
        await page.goto('/fr/');
        await openNavigation(page);
        await page.locator('[data-theme-toggle]').click();
        await expect(page.locator('html')).toHaveAttribute('data-theme-mode', 'light');

        await page.reload();
        await expect(page.locator('html')).toHaveAttribute('data-theme-mode', 'light');
    });

    test('la page ne défile pas horizontalement', async ({ page }) => {
        await page.goto('/fr/');
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
        );
        expect(overflow).toBe(false);
    });

    test('un seul h1 par page', async ({ page }) => {
        await page.goto('/fr/');
        await expect(page.locator('h1')).toHaveCount(1);
    });
});
