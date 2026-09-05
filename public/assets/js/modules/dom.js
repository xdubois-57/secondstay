/**
 * Accès typé au DOM.
 *
 * `querySelector()` et `querySelectorAll()` rendent `Element`, le plus petit
 * dénominateur commun : ni `value`, ni `dataset`, ni `style`, ni `focus()`
 * n'y existent. Le code appelant sait pourtant, par le sélecteur qu'il vient
 * d'écrire, sur quel genre d'élément il travaille.
 *
 * Ces accesseurs disent cette connaissance **une fois**, ici, plutôt que de
 * disperser une assertion sur chaque appel : le jour où l'une d'elles devient
 * fausse — un `<input>` devenu `<select>` dans un gabarit — elle se corrige à
 * un seul endroit, et le vérificateur signale tout le reste.
 *
 * Rien de tout cela n'existe à l'exécution : aucune conversion, aucune copie.
 * Les types ne servent qu'à `tsc` (voir `tsconfig.json`, qui est un
 * vérificateur et jamais une étape de construction) ; le JavaScript servi en
 * production est celui qui est écrit ici.
 */

/**
 * Les contrôles qu'un formulaire peut porter, et que ce projet manipule :
 * tous trois ont `disabled`, `focus()` et `value`.
 *
 * @typedef {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} FormField
 */

/**
 * Premier élément correspondant au sélecteur, vu comme un `HTMLElement`.
 *
 * @param {Document|Element} root
 * @param {string} selector
 * @returns {HTMLElement|null}
 */
export function queryElement(root, selector) {
    return /** @type {HTMLElement|null} */ (root.querySelector(selector));
}

/**
 * @param {Element} node
 * @returns {HTMLInputElement}
 */
export function asInput(node) {
    return /** @type {HTMLInputElement} */ (node);
}

/**
 * @param {Element} node
 * @returns {FormField}
 */
export function asFormField(node) {
    return /** @type {FormField} */ (node);
}

/**
 * Le document propriétaire d'une racine, qu'elle soit un document ou un
 * fragment de page.
 *
 * @param {Document|Element} root
 * @returns {Document}
 */
export function documentOf(root) {
    return /** @type {Document} */ (root.ownerDocument || root);
}
