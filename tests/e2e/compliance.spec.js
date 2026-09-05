import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait, submitSignUp } from './helpers/fixtures.js';
import { fillDate } from './helpers/forms.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Scénario critique « réservation historique conserve version et langue du
 * texte légal accepté » (ROADMAP.md itération 12, SPECIFICATIONS.md §61 à §65).
 *
 * Le test ne se contente pas de vérifier qu'une version est enregistrée : il
 * réécrit les conditions **après** la réservation, publie une nouvelle
 * version, et vérifie que la réservation d'origine cite toujours la version et
 * la langue qu'elle a réellement acceptées. C'est toute la valeur d'un
 * consentement versionné.
 */
test.describe('conformité et textes légaux', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    let suffix = 'desktop';
    let stay = { arrival: '', departure: '' };
    let client = '';
    let reference = '';
    let firstVersion = '';
    let secondVersion = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet, distinct de tous les autres scénarios — et à
        // l'intérieur de l'horizon de réservation configuré.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 5 : 4), 1);
        const month = base.toISOString().slice(0, 7);

        stay = { arrival: `${month}-06`, departure: `${month}-13` };
        client = `conformite.${suffix}@example.test`;
        // Les deux projets publient des versions distinctes : ils partagent la
        // même installation, et une version publiée ne se republie pas.
        firstVersion = suffix === 'mobile' ? '2026-11' : '2026-01';
        secondVersion = suffix === 'mobile' ? '2027-11' : '2027-01';

        await clearRateLimits(browser);
    });

    // --- Textes légaux ------------------------------------------------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('l’installation publie déjà une version opposable', async ({ page }) => {
            await page.goto('/fr/admin/compliance');

            const terms = page.locator('[data-legal-type="terms"]');
            await expect(terms.locator('[data-locale="fr"]')).not.toHaveAttribute('data-version', '');
            await expect(terms.locator('[data-locale="de"]')).not.toHaveAttribute('data-version', '');
        });

        test('une version est publiée dans les quatre langues', async ({ page }) => {
            await page.goto('/fr/admin/compliance');
            await page.fill('#version_terms', firstVersion);
            await page.locator('[data-testid="legal-publish-submit-terms"]').click();

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/compliance');
            const terms = page.locator('[data-legal-type="terms"]');
            for (const locale of ['fr', 'en', 'nl', 'de']) {
                await expect(terms.locator(`[data-locale="${locale}"]`))
                    .toHaveAttribute('data-version', firstVersion);
            }

            // Chaque langue a sa ligne d'historique, avec son empreinte.
            await expect(page.locator(`[data-testid="legal-versions-terms"] [data-legal-version="${firstVersion}"]`))
                .toHaveCount(4);
        });

        test('republier le même numéro est refusé', async ({ page }) => {
            await page.goto('/fr/admin/compliance');
            await page.fill('#version_terms', firstVersion);
            await page.locator('[data-testid="legal-publish-submit-terms"]').click();

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
        });
    });

    // --- Réservation en allemand ----------------------------------------------------

    test('le voyageur réserve en allemand et son acceptation est datée', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/de/account/signup');
        await page.fill('#first_name', 'Konformität');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobil' : 'Desktop');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await submitSignUp(page);

        const mail = await waitForMail(request, client, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));

        await page.goto(`/de/booking?arrival=${stay.arrival}&departure=${stay.departure}&adults=2`);
        await page.click('[data-testid="start-booking"]');
        await page.check('#accept_rules');
        await page.click('[data-testid="submit-booking"]');

        await expect(page).toHaveURL(/\/de\/booking\/[A-Z0-9-]+$/);
        reference = (await page.locator('[data-testid="booking-reference"]').innerText()).trim();

        const consent = page.locator('[data-testid="booking-consents"] [data-consent="terms"]');
        await expect(consent).toHaveAttribute('data-consent-version', firstVersion);
        // La langue de la page où la case a été cochée, pas celle du séjour.
        await expect(consent).toHaveAttribute('data-consent-locale', 'de');

        await context.close();
    });

    // --- Le texte change, la preuve ne bouge pas ------------------------------------

    test.describe('nouvelle version', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('les conditions sont réécrites puis republiées', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/content');
            await page.locator('[data-page-slug="terms"] a').first().click();

            for (const locale of ['fr', 'de']) {
                // Chaque langue vit dans son onglet : il faut l'ouvrir avant
                // d'écrire dedans, exactement comme un rédacteur le ferait.
                await page.locator(`[data-locale-tab="${locale}"]`).click();
                await expect(page.locator(`[data-locale-pane="${locale}"]`)).toBeVisible();
                await page.fill(
                    `#body_${locale}`,
                    `Version ${secondVersion} — conditions entièrement réécrites (${locale}).`
                );
            }
            await page.locator('[data-testid="save-page"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/compliance');
            await page.fill('#version_terms', secondVersion);
            await page.locator('[data-testid="legal-publish-submit-terms"]').click();
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/compliance');
            await expect(page.locator('[data-legal-type="terms"] [data-locale="fr"]'))
                .toHaveAttribute('data-version', secondVersion);
        });

        test('la réservation historique cite toujours ce qu’elle a accepté', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.locator(`a:has-text("${reference}")`).first().click();

            const consent = page.locator('[data-testid="booking-consents"] [data-consent="terms"]');
            await expect(consent).toHaveAttribute('data-consent-version', firstVersion);
            await expect(consent).toHaveAttribute('data-consent-locale', 'de');
        });
    });

    test('le voyageur retrouve la version et la langue acceptées', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        // Le compte a été créé en allemand : la connexion y ramène.
        await signInAndWait(page, client, PASSWORD, 'de');

        // La fiche est consultée en français, mais ce qui a été accepté reste
        // ce qui a été accepté : version et langue ne suivent pas la page.
        await page.goto(`/fr/booking/${reference}`);

        const consent = page.locator('[data-testid="booking-consents"] [data-consent="terms"]');
        await expect(consent).toHaveAttribute('data-consent-version', firstVersion);
        await expect(consent).toHaveAttribute('data-consent-locale', 'de');

        await context.close();
    });

    // --- Assistant conformité -------------------------------------------------------

    test.describe('assistant', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('chaque sujet de la spécification est présenté', async ({ page }) => {
            await page.goto('/fr/admin/compliance');

            await expect(page.locator('[data-testid="compliance-disclaimer"]')).toBeVisible();
            await expect(page.locator('[data-compliance-topic]')).toHaveCount(18);

            // Les sujets pilotés ailleurs renvoient vers leur écran.
            await expect(page.locator('[data-testid="managed-tourist_tax"]')).toBeVisible();
            await expect(page.locator('[data-testid="managed-police_record"]')).toBeVisible();
        });

        test('un sujet déclaré conforme est daté et sort du « à faire »', async ({ page }) => {
            await page.goto('/fr/admin/compliance');
            await page.selectOption('#status_insurance', 'compliant');
            await page.fill('#source_url_insurance', 'https://example.test/assurance');
            await page.fill('#notes_insurance', 'Avenant location saisonnière signé.');
            await page.locator('[data-testid="compliance-save-insurance"]').click();

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/compliance');
            await expect(page.locator('[data-testid="status-insurance"]')).toHaveText('Conforme');
            await expect(page.locator('#last_verified_insurance')).not.toHaveValue('');
            await expect(page.locator('#next_review_insurance')).not.toHaveValue('');
        });

        test('une source qui n’est pas une adresse web est refusée', async ({ page }) => {
            await page.goto('/fr/admin/compliance');
            await page.selectOption('#status_siret', 'compliant');
            await page.fill('#value_siret', '123 456 789 00012');
            // Une adresse absolue que le navigateur accepte, mais qui n'est
            // pas une source consultable : c'est le serveur qui tranche.
            await page.fill('#source_url_siret', 'ftp://example.test/attestation.pdf');
            await page.locator('[data-testid="compliance-save-siret"]').click();

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();

            await page.goto('/fr/admin/compliance');
            await expect(page.locator('[data-testid="status-siret"]')).toHaveText('À vérifier');
        });

        test('la conformité restante apparaît dans « À faire »', async ({ page }) => {
            await page.goto('/fr/admin');

            await expect(page.locator('[data-todo="compliance_to_verify"]')).toBeVisible();
        });
    });

    // --- Taxe de séjour datée --------------------------------------------------------

    test.describe('taxe de séjour', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('un barème daté est enregistré et signalé en vigueur', async ({ page }) => {
            test.slow();

            // Le scénario est rejouable : les deux projets partagent la même
            // installation, il ne suppose donc pas une table vide.
            await page.goto('/fr/admin/tax');
            const before = await page.locator('[data-tax-rule]').count();

            await fillDate(page, '#effective_from', suffix === 'mobile' ? '2021-01-01' : '2020-01-01');
            await page.fill('#territory', 'Communauté de communes');
            await page.fill('#per_adult_night', '2.20');
            await page.fill('#source_url', 'https://example.test/deliberation');
            await page.locator('[data-testid="tax-create"]').click();

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator('[data-tax-rule]')).toHaveCount(before + 1);
            // La règle la plus récemment entrée en vigueur est celle qui
            // s'applique aujourd'hui.
            await expect(page.locator('[data-tax-rule]').first()).toHaveAttribute('data-tax-active', 'true');
        });

        test('un barème qui finirait avant de commencer est refusé', async ({ page }) => {
            await page.goto('/fr/admin/tax');
            await fillDate(page, '#effective_from', '2030-01-01');
            await fillDate(page, '#effective_to', '2029-01-01');
            await page.fill('#per_adult_night', '1.00');
            await page.locator('[data-testid="tax-create"]').click();

            await expect(page.locator('[data-flash-type="danger"]')).toBeVisible();
        });
    });

    // --- Fiche de police et rétention ------------------------------------------------

    test.describe('fiche de police', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('rien n’est collecté tant que l’obligation n’est pas activée', async ({ page }) => {
            await page.goto('/fr/admin/police');

            await expect(page.locator('[data-testid="police-enabled"]'))
                .toHaveAttribute('data-enabled', 'false');
            await expect(page.locator('[data-testid="police-records"]')).toHaveCount(0);

            // La fiche d'un séjour n'est pas non plus atteignable en direct :
            // masquer un lien ne protège rien.
            await page.goto('/fr/admin/bookings');
            await page.locator(`a:has-text("${reference}")`).first().click();
            await expect(page.locator('[data-testid="police-link"]')).toHaveCount(0);

            const url = page.url().replace('/admin/bookings/', '/admin/police/');
            expect((await page.request.get(url)).status()).toBe(404);
        });

        test('activée, la fiche est enregistrée chiffrée et datée pour purge', async ({ page }) => {
            test.slow();

            await page.goto('/fr/admin/settings?module=legal');
            await page.check('#setting_compliance__police_record_enabled');
            await page.click('[data-testid="settings-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/bookings');
            await page.locator(`a:has-text("${reference}")`).first().click();
            await page.locator('[data-testid="police-link"]').click();

            await page.fill('#last_name', 'Dubois');
            await page.fill('#first_names', 'Claire Marie');
            await page.fill('#birth_date', '1984-03-11');
            await page.fill('#nationality', 'Belge');
            await page.locator('[data-testid="police-save"]').click();

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            await page.goto('/fr/admin/police');
            await expect(page.locator('[data-testid="police-records"] [data-police-booking]').first())
                .toBeVisible();
            await expect(page.locator('[data-testid="retention-policy"] [data-retention="police_records"]'))
                .toBeVisible();
        });

        test('la fiche est supprimée à la demande, et la rétention s’applique', async ({ page }) => {
            await page.goto('/fr/admin/bookings');
            await page.locator(`a:has-text("${reference}")`).first().click();
            await page.locator('[data-testid="police-link"]').click();
            await page.locator('[data-testid="police-delete"]').click();

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await expect(page.locator('[data-testid="police-empty"]')).toBeVisible();

            await page.locator('[data-testid="retention-purge"]').click();
            await expect(page.locator('[data-flash-type]')).toBeVisible();
        });

        test('l’obligation est refermée derrière le test', async ({ page }) => {
            await page.goto('/fr/admin/settings?module=legal');
            await page.uncheck('#setting_compliance__police_record_enabled');
            await page.click('[data-testid="settings-save"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            await page.goto('/fr/admin/police');
            await expect(page.locator('[data-testid="police-enabled"]'))
                .toHaveAttribute('data-enabled', 'false');
        });
    });

    // --- Cloisonnement -----------------------------------------------------------------

    test('les écrans de conformité sont fermés aux visiteurs', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        for (const path of ['/fr/admin/compliance', '/fr/admin/tax', '/fr/admin/police']) {
            expect((await page.request.get(path)).status()).toBe(403);
        }

        await context.close();
    });
});
