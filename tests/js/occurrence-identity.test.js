import { describe, expect, it } from 'vitest';

import { normaliseLine, occurrenceIdentity } from '../../scripts/lib/occurrence-identity.mjs';

/**
 * L'identité d'une occurrence de constat `tsc`, pour la baseline.
 *
 * Ce test existe à cause d'un constat de revue, et il décrit le défaut que ce
 * constat visait : une baseline qui identifie une occurrence par le seul texte
 * de sa ligne laisse passer un **échange**. On corrige une occurrence, on en
 * introduit une autre au texte identique ailleurs dans le même fichier, le
 * total ne bouge pas, l'entrée acceptée est consommée par la nouvelle, et le
 * constat neuf n'est jamais signalé.
 *
 * Le dépôt ne porte aujourd'hui aucune baseline — c'est la politique, et elle
 * rend ce chemin dormant. Un garde-fou dormant reste un garde-fou : le jour
 * où quelqu'un accepte une dette existante, il faut qu'il fonctionne.
 */
describe("identité d'une occurrence", () => {
    it('distingue deux lignes identiques placées dans des contextes différents', () => {
        const lines = [
            'function premiere(valeur) {',
            '    const brut = lire(valeur);',
            '    return null;',
            '}',
            '',
            'function seconde(valeur) {',
            '    const propre = nettoyer(valeur);',
            '    return null;',
            '}',
        ];

        // Deux `return null;` au texte rigoureusement identique.
        expect(lines[2].trim()).toBe(lines[7].trim());

        // C'est tout l'enjeu : leurs identités, elles, diffèrent.
        expect(occurrenceIdentity(lines, 3)).not.toBe(occurrenceIdentity(lines, 8));
    });

    /**
     * Le cas exact décrit par la revue, joué de bout en bout sur la règle de
     * correspondance : chaque occurrence courante consomme au plus une entrée
     * acceptée portant la même identité.
     */
    it("signale un constat neuf qui remplace un constat corrigé", () => {
        const avant = [
            'function premiere(valeur) {',
            '    const brut = lire(valeur);',
            '    return null;',
            '}',
            '',
            'function seconde(valeur) {',
            '    const propre = nettoyer(valeur);',
            '    return null;',
            '}',
        ];

        // La baseline accepte les deux occurrences telles qu'elles étaient.
        const acceptees = [occurrenceIdentity(avant, 3), occurrenceIdentity(avant, 8)];

        // La première est corrigée ; une troisième apparaît ailleurs, au même
        // texte. Le total est inchangé : deux occurrences, hier comme
        // aujourd'hui.
        const apres = [
            'function premiere(valeur) {',
            '    const brut = lire(valeur);',
            '    return brut;',
            '}',
            '',
            'function seconde(valeur) {',
            '    const propre = nettoyer(valeur);',
            '    return null;',
            '}',
            '',
            'function troisieme(valeur) {',
            '    const decode = decoder(valeur);',
            '    return null;',
            '}',
        ];
        const courantes = [occurrenceIdentity(apres, 8), occurrenceIdentity(apres, 13)];

        expect(courantes).toHaveLength(acceptees.length);
        expect(nouvelles(courantes, acceptees)).toEqual([occurrenceIdentity(apres, 13)]);
    });

    it('ne signale rien quand les occurrences acceptées se reproduisent', () => {
        const lines = [
            'function premiere(valeur) {',
            '    const brut = lire(valeur);',
            '    return null;',
            '}',
        ];

        expect(nouvelles([occurrenceIdentity(lines, 3)], [occurrenceIdentity(lines, 3)])).toEqual([]);
    });

    /**
     * L'identité doit survivre à ce qui ne concerne pas l'occurrence :
     * ré-indentation, lignes vides, commentaires intercalés, et surtout du
     * code sans rapport ajouté au-dessus. Sinon toute modification d'un
     * fichier ferait réapparaître les constats situés en dessous, et la
     * baseline serait à régénérer en permanence — ce qui revient à ne plus la
     * lire.
     */
    it("ne bouge pas quand du code sans rapport bouge au-dessus", () => {
        const identity = occurrenceIdentity([
            'function premiere(valeur) {',
            '    const brut = lire(valeur);',
            '    return null;',
        ], 3);

        const deplacee = occurrenceIdentity([
            "import { autre } from './autre.js';",
            '',
            'const CONSTANTE = 12;',
            '',
            'function premiere(valeur) {',
            '    // Un commentaire qui ne change rien.',
            '',
            '        const brut   =   lire(valeur);',
            '    return null;',
        ], 9);

        expect(deplacee).toBe(identity);
    });

    it('réduit les espaces et ignore une ligne absente', () => {
        expect(normaliseLine('   const   a =  1;  ')).toBe('const a = 1;');
        expect(normaliseLine(undefined)).toBe('');
        expect(occurrenceIdentity([], 4)).toBe('');
    });
});

/**
 * La règle de correspondance de `js-typecheck.mjs`, reproduite ici sur des
 * identités seules : chaque occurrence courante consomme au plus une entrée
 * acceptée. Ce qui reste sans correspondance est neuf.
 *
 * @param {string[]} courantes
 * @param {string[]} acceptees
 * @returns {string[]}
 */
function nouvelles(courantes, acceptees) {
    const disponibles = [...acceptees];

    return courantes.filter((identity) => {
        const index = disponibles.indexOf(identity);
        if (index === -1) return true;
        disponibles.splice(index, 1);
        return false;
    });
}
