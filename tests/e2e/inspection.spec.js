import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait } from './helpers/fixtures.js';
import { pngBuffer } from './helpers/image.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « workflow mobile arrivée/départ »
 * (ROADMAP.md itération 11, SPECIFICATIONS.md §53 et §54).
 *
 * Ce qui est réellement vérifié ici est la règle qui protège les deux parties :
 * à l'arrivée, le voyageur signale et peut clore sans photo ; au départ, le
 * serveur refuse de clore tant qu'une zone requise n'a pas la sienne. Le
 * bouton n'est jamais l'autorité : le refus est provoqué depuis l'interface,
 * exactement comme le ferait un voyageur pressé.
 */
test.describe('états des lieux', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    /** Zones conservées pour le scénario, dans l'ordre du parcours. */
    const KEPT = ['entrance', 'kitchen', 'meters'];
    const DISABLED = ['living_room', 'bedrooms', 'bathrooms', 'outdoor'];
    /** Zones qui exigent une photo au départ. */
    const WITH_PHOTO = ['kitchen', 'meters'];

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let reference = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct de tous les autres scénarios.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 17 : 16), 1);
        const month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-05`, departure: `${month}-12` };
        client = `constat.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    // --- Configuration des zones -----------------------------------------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les zones proposées existent dès l’installation', async ({ page }) => {
            await page.goto('/fr/admin/inspections');

            for (const code of [...KEPT, ...DISABLED]) {
                await expect(page.locator(`[data-zone-editor="${code}"]`)).toHaveCount(1);
            }
        });

        test('le propriétaire choisit les zones et celles qui exigent une photo', async ({ page }) => {
            test.slow();

            for (const code of DISABLED) {
                await page.goto('/fr/admin/inspections?locale=fr');
                const form = page.locator(`[data-testid="zone-form-${code}"]`);
                await form.locator('input[name="active"]').uncheck();
                await form.locator(`[data-testid="zone-save-${code}"]`).click();
                await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            }

            for (const code of KEPT) {
                await page.goto('/fr/admin/inspections?locale=fr');
                const form = page.locator(`[data-testid="zone-form-${code}"]`);
                await form.locator('input[name="active"]').check();

                const photo = form.locator('input[name="photo_required"]');
                await (WITH_PHOTO.includes(code) ? photo.check() : photo.uncheck());

                await form.locator('input[name="name"]').fill(
                    { entrance: 'Entrée', kitchen: 'Cuisine', meters: 'Compteurs' }[code]
                );
                await form.locator(`[data-testid="zone-save-${code}"]`).click();
                await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            }

            await page.goto('/fr/admin/inspections?locale=fr');
            for (const code of KEPT) {
                await expect(page.locator(`[data-zone-editor="${code}"] [data-zone-active]`))
                    .toHaveAttribute('data-zone-active', 'true');
                await expect(page.locator(`[data-zone-editor="${code}"] [data-zone-photo]`))
                    .toHaveAttribute('data-zone-photo', WITH_PHOTO.includes(code) ? 'true' : 'false');
            }
            for (const code of DISABLED) {
                await expect(page.locator(`[data-zone-editor="${code}"] [data-zone-active]`))
                    .toHaveAttribute('data-zone-active', 'false');
            }
        });

        test('une photo de référence est acceptée, un PDF ne l’est pas', async ({ page }) => {
            await page.goto('/fr/admin/inspections?locale=fr');
            await page.setInputFiles('#reference_kitchen', {
                name: 'cuisine-reference.png',
                mimeType: 'image/png',
                buffer: pngBuffer(64, 48, [200, 120, 40])
            });
            await page.locator('[data-testid="reference-upload-kitchen"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/inspections?locale=fr');
            await expect(page.locator('[data-testid="references-kitchen"] [data-reference]').first()).toBeVisible();

            // Un PDF ne montre pas l'état attendu d'une pièce : il est refusé.
            await page.setInputFiles('#reference_meters', {
                name: 'notice.pdf',
                mimeType: 'application/pdf',
                buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n', 'utf8')
            });
            await page.locator('[data-testid="reference-upload-meters"]').click();
            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
            await page.goto('/fr/admin/inspections?locale=fr');
            await expect(page.locator('[data-testid="no-reference-meters"]')).toBeVisible();
        });
    });

    // --- Parcours voyageur -------------------------------------------------------------

    test('le voyageur réserve et ouvre son état des lieux d’arrivée', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Constat');
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

        // Depuis « Mon séjour », l'état des lieux est à un clic.
        await page.goto(`/fr/stay/${reference}`);
        await page.click('[data-testid="stay-inspection-checkin"]');

        await expect(page).toHaveURL(new RegExp(`/fr/stay/${reference}/inspection/checkin$`));

        const inspection = page.locator('[data-testid="inspection-page"]');
        await expect(inspection).toHaveAttribute('data-kind', 'checkin');
        await expect(inspection).toHaveAttribute('data-status', 'open');

        // Seules les zones actives apparaissent, dans l'ordre du parcours.
        await expect(page.locator('[data-zone]')).toHaveCount(KEPT.length);
        for (const code of DISABLED) {
            await expect(page.locator(`[data-zone="${code}"]`)).toHaveCount(0);
        }

        await expect(page.locator('[data-testid="inspection-progress"]')).toHaveAttribute('data-done', '0');
        await expect(page.locator('[data-testid="inspection-progress"]')).toHaveAttribute('data-total', '3');

        await context.close();
    });

    test('une anomalie constatée à l’arrivée devient un incident', async ({ browser }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}/inspection/checkin`);

        // Entrée : anomalie, avec un commentaire.
        // Les boutons radio Bootstrap sont masqués derrière leur libellé :
        // c'est le libellé que le pouce touche, donc le libellé que l'on clique.
        await page.click('label[for="state_anomaly_entrance"]');
        await page.fill('#note_entrance', 'Le volet de l’entrée ne ferme plus.');
        await page.click('[data-testid="save-entrance"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        await expect(page.locator('[data-zone="entrance"]')).toHaveAttribute('data-state', 'anomaly');

        // Le formulaire d'incident n'apparaît que sur une zone en anomalie.
        await expect(page.locator('[data-testid="incident-form-entrance"]')).toBeVisible();
        await expect(page.locator('[data-testid="incident-form-kitchen"]')).toHaveCount(0);

        await page.selectOption('#severity_entrance', 'urgent');
        await page.fill('#description_entrance', 'Impossible de fermer, le logement n’est pas sécurisé.');
        await page.click('[data-testid="incident-entrance"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        // Les deux autres zones sont conformes : aucune photo n'est demandée à
        // l'arrivée, même sur une zone qui en exigera une au départ.
        for (const code of ['kitchen', 'meters']) {
            await page.click(`label[for="state_ok_${code}"]`);
            await page.click(`[data-testid="save-${code}"]`);
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        }

        await expect(page.locator('[data-testid="inspection-progress"]')).toHaveAttribute('data-done', '3');

        await page.click('[data-testid="inspection-complete"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await expect(page.locator('[data-testid="inspection-page"]')).toHaveAttribute('data-status', 'completed');
        await expect(page.locator('[data-testid="inspection-done"]')).toBeVisible();

        // Un état des lieux clos ne se modifie plus : les formulaires ont disparu.
        await expect(page.locator('[data-testid="save-entrance"]')).toHaveCount(0);

        await context.close();
    });

    test('le départ est refusé tant qu’une photo requise manque', async ({ browser }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}/inspection/checkout`);
        await expect(page.locator('[data-testid="inspection-page"]')).toHaveAttribute('data-kind', 'checkout');

        // Toutes les zones sont déclarées conformes, sans aucune photo.
        for (const code of KEPT) {
            await page.click(`label[for="state_ok_${code}"]`);
            await page.click(`[data-testid="save-${code}"]`);
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        }

        await expect(page.locator('[data-testid="inspection-blocking"]')).toBeVisible();

        // Le refus vient du serveur, pas d'un bouton grisé.
        await page.click('[data-testid="inspection-complete"]');
        await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
        await expect(page.locator('[data-testid="inspection-page"]')).toHaveAttribute('data-status', 'open');

        // Seules les zones réellement requises bloquent.
        const blocking = page.locator('[data-testid="inspection-blocking"]');
        await expect(blocking).toContainText('Cuisine');
        await expect(blocking).toContainText('Compteurs');
        await expect(blocking).not.toContainText('Entrée');

        await context.close();
    });

    test('le départ se clôt une fois les photos prises', async ({ browser }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}/inspection/checkout`);

        for (const code of WITH_PHOTO) {
            await page.click(`label[for="state_ok_${code}"]`);
            await page.setInputFiles(`#photo_${code}`, {
                name: `${code}.png`,
                mimeType: 'image/png',
                buffer: pngBuffer(48, 36, [code === 'kitchen' ? 30 : 180, 110, 70])
            });
            await page.click(`[data-testid="save-${code}"]`);
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await expect(page.locator(`[data-zone="${code}"]`)).toHaveAttribute('data-photos', '1');
        }

        await expect(page.locator('[data-testid="inspection-blocking"]')).toHaveCount(0);

        await page.click('[data-testid="inspection-complete"]');
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        await expect(page.locator('[data-testid="inspection-page"]')).toHaveAttribute('data-status', 'completed');

        // La photo reste un document servi par l'application, jamais par le
        // serveur web : son propriétaire y accède, un anonyme non.
        const photo = page.locator('[data-testid="photos-kitchen"] [data-photo]').first();
        const href = await photo.getAttribute('href');
        expect(href).toMatch(/\/document\/\d+$/);
        expect((await page.request.get(href)).status()).toBe(200);

        await context.close();

        const anonymous = await anonymousContext(browser);
        expect((await anonymous.request.get(href)).status()).toBe(403);
        await anonymous.close();
    });

    // --- Suivi côté exploitation ----------------------------------------------------

    test.describe('suivi', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('l’incident urgent est visible et suit son cycle de vie', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/incidents');
            const row = page.locator('[data-testid="incident-list"] tr[data-incident-severity="urgent"]').first();
            await expect(row).toHaveAttribute('data-incident-status', 'reported');
            await row.locator('a').first().click();

            await expect(page.locator('[data-testid="incident-detail"]')).toBeVisible();
            await expect(page.locator('[data-testid="incident-status"]')).toHaveText('Signalé');

            await page.locator('[data-testid="incident-to-acknowledged"]').click();
            await expect(page.locator('[data-testid="incident-status"]')).toHaveText('Pris en charge');

            await page.fill('#incident_note', 'Serrurier prévenu.');
            await page.locator('[data-testid="incident-comment"]').click();
            await expect(page.locator('[data-testid="incident-history"]')).toContainText('Serrurier prévenu.');

            await page.locator('[data-testid="incident-to-resolved"]').click();
            await expect(page.locator('[data-testid="incident-status"]')).toHaveText('Résolu');

            // L'historique garde chaque étape, dans l'ordre.
            const history = page.locator('[data-testid="incident-history"] [data-incident-event]');
            await expect(history.nth(0)).toHaveAttribute('data-incident-event', 'reported');
            await expect(history.last()).toHaveAttribute('data-incident-event', 'resolved');
        });

        test('la fiche du séjour montre les deux états des lieux terminés', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.locator(`a:has-text("${reference}")`).first().click();

            const panel = page.locator('[data-testid="admin-inspections"]');
            await expect(panel.locator('[data-inspection-kind="checkin"]'))
                .toHaveAttribute('data-inspection-status', 'completed');
            await expect(panel.locator('[data-inspection-kind="checkout"]'))
                .toHaveAttribute('data-inspection-status', 'completed');

            await page.click('[data-testid="inspection-detail-link"]');
            await expect(page.locator('[data-testid="inspection-status-checkin"]')).toHaveText('Terminé');
            await expect(page.locator('[data-inspection-kind="checkin"] [data-zone="entrance"]'))
                .toHaveAttribute('data-state', 'anomaly');
        });
    });

    // --- Cloisonnement ----------------------------------------------------------------

    test('l’état des lieux d’un autre séjour reste inaccessible', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        // Sans compte : refusé.
        expect((await page.request.get(`/fr/stay/${reference}/inspection/checkin`)).status()).toBe(403);

        await context.close();
    });

    test('un type d’état des lieux inventé n’existe pas', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        // Le type est contraint par la route elle-même.
        expect((await page.request.get(`/fr/stay/${reference}/inspection/milieu`)).status()).toBe(404);

        await context.close();
    });

});
