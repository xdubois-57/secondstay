/**
 * Sur mobile la navigation est repliée derrière le bouton hamburger.
 * Ce helper garantit que les tests fonctionnent sur les deux viewports.
 */
export async function openNavigation(page) {
    // Les commandes de la barre (thème, langue, repli mobile) sont pilotées
    // par JavaScript : on attend que les modules soient installés, sinon un
    // clic trop précoce est simplement perdu.
    await page.waitForSelector('html[data-js-ready="true"]');

    const toggler = page.locator('.navbar-toggler');
    if (await toggler.isVisible()) {
        const collapse = page.locator('#primary-navigation');
        if (!(await collapse.evaluate((element) => element.classList.contains('show')))) {
            await toggler.click();
            await collapse.waitFor({ state: 'visible' });
        }
        await settled(collapse);
    }
}

/**
 * Attend la fin de l'animation Bootstrap.
 *
 * Le menu est « visible » dès le premier pixel déplié, mais il bouge encore :
 * un clic posé à ce moment-là peut atterrir à côté de sa cible, et le test
 * échouerait sans qu'aucun défaut du produit soit en cause. Bootstrap marque
 * la transition par la classe `collapsing`, qu'il retire à la fin.
 */
async function settled(collapse) {
    await collapse.evaluate((element) => new Promise((resolve) => {
        const done = () => {
            if (!element.classList.contains('collapsing')) {
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
 * Ouvre le sélecteur de langue (menu déroulant Bootstrap).
 */
export async function openLocaleSwitcher(page) {
    await openNavigation(page);
    const menu = page.locator('[data-locale-switcher] .dropdown-menu');
    if (!(await menu.isVisible())) {
        await page.locator('[data-locale-switcher] .dropdown-toggle').click();
        await menu.waitFor({ state: 'visible' });
    }
}

/**
 * Referme la navigation mobile.
 *
 * Le menu déplié recouvre le contenu : le laisser ouvert fausse les
 * interactions avec le formulaire situé en dessous.
 */
export async function closeNavigation(page) {
    const toggler = page.locator('.navbar-toggler');
    if (!(await toggler.isVisible())) {
        return;
    }

    const collapse = page.locator('#primary-navigation');
    if (await collapse.evaluate((element) => element.classList.contains('show'))) {
        await toggler.click();
        await collapse.waitFor({ state: 'hidden' });
    }
}
