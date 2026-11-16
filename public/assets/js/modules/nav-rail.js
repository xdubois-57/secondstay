/**
 * Barre de sous-pages (`partials/nav_rail.html.twig`) : ramène l'onglet
 * courant au centre, pour qu'il soit visible sans faire défiler la barre.
 * Rien d'autre — la barre est complète et utilisable sans JavaScript.
 * Le défilement respecte `prefers-reduced-motion`.
 *
 * @param {Document} doc
 * @param {Window} win
 */
export function initNavRails(doc, win) {
    const reduced = typeof win.matchMedia === 'function'
        && win.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const behavior = /** @type {ScrollBehavior} */ (reduced ? 'auto' : 'smooth');

    doc.querySelectorAll('.nav-rail').forEach((rail) => {
        const selected = rail.querySelector('.nav-link[aria-current="page"]');
        if (selected && typeof selected.scrollIntoView === 'function') {
            selected.scrollIntoView({ behavior, inline: 'center', block: 'nearest' });
        }
    });
}
