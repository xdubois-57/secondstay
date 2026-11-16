/**
 * Boutons de menu du fil d'Ariane (`partials/breadcrumb_bar.html.twig`).
 *
 * Un menu n'est pas une page : son bouton l'ouvre plutôt que de naviguer. Sur
 * ordinateur, le panneau du menu ; sur téléphone, le panneau latéral avec la
 * section correspondante de l'accordéon dépliée — le visiteur choisit
 * lui-même la page, comme depuis le menu.
 *
 * @param {Document} doc
 * @param {Window} win
 * @param {{show: (menuId: string) => boolean}} desktopMenus
 */
export function initBreadcrumb(doc, win, desktopMenus) {
    doc.querySelectorAll('[data-open-menu]').forEach((button) => {
        button.addEventListener('click', () => {
            const menuId = button.getAttribute('data-open-menu') || '';
            const desktop = doc.getElementById('desktopNav');
            if (desktop && win.getComputedStyle(desktop).display !== 'none') {
                desktopMenus.show(menuId);
                return;
            }

            const bootstrap = /** @type {any} */ (win).bootstrap;
            const offcanvas = doc.getElementById('navOffcanvas');
            const section = doc.getElementById(`mob-${menuId}`);
            if (!bootstrap || !offcanvas) {
                return;
            }
            if (section) {
                bootstrap.Collapse.getOrCreateInstance(section, { toggle: false }).show();
            }
            bootstrap.Offcanvas.getOrCreateInstance(offcanvas).show();
        });
    });
}
