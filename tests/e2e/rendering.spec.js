import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE } from './helpers/fixtures.js';
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

/**
 * Analyse automatisée d'accessibilité (TESTING.md §10, objectif WCAG 2.2 AA).
 *
 * Un outil automatique ne prouve pas l'accessibilité — il ne voit ni l'ordre de
 * lecture, ni la pertinence d'un texte alternatif. Il attrape en revanche ce
 * qui se casse en silence à chaque modification de gabarit : un contraste
 * insuffisant, un champ sans étiquette, un attribut ARIA qui ne désigne plus
 * rien. C'est exactement ce qu'une campagne doit surveiller.
 *
 * Le périmètre couvre les trois familles de pages du produit : une page de
 * contenu, un formulaire, et l'administration — dont les tableaux et les
 * commutateurs sont les plus fragiles.
 */
test.describe('accessibilité automatisée', () => {
    const STANDARD = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

    async function violations(page) {
        const results = await new AxeBuilder({ page }).withTags(STANDARD).analyze();

        return results.violations.map((violation) => ({
            id: violation.id,
            impact: violation.impact,
            nodes: violation.nodes.map((node) => node.target.join(' '))
        }));
    }

    for (const path of ['/fr/', '/fr/property', '/fr/gallery', '/fr/availability', '/fr/contact']) {
        test(`les pages publiques respectent WCAG 2.2 AA — ${path}`, async ({ page }) => {
            await page.goto(path);
            await page.waitForSelector('html[data-js-ready="true"]');

            expect(await violations(page)).toEqual([]);
        });
    }

    test('la page de connexion respecte WCAG 2.2 AA', async ({ page }) => {
        await page.goto('/fr/login');
        await page.waitForSelector('html[data-js-ready="true"]');

        expect(await violations(page)).toEqual([]);
    });

    /**
     * Le thème sombre n'est pas une variante décorative : c'est le thème par
     * défaut d'une bonne partie des téléphones. Les couleurs y sont
     * différentes, donc les contrastes aussi, et une correction faite en
     * clair peut très bien ne rien corriger en sombre.
     */
    test('le thème sombre respecte aussi WCAG 2.2 AA', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.goto('/fr/');
        await page.waitForSelector('html[data-bs-theme="dark"]');

        expect(await violations(page)).toEqual([]);
    });

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        for (const path of ['/fr/admin', '/fr/admin/operations', '/fr/admin/settings?module=site']) {
            test(`l’administration respecte WCAG 2.2 AA — ${path}`, async ({ page }) => {
                await page.goto(path);
                await page.waitForSelector('html[data-js-ready="true"]');

                expect(await violations(page)).toEqual([]);
            });
        }

        test('le tableau de bord sombre respecte aussi WCAG 2.2 AA', async ({ page }) => {
            await page.emulateMedia({ colorScheme: 'dark' });
            await page.goto('/fr/admin');
            await page.waitForSelector('html[data-bs-theme="dark"]');

            expect(await violations(page)).toEqual([]);
        });
    });
});
