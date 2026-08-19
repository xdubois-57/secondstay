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
    }
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
