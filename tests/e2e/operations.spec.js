import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits } from './helpers/fixtures.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « affectation → ICS → révocation token »
 * (ROADMAP.md itération 9, SPECIFICATIONS.md §48 à §51).
 *
 * L'enjeu n'est pas seulement qu'un flux ICS existe : c'est qu'il montre
 * exactement ce que sa portée autorise, et qu'une révocation coupe l'accès
 * immédiatement.
 */
test.describe('exploitation', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let manager = '';
    let reference = '';
    let adminFeed = '';
    let customerFeed = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct de tous les autres scénarios.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 13 : 12), 1);
        const month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-07`, departure: `${month}-14` };
        client = `operations.${suffix}@example.test`;
        manager = `responsable.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    // --- Préparation -----------------------------------------------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('un responsable local est créé', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/users');
            await page.fill('#first_name', 'Marc');
            await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
            await page.fill('#new_email', manager);
            await page.fill('#phone', '+33600000001');
            await page.fill('#new_password', PASSWORD);
            await page.selectOption('#new_role', 'local_manager');
            await page.click('[data-testid="create-user-form"] button[type="submit"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator(`[data-user-email="${manager}"]`)).toBeVisible();
        });
    });

    test('un voyageur réserve un séjour', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Voyageur');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await page.click('[data-testid="signup-form"] button[type="submit"]');

        const mail = await waitForMail(request, client, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));

        await page.goto(`/fr/booking?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`);
        await page.click('[data-testid="start-booking"]');
        await page.check('#accept_rules');
        await page.click('[data-testid="submit-booking"]');

        await expect(page).toHaveURL(/\/fr\/booking\/[A-Z0-9-]+$/);
        reference = (await page.locator('[data-testid="booking-reference"]').innerText()).trim();

        await context.close();
    });

    // --- Affectation ---------------------------------------------------------------

    test.describe('affectation', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le séjour est affecté au responsable local', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/bookings');
            await page.click(`[data-booking-reference="${reference}"] a`);

            const panel = page.locator('[data-testid="admin-operations"]');
            await expect(panel).toBeVisible();
            await expect(page.locator('[data-testid="effective-manager"]')).toHaveAttribute('data-manager', '');

            // L'option est repérée par l'adresse du responsable, seule
            // valeur réellement stable entre deux exécutions.
            const option = page.locator('#manager_id option').filter({ hasText: manager });
            await page.selectOption('#manager_id', (await option.getAttribute('value')) ?? '');
            await page.click('[data-testid="assign-manager"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator('[data-testid="effective-manager"]')).not.toHaveAttribute('data-manager', '');

            // La ligne « responsable » de la checklist suit, sans être cochée
            // à la main.
            await expect(panel.locator('[data-item="manager"]')).toHaveAttribute('data-status', 'done');
            await expect(panel.locator('[data-item="manager"]')).toHaveAttribute('data-manual', 'false');
        });

        test('une ligne de checklist manuelle se coche', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.click(`[data-booking-reference="${reference}"] a`);

            const item = page.locator('[data-testid="admin-operations"] [data-item="access_shared"]');
            await expect(item).toHaveAttribute('data-status', 'pending');

            await item.locator('input[name="done"]').check();
            await page.click('[data-testid="task-access_shared"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(
                page.locator('[data-testid="admin-operations"] [data-item="access_shared"]')
            ).toHaveAttribute('data-status', 'done');
        });

        test('le séjour apparaît parmi ceux à préparer', async ({ page }) => {
            await page.goto('/fr/admin/operations');

            await expect(page.locator('[data-testid="todo-list"]')).toBeVisible();
            await expect(page.locator('[data-testid="todo-list"] [data-todo="bookings_to_confirm"]')).toBeVisible();
        });

        test('un lien de calendrier d’administration est délivré', async ({ page }) => {
            await page.goto('/fr/admin/operations');
            await page.selectOption('#scope', 'admin');
            await page.click('[data-testid="issue-calendar"]');

            await expect(page.locator('[data-testid="issued-calendar"]')).toBeVisible();
            adminFeed = (await page.locator('[data-testid="calendar-url"]').innerText()).trim();
            expect(adminFeed).toMatch(/\/calendar\/[a-f0-9]{64}\.ics$/);

            // L'adresse n'est montrée qu'une fois.
            await page.goto('/fr/admin/operations');
            await expect(page.locator('[data-testid="issued-calendar"]')).toHaveCount(0);
        });
    });

    // --- Flux ICS -----------------------------------------------------------------------

    test('le flux d’administration est un calendrier valide et complet', async ({ request }) => {
        const response = await request.get(new URL(adminFeed).pathname);

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('text/calendar');
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(response.headers()['x-robots-tag']).toContain('noindex');

        const body = await response.text();
        expect(body.startsWith('BEGIN:VCALENDAR\r\n')).toBe(true);
        expect(body.trimEnd().endsWith('END:VCALENDAR')).toBe(true);
        expect(body).toContain(reference);

        // Le jour du départ reste libre : DTEND est exclusif.
        expect(body).toContain(`DTSTART;VALUE=DATE:${stay.arrival.replaceAll('-', '')}`);
        expect(body).toContain(`DTEND;VALUE=DATE:${stay.departure.replaceAll('-', '')}`);
    });

    test('le voyageur obtient un flux limité à son séjour', async ({ browser, request }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/login');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.click('[data-testid="login-form"] button[type="submit"]');

        await page.goto(`/fr/booking/${reference}`);

        // Le contact du responsable est affiché au voyageur.
        await expect(page.locator('[data-testid="stay-manager"]')).toBeVisible();
        await expect(page.locator('[data-testid="stay-manager"]')).toContainText(manager);

        await page.click('[data-testid="calendar-link"]');
        await expect(page.locator('[data-testid="calendar-url"]')).toBeVisible();
        customerFeed = (await page.locator('[data-testid="calendar-url"]').innerText()).trim();

        const feed = await request.get(new URL(customerFeed).pathname);
        expect(feed.status()).toBe(200);

        const body = await feed.text();
        expect(body).toContain(reference);
        // Le flux du voyageur porte le contact du responsable, jamais de
        // montant.
        expect(body).toContain(manager.replace(/@/, '@'));
        expect(body).not.toMatch(/\d+,\d{2}/);

        await context.close();
    });

    // --- Révocation ------------------------------------------------------------------------

    test.describe('révocation', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('révoquer un lien coupe immédiatement l’accès', async ({ page, request }) => {
            test.slow();

            // Le lien fonctionne encore.
            expect((await request.get(new URL(adminFeed).pathname)).status()).toBe(200);

            await page.goto('/fr/admin/operations');
            const row = page.locator('[data-testid="calendar-tokens"] tr[data-scope="admin"]').first();
            await expect(row).toBeVisible();

            await row.locator('button').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            const revoked = await request.get(new URL(adminFeed).pathname);
            expect(revoked.status()).toBe(404);
        });

        test('un jeton inventé ne donne accès à rien', async ({ request }) => {
            const response = await request.get(`/calendar/${'0'.repeat(64)}.ics`);

            expect(response.status()).toBe(404);
        });
    });
});
