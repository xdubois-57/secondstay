/**
 * Formulaires renvoyés en erreur (SPECIFICATIONS.md §10).
 *
 * Le serveur renvoie la page avec `is-invalid` sur les champs refusés et le
 * motif juste en dessous. Cela suffit à qui voit la page entière — pas à qui
 * la parcourt au lecteur d'écran, ni à qui la lit sur un téléphone où le champ
 * fautif est trois écrans plus bas. Le curseur va donc s'y placer.
 *
 * Deux précautions :
 *
 * 1. **on ne déplace jamais un curseur déjà posé.** Si le navigateur a
 *    restauré le focus, ou si l'utilisateur a déjà touché un champ, le
 *    déplacer lui prendrait la main des doigts ;
 * 2. **on n'invente aucune validation.** Ce module ne juge rien : il suit ce
 *    que le serveur a décidé. Une validation côté client qui divergerait de
 *    la règle serveur donnerait deux vérités.
 */

import { asFormField, documentOf } from './dom.js';

/** Contrôles qu'un humain peut réellement corriger. */
const FIELDS = 'input, select, textarea';

/**
 * Place le focus sur le premier champ refusé, s'il y en a un.
 *
 * @param {Document|Element} root
 * @returns {import('./dom.js').FormField|null} le champ atteint, ou null
 */
export function focusFirstInvalid(root) {
    const field = firstInvalid(root);
    if (!field) {
        return null;
    }

    // `aria-invalid` est posé ici plutôt que dans chaque gabarit : c'est la
    // même information que la classe `is-invalid`, rendue lisible par les
    // technologies d'assistance.
    markInvalid(root);

    // Le document est déduit de la racine reçue, jamais pris dans le global :
    // ce module doit rester utilisable sur un fragment comme sur une page,
    // et testable sans navigateur.
    const owner = documentOf(root);
    const active = owner.activeElement;
    if (active && active !== owner.body && active.matches && active.matches(FIELDS)) {
        return null;
    }

    if (typeof field.focus === 'function') {
        field.focus({ preventScroll: false });
    }

    return field;
}

/**
 * Marque tous les champs refusés pour les technologies d'assistance.
 *
 * @param {Document|Element} root
 * @returns {number} champs marqués
 */
export function markInvalid(root) {
    const fields = root.querySelectorAll('.is-invalid');
    let marked = 0;

    fields.forEach((field) => {
        if (field.matches && field.matches(FIELDS)) {
            field.setAttribute('aria-invalid', 'true');
            marked++;
        }
    });

    return marked;
}

/**
 * @param {Document|Element} root
 * @returns {import('./dom.js').FormField|null}
 */
function firstInvalid(root) {
    const fields = root.querySelectorAll('.is-invalid');

    for (const element of fields) {
        const field = asFormField(element);
        if (field.matches && field.matches(FIELDS) && !field.disabled) {
            return field;
        }
    }

    return null;
}
