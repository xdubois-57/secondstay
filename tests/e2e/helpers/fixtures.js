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
