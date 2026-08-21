import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits } from './helpers/fixtures.js';
import { fillDate } from './helpers/forms.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Suite transverse de clôture (ROADMAP.md itération 14 ;
 * SPECIFICATIONS.md §52, §66, §67 et §68).
 *
 * Elle joue d'un bout à l'autre ce qui ferme l'exploitation :
 *
 * - un calendrier externe importé ferme réellement des nuits au public, sans
 *   jamais effacer ce que le propriétaire a bloqué lui-même, et un flux muet
 *   ne rouvre rien ;
 * - le reporting compte le séjour et s'exporte en classeur ;
 * - un litige s'ouvre, se discute et se clôt avec son montant et son
 *   explication ;
 * - un quota atteint refuse l'écriture, et libérer de la place la rétablit.
 *
 * Les deux projets Playwright partagent une seule installation : chaque
 * scénario pose son propre état de départ et le rend à la fin.
 */
test.describe('clôture de l’exploitation', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    let suffix = 'desktop';
    let month = '';
    let stay = { arrival: '', departure: '' };
    let ownerBlock = { start: '', end: '' };
    let feed = '';
    let client = '';
    let reference = '';
    let bookingId = '';
    let documentId = '';

    /** Nuits publiées par le flux externe : `DTEND` est exclusif. */
    const feedNights = () => ({
        start: `${month.replace(/-/g, '')}26`,
        end: `${month.replace(/-/g, '')}29`
    });

    const ics = (uid, from, to, summary) => [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Plateforme//Test//FR',
        'BEGIN:VEVENT',
        `UID:${uid}`,
        `DTSTART;VALUE=DATE:${from}`,
        `DTEND;VALUE=DATE:${to}`,
        `SUMMARY:${summary}`,
        'END:VEVENT',
        'END:VCALENDAR',
        ''
    ].join('\r\n');

    async function publishFeed(request, body, status = 200) {
        const response = await request.post('/webhook/dev/http', {
            form: { url: feed, body, status: String(status) }
        });

        if (!response.ok()) {
            throw new Error(`Fixture de flux refusée (${response.status()}).`);
        }
    }

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Dernier mois disponible dans l'horizon de réservation, avec des
        // jours distincts de ceux des autres scénarios du même mois.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 17 : 16), 1);
        month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-18`, departure: `${month}-24` };
        ownerBlock = { start: `${month}-15`, end: `${month}-16` };
        feed = `https://calendrier.example.test/${suffix}.ics`;
        client = `cloture.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    // --- Calendriers externes ---------------------------------------------------

    test.describe('calendriers externes', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le propriétaire bloque des nuits à la main', async ({ page }) => {
            await page.goto(`/fr/admin/pricing?month=${month}`);
            await fillDate(page, '#start', ownerBlock.start);
            await fillDate(page, '#end', ownerBlock.end);
            await page.selectOption('#kind', 'owner');
            await page.fill('#label', `Famille ${suffix}`);
            await page.click('[data-testid="create-block"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });

        test('un flux externe est déclaré puis synchronisé', async ({ page, request }) => {
            const nights = feedNights();
            await publishFeed(request, ics('plateforme-1', nights.start, nights.end, 'Reserved'));

            await page.goto('/fr/admin/operations');
            await page.fill('[data-testid="import-url"]', feed);
            await page.fill('[data-testid="import-label"]', `Plateforme ${suffix}`);
            await page.selectOption('[data-testid="import-provider"]', 'airbnb');
            await page.click('[data-testid="add-import"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            const row = page.locator('[data-testid="calendar-imports"] tr', { hasText: feed });
            await expect(row).toHaveAttribute('data-import-status', '');

            await page.click('[data-testid="sync-imports"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/operations');
            const synced = page.locator('[data-testid="calendar-imports"] tr', { hasText: feed });
            await expect(synced).toHaveAttribute('data-import-status', 'ok');
            await expect(synced.locator('td').nth(3)).toHaveText('1');
        });

        test('les nuits importées sont réellement fermées au public', async ({ browser }) => {
            const context = await anonymousContext(browser);
            const page = await context.newPage();

            await page.goto(`/fr/availability?month=${month}`);

            // 26, 27 et 28 sont vendues ailleurs ; le 29 reste libre car
            // `DTEND` est exclusif.
            for (const day of ['26', '27', '28']) {
                await expect(page.locator(`[data-day="${month}-${day}"]`))
                    .toHaveAttribute('data-state', 'blocked');
            }
            await expect(page.locator(`[data-day="${month}-29"]`)).toHaveAttribute('data-state', 'free');

            await context.close();
        });

        test('un flux muet ne rouvre aucune nuit', async ({ page, request }) => {
            await publishFeed(request, '', 503);

            await page.goto('/fr/admin/operations');
            await page.click('[data-testid="sync-imports"]');

            // Une erreur réseau se signale, mais ne libère rien : rendre
            // disponibles des nuits déjà vendues serait le pire résultat.
            await expect(page.locator('[data-flash-type="warning"]')).toBeVisible();

            await page.goto('/fr/admin/operations');
            const row = page.locator('[data-testid="calendar-imports"] tr', { hasText: feed });
            await expect(row).toHaveAttribute('data-import-status', 'http_503');

            await page.goto(`/fr/availability?month=${month}`);
            await expect(page.locator(`[data-day="${month}-26"]`)).toHaveAttribute('data-state', 'blocked');
        });

        test('une page HTML n’est pas prise pour un calendrier vide', async ({ page, request }) => {
            await publishFeed(request, '<html><body>Connexion requise</body></html>');

            await page.goto('/fr/admin/operations');
            await page.click('[data-testid="sync-imports"]');
            await expect(page.locator('[data-flash-type="warning"]')).toBeVisible();

            await page.goto('/fr/admin/operations');
            await expect(page.locator('[data-testid="calendar-imports"] tr', { hasText: feed }))
                .toHaveAttribute('data-import-status', 'not_a_calendar');

            await page.goto(`/fr/availability?month=${month}`);
            await expect(page.locator(`[data-day="${month}-26"]`)).toHaveAttribute('data-state', 'blocked');
        });

        test('un flux vers le réseau interne est refusé à la saisie', async ({ page }) => {
            await page.goto('/fr/admin/operations');
            await page.fill('[data-testid="import-url"]', 'http://127.0.0.1/admin/calendar.ics');
            await page.click('[data-testid="add-import"]');

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
            await expect(page.locator('[data-testid="calendar-imports"] tr', { hasText: '127.0.0.1' }))
                .toHaveCount(0);
        });

        test('supprimer le flux libère ses nuits et laisse celles du propriétaire', async ({ page }) => {
            await page.goto('/fr/admin/operations');
            const row = page.locator('[data-testid="calendar-imports"] tr', { hasText: feed });
            const id = await row.getAttribute('data-calendar-import');

            await page.click(`[data-testid="delete-import-${id}"]`);
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto(`/fr/availability?month=${month}`);
            await expect(page.locator(`[data-day="${month}-26"]`)).toHaveAttribute('data-state', 'free');
            // Ce que le propriétaire a décidé lui-même survit à tout import.
            await expect(page.locator(`[data-day="${ownerBlock.start}"]`))
                .toHaveAttribute('data-state', 'blocked');
        });
    });

    // --- Séjour, reporting et litige --------------------------------------------

    test('le voyageur réserve le séjour qui sera compté et discuté', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Clôture');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Bureau');
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

    test.describe('reporting', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le séjour est confirmé', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.click(`[data-booking-reference="${reference}"] a`);

            bookingId = (new URL(page.url())).pathname.split('/').pop();
            await page.selectOption('#status', 'confirmed');
            await page.click('[data-testid="apply-transition"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });

        test('le mois du séjour est compté dans le reporting', async ({ page }) => {
            const [year, number] = month.split('-');

            await page.goto(`/fr/admin/reports?year=${year}&month=${Number(number)}`);

            await expect(page.locator('[data-testid="report-disclaimer"]')).toBeVisible();
            await expect(page.locator('[data-testid="report-summary"]')).toHaveAttribute('data-period', month);

            const row = page.locator(`[data-report-stay="${reference}"]`);
            await expect(row).toBeVisible();
            // 18 → 24 : six nuits, celle du départ ne compte pas.
            await expect(row.locator('[data-report-nights]')).toHaveText('6');

            // Rien n'est encore encaissé : tout est attendu.
            await expect(page.locator('[data-report="outstanding"]')).not.toHaveText('0,00 €');
        });

        test('le reporting s’exporte en classeur lisible', async ({ page }) => {
            const [year, number] = month.split('-');

            const response = await page.request.get(
                `/fr/admin/reports/export.xlsx?year=${year}&month=${Number(number)}`
            );

            expect(response.status()).toBe(200);
            expect(response.headers()['content-type']).toContain('spreadsheetml.sheet');
            expect(response.headers()['content-disposition']).toContain(`secondstay-${month}.xlsx`);
            expect(response.headers()['cache-control']).toContain('no-store');

            const body = await response.body();
            // Signature ZIP : ce que tout tableur cherche en premier.
            expect(body.subarray(0, 2).toString('binary')).toBe('PK');
            expect(body.toString('binary')).toContain('xl/workbook.xml');
        });

        test('le reporting montre l’occupation du stockage', async ({ page }) => {
            await page.goto('/fr/admin/reports');

            const media = page.locator('[data-quota="media"]');
            await expect(media).toBeVisible();
            await expect(media).toHaveAttribute('data-quota-exceeded', 'false');
        });
    });

    test.describe('litiges', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        let disputeId = '';

        test('un litige s’ouvre depuis le séjour', async ({ page }) => {
            await page.goto(`/fr/admin/bookings/${bookingId}`);

            await page.selectOption('[data-testid="dispute-kind"]', 'damage');
            await page.fill('[data-testid="dispute-claimed"]', '120');
            await page.fill('[data-testid="dispute-summary"]', `Store cassé ${suffix}`);
            await page.click('[data-testid="open-dispute"]');

            await expect(page).toHaveURL(/\/fr\/admin\/disputes\/\d+$/);
            disputeId = page.url().split('/').pop();

            await expect(page.locator('[data-testid="dispute-status"]')).toHaveText('Ouvert');
            await expect(page.locator('[data-testid="dispute-history"] [data-dispute-event="opened"]'))
                .toHaveCount(1);
        });

        test('les pièces déjà collectées accompagnent la discussion', async ({ page }) => {
            await page.goto(`/fr/admin/disputes/${disputeId}`);

            const evidence = page.locator('[data-testid="dispute-evidence"]');
            await expect(evidence.locator('[data-evidence="deposit"]')).toBeVisible();
            await expect(evidence.locator('[data-evidence="checkout"]')).toContainText('Non');
            await expect(evidence.locator('[data-evidence="incidents"]')).toContainText('0');
        });

        test('un litige ouvert apparaît dans le « À faire »', async ({ page }) => {
            await page.goto('/fr/admin/operations');

            await expect(page.locator('[data-todo="disputes_open"]')).toBeVisible();
        });

        test('la discussion s’enregistre puis se clôt avec son montant', async ({ page }) => {
            await page.goto(`/fr/admin/disputes/${disputeId}`);
            await page.fill('#note', 'Photos reçues du voyageur');
            await page.click('[data-testid="dispute-comment"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto(`/fr/admin/disputes/${disputeId}`);
            await page.click('[data-testid="dispute-to-discussing"]');
            await expect(page.locator('[data-testid="dispute-status"]')).toHaveText('En discussion');

            await page.fill('#settled', '45');
            await page.fill('#resolution', 'Moitié prise en charge, store remplacé.');
            await page.click('[data-testid="dispute-to-resolved"]');

            await expect(page.locator('[data-testid="dispute-status"]')).toHaveText('Résolu');
            await expect(page.locator('[data-dispute-settled]')).toHaveText('45,00 €');
            await expect(page.locator('[data-testid="dispute-history"] li')).toHaveCount(4);
        });

        test('clore sans explication est refusé', async ({ page }) => {
            await page.goto(`/fr/admin/disputes/${disputeId}`);
            // Le litige est résolu : on le rouvre pour éprouver la règle.
            await page.click('[data-testid="dispute-to-discussing"]');
            await expect(page.locator('[data-testid="dispute-status"]')).toHaveText('En discussion');

            await page.fill('#settled', '10');
            await page.click('[data-testid="dispute-to-resolved"]');

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
            await expect(page.locator('[data-testid="dispute-status"]')).toHaveText('En discussion');
        });

        test('le litige se retrouve depuis la liste filtrée', async ({ page }) => {
            await page.goto('/fr/admin/disputes?status=discussing');

            await expect(page.locator(`[data-dispute-id="${disputeId}"]`)).toBeVisible();

            await page.goto('/fr/admin/disputes?status=resolved');
            await expect(page.locator(`[data-dispute-id="${disputeId}"]`)).toHaveCount(0);
        });
    });

    // --- Quotas ------------------------------------------------------------------

    test.describe('quotas de stockage', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        const oversized = () => ({
            name: 'inventaire.txt',
            mimeType: 'text/plain',
            buffer: Buffer.from('inventaire du logement\n'.repeat(70000), 'utf8')
        });

        async function setDocumentQuota(page, megabytes) {
            await page.goto('/fr/admin/settings?module=quota');
            await page.fill('#setting_quota__documents_mb', String(megabytes));
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        }

        test('un quota atteint refuse l’écriture', async ({ page }) => {
            await setDocumentQuota(page, 1);

            await page.goto(`/fr/admin/bookings/${bookingId}`);
            await page.setInputFiles('#document', oversized());
            await page.selectOption('#document_kind', 'inventory');
            await page.click('[data-testid="upload-document"]');

            const flash = page.locator('[data-flash-type="danger"]');
            await expect(flash).toBeVisible();
            await expect(flash).toContainText('quota');
        });

        test('relever la limite rétablit l’écriture', async ({ page }) => {
            // Zéro veut dire « pas de limite » : c'est la valeur d'origine.
            await setDocumentQuota(page, 0);

            await page.goto(`/fr/admin/bookings/${bookingId}`);
            await page.setInputFiles('#document', oversized());
            await page.selectOption('#document_kind', 'inventory');
            await page.click('[data-testid="upload-document"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            // Le séjour porte déjà son contrat : c'est bien l'inventaire
            // qu'on repère, pas « le dernier de la liste ».
            const uploaded = page.locator('[data-testid="admin-documents"] [data-kind="inventory"]');
            await expect(uploaded).toHaveCount(1);
            documentId = await uploaded.getAttribute('data-document');
            expect(documentId).not.toBeNull();
        });

        test('supprimer le document rend la place mesurée', async ({ page }) => {
            await page.goto('/fr/admin/reports');
            const before = await page.locator('[data-quota="documents"]').innerText();

            await page.goto(`/fr/admin/bookings/${bookingId}`);
            await page.click(`[data-testid="delete-document-${documentId}"]`);
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator('[data-testid="admin-documents"] [data-kind="inventory"]')).toHaveCount(0);

            await page.goto('/fr/admin/reports');
            await expect(page.locator('[data-quota="documents"]')).not.toHaveText(before);
        });
    });
});
