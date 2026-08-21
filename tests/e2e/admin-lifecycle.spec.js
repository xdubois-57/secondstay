import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, PROPERTY_NAME } from './helpers/fixtures.js';

/**
 * Scénario E2E de l'itération 1 (ROADMAP.md) :
 * installation → configuration → sauvegarde → modification → restauration →
 * état retrouvé.
 */
test.describe('cycle de vie administrateur', () => {
    test.use({ storageState: ADMIN_STATE_FILE });
    test.describe.configure({ mode: 'serial' });

    test('configuration, sauvegarde, modification puis restauration', async ({ page }) => {
        test.slow();

        // --- 1. Configuration -------------------------------------------
        await page.goto('/fr/admin/settings?module=pricing');
        await expect(page.locator('[data-settings-module="pricing"].active')).toBeVisible();

        await page.fill('#setting_pricing__default_night_price', '145.50');
        await page.fill('#setting_pricing__cleaning_price', '100.00');
        await page.click('[data-testid="settings-save"]');

        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await page.goto('/fr/admin/settings?module=pricing');
        await expect(page.locator('#setting_pricing__default_night_price')).toHaveValue('145.50');

        // --- 2. Sauvegarde ----------------------------------------------
        // Le scénario est rejouable : il ne suppose pas l'absence de
        // sauvegardes antérieures.
        await page.goto('/fr/admin/backups');
        const initialCount = await page.locator('[data-backup-id]').count();

        // SPECIFICATIONS.md §50 — une installation sans aucune sauvegarde le
        // dit dans le tableau « À faire », là où le propriétaire regarde,
        // plutôt que de le taire jusqu'au jour où il en aura besoin.
        if (initialCount === 0) {
            await page.goto('/fr/admin/operations');
            await expect(page.locator('[data-todo="backup_missing"]')).toBeVisible();
            await page.goto('/fr/admin/backups');
        }

        await page.click('[data-testid="create-backup"]');
        await expect(page.locator('[data-backup-id]')).toHaveCount(initialCount + 1);

        // Une fois la sauvegarde faite, l'entrée disparaît : le tableau ne
        // liste que ce qui réclame encore une décision.
        await page.goto('/fr/admin/operations');
        await expect(page.locator('[data-todo="backup_missing"]')).toHaveCount(0);
        await page.goto('/fr/admin/backups');

        // Les sauvegardes sont listées de la plus récente à la plus ancienne.
        const backupId = await page.locator('[data-backup-id]').first().getAttribute('data-backup-id');
        expect(backupId).toBeTruthy();

        // --- 3. Vérification d'intégrité --------------------------------
        const verification = await page.request.get(`/fr/admin/backups/${backupId}/verify`);
        expect(verification.status()).toBe(200);
        const verificationPayload = await verification.json();
        expect(verificationPayload.ok).toBe(true);
        expect(verificationPayload.manifest.app_version).toMatch(/^\d+\.\d+\.\d+$/);

        // --- 4. Modification après sauvegarde ---------------------------
        await page.goto('/fr/admin/settings?module=pricing');
        await page.fill('#setting_pricing__default_night_price', '999.00');
        await page.click('[data-testid="settings-save"]');
        // L'enregistrement doit avoir abouti avant de recharger : partir tout
        // de suite annulerait la requête au lieu de vérifier son effet.
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        await page.goto('/fr/admin/settings?module=pricing');
        await expect(page.locator('#setting_pricing__default_night_price')).toHaveValue('999.00');

        // --- 5. Restauration --------------------------------------------
        await page.goto('/fr/admin/backups');
        await page.click(`[data-restore-backup="${backupId}"]`);
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        // --- 6. État retrouvé -------------------------------------------
        await page.goto('/fr/admin/settings?module=pricing');
        await expect(page.locator('#setting_pricing__default_night_price')).toHaveValue('145.50');

        // --- 7. La restauration est auditée -----------------------------
        await page.goto('/fr/admin/audit');
        await expect(page.locator('[data-audit-action="backup.restored"]').first()).toBeVisible();
    });

    test('le nom du logement configuré à l’installation est conservé', async ({ page }) => {
        await page.goto('/fr/admin/settings?module=property');
        await expect(page.locator('#setting_property__name')).toHaveValue(PROPERTY_NAME);
    });

    test('un réglage invalide est refusé avec un message localisé', async ({ page }) => {
        await page.goto('/de/admin/settings?module=booking');
        await page.fill('#setting_booking__max_guests', '999999');
        await page.click('[data-testid="settings-save"]');

        await expect(page.locator('[data-error-for="booking.max_guests"]')).toHaveText('Wert ist zu groß.');
    });

    test('les diagnostics sont verts sur une installation neuve', async ({ page }) => {
        await page.goto('/fr/admin/diagnostics');

        await expect(page.locator('[data-diagnostic="database_connection"][data-status="ok"]')).toBeVisible();
        await expect(page.locator('[data-diagnostic="crypto_sodium"][data-status="ok"]')).toBeVisible();
        await expect(page.locator('[data-diagnostic="storage_backups"][data-status="ok"]')).toBeVisible();
        await expect(page.locator('[data-testid="schema-version"]')).toHaveText(/^\d{4}$/);

        // SPECIFICATIONS.md §18 — paiement, IA, cron, sauvegarde et mise à
        // jour figurent au même titre que PHP, la base et le chiffrement.
        for (const check of [
            'payment_provider',
            'llm_provider',
            'scheduler_cron',
            'scheduler_tasks',
            'backup_state',
            'update_channel'
        ]) {
            await expect(page.locator(`[data-diagnostic="${check}"]`)).toBeVisible();
        }

        // Aucune tâche ne doit être en échec. L'état exact du cron dépend de
        // ce que la campagne a déjà déclenché — les deux projets Playwright
        // partagent une installation — et n'est donc pas assertable ici ; il
        // l'est en PHP, où l'état de départ est maîtrisé.
        await expect(page.locator('[data-diagnostic="scheduler_tasks"]')).not.toHaveAttribute(
            'data-status',
            'error'
        );
    });

    test('le secret d’un réglage n’est jamais réaffiché', async ({ page }) => {
        await page.goto('/fr/admin/settings?module=update');
        const secretInputs = page.locator('input[type="password"]');
        const count = await secretInputs.count();

        for (let index = 0; index < count; index++) {
            await expect(secretInputs.nth(index)).toHaveValue('');
        }
    });
});
