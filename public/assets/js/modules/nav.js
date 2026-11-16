/**
 * Panneaux « mega-menu » de la barre de bureau (`partials/nav.html.twig`).
 *
 * Au clic seulement, jamais au survol : un panneau ouvert au survol est
 * inutilisable sur un portable tactile et hostile au clavier. Un second clic
 * sur l'onglet, Échap (le focus revient à l'onglet) ou un appui hors de la
 * barre le referment. La classe `active` de l'onglet n'est jamais touchée :
 * elle marque le menu de la page courante, rendu côté serveur ; le panneau
 * ouvert se lit dans `aria-expanded`.
 */

/**
 * @param {Document} doc
 * @returns {{show: (menuId: string) => boolean, hideAll: () => void}}
 */
export function initDesktopMenus(doc) {
    const buttons = Array.from(doc.querySelectorAll('.desktop-menu-btn'));
    const panels = Array.from(doc.querySelectorAll('.desktop-megamenu'));

    const panelFor = (menuId) => /** @type {HTMLElement|null} */ (
        panels.find((panel) => panel.getAttribute('data-megamenu-id') === menuId) || null
    );
    const triggerFor = (menuId) => /** @type {HTMLElement|null} */ (
        buttons.find((button) => button.getAttribute('data-menu-id') === menuId) || null
    );

    function hideAll() {
        panels.forEach((panel) => panel.classList.add('d-none'));
        buttons.forEach((button) => button.setAttribute('aria-expanded', 'false'));
    }

    // Ouvre toujours, ne bascule jamais : le fil d'Ariane s'en sert pour
    // ouvrir un menu à coup sûr, même s'il l'était déjà.
    function show(menuId) {
        const panel = panelFor(menuId);
        if (!panel) {
            return false;
        }
        hideAll();
        panel.classList.remove('d-none');
        triggerFor(menuId)?.setAttribute('aria-expanded', 'true');
        return true;
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const menuId = button.getAttribute('data-menu-id') || '';
            const panel = panelFor(menuId);
            if (panel && !panel.classList.contains('d-none')) {
                hideAll();
            } else {
                show(menuId);
            }
        });
    });

    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const open = buttons.find((button) => button.getAttribute('aria-expanded') === 'true');
        if (!open) {
            return;
        }
        hideAll();
        /** @type {HTMLElement} */ (open).focus();
    });

    // `mousedown` et non `click` : le panneau flotte sur la page, il doit
    // s'effacer avant que l'appui qu'il recouvrait n'aboutisse — sinon le
    // premier clic ne sert qu'à le fermer.
    doc.addEventListener('mousedown', (event) => {
        const nav = doc.getElementById('desktopNav');
        const target = /** @type {Node|null} */ (event.target);
        if (!nav || (target && nav.contains(target))) {
            return;
        }
        hideAll();
    });

    return { show, hideAll };
}
