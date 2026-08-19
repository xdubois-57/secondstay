/**
 * Données de test partagées entre les scénarios E2E.
 * Aucune donnée réelle du logement ne figure dans le dépôt.
 */
export const ADMIN = {
    email: 'owner@example.test',
    password: 'Marée-Haute-2026!',
    firstName: 'Claire',
    lastName: 'Dubois',
    phone: '+33600000000'
};

export const PROPERTY_NAME = 'Maison des Pins';

export const ADMIN_STATE_FILE = 'tests/e2e/.auth/admin.json';

export async function signIn(page, email = ADMIN.email, password = ADMIN.password, locale = 'fr') {
    await page.goto(`/${locale}/login`);
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.click('form[data-testid="login-form"] button[type="submit"]');
}

/**
 * Contexte réellement anonyme.
 *
 * `browser.newContext()` hérite du `storageState` déclaré par `test.use` :
 * il faut donc vider explicitement l'état pour simuler un visiteur.
 */
export async function anonymousContext(browser) {
    return browser.newContext({ storageState: { cookies: [], origins: [] } });
}

/**
 * Remet à zéro les compteurs de limitation de débit via l'action
 * d'administration prévue pour cela.
 *
 * Les deux projets Playwright partagent la même installation et la même
 * adresse IP : sans cela, les scénarios d'inscription se bloqueraient
 * mutuellement. On utilise la fonctionnalité réelle du produit, jamais une
 * porte dérobée réservée aux tests.
 */
export async function clearRateLimits(browser) {
    const context = await browser.newContext({ storageState: ADMIN_STATE_FILE });
    const page = await context.newPage();

    await page.goto('/fr/admin/diagnostics');
    await page.click('[data-testid="clear-rate-limits"]');
    await page.waitForSelector('[data-flash-type="success"]');

    await context.close();
}
