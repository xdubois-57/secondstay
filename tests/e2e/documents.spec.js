import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait, submitSignUp } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';
import { deliverToInbox, replyWithAttachment, samplePdf } from './helpers/inbox.js';

/**
 * Scénario critique « réponse mail avec contrat signé → document réservation »
 * (ROADMAP.md itération 8, SPECIFICATIONS.md §36 à §41).
 *
 * Le parcours suit le chemin réel : le voyageur lit puis accepte son contrat,
 * répond à l'adresse étiquetée en joignant le contrat signé, et la relève de
 * la boîte fait apparaître ce fichier dans les documents de son séjour.
 */
test.describe('documents et courrier', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';
    const MAILBOX = 'logement@example.test';

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let reference = '';
    let replyAddress = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct des autres scénarios : les deux
        // projets partagent la même installation.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 11 : 10), 1);
        const month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-05`, departure: `${month}-12` };
        client = `documents.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    test.describe('configuration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('la boîte de réception et les mentions légales sont configurées', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/settings?module=imap');
            await page.check('#setting_imap__enabled');
            await page.fill('#setting_imap__reply_address', MAILBOX);
            await page.fill('#setting_imap__mailbox', 'INBOX');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/settings?module=legal');
            await page.fill('#setting_legal__terms_version', '2026-01');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });
    });

    // --- Parcours voyageur ----------------------------------------------------

    test('le voyageur lit et accepte son contrat', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Lecteur');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await submitSignUp(page);

        const mail = await waitForMail(request, client, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));

        await page.goto(`/fr/booking?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`);
        await page.click('[data-testid="start-booking"]');
        await page.check('#accept_rules');
        await page.click('[data-testid="submit-booking"]');

        await expect(page).toHaveURL(/\/fr\/booking\/[A-Z0-9-]+$/);
        reference = (await page.locator('[data-testid="booking-reference"]').innerText()).trim();

        // 1. Le contrat existe et se télécharge en PDF.
        const panel = page.locator('[data-testid="contract-panel"]');
        await expect(panel).toHaveAttribute('data-accepted', 'false');

        const contract = await page.request.get(`/fr/booking/${reference}/contract`);
        expect(contract.status()).toBe(200);
        expect(contract.headers()['content-type']).toContain('application/pdf');
        expect((await contract.body()).subarray(0, 5).toString()).toBe('%PDF-');

        // 2. L'adresse de réponse étiquetée est annoncée au voyageur.
        replyAddress = (await page.locator('[data-testid="reply-address"]').innerText()).trim();
        expect(replyAddress).toContain(`+${reference}.`);
        expect(replyAddress).toContain('@example.test');

        // 3. Sans la case cochée, rien n'est accepté.
        await page.click('[data-testid="contract-accept"]');
        await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
        await expect(page.locator('[data-testid="contract-panel"]')).toHaveAttribute('data-accepted', 'false');

        // 4. Acceptation en règle.
        await page.check('#accept_contract');
        await page.click('[data-testid="contract-accept"]');

        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await expect(page.locator('[data-testid="contract-panel"]')).toHaveAttribute('data-accepted', 'true');
        await expect(page.locator('[data-testid="contract-accepted"]')).toBeVisible();

        await context.close();
    });

    test('un document de séjour n’est pas lisible par un autre visiteur', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        const response = await page.request.get(`/fr/booking/${reference}/contract`);
        expect(response.status()).toBe(403);

        await context.close();
    });

    // --- Réponse par e-mail ------------------------------------------------------

    test('la réponse du voyageur dépose le contrat signé dans ses documents', async ({ request }) => {
        const raw = replyWithAttachment({
            from: `Lecteur ${suffix} <${client}>`,
            to: replyAddress,
            subject: `Re: ${reference} — contrat signé`,
            body: 'Bonjour, voici le contrat signé. Bien cordialement.',
            filename: 'contrat-signe.pdf',
            contents: samplePdf(reference)
        });

        const delivered = await deliverToInbox(request, raw);
        expect(delivered.ok).toBe(true);
        expect(delivered.uid).toBeGreaterThan(0);
    });

    test.describe('relève', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('la relève rattache le message et classe la pièce jointe', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/mailbox');
            await page.click('[data-testid="mailbox-sync"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            // Le message est rattaché par le jeton signé, pas au jugé.
            const row = page.locator('[data-testid="mailbox-table"] tr[data-mail]').first();
            await expect(row).toHaveAttribute('data-linked-by', 'token');
            await expect(page.locator('[data-testid="unlinked-empty"]')).toBeVisible();

            // La pièce jointe est classée « contrat signé ».
            await row.locator('a').click();
            await expect(page.locator('[data-testid="mail-detail"]')).toBeVisible();
            await expect(
                page.locator('[data-testid="mail-attachments"] [data-kind="signed_contract"]')
            ).toHaveCount(1);
        });

        test('une seconde relève ne duplique rien', async ({ page }) => {
            await page.goto('/fr/admin/mailbox');
            const before = await page.locator('[data-testid="mailbox-table"] tr[data-mail]').count();

            await page.click('[data-testid="mailbox-sync"]');
            await page.goto('/fr/admin/mailbox');

            await expect(page.locator('[data-testid="mailbox-table"] tr[data-mail]')).toHaveCount(before);
        });

        test('le document apparaît dans le séjour, avec son contrat accepté', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.click(`[data-booking-reference="${reference}"] a`);

            const documents = page.locator('[data-testid="admin-documents"]');
            await expect(documents).toBeVisible();
            await expect(documents.locator('[data-kind="contract"]')).toHaveCount(1);
            await expect(documents.locator('[data-kind="signed_contract"]')).toHaveCount(1);

            // L'instantané accepté correspond toujours à son empreinte.
            await expect(page.locator('[data-testid="contract-integrity"]'))
                .toHaveAttribute('data-intact', 'true');
        });
    });

    test('le voyageur retrouve le contrat signé dans son espace', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/booking/${reference}`);

        const list = page.locator('[data-testid="document-list"]');
        await expect(list).toBeVisible();
        await expect(list.locator('[data-kind="signed_contract"]')).toHaveCount(1);

        // Le fichier se télécharge réellement, avec son type et sans cache.
        const link = list.locator('[data-testid="document-link-signed_contract"]');
        const href = await link.getAttribute('href');
        const download = await page.request.get(href);

        expect(download.status()).toBe(200);
        expect(download.headers()['content-type']).toContain('application/pdf');
        expect(download.headers()['cache-control']).toContain('no-store');
        expect((await download.body()).toString()).toContain(reference);

        await context.close();
    });
});
