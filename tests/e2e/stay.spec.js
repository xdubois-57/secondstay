import { expect, test } from '@playwright/test';
import { ADMIN_STATE_FILE, anonymousContext, clearRateLimits, signInAndWait } from './helpers/fixtures.js';
import { pngBuffer } from './helpers/image.js';
import { linkFrom, waitForMail } from './helpers/mailbox.js';

/**
 * Bascule un interrupteur et **confirme sur place** son nouvel état.
 *
 * L'écran du livret porte huit blocs de six champs : sur un petit écran, la
 * page est longue et le navigateur la remet en page pendant que le script
 * clique. Playwright rapporte alors « l'élément n'est pas stable », ou pire,
 * un clic qui ne change rien — et l'échec s'affiche trois lignes plus bas, sur
 * une assertion qui n'a rien à voir (TESTING.md §31.5 et §32.5).
 *
 * Attendre le marqueur de fin de script, amener l'interrupteur sous le doigt,
 * puis vérifier qu'il a bougé rend l'interaction déterministe et fait échouer
 * le scénario là où le problème se produit.
 */
async function toggle(page, selector, expected) {
    await page.waitForSelector('html[data-js-ready="true"]');

    const control = page.locator(selector);
    await control.scrollIntoViewIfNeeded();
    await expect(control).toBeEnabled();

    if (expected) {
        await control.check();
    } else {
        await control.uncheck();
    }

    await expect(control).toBeChecked({ checked: expected });
}

/**
 * Scénario critique « mobile offline → informations utiles dans langue
 * choisie » (ROADMAP.md itération 10, SPECIFICATIONS.md §44 à §47).
 *
 * Le test coupe réellement le réseau du navigateur après une première visite,
 * puis vérifie que le livret d'accueil reste lisible — et que la fiche de
 * réservation, elle, ne l'est pas : les montants et les documents n'ont rien
 * à faire dans un cache.
 */
test.describe('mon séjour', () => {
    test.describe.configure({ mode: 'serial' });

    const PASSWORD = 'Marée-Haute-2026!';

    let suffix = 'desktop';
    let stayDates = { arrival: '', departure: '' };
    let client = '';
    let reference = '';
    let guestUrl = '';

    test.beforeAll(async ({ browser }, testInfo) => {
        suffix = testInfo.project.name === 'mobile-safari' ? 'mobile' : 'desktop';

        // Ce groupe est `serial` : un seul échec le rejoue **en entier**,
        // inscription et réservation comprises. Avec une adresse et des dates
        // fixes, la reprise repartait sur un compte déjà inscrit et sur un
        // séjour déjà réservé — l'inscription échouait, `waitForMail`
        // retrouvait le courriel de la tentative précédente, la confirmation
        // ne connectait personne, et le scénario attendait six minutes un
        // bouton que la page ne propose ni à un visiteur anonyme ni sur des
        // dates indisponibles. Une reprise ne réparait donc rien : elle
        // remplaçait un échec net par un blocage long.
        //
        // Chaque tentative repart donc d'un compte neuf et d'un mois libre.
        // Le décalage est de deux mois par reprise pour que les deux projets,
        // qui partent de 14 et 15, ne se croisent jamais.
        const base = new Date();
        base.setUTCHours(0, 0, 0, 0);
        // Un mois par projet et par tentative, distinct de tous les autres
        // scénarios.
        base.setUTCMonth(base.getUTCMonth() + (suffix === 'mobile' ? 15 : 14) + testInfo.retry * 2, 1);
        const month = base.toISOString().slice(0, 7);

        stayDates = { arrival: `${month}-09`, departure: `${month}-16` };
        client = `stay.${suffix}${testInfo.retry > 0 ? `.r${testInfo.retry}` : ''}@example.test`;

        await clearRateLimits(browser);
    });

    // --- Livret d'accueil --------------------------------------------------------

    test.describe('administration', () => {
        test.use({ storageState: ADMIN_STATE_FILE });

        test('le livret d’accueil est rempli en français et en allemand', async ({ page }) => {
            test.slow();

            for (const [locale, texts] of [
                ['fr', { welcome: 'Bienvenue à la Maison des Pins', waste: 'Le tri se fait au bout de la rue.' }],
                ['de', { welcome: 'Willkommen im Haus der Pinien', waste: 'Die Mülltrennung ist am Ende der Straße.' }]
            ]) {
                await page.goto(`/fr/admin/stay?locale=${locale}`);
                await page.fill('#title_welcome', texts.welcome);
                await page.fill('#body_welcome', texts.welcome + ' ' + texts.waste);
                await page.fill('#title_waste', locale === 'fr' ? 'Déchets' : 'Abfall');
                await page.fill('#body_waste', texts.waste);
                await page.click('[data-testid="stay-save"]');

                await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            }

            // La complétude nomme ce qui manque : NL et EN ne sont pas remplis.
            await page.goto('/fr/admin/stay');
            const row = page.locator('[data-testid="stay-completeness"] [data-block="welcome"]');
            await expect(row.locator('[data-locale="fr"]')).toHaveAttribute('data-filled', 'true');
            await expect(row.locator('[data-locale="de"]')).toHaveAttribute('data-filled', 'true');
            await expect(row.locator('[data-locale="nl"]')).toHaveAttribute('data-filled', 'false');
        });

        test('les codes d’accès sont enregistrés chiffrés', async ({ page }) => {
            await page.goto('/fr/admin/stay');
            await page.fill('#secret_wifi_password', 'sapin-2026');
            await page.fill('#secret_key_box', '4712');
            await page.click('[data-testid="stay-secrets-save"]');

            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            // Le champ ne réaffiche jamais la valeur : seul un aperçu masqué.
            await page.goto('/fr/admin/stay');
            await expect(page.locator('#secret_wifi_password')).toHaveValue('');
            await expect(page.locator('#secret_wifi_password')).toHaveAttribute('placeholder', /•/);
        });

        /**
         * SPECIFICATIONS.md §47 — un QR collé sur le local à poubelles ouvre
         * une adresse qui ne bouge plus. La publication est refusée par
         * défaut : le livret contient des choses qui n'ont rien à faire sur le
         * web ouvert.
         */
        test('un bloc du livret s’ouvre à une adresse publique une fois publié', async ({ browser, page }) => {
            // Les deux projets Playwright partagent une installation : chacun
            // publie son propre bloc, faute de quoi le second trouverait
            // déjà publié ce que le premier a ouvert (TESTING.md §31.5).
            //
            // Ni « welcome » ni « waste » : ces deux blocs portent le texte
            // que le scénario hors ligne relit plus bas, et le réécrire ici
            // ferait échouer un test qui ne parle pas des QR.
            const code = suffix === 'mobile' ? 'appliances' : 'safety';
            const text = `Bloc public de la campagne ${suffix}.`;

            await page.goto('/fr/admin/stay?locale=fr');
            await expect(page.locator(`#public_${code}`)).not.toBeChecked();

            // Tant que rien n'est publié, l'adresse n'existe pas.
            const anonymous = await anonymousContext(browser);
            expect((await anonymous.request.get(`/fr/info/${code}`)).status()).toBe(404);

            // SPECIFICATIONS.md §55 — une photo explique le tri des déchets
            // mieux qu'un paragraphe, et se lit dans toutes les langues. Le
            // scénario téléverse la sienne pour ne dépendre d'aucun autre.
            await page.goto('/fr/admin/media');
            await page.setInputFiles('#media', {
                name: `livret-${suffix}.png`,
                mimeType: 'image/png',
                buffer: pngBuffer(240, 180, [90, 140, 60])
            });
            await page.fill('#category', `livret-${suffix}`);
            await page.selectOption('#upload_season', 'all');
            await page.click('[data-testid="upload-form"] button[type="submit"]');
            await expect(page).toHaveURL(/\/fr\/admin\/media\/\d+$/);
            await page.fill('#caption_fr', `Photo du livret ${suffix}`);
            await page.fill('#alt_fr', `Photo du livret ${suffix}`);
            await page.click('[data-testid="save-media"]');
            await expect(page).toHaveURL(/\/fr\/admin\/media$/);

            await page.goto('/fr/admin/stay?locale=fr');
            await page.fill(`#body_${code}`, text);
            await page.selectOption(`#media_${code}`, { label: `Photo du livret ${suffix}` });
            // SPECIFICATIONS.md §55 — la section séjour est « sourcée » : un
            // lien ouvrable pour aller sur place, et la provenance datée de la
            // règle locale, qui change sans prévenir.
            await page.fill(`#link_url_${code}`, `https://carte.example/${suffix}`);
            await page.fill(`#link_label_${code}`, `Y aller (${suffix})`);
            await page.fill(`#source_url_${code}`, `https://commune.example/${suffix}`);
            await page.fill(`#source_checked_on_${code}`, '2026-03-14');
            await toggle(page, `#public_${code}`, true);
            await page.click('[data-testid="stay-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

            // Une adresse qui n'est pas ouvrable est refusée, et rien n'est
            // enregistré : `javascript:` dans un lien du livret serait une
            // injection déguisée en commodité.
            await page.goto('/fr/admin/stay?locale=fr');
            await page.fill(`#link_url_${code}`, 'javascript:alert(1)');
            await page.click('[data-testid="stay-save"]');
            await expect(page.locator('[data-testid="stay-errors"]')).toBeVisible();
            // La saisie revient telle quelle, le champ fautif est marqué : une
            // faute de frappe ne doit pas emporter huit zones de texte.
            await expect(page.locator(`#link_url_${code}`)).toHaveValue('javascript:alert(1)');
            await expect(page.locator(`#link_url_${code}`)).toHaveClass(/is-invalid/);

            // Et rien n'a été enregistré : l'adresse d'origine est intacte.
            await page.goto('/fr/admin/stay?locale=fr');
            await expect(page.locator(`#link_url_${code}`))
                .toHaveValue(`https://carte.example/${suffix}`);

            await page.goto('/fr/admin/stay?locale=fr');
            const printed = (await page.locator(`[data-testid="qr-url-${code}"]`).innerText()).trim();
            expect(printed).toMatch(new RegExp(`/fr/info/${code}$`));
            await expect(page.locator(`[data-testid="qr-${code}"] svg`)).toBeVisible();

            // Un visiteur sans compte, sans séjour et sans lien invité lit la
            // page : c'est exactement la situation de celui qui scanne.
            const scanned = await anonymous.newPage();
            await scanned.goto(new URL(printed).pathname);
            await expect(scanned.locator('[data-testid="stay-info-page"]')).toBeVisible();
            await expect(scanned.locator('[data-testid="info-body"]')).toContainText(text);
            await expect(scanned.locator(`[data-block-illustration="${code}"]`)).toBeVisible();
            await expect(scanned.locator(`[data-block-illustration="${code}"]`))
                .toHaveAttribute('alt', `Photo du livret ${suffix}`);
            await expect(scanned.locator(`[data-block-link="${code}"]`))
                .toHaveText(`Y aller (${suffix})`);
            await expect(scanned.locator(`[data-block-link="${code}"]`))
                .toHaveAttribute('href', `https://carte.example/${suffix}`);
            await expect(scanned.locator(`[data-block-link="${code}"]`))
                .toHaveAttribute('rel', 'noopener noreferrer');
            await expect(scanned.locator(`[data-block-source="${code}"]`)).toHaveText('commune.example');
            await expect(scanned.locator(`[data-block-references="${code}"]`)).toContainText('14');
            await expect(scanned.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);

            // Le mot de passe Wi-Fi n'y figure jamais.
            expect(await scanned.content()).not.toContain('sapin-2026');

            // L'état de départ est rendu : dépublier referme l'adresse.
            await page.goto('/fr/admin/stay?locale=fr');
            await page.selectOption(`#media_${code}`, '0');
            await page.fill(`#link_url_${code}`, '');
            await page.fill(`#link_label_${code}`, '');
            await page.fill(`#source_url_${code}`, '');
            await page.fill(`#source_checked_on_${code}`, '');
            await toggle(page, `#public_${code}`, false);
            await page.click('[data-testid="stay-save"]');
            await expect(page.locator('[data-flash-type="success"]')).toBeVisible();
            expect((await anonymous.request.get(`/fr/info/${code}`)).status()).toBe(404);

            await anonymous.close();
        });
    });

    // --- Parcours voyageur --------------------------------------------------------------

    test('le voyageur réserve et ouvre « Mon séjour »', async ({ browser, request }) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await page.goto('/fr/account/signup');
        await page.fill('#first_name', 'Séjour');
        await page.fill('#last_name', suffix === 'mobile' ? 'Mobile' : 'Desktop');
        await page.fill('#email', client);
        await page.fill('#password', PASSWORD);
        await page.check('#accept_terms');
        await page.click('[data-testid="signup-form"] button[type="submit"]');

        const mail = await waitForMail(request, client, 'account_confirmation');
        await page.goto(linkFrom(mail, '/account/confirm'));

        await page.goto(`/fr/booking?arrival=${stayDates.arrival}&departure=${stayDates.departure}&adults=2`);
        await page.click('[data-testid="start-booking"]');
        await page.check('#accept_rules');
        await page.click('[data-testid="submit-booking"]');

        await expect(page).toHaveURL(/\/fr\/booking\/[A-Z0-9-]+$/);
        reference = (await page.locator('[data-testid="booking-reference"]').innerText()).trim();

        await page.click('[data-testid="stay-link"]');
        await expect(page).toHaveURL(new RegExp(`/fr/stay/${reference}$`));

        const stay = page.locator('[data-testid="stay-page"]');
        await expect(stay).toHaveAttribute('data-phase', 'before');
        await expect(stay).toHaveAttribute('data-locale', 'fr');
        await expect(page.locator('[data-block="welcome"]')).toContainText('Bienvenue à la Maison des Pins');

        // Le séjour n'a pas commencé : aucun code d'accès n'est publié.
        await expect(page.locator('[data-testid="stay-secrets"]')).toHaveAttribute('data-visible', 'false');
        await expect(page.locator('[data-testid="secrets-hidden"]')).toBeVisible();

        // Aucun montant sur cette page : elle est faite pour le hors ligne.
        await expect(page.locator('[data-testid="stay-page"]')).not.toContainText('€');

        await context.close();
    });

    test('la page suit la langue choisie', async ({ browser }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/de/stay/${reference}`);

        await expect(page.locator('[data-testid="stay-page"]')).toHaveAttribute('data-locale', 'de');
        await expect(page.locator('[data-block="welcome"]')).toContainText('Willkommen im Haus der Pinien');
        await expect(page.locator('[data-block="waste"]')).toContainText('Mülltrennung');

        await context.close();
    });

    // --- Lien invité ------------------------------------------------------------------------

    test('un lien invité ouvre le séjour sans compte', async ({ browser }) => {
        test.slow();

        const owner = await anonymousContext(browser);
        const page = await owner.newPage();

        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}`);
        await page.fill('#label', 'Les cousins');
        await page.click('[data-testid="create-guest-link"]');

        await expect(page.locator('[data-testid="guest-url"]')).toBeVisible();
        guestUrl = (await page.locator('[data-testid="guest-url"]').innerText()).trim();
        expect(guestUrl).toMatch(/\/guest\/[a-f0-9]{64}$/);

        // Le QR est rendu en ligne, pas servi par une seconde requête.
        await expect(page.locator('[data-testid="guest-qr"] svg')).toBeVisible();

        await owner.close();

        // Un visiteur sans compte suit le lien.
        const guest = await anonymousContext(browser);
        const guestPage = await guest.newPage();

        await guestPage.goto(new URL(guestUrl).pathname);
        await expect(guestPage.locator('[data-testid="guest-banner"]')).toBeVisible();
        await expect(guestPage.locator('[data-testid="stay-page"]')).toHaveAttribute('data-guest', 'true');
        await expect(guestPage.locator('[data-block="welcome"]')).toBeVisible();

        // Un invité ne voit ni référence, ni finances, ni partage.
        await expect(guestPage.locator('[data-testid="stay-reference"]')).toHaveCount(0);
        await expect(guestPage.locator('[data-testid="guest-links"]')).toHaveCount(0);

        // Et il ne peut pas remonter à la réservation.
        expect((await guestPage.request.get(`/fr/booking/${reference}`)).status()).toBe(403);

        await guest.close();
    });

    test('un lien invité inventé n’ouvre rien', async ({ request }) => {
        expect((await request.get(`/fr/guest/${'0'.repeat(64)}`)).status()).toBe(404);
    });

    // --- Hors ligne ----------------------------------------------------------------------------

    test('le livret reste lisible sans réseau', async ({ browser }, testInfo) => {
        test.slow();

        const context = await anonymousContext(browser);
        const page = await context.newPage();
        const path = new URL(guestUrl).pathname;

        // 1. Première visite en ligne : le service worker prend la main et met
        //    la page en cache.
        await page.goto(path);
        await expect(page.locator('[data-block="welcome"]')).toBeVisible();

        // Le service worker doit être **activé**, pas seulement enregistré :
        // une page non contrôlée ne serait jamais mise en cache.
        await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.ready;
            const worker = registration.active;
            if (!worker || worker.state === 'activated') {
                return;
            }
            await new Promise((resolve) => {
                worker.addEventListener('statechange', () => {
                    if (worker.state === 'activated') {
                        resolve();
                    }
                });
            });
        });
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, { timeout: 15000 });

        // Une seconde visite garantit que la réponse est passée par le service
        // worker et a été stockée.
        await page.goto(path);
        await expect(page.locator('[data-block="welcome"]')).toBeVisible();

        // 2. Ce qui est réellement sur l'appareil.
        //
        // C'est la vérification qui porte la propriété : le contenu servi sans
        // réseau est exactement celui stocké ici, et les surfaces interdites
        // n'y figurent pas.
        const stored = await page.waitForFunction(async (wanted) => {
            for (const name of await caches.keys()) {
                const cache = await caches.open(name);
                const hit = await cache.match(wanted);
                if (hit) {
                    const urls = [];
                    for (const key of await caches.keys()) {
                        const other = await caches.open(key);
                        for (const request of await other.keys()) {
                            urls.push(request.url);
                        }
                    }
                    return { html: await hit.text(), urls };
                }
            }
            return null;
        }, path, { timeout: 20000 }).then((handle) => handle.jsonValue());

        // Le livret hors ligne est complet, dans la langue choisie.
        expect(stored.html).toContain('Bienvenue à la Maison des Pins');
        expect(stored.html).toContain('Le tri se fait au bout de la rue.');
        expect(stored.html).toContain('data-testid="stay-contact"');

        // Ni montants, ni documents, ni réservation.
        expect(stored.urls.some((url) => url.includes('/guest/'))).toBe(true);
        expect(stored.urls.some((url) => url.includes('/booking/'))).toBe(false);
        expect(stored.urls.some((url) => url.includes('/payment/'))).toBe(false);
        expect(stored.urls.some((url) => url.includes('/document/'))).toBe(false);

        // 3. Coupure réseau réelle, puis navigation.
        //
        // WebKit tombe sur une erreur interne dès qu'une navigation est
        // demandée hors ligne sur une page contrôlée par un service worker :
        // c'est une limite du moteur d'automatisation, pas du produit. La
        // propriété reste vérifiée ci-dessus pour les deux moteurs ; le
        // parcours complet est joué là où le moteur le permet.
        if (testInfo.project.name === 'mobile-safari') {
            await context.close();

            return;
        }

        await context.setOffline(true);
        await page.goto(path);

        await expect(page.locator('[data-testid="stay-page"]')).toBeVisible();
        await expect(page.locator('[data-block="welcome"]')).toContainText('Bienvenue à la Maison des Pins');
        await expect(page.locator('[data-block="waste"]')).toContainText('Le tri se fait au bout de la rue.');
        await expect(page.locator('[data-testid="stay-contact"]')).toBeVisible();

        // Faute de cache, la fiche de réservation ne se charge pas du tout :
        // c'est la bonne réponse, plutôt qu'une page de secours trompeuse.
        await expect(page.goto(`/fr/booking/${reference}`)).rejects.toThrow();

        await context.setOffline(false);
        await context.close();
    });

    // --- Révocation ------------------------------------------------------------------------------

    test('révoquer un lien invité coupe l’accès', async ({ browser, request }) => {
        const context = await anonymousContext(browser);
        const page = await context.newPage();

        await signInAndWait(page, client, PASSWORD);

        await page.goto(`/fr/stay/${reference}`);
        const link = page.locator('[data-testid="guest-link-list"] [data-guest-link]').first();
        await expect(link).toBeVisible();

        await link.locator('button').click();
        await expect(page.locator('[data-flash-type="success"]')).toBeVisible();

        expect((await request.get(new URL(guestUrl).pathname)).status()).toBe(404);

        await context.close();
    });
});
