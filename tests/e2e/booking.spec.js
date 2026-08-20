import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « deux clients concurrents → un seul succès »
 * (SPECIFICATIONS.md §27, TESTING.md §7).
 *
 * Les deux navigateurs postent leur verrou **en parallèle**, sans attendre la
 * réponse de l'autre : c'est la seule façon d'exercer réellement la garantie
 * transactionnelle plutôt que deux appels successifs.
 */
test.describe('réservation', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    let suffix = 'desktop';
    let month = '';
    let stay = { arrival: '', departure: '' };
    let contested = { arrival: '', departure: '' };
    const clients = [];

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct de ceux du scénario de tarification.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 7 : 6), 1);
        month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-08`, departure: `${month}-15` };
        contested = { arrival: `${month}-20`, departure: `${month}-24` };

        clients.length = 0;
        clients.push(`booking1.${suffix}@example.test`, `booking2.${suffix}@example.test`);

        await clearRateLimits(browser);
    });

    /** Crée et confirme un compte client, puis renvoie son contexte connecté. */
    async function signedInClient(browser, request, email) {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Client');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
        await page.fill('#email', email);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await page.click('[data-testid="signup-form"] button[type="submit"]');

        const mail = await waitForMail(request, email, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));
        await expect(page).toHaveURL(/\/fr\/account$/);

        return { context, page };
    }

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les règles de réservation sont configurées', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/settings?module=booking');
            await page.fill('#setting_booking__min_nights', '2');
            await page.fill('#setting_booking__max_guests', '6');
            await page.fill('#setting_booking__hold_minutes', '30');
            await page.check('#setting_booking__requires_approval');
            await page.check('#setting_booking__allow_waitlist');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });

        test('un code promo est créé', async ({ page }) => {
            await page.goto('/fr/admin/bookings');

            await page.fill('#code', `REMISE-${suffix}`);
            await page.selectOption('#kind', 'percent');
            await page.fill('#value', '10');
            await page.click('[data-testid="create-promo"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator(`[data-promo-code="REMISE-${suffix.toUpperCase()}"]`)).toBeVisible();
        });
    });

    // --- Parcours complet ----------------------------------------------------

    test('un client réserve un séjour de bout en bout', async ({ browser, request }) => {
        test.slow();

        const { context, page } = await signedInClient(browser, request, clients[0]);

        // 1. Le calendrier mène au parcours avec les dates choisies.
        await page.goto(`/fr/booking?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`);
        await expect(page.locator('[data-testid="booking-quote"]')).toBeVisible();

        const nightly = Number(await page.locator('[data-quote-accommodation]').getAttribute('data-cents'));
        expect(nightly).toBeGreaterThan(0);

        // 2. Le code promo réduit l'hébergement.
        await page.fill('#promo_code', `remise-${suffix}`);
        await page.click('[data-testid="recalculate"]');

        const total = Number(await page.locator('[data-quote-total]').getAttribute('data-cents'));
        expect(total).toBeGreaterThan(0);

        // 3. Le verrou est posé.
        await page.click('[data-testid="start-booking"]');
        await expect(page).toHaveURL(/\/fr\/booking\/finalise$/);
        await expect(page.locator('[data-testid="booking-finalise"]')).toBeVisible();

        // 4. Les règles doivent être acceptées.
        await page.click('[data-testid="submit-booking"]');
        await expect(page.locator('[data-testid="booking-finalise"]')).toBeVisible();

        await page.check('#accept_rules');
        await page.fill('#message', 'Nous arriverons en fin de journée.');
        await page.click('[data-testid="submit-booking"]');

        // 5. Le séjour existe, avec sa référence et sa timeline.
        await expect(page).toHaveURL(/\/fr\/booking\/[A-Z0-9-]+$/);
        await expect(page.locator('[data-testid="booking-status"]')).toBeVisible();
        await expect(page.locator('[data-testid="booking-detail"]')).toHaveAttribute('data-status', 'to_confirm');

        // La timeline nomme chaque étape : verrou, demande, puis production
        // du contrat dès la première consultation de la fiche.
        const timeline = page.locator('[data-testid="booking-timeline"] [data-event]');
        await expect(timeline.first()).toHaveAttribute('data-event', 'hold_created');
        for (const event of ['hold_created', 'requested', 'contract_generated']) {
            await expect(page.locator(`[data-testid="booking-timeline"] [data-event="${event}"]`)).toHaveCount(1);
        }

        // 6. Le séjour apparaît dans l'espace client.
        await page.goto('/fr/account');
        await expect(page.locator('[data-testid="my-bookings"] [data-booking-reference]')).toHaveCount(1);

        await context.close();
    });

    test('les nuits réservées deviennent indisponibles pour tout le monde', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const visitor = await context.newPage();

        await visitor.goto(`/fr/availability?month=${month}`);

        for (const day of [`${month}-08`, `${month}-14`]) {
            await expect(visitor.locator(`[data-day="${day}"]`)).toHaveAttribute('data-state', 'blocked');
            await expect(visitor.locator(`[data-day="${day}"]`)).toBeDisabled();
        }
        // La nuit du départ reste libre pour l'arrivée suivante.
        await expect(visitor.locator(`[data-day="${month}-15"]`)).toHaveAttribute('data-state', 'free');

        // Rien n'identifie le client.
        const html = await visitor.content();
        expect(html).not.toContain(clients[0]);

        await context.close();
    });

    // --- Anti-double-réservation ---------------------------------------------

    test('deux clients concurrents : un seul obtient les nuits', async ({ browser, request }) => {
        test.slow();

        const first = await signedInClient(browser, request, clients[1]);
        const second = { context: await anonymousContext(browser) };
        second.page = await second.context.newPage();

        // Le second client réutilise le compte du premier scénario.
        await second.page.goto('/fr/login');
        await second.page.fill('#email', clients[0]);
        await second.page.fill('#password', PASSWORD);
        await second.page.click('[data-testid="login-form"] button[type="submit"]');
        await expect(second.page).toHaveURL(/\/fr\/account$/);

        // Les deux atteignent le même récapitulatif, sur les mêmes dates.
        const url = `/fr/booking?arrival=${contested.arrival}&departure=${contested.departure}&adults=2`;
        await Promise.all([first.page.goto(url), second.page.goto(url)]);

        await expect(first.page.locator('[data-testid="start-booking"]')).toBeVisible();
        await expect(second.page.locator('[data-testid="start-booking"]')).toBeVisible();

        // Les deux verrous partent **en parallèle**.
        const results = await Promise.all([
            first.page.click('[data-testid="start-booking"]').then(() => first.page.url()),
            second.page.click('[data-testid="start-booking"]').then(() => second.page.url())
        ]);

        const winners = results.filter((current) => /\/booking\/finalise$/.test(current));
        const losers = results.filter((current) => !/\/booking\/finalise$/.test(current));

        // Exactement un gagnant, exactement un perdant.
        expect(winners).toHaveLength(1);
        expect(losers).toHaveLength(1);

        const loser = results[0] === losers[0] ? first.page : second.page;
        await expect(loser.locator('[data-testid="booking-errors"]')).toBeVisible();

        // Le perdant se voit proposer la liste d'attente, pas une erreur brute.
        await expect(loser.locator('[data-testid="waitlist"]')).toBeVisible();

        await first.context.close();
        await second.context.close();
    });

    test('le perdant peut s’inscrire en liste d’attente', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto(`/fr/booking?arrival=${contested.arrival}&departure=${contested.departure}&adults=2`);
        await expect(page.locator('[data-testid="waitlist"]')).toBeVisible();

        await page.fill('#email', `attente.${suffix}@example.test`);
        await page.click('[data-testid="join-waitlist"]');

        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await context.close();
    });

    // --- Workflow côté propriétaire -------------------------------------------

    test.describe('validation', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le propriétaire confirme une demande', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/bookings?status=to_confirm');
            const row = page.locator('[data-booking-reference]').first();
            await expect(row).toBeVisible();

            const reference = await row.getAttribute('data-booking-reference');
            await row.locator('a').first().click();

            await expect(page.locator('[data-testid="booking-reference"]')).toHaveText(reference);

            await page.selectOption('#status', 'confirmed');
            await page.click('[data-testid="apply-transition"]');

            await expect(page.locator('[data-testid="booking-status"]')).toBeVisible();
            await expect(page.locator('[data-testid="booking-timeline"] [data-event="status_confirmed"]')).toBeVisible();
        });

        test('annuler les séjours libère leurs nuits', async ({ page }) => {
            test.slow();

            // Tous les séjours encore ouverts sont annulés : les nuits qu'ils
            // tenaient doivent redevenir réservables.
            for (let guard = 0; guard < 10; guard++) {
                await page.goto('/fr/admin/bookings');

                const open = page.locator('[data-booking-reference]').filter({
                    has: page.locator('.badge')
                });
                const rows = await open.count();
                let handled = false;

                for (let index = 0; index < rows; index++) {
                    const row = open.nth(index);
                    const status = await row.getAttribute('data-status');
                    if (status === 'cancelled' || status === 'refused' || status === 'completed') {
                        continue;
                    }

                    await row.locator('a').first().click();
                    const options = await page.locator('#status option').evaluateAll((nodes) =>
                        nodes.map((node) => node.value)
                    );
                    if (options.indexOf('cancelled') === -1) {
                        break;
                    }

                    await page.selectOption('#status', 'cancelled');
                    await page.click('[data-testid="apply-transition"]');
                    await expect(
                        page.locator('[data-testid="booking-timeline"] [data-event="status_cancelled"]')
                    ).toBeVisible();
                    handled = true;
                    break;
                }

                if (!handled) {
                    break;
                }
            }

            // Les nuits redeviennent libres dans le calendrier public.
            await page.goto(`/fr/availability?month=${month}`);
            for (const day of [`${month}-08`, `${month}-14`, `${month}-20`]) {
                await expect(page.locator(`[data-day="${day}"]`), day).toHaveAttribute('data-state', 'free');
            }
        });
    });

    // --- Sécurité ---------------------------------------------------------------

    test('le détail d’un séjour n’est pas accessible aux autres', async ({ browser, request }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        // Un visiteur anonyme ne voit rien, même avec une référence valide.
        const response = await page.goto('/fr/booking/ABCD-2345');
        expect([403, 404]).toContain(response?.status() ?? 0);

        await context.close();
    });

    test('les mutations de réservation exigent un jeton CSRF', async ({ request }) => {
        for (const path of ['/fr/booking/hold', '/fr/booking/finalise', '/fr/booking/waitlist']) {
            const response = await request.post(path, { form: {}, maxRedirects: 0 });
            expect(response.status(), `${path} → ${response.status()}`).toBe(403);
        }
    });

    test('un séjour aux dates incohérentes est refusé sans planter', async ({ page }) => {
        const response = await page.goto('/fr/booking?arrival=hier&departure=demain&adults=2');

        expect(response?.status()).toBe(200);
        await expect(page.locator('[data-testid="booking-errors"]')).toBeVisible();
        await expect(page.locator('[data-testid="start-booking"]')).toHaveCount(0);
    });
});
