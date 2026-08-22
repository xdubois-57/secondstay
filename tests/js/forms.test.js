import { JSDOM } from 'jsdom';
import { beforeEach, describe, expect, it } from 'vitest';
import { focusFirstInvalid, markInvalid } from '../../public/assets/js/modules/forms.js';

/**
 * Formulaires renvoyés en erreur (SPECIFICATIONS.md §10).
 *
 * Ce que ces tests protègent : le curseur va au premier champ que le serveur a
 * refusé — et **seulement** là. Un curseur qui se déplace alors que l'humain
 * écrit déjà lui prend la main des doigts, ce qui est pire que de ne rien
 * faire.
 */
describe('formulaires en erreur', () => {
    let dom;
    let document;

    beforeEach(() => {
        dom = new JSDOM('<!doctype html><html><body></body></html>');
        document = dom.window.document;
    });

    function form(html) {
        document.body.innerHTML = `<form>${html}</form>`;
        return document;
    }

    it('place le curseur sur le premier champ refusé', () => {
        const root = form(`
            <input id="first" name="first">
            <input id="email" name="email" class="form-control is-invalid">
            <input id="phone" name="phone" class="form-control is-invalid">
        `);

        const focused = focusFirstInvalid(root);

        expect(focused.id).toBe('email');
        expect(document.activeElement.id).toBe('email');
    });

    it('marque tous les champs refusés pour les lecteurs d’écran', () => {
        const root = form(`
            <input id="email" class="is-invalid">
            <textarea id="notes" class="is-invalid"></textarea>
        `);

        expect(markInvalid(root)).toBe(2);
        expect(root.querySelector('#email').getAttribute('aria-invalid')).toBe('true');
        expect(root.querySelector('#notes').getAttribute('aria-invalid')).toBe('true');
    });

    it('ne fait rien quand aucun champ n’est refusé', () => {
        const root = form('<input id="email" class="form-control">');

        expect(focusFirstInvalid(root)).toBeNull();
        expect(document.activeElement).toBe(document.body);
    });

    /**
     * Le message d'erreur porte lui aussi la classe : le curseur doit aller
     * sur le champ à corriger, pas sur le paragraphe qui l'explique.
     */
    it('ignore le message d’erreur et vise le champ', () => {
        const root = form(`
            <div class="invalid-feedback is-invalid">Adresse invalide</div>
            <input id="email" class="form-control is-invalid">
        `);

        expect(focusFirstInvalid(root).id).toBe('email');
    });

    it('ignore un champ désactivé, qu’on ne peut pas corriger', () => {
        const root = form(`
            <input id="locked" class="is-invalid" disabled>
            <input id="email" class="is-invalid">
        `);

        expect(focusFirstInvalid(root).id).toBe('email');
    });

    it('ne déplace pas un curseur déjà posé par l’humain', () => {
        const root = form(`
            <input id="notes" class="form-control">
            <input id="email" class="form-control is-invalid">
        `);

        root.querySelector('#notes').focus();

        expect(focusFirstInvalid(root)).toBeNull();
        expect(document.activeElement.id).toBe('notes');
        // Le marquage, lui, reste posé : il ne prend la main à personne.
        expect(root.querySelector('#email').getAttribute('aria-invalid')).toBe('true');
    });
});
