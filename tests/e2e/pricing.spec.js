import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext } from './helpers/fixtures.js';
import { fillDate } from './helpers/forms.js';

/**
 * Scénario critique « séjour traversant plusieurs tarifs → total exact dans
 * quatre locales » (TESTING.md §7).
 *
 * Le total affiché pendant la sélection provient de l'API de devis, donc du
 * même calcul que le total facturé. Les quatre langues affichent le même
 * montant, formaté selon leurs propres règles.
 */
test.describe('disponibilités et tarifs', () => {
    test.describe.configure({ mode: 'serial' });

    const NIGHT_LOW = 120_00;
    const NIGHT_HIGH = 250_00;
    const CLEANING = 100_00;

    // Chaque projet travaille sur son propre mois : les scénarios restent
    // indépendants sur une installation partagée.
    let month = '';
    let firstNight = '';
    let stay = { arrival: '', departure: '' };
    let highNights = [];

    test.beforeAll(({}, testInfo) => {
        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Deux mois pour le projet desktop, trois pour le mobile.
        base.setUTCMonth(base.getUTCMonth() + (testInfo.project.name === 'mobile-safari' ? 3 : 2), 1);

        month = base.toISOString().slice(0, 7);
        // Le séjour commence le 8 : jamais sur un bord de grille.
        firstNight = `${month}-08`;
        stay = { arrival: `${month}-08`, departure: `${month}-15` };
        highNights = [`${month}-08`, `${month}-09`, `${month}-10`];
    });

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le tarif de référence et le ménage sont configurés', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/settings?module=pricing');
            await page.fill('#setting_pricing__default_night_price', '120.00');
            await page.fill('#setting_pricing__cleaning_price', '100.00');
            await page.selectOption('#setting_pricing__cleaning_mode', 'mandatory');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/settings?module=booking');
            await page.fill('#setting_booking__min_nights', '2');
            await page.fill('#setting_booking__max_guests', '6');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });

        test('un tarif de haute saison est appliqué à trois nuits', async ({ page }) => {
            test.slow();

            await page.goto(`/fr/admin/pricing?month=${month}`);
            await expect(page.locator('[data-testid="pricing-month"]')).not.toBeEmpty();

            await fillDate(page, '#from', highNights[0]);
            await fillDate(page, '#to', highNights[2]);
            await page.fill('#price', '250,00');
            await page.fill('#note', 'Haute saison');
            await page.click('[data-testid="apply-rates"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto(`/fr/admin/pricing?month=${month}`);
            for (const night of highNights) {
                await expect(page.locator(`[data-admin-day="${night}"]`)).toHaveAttribute('data-override', '1');
            }
            // La nuit suivante reste au tarif de référence.
            await expect(page.locator(`[data-admin-day="${month}-11"]`)).toHaveAttribute('data-override', '0');
        });

        test('une indisponibilité bloque exactement ses nuits', async ({ page }) => {
            test.slow();

            await page.goto(`/fr/admin/pricing?month=${month}`);
            const before = await page.locator('[data-block-id]').count();

            await fillDate(page, '#start', `${month}-20`);
            await fillDate(page, '#end', `${month}-21`);
            await page.selectOption('#kind', 'owner');
            await page.fill('#label', 'Séjour propriétaire');
            await page.click('[data-testid="create-block"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator('[data-block-id]')).toHaveCount(before + 1);

            await page.goto(`/fr/admin/pricing?month=${month}`);
            await expect(page.locator(`[data-admin-day="${month}-20"]`)).toHaveAttribute('data-state', 'blocked');
            await expect(page.locator(`[data-admin-day="${month}-21"]`)).toHaveAttribute('data-state', 'blocked');
            // La nuit du 22 reste libre : un blocage se termine sur sa dernière nuit.
            await expect(page.locator(`[data-admin-day="${month}-22"]`)).toHaveAttribute('data-state', 'free');
        });
    });

    // --- Calendrier public ---------------------------------------------------

    test('le calendrier public affiche le prix réel de chaque nuit', async ({ page }) => {
        await page.goto(`/fr/availability?month=${month}`);

        await expect(page.locator('[data-testid="stay-rules"]')).toBeVisible();
        await expect(page.locator('[data-rule="min-nights"]')).toContainText('2');

        // Une nuit de haute saison et une nuit ordinaire, côte à côte.
        const high = page.locator(`[data-day="${highNights[0]}"] [data-night-price]`);
        const low = page.locator(`[data-day="${month}-11"] [data-night-price]`);

        await expect(high).toHaveText(/250/);
        await expect(low).toHaveText(/120/);

        // Les nuits bloquées ne sont pas sélectionnables.
        await expect(page.locator(`[data-day="${month}-20"]`)).toBeDisabled();
        await expect(page.locator(`[data-day="${month}-20"]`)).toHaveAttribute('data-state', 'blocked');
    });

    test('un séjour traversant deux tarifs affiche le total exact', async ({ page }) => {
        test.slow();

        await page.goto(`/fr/availability?month=${month}`);
        await page.waitForSelector('html[data-js-ready="true"]');

        await page.click(`[data-day="${stay.arrival}"]`);
        await expect(page.locator('[data-testid="quote"]')).toBeHidden();

        await page.click(`[data-day="${stay.departure}"]`);
        await expect(page.locator('[data-testid="quote"]')).toBeVisible();

        // 3 nuits à 250 € + 4 nuits à 120 € + 100 € de ménage.
        const expected = 3 * NIGHT_HIGH + 4 * NIGHT_LOW + CLEANING;

        await expect(page.locator('[data-quote-nights]')).toHaveText('7');
        await expect(page.locator('[data-quote-total]')).toHaveAttribute('data-cents', String(expected));

        const rendered = await page.locator('[data-quote-total]').textContent();
        expect(rendered.replace(/\D+/gu, '')).toBe(String(expected));

        // Le total n'est jamais une moyenne : 7 × prix moyen ne tombe pas juste.
        expect(expected).not.toBe(7 * Math.round((3 * NIGHT_HIGH + 4 * NIGHT_LOW) / 7) + CLEANING);
    });

    test('le même séjour donne le même total dans les quatre langues', async ({ page }) => {
        test.slow();

        const expected = 3 * NIGHT_HIGH + 4 * NIGHT_LOW + CLEANING;
        const rendered = [];

        for (const locale of ['fr', 'en', 'nl', 'de']) {
            await page.goto(`/${locale}/availability?month=${month}`);
            await page.waitForSelector('html[data-js-ready="true"]');

            await page.click(`[data-day="${stay.arrival}"]`);
            await page.click(`[data-day="${stay.departure}"]`);

            await expect(page.locator('[data-testid="quote"]')).toBeVisible();
            await expect(page.locator('[data-quote-total]')).toHaveAttribute('data-cents', String(expected));

            const text = await page.locator('[data-quote-total]').textContent();
            // Les chiffres sont identiques dans les quatre langues.
            expect(text.replace(/\D+/gu, ''), locale).toBe(String(expected));
            rendered.push(text.trim());

            // Le mois est écrit dans la langue de la page.
            await expect(page.locator('[data-testid="calendar-month"]')).not.toBeEmpty();
        }

        // Le formatage suit réellement la locale.
        expect(new Set(rendered).size).toBeGreaterThan(1);
    });

    test('l’API de devis renvoie le même total que la page', async ({ request }) => {
        const response = await request.get(
            `/api/quote?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`
        );

        expect(response.status()).toBe(200);
        const body = await response.json();

        expect(body.ok).toBe(true);
        expect(body.quote.night_count).toBe(7);
        expect(body.quote.accommodation_cents).toBe(3 * NIGHT_HIGH + 4 * NIGHT_LOW);
        expect(body.quote.cleaning_cents).toBe(CLEANING);
        expect(body.quote.total_cents).toBe(3 * NIGHT_HIGH + 4 * NIGHT_LOW + CLEANING);

        // Le détail nuit par nuit est explicite.
        expect(body.quote.nights).toHaveLength(7);
        expect(body.quote.nights[0].price_cents).toBe(NIGHT_HIGH);
        expect(body.quote.nights[0].is_override).toBe(true);
        expect(body.quote.nights[6].price_cents).toBe(NIGHT_LOW);
        expect(body.quote.nights[6].is_override).toBe(false);
    });

    test('un séjour indisponible est refusé avec un message traduit', async ({ request }) => {
        const response = await request.get(
            `/api/quote?arrival=${month}-19&departure=${month}-22&adults=2`
        );

        const body = await response.json();

        expect(body.ok).toBe(false);
        expect(body.conflicts).toContain(`${month}-20`);
        expect(body.errors.length).toBeGreaterThan(0);
        // Une phrase traduite, jamais une clé technique.
        for (const message of body.errors) {
            expect(message).not.toContain('booking.error.');
        }
        // Le prix reste calculé : le visiteur sait ce que coûterait le séjour.
        expect(body.quote.total_cents).toBeGreaterThan(0);
    });

    test('les règles de séjour sont appliquées côté serveur', async ({ request }) => {
        // Une seule nuit alors que le minimum est de deux.
        const short = await (await request.get(
            `/api/quote?arrival=${month}-08&departure=${month}-09&adults=2`
        )).json();
        expect(short.ok).toBe(false);
        expect(short.errors.length).toBeGreaterThan(0);

        // Trop de voyageurs pour la capacité configurée.
        const crowded = await (await request.get(
            `/api/quote?arrival=${stay.arrival}&departure=${stay.departure}&adults=9`
        )).json();
        expect(crowded.ok).toBe(false);

        // Une date incohérente ne fait jamais tomber l'API.
        const nonsense = await request.get('/api/quote?arrival=hier&departure=demain');
        expect(nonsense.status()).toBe(200);
        expect((await nonsense.json()).ok).toBe(false);
    });

    test('la page des tarifs publie les montants et les règles', async ({ page }) => {
        await page.goto('/de/rates');

        await expect(page.locator('[data-testid="rate-summary"]')).toBeVisible();
        await expect(page.locator('[data-rate="night"]')).toContainText(/120/);
        await expect(page.locator('[data-rate="cleaning"]')).toContainText(/100/);
        await expect(page.locator('[data-testid="stay-rules"] [data-rule="times"]')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    });

    test('le calendrier reste consultable sans compte', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const visitor = await context.newPage();

        const response = await visitor.goto(`/fr/availability?month=${month}`);
        expect(response?.status()).toBe(200);
        await expect(visitor.locator('[data-calendar]')).toBeVisible();

        // Aucune information interne n'est publiée.
        const html = await visitor.content();
        expect(html).not.toContain('Séjour propriétaire');
        expect(html).not.toContain('Haute saison');

        await context.close();
    });

    test('un mois aberrant ne fait pas tomber la page', async ({ request }) => {
        for (const value of ['abcd-ef', '2026-13', '0000-00', '1900-01', '9999-99']) {
            const response = await request.get(`/fr/availability?month=${encodeURIComponent(value)}`);
            expect(response.status(), value).toBe(200);
        }
    });
});
