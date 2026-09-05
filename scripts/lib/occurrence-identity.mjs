/**
 * Identité d'une occurrence de constat, pour la baseline de `tsc`.
 *
 * ## Pourquoi ce module existe séparément
 *
 * `scripts/js-typecheck.mjs` s'exécute entièrement au chargement : il lance
 * `tsc` dès qu'on l'importe. Rien de ce qu'il contient n'est donc testable, y
 * compris la règle ci-dessous, qui décide seule si un constat neuf est vu ou
 * confondu avec un constat accepté. Une règle pareille sans test est un
 * garde-fou dont personne ne peut dire s'il fonctionne.
 *
 * ## La règle
 *
 * Une occurrence est identifiée par le **texte de sa ligne**, précédé des deux
 * lignes de code qui la précèdent.
 *
 * Le texte seul ne suffisait pas, et c'est une revue qui l'a montré : deux
 * occurrences identiques dans le même fichier — deux `return null;`, deux
 * appels au même helper — portent le même texte. Corriger l'une et en
 * introduire une autre ailleurs laissait le total inchangé, l'entrée de
 * baseline consommée par la nouvelle, et le constat neuf invisible. Très
 * exactement le vert qui ne prouve rien.
 *
 * Le contexte est pris en **lignes de code**, les vides et les commentaires
 * sautés, et jamais en numéros de ligne : ce qui doit rester stable, c'est
 * l'identité d'une occurrence quand du code sans rapport bouge au-dessus
 * d'elle.
 *
 * Ce n'est pas une identité parfaite — deux occurrences dont les trois lignes
 * coïncident restent indiscernables — et c'est assumé : il faut alors que le
 * code soit dupliqué à l'identique sur trois lignes, ce qu'un relecteur voit.
 */

/** Une ligne vide ou purement commentaire n'apporte aucun contexte. */
function isContextual(text) {
    return text !== '' && !text.startsWith('//') && !text.startsWith('*') && !text.startsWith('/*');
}

/**
 * Normalise une ligne : espaces de bord retirés, espaces internes réduits.
 * Une simple ré-indentation ne doit pas faire passer pour neuf un constat
 * déjà accepté.
 *
 * @param {string | undefined} raw
 * @returns {string}
 */
export function normaliseLine(raw) {
    return (raw ?? '').trim().replace(/\s+/g, ' ');
}

/**
 * @param {string[]} lines lignes du fichier, dans l'ordre
 * @param {number} line numéro de ligne du constat, à partir de 1
 * @param {number} context nombre de lignes de code retenues au-dessus
 * @returns {string}
 */
export function occurrenceIdentity(lines, line, context = 2) {
    const above = [];
    for (let index = line - 1; index >= 1 && above.length < context; index--) {
        const text = normaliseLine(lines[index - 1]);
        if (isContextual(text)) {
            above.unshift(text);
        }
    }

    return [...above, normaliseLine(lines[line - 1])].join(' ⏎ ');
}
