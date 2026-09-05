import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait, submitSignUp } from './helpers/fixtures.js';
import { agendaPage, purgePages, storePage } from './helpers/fixtures-http.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « fixtures HTML + fake LLM »
 * (ROADMAP.md itération 13, SPECIFICATIONS.md §56 à §59).
 *
 * Le pipeline est joué en entier : de vraies pages HTML sont servies au
 * produit, extraites, placées dans le prompt gardé, lues par le modèle
 * factice, validées, stockées, puis filtrées sur les dates exactes du séjour.
 * Rien n'est simulé au milieu — c'est le seul moyen de prouver que la chaîne
 * tient d'un bout à l'autre.
 */
test.describe('contenu local', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    const AGENDA = 'https://agenda.example.test/juillet';
    const OFFICE = 'https://office.example.test/sorties';
    const ABSENT = 'https://absent.example.test/page';
    const INTERNAL = 'http://127.0.0.1/admin';

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let reference = '';
    let month = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Mêmes mois que le scénario de conformité, mais des nuits distinctes :
        // deux séjours peuvent cohabiter dans un mois, pas sur une même nuit.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 5 : 4), 1);
        month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-20`, departure: `${month}-27` };
        client = `local.${suffix}@example.test`;

        await clearRateLimits(browser);
    });

    // --- Configuration -------------------------------------------------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('désactivé, rien n’est produit', async ({ page }) => {
            test.slow();

            // Le scénario est rejouable : les deux projets partagent la même
            // installation, il repart donc d'un état qu'il pose lui-même.
            await page.goto('/fr/admin/settings?module=llm');
            await page.uncheck('#setting_llm__enabled');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/local');
            for (const id of await page.locator('[data-source-id]').evaluateAll(
                (nodes) => nodes.map((node) => node.getAttribute('data-source-id'))
            )) {
                await page.locator(`[data-testid="source-delete-${id}"]`).click();
                await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
                await page.goto('/fr/admin/local');
            }

            await expect(page.locator('[data-testid="local-enabled"]'))
                .toHaveAttribute('data-enabled', 'false');
            await expect(page.locator('[data-testid="local-sources-empty"]')).toBeVisible();
        });

        test('le contenu local est activé et la fenêtre élargie', async ({ page }) => {
            await page.goto('/fr/admin/settings?module=llm');
            await page.check('#setting_llm__enabled');
            // La fenêtre couvre le séjour de test, qui est à plusieurs mois.
            await page.fill('#setting_llm__window_weeks', '26');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/local');
            await expect(page.locator('[data-testid="local-enabled"]'))
                .toHaveAttribute('data-enabled', 'true');
            await expect(page.locator('[data-testid="local-enabled"]'))
                .toHaveAttribute('data-provider', 'fake');
        });

        test('une adresse interne est refusée dès la saisie', async ({ page }) => {
            await page.goto('/fr/admin/local');
            await page.fill('#url', INTERNAL);
            await page.locator('[data-testid="local-source-add"]').click();

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
            await page.goto('/fr/admin/local');
            await expect(page.locator('[data-testid="local-sources-empty"]')).toBeVisible();
        });

        test('les sources sont enregistrées', async ({ page }) => {
            test.slow();

            for (const [url, label] of [
                [AGENDA, 'Agenda communal'],
                [OFFICE, 'Office de tourisme'],
                [ABSENT, 'Site injoignable']
            ]) {
                await page.goto('/fr/admin/local');
                await page.fill('#url', url);
                await page.fill('#label', label);
                await page.locator('[data-testid="local-source-add"]').click();
                await expect(page.locator('[data-flash-type]')).toBeVisible();
            }

            await page.goto('/fr/admin/local');
            await expect(page.locator('[data-testid="local-sources"] [data-source-id]')).toHaveCount(3);

            // La même adresse deux fois n'est pas ajoutée deux fois.
            await page.fill('#url', AGENDA);
            await page.locator('[data-testid="local-source-add"]').click();
            await expect(page.locator('[data-flash-type="warning"]')).toBeVisible();
            await page.goto('/fr/admin/local');
            await expect(page.locator('[data-testid="local-sources"] [data-source-id]')).toHaveCount(3);
        });

        test('le prompt est proposé à partir de la localisation', async ({ page }) => {
            await page.goto('/fr/admin/local');
            await page.locator('[data-testid="local-prompt-suggest"]').click();

            // La proposition est mise dans le formulaire, pas enregistrée.
            await expect(page.locator('#prompt')).not.toHaveValue('');
            const suggested = await page.locator('#prompt').inputValue();
            expect(suggested).toContain('Maison des Pins');

            await page.locator('[data-testid="local-prompt-save"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/local');
            await expect(page.locator('#prompt')).toHaveValue(suggested);
        });
    });

    // --- Génération -------------------------------------------------------------------

    test.describe('génération', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les pages sont lues et les activités produites', async ({ page, request }) => {
            test.slow();

            await purgePages(request);
            await storePage(request, AGENDA, agendaPage('Agenda communal', [
                // Avant l'arrivée : stockée, mais jamais affichée.
                { title: 'Marché de printemps', start: `${month}-12` },
                { title: 'Festival des lanternes', start: `${month}-21`, end: `${month}-23`, booking: true },
                { title: 'Marché de Sainte-Anne', start: `${month}-25` }
            ]));
            await storePage(request, OFFICE, agendaPage('Office de tourisme', [
                { title: 'Fest-noz du bourg', start: `${month}-26` },
                // Bien après le départ.
                { title: 'Randonnée des falaises', start: `${month}-28` }
            ]));

            await page.goto('/fr/admin/local');
            await page.locator('[data-testid="local-test"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/local');
            // Deux sources répondent, la troisième est refusée à la sortie.
            const sources = page.locator('[data-testid="local-sources"] [data-source-id]');
            await expect(sources.nth(0).locator('[data-source-status]'))
                .toHaveAttribute('data-source-status', 'ok');
            await expect(sources.nth(1).locator('[data-source-status]'))
                .toHaveAttribute('data-source-status', 'ok');
            await expect(sources.nth(2).locator('[data-source-status]'))
                .toHaveAttribute('data-source-status', 'blocked');

            await expect(page.locator('[data-testid="local-generations"] [data-generation-status]').first())
                .toHaveAttribute('data-generation-status', 'done');
        });

        test('une source désactivée n’est plus consultée', async ({ page }) => {
            await page.goto('/fr/admin/local');
            const source = page.locator('[data-testid="local-sources"] [data-source-id]').nth(2);
            const id = await source.getAttribute('data-source-id');

            await page.locator(`[data-testid="source-toggle-${id}"]`).click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/local');
            await expect(page.locator(`[data-source-id="${id}"]`))
                .toHaveAttribute('data-source-active', 'false');
        });
    });

    // --- Le voyageur ----------------------------------------------------------------------

    test('le voyageur réserve et retrouve les activités de ses dates', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Local');
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

        await context.close();
    });

    test.describe('rafraîchissement', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le séjour entre dans la fenêtre et son contenu est produit', async ({ page }) => {
            await page.goto('/fr/admin/local');
            await expect(page.locator('[data-testid="local-due"]')).not.toHaveAttribute('data-due', '0');

            await page.locator('[data-testid="local-refresh"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
        });
    });

    test('la page du séjour n’affiche que les activités de ses dates', async ({ browser }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}`);

        const activities = page.locator('[data-testid="stay-activities"]');
        await expect(activities).toBeVisible();

        // Dans les dates : affichées.
        await expect(activities).toContainText('Festival des lanternes');
        await expect(activities).toContainText('Marché de Sainte-Anne');
        await expect(activities).toContainText('Fest-noz du bourg');

        // Hors dates : jamais.
        await expect(activities).not.toContainText('Marché de printemps');
        await expect(activities).not.toContainText('Randonnée des falaises');

        // Groupées selon qu'il faut réserver ou non.
        const bookAhead = page.locator('[data-activity-group="book_ahead"] + ul');
        await expect(bookAhead).toContainText('Festival des lanternes');
        await expect(bookAhead).not.toContainText('Fest-noz du bourg');

        // Chaque activité cite sa source et sa date de vérification.
        const first = page.locator('[data-activity]').first();
        await expect(first.locator('[data-activity-source]')).toBeVisible();
        await expect(first).toContainText('vérifié le');

        await context.close();
    });

    test('les suggestions suivent la langue demandée', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();
        await signInAndWait(page, client, PASSWORD);

        // Le contenu allemand n'a pas été produit : la page reste utilisable,
        // simplement sans suggestions.
        await page.goto(`/de/stay/${reference}`);
        await expect(page.locator('[data-testid="stay-page"]')).toBeVisible();
        await expect(page.locator('[data-testid="stay-activities"]')).toHaveCount(0);

        await context.close();
    });

    // --- Cloisonnement ------------------------------------------------------------------

    test('l’écran de contenu local est fermé aux visiteurs', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        expect((await page.request.get('/fr/admin/local')).status()).toBe(403);

        await context.close();
    });
});
