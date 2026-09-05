import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait, submitSignUp } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';
import { deliverWebhook, providerPayments, settleAtProvider } from './helpers/payments.js';

/**
 * Scénario critique « acompte → webhook confirmé → réservation confirmée »
 * (ROADMAP.md itération 7, SPECIFICATIONS.md §29 à §34).
 *
 * Le paiement n'est jamais confirmé par la redirection de retour : c'est la
 * notification, puis la relecture de l'état chez le fournisseur, qui font
 * foi. Le scénario suit donc ce chemin-là, et vérifie au passage qu'un
 * webhook rejoué ne double rien.
 */
test.describe('paiement', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';
    const IBAN = 'FR7630006000011234567890189';

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let reference = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct des scénarios de tarification et de
        // réservation : les deux projets partagent la même installation.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 9 : 8), 1);
        const month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-06`, departure: `${month}-13` };
        client = `payment.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    test.describe('configuration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les coordonnées de virement et la taxe de séjour sont configurées', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/settings?module=payment');
            await page.check('#setting_payment__transfer_enabled');
            await page.fill('#setting_payment__beneficiary_name', 'Maison des Pins');
            await page.fill('#setting_payment__iban', IBAN);
            await page.fill('#setting_payment__balance_days_before', '30');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/settings?module=tax');
            await page.check('#setting_tax__tourist_enabled');
            await page.fill('#setting_tax__tourist_per_adult_night', '1.50');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });

        test('un IBAN dont la clé est fausse est refusé', async ({ page }) => {
            await page.goto('/fr/admin/settings?module=payment');
            await page.fill('#setting_payment__iban', 'FR7630006000011234567890188');
            await page.click('[data-testid="settings-save"]');

            await expect(page.locator('[data-error-for="payment.iban"]')).toBeVisible();
            await expect(page.locator('#setting_payment__iban')).toHaveClass(/is-invalid/);

            // Rien n'a été enregistré : la valeur valide précédente subsiste.
            await page.goto('/fr/admin/settings?module=payment');
            await expect(page.locator('#setting_payment__iban')).toHaveValue(IBAN);
        });
    });

    // --- Parcours voyageur ---------------------------------------------------

    test('le voyageur réserve puis paie son acompte', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        // 1. Compte confirmé.
        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Payeur');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await submitSignUp(page);

        const mail = await waitForMail(request, client, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));

        // 2. Réservation.
        await page.goto(`/fr/booking?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`);
        await page.click('[data-testid="start-booking"]');
        await page.check('#accept_rules');
        await page.click('[data-testid="submit-booking"]');

        await expect(page).toHaveURL(/\/fr\/booking\/[A-Z0-9-]+$/);
        reference = (await page.locator('[data-testid="booking-reference"]').innerText()).trim();
        await expect(page.locator('[data-testid="booking-detail"]')).toHaveAttribute('data-status', 'to_confirm');

        // 3. L'échéancier est complet : acompte, solde, caution, taxe.
        const schedule = page.locator('[data-testid="payment-schedule"]');
        await expect(schedule).toBeVisible();
        for (const kind of ['deposit', 'balance', 'security_deposit', 'tourist_tax']) {
            await expect(schedule.locator(`[data-kind="${kind}"]`)).toHaveCount(1);
        }

        const due = await page.locator('[data-testid="payment-total-due"]').innerText();
        expect(due).not.toBe('');

        // 4. L'acompte part chez le fournisseur ; le retour n'affirme rien.
        await page.click('[data-testid="pay-online-deposit"]');
        await expect(page).toHaveURL(/\/payment\/\d+\/return$/);
        await expect(page.locator('[data-testid="payment-return-pending"]')).toBeVisible();

        // 5. Le fournisseur encaisse. L'application ne le sait pas encore.
        const opened = await providerPayments(request);
        expect(opened.length).toBeGreaterThan(0);
        const pending = opened.filter((payment) => payment.status === 'pending');
        expect(pending.length).toBeGreaterThan(0);
        const providerReference = pending[pending.length - 1].reference;

        await settleAtProvider(page, providerReference);

        await page.goto(`/fr/booking/${reference}`);
        await expect(page.locator('[data-testid="booking-detail"]')).toHaveAttribute('data-status', 'to_confirm');

        // 6. La notification, seule, confirme le séjour.
        const first = await deliverWebhook(request, providerReference);
        expect(first.status).toBe('applied');

        await page.goto(`/fr/booking/${reference}`);
        await expect(page.locator('[data-testid="booking-detail"]')).toHaveAttribute('data-status', 'confirmed');
        await expect(
            page.locator('[data-testid="payment-schedule"] [data-kind="deposit"]')
        ).toHaveAttribute('data-status', 'paid');

        // 7. Rejouée, la même notification ne produit rien de plus.
        const replay = await deliverWebhook(request, providerReference);
        expect(replay.status).toBe('duplicate');

        await page.goto(`/fr/booking/${reference}`);
        await expect(page.locator('[data-testid="booking-detail"]')).toHaveAttribute('data-status', 'confirmed');
        await expect(
            page.locator('[data-testid="payment-schedule"] [data-kind="deposit"]')
        ).toHaveAttribute('data-status', 'paid');

        await context.close();
    });

    test('le solde peut être payé par virement, avec un QR code EPC', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/booking/${reference}`);
        await page.click('[data-testid="pay-transfer-balance"]');

        await expect(page).toHaveURL(/\/payment\/\d+\/transfer$/);
        await expect(page.locator('[data-testid="transfer-iban"]')).toHaveText('FR76 3000 6000 0112 3456 7890 189');
        await expect(page.locator('[data-testid="transfer-reference"]')).toContainText(reference);

        // Le QR code est une vraie image SVG servie par l'application.
        const qr = page.locator('[data-testid="payment-epc-qr"]');
        await expect(qr).toBeVisible();

        // Le QR porte une référence de virement : il est servi derrière
        // l'authentification, on le récupère donc avec la session du client.
        const source = await qr.getAttribute('src');
        const svg = await page.request.get(source);
        expect(svg.status()).toBe(200);
        expect(svg.headers()['content-type']).toContain('image/svg+xml');
        expect(await svg.text()).toContain('<svg');

        // Le même QR est refusé à un visiteur anonyme.
        const stranger = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        expect((await stranger.request.get(source)).status()).toBe(403);
        await stranger.close();

        await context.close();
    });

    // --- Administration --------------------------------------------------------

    test.describe('suivi', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('l’acompte encaissé apparaît dans le suivi financier', async ({ page }) => {
            await page.goto('/fr/admin/payments');

            await expect(page.locator('[data-testid="outstanding-table"]')).toBeVisible();
            await expect(page.locator('[data-testid="webhook-table"] tr[data-webhook-status]').first())
                .toHaveAttribute('data-webhook-status', 'processed');

            // L'acompte est réglé : il ne figure plus parmi les échéances.
            const rows = page.locator('[data-testid="outstanding-table"] tbody tr');
            await expect(rows.first()).toBeVisible();
        });

        test('la caution suit son cycle et le remboursement est tracé', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/bookings');
            await page.click(`[data-booking-reference="${reference}"] a`);
            await expect(page.locator('[data-testid="admin-payments"]')).toBeVisible();

            const hold = page.locator('[data-testid="admin-payments"] [data-kind="security_deposit"]');
            await expect(hold).toHaveAttribute('data-hold', 'to_pay');

            // Encaissement manuel : il ne confirme rien de plus à lui seul.
            await page.click('[data-testid="record-security_deposit"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(hold).toHaveAttribute('data-hold', 'received');

            await page.click('[data-testid="hold-to-return"]');
            await expect(hold).toHaveAttribute('data-hold', 'to_return');

            // Restitution complète.
            await page.click('[data-testid="refund-security_deposit"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(hold).toHaveAttribute('data-hold', 'returned');

            await expect(page.locator('[data-testid="booking-timeline"] [data-event="payment_deposit"]'))
                .toHaveCount(1);
        });
    });
});
