import { expect, test } from '@playwright/test';
import { ADMIN, ADMIN_STATE_FILE, PROPERTY_NAME } from './helpers/fixtures.js';

/**
 * Scénario critique 1 de TESTING.md : installation neuve complète.
 *
 * L'installation est réalisée dans un vrai navigateur, sur une base vide, et
 * produit l'état de session administrateur réutilisé par les autres scénarios.
 */
test('installation neuve puis accès à l’administration', async ({ page, context }) => {
    test.slow();

    // 1. Toute URL redirige vers l'assistant tant que l'installation n'est pas faite.
    await page.goto('/fr/');
    await expect(page).toHaveURL(/\/fr\/install$/);

    // 2. Les prérequis obligatoires sont satisfaits.
    await expect(page.locator('[data-requirement="php_version"][data-ok="1"]')).toBeVisible();
    await expect(page.locator('[data-requirement="ext_pdo_mysql"][data-ok="1"]')).toBeVisible();
    await expect(page.locator('[data-requirement="storage_writable"][data-ok="1"]')).toBeVisible();

    // 3. Base de données.
    await page.fill('#db_host', process.env.SECONDSTAY_TEST_DB_HOST || '127.0.0.1');
    await page.fill('#db_port', process.env.SECONDSTAY_TEST_DB_PORT || '3306');
    await page.fill('#db_name', process.env.SECONDSTAY_TEST_DB_NAME || 'secondstay_test');
    await page.fill('#db_user', process.env.SECONDSTAY_TEST_DB_USER || 'secondstay');
    await page.fill('#db_password', process.env.SECONDSTAY_TEST_DB_PASSWORD || 'secondstay');

    // 4. Premier administrateur.
    await page.fill('#admin_first_name', ADMIN.firstName);
    await page.fill('#admin_last_name', ADMIN.lastName);
    await page.fill('#admin_email', ADMIN.email);
    await page.fill('#admin_phone', ADMIN.phone);
    await page.fill('#admin_password', ADMIN.password);
    await page.fill('#admin_password_confirm', ADMIN.password);

    // L'indicateur de robustesse réagit à la saisie.
    await expect(page.locator('[data-password-strength]')).toHaveAttribute('data-level', 'strong');

    // 5. Logement et langue.
    await page.fill('#property_name', PROPERTY_NAME);
    await page.selectOption('#locale', 'fr');
    await page.selectOption('#timezone', 'Europe/Paris');

    await page.click('[data-testid="install-submit"]');

    // 6. L'administrateur arrive connecté sur le tableau de bord.
    await expect(page).toHaveURL(/\/fr\/admin$/);
    await expect(page.locator('[data-testid="todo-list"]')).toBeVisible();
    await expect(page.locator('[data-metric="administrators"]')).toHaveText('1');
    await expect(page.locator('[data-metric="schema"]')).not.toHaveText('—');

    // 7. L'assistant d'installation n'est plus atteignable.
    const installResponse = await page.goto('/fr/install');
    expect(installResponse?.status()).toBe(404);

    await context.storageState({ path: ADMIN_STATE_FILE });
});
