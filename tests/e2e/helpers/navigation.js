/**
 * Navigation du site (`templates/partials/nav.html.twig`).
 *
 * Sur téléphone, les menus vivent dans un panneau latéral (offcanvas) ouvert
 * par le bouton de la barre ; sur ordinateur, chaque menu est un onglet qui
 * ouvre un panneau sous la barre. Ces helpers rendent les deux identiques
 * pour un scénario.
 */

/**
 * Rend la navigation visible : ouvre le panneau latéral sur téléphone, le
 * panneau du premier menu sur ordinateur.
 */
export async function openNavigation(page) {
    // Les commandes de la barre sont pilotées par JavaScript : on attend que
    // les modules soient installés, sinon un clic trop précoce est perdu.
    await page.waitForSelector('html[data-js-ready="true"]');

    const toggler = page.locator('[data-testid="mobile-menu-toggle"]');
    if (await toggler.isVisible()) {
        const offcanvas = page.locator('#navOffcanvas');
        if (!(await offcanvas.evaluate((element) => element.classList.contains('show')))) {
            await toggler.click();
        }
        await settled(offcanvas);
        return;
    }

    const tab = page.locator('.desktop-menu-btn').first();
    if ((await tab.getAttribute('aria-expanded')) !== 'true') {
        await tab.click();
    }
    await page.locator('.desktop-megamenu:not(.d-none)').first().waitFor({ state: 'visible' });
}

/**
 * Attend la fin de l'animation Bootstrap du panneau latéral.
 *
 * Le panneau est « visible » dès le premier pixel, mais il glisse encore : un
 * clic posé à ce moment-là peut atterrir à côté de sa cible. Bootstrap retire
 * `showing` et pose `show` à la fin de la transition.
 */
async function settled(offcanvas) {
    await offcanvas.evaluate((element) => new Promise((resolve) => {
        const done = () => {
            if (element.classList.contains('show') && !element.classList.contains('showing')) {
                resolve();
                return true;
            }
            return false;
        };

        if (done()) {
            return;
        }

        const observer = new MutationObserver(() => {
            if (done()) {
                observer.disconnect();
            }
        });
        observer.observe(element, { attributes: true, attributeFilter: ['class'] });
    }));
}

/**
 * Ouvre le sélecteur de langue visible (barre de bureau, ou pied du panneau
 * latéral sur téléphone).
 */
export async function openLocaleSwitcher(page) {
    await page.waitForSelector('html[data-js-ready="true"]');
    if (await page.locator('[data-testid="mobile-menu-toggle"]').isVisible()) {
        await openNavigation(page);
    }
    const switcher = page.locator('[data-locale-switcher]:visible');
    const menu = switcher.locator('.dropdown-menu');
    if (!(await menu.isVisible())) {
        await switcher.locator('.dropdown-toggle').click();
        await menu.waitFor({ state: 'visible' });
    }
}

/**
 * Referme la navigation : le panneau latéral recouvre le contenu, et un
 * panneau de menu ouvert sur ordinateur aussi.
 */
export async function closeNavigation(page) {
    const offcanvas = page.locator('#navOffcanvas');
    if (await page.locator('[data-testid="mobile-menu-toggle"]').isVisible()) {
        if (await offcanvas.evaluate((element) => element.classList.contains('show'))) {
            await offcanvas.locator('.btn-close').click();
            await offcanvas.waitFor({ state: 'hidden' });
        }
        return;
    }

    if (await page.locator('.desktop-megamenu:not(.d-none)').count()) {
        await page.keyboard.press('Escape');
        await page.locator('.desktop-megamenu:not(.d-none)').waitFor({ state: 'detached' }).catch(() => null);
    }
}
