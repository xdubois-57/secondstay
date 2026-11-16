import { createHmac } from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE } from './helpers/fixtures.js';

/**
 * Canal de développement des mises à jour (RELEASE.md §12.1).
 *
 * Le parcours de l'administrateur : choisir le canal, obtenir l'URL et le
 * secret du webhook à déclarer dans GitHub — secret montré une seule fois — et
 * vérifier que ce secret, et lui seul, authentifie une notification.
 *
 * L'installation elle-même (construction attendue, puis installée) est jouée
 * en PHP (`UpdateServiceTest`) : elle demande une archive de release que la
 * campagne n'a pas à fabriquer.
 */
test.describe('canal de développement', () => {
    test.use({ storageState: ADMIN_STATE_FILE });
    test.describe.configure({ mode: 'serial' });

    async function selectChannel(page, channel) {
        await page.goto('/fr/admin/settings?module=update');
        await page.selectOption('#setting_update__channel', channel);
        await page.click('[data-testid="settings-save"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
    }

    function signed(secret, payload) {
        const body = JSON.stringify(payload);
        return {
            data: body,
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'ping',
                'X-Hub-Signature-256': 'sha256=' + createHmac('sha256', secret).update(body).digest('hex')
            }
        };
    }

    test('le webhook se déclare depuis la page des mises à jour', async ({ page, request }) => {
        await selectChannel(page, 'dev');

        try {
            await page.goto('/fr/admin/updates');
            const block = page.locator('[data-testid="dev-channel"]');
            await expect(block).toBeVisible();
            await expect(page.locator('[data-testid="webhook-url"]')).toHaveText(/\/webhook\/github$/);

            const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze();
            expect(accessibility.violations.map((violation) => violation.id)).toEqual([]);

            // Le secret est montré une fois, dans la réponse qui le crée.
            await page.click('[data-testid="generate-webhook-secret"]');
            const value = page.locator('[data-testid="webhook-secret-value"]');
            await expect(value).toHaveText(/^[0-9a-f]{64}$/);
            const secret = (await value.textContent()).trim();

            await page.goto('/fr/admin/updates');
            await expect(page.locator('[data-testid="webhook-secret-value"]')).toHaveCount(0);
            await expect(page.locator('[data-testid="webhook-secret-state"]')).toHaveAttribute('data-defined', 'true');
            expect(await page.content()).not.toContain(secret);

            // Ce secret authentifie une notification ; un autre, non.
            const accepted = await request.post('/webhook/github', signed(secret, { zen: 'Speak like a human.' }));
            expect(accepted.status()).toBe(200);
            expect((await accepted.json()).status).toBe('pong');

            const refused = await request.post('/webhook/github', signed('x'.repeat(64), { zen: 'Speak like a human.' }));
            expect(refused.status()).toBe(403);

            await page.goto('/fr/admin/updates');
            await expect(page.locator('[data-testid="webhook-last-delivery"]')).toContainText('ping');
        } finally {
            // Les deux projets partagent l'installation : le canal est rendu.
            await selectChannel(page, 'stable');
        }
    });
});
