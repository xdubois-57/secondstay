import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import { showToast } from '../../public/assets/js/modules/toast.js';

describe('toast', () => {
    let document;

    beforeEach(() => {
        document = new JSDOM('<!doctype html><html><body data-toast-close-label="Close"></body></html>').window.document;
    });

    it('dessine le toast de ScoutMagic dans un conteneur annoncé', () => {
        const toast = showToast(document, undefined, 'Enregistré');
        const container = document.querySelector('[data-toast-container]');

        expect(container.className).toBe('toast-container position-fixed bottom-0 end-0 p-3');
        expect(container.getAttribute('aria-live')).toBe('polite');
        expect(toast.className).toBe('toast align-items-center text-bg-success border-0 show');
        expect(toast.getAttribute('role')).toBe('status');
        expect(toast.querySelector('.toast-body').textContent).toBe('Enregistré');
        const close = toast.querySelector('.btn-close');
        expect(close.className).toBe('btn-close btn-close-white me-2 m-auto');
        expect(close.getAttribute('aria-label')).toBe('Close');
    });

    it('associe chaque variante à un ton Bootstrap', () => {
        expect(showToast(document, undefined, 'x', { variant: 'error' }).classList.contains('text-bg-danger')).toBe(true);
        expect(showToast(document, undefined, 'x', { variant: 'warning' }).classList.contains('text-bg-warning')).toBe(true);
        expect(showToast(document, undefined, 'x', { variant: 'info' }).classList.contains('text-bg-info')).toBe(true);
        expect(document.querySelectorAll('[data-toast-container]')).toHaveLength(1);
        expect(document.querySelectorAll('.toast')).toHaveLength(3);
    });

    it('n’interprète jamais le message comme du balisage', () => {
        const toast = showToast(document, undefined, '<img src=x onerror=alert(1)>');
        expect(toast.querySelector('img')).toBeNull();
        expect(toast.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
    });

    it('se retire seul à l’échéance sans Bootstrap, plus tard pour une erreur', () => {
        vi.useFakeTimers();
        const success = showToast(document, undefined, 'ok');
        const error = showToast(document, undefined, 'ko', { variant: 'error' });
        vi.advanceTimersByTime(4000);
        expect(success.isConnected).toBe(false);
        expect(error.isConnected).toBe(true);
        vi.advanceTimersByTime(4000);
        expect(error.isConnected).toBe(false);
        vi.useRealTimers();
    });

    it('se ferme à la main et exécute l’action proposée', () => {
        const onClick = vi.fn();
        const toast = showToast(document, undefined, 'Supprimé', { action: { label: 'Annuler', onClick } });
        toast.querySelector('.btn-light').click();
        expect(onClick).toHaveBeenCalledOnce();
        expect(toast.isConnected).toBe(false);

        const other = showToast(document, undefined, 'x');
        other.querySelector('.btn-close').click();
        expect(other.isConnected).toBe(false);
    });

    it('confie l’affichage à bootstrap.Toast quand il est chargé', () => {
        const show = vi.fn();
        const hide = vi.fn();
        const Toast = vi.fn(function () {
            this.show = show;
            this.hide = hide;
        });
        const toast = showToast(document, { bootstrap: { Toast } }, 'ok', { delayMs: 1234 });
        expect(Toast.mock.calls[0][0]).toBe(toast);
        expect(Toast.mock.calls[0][1]).toEqual({ delay: 1234 });
        expect(show).toHaveBeenCalled();
        toast.querySelector('.btn-close').click();
        expect(hide).toHaveBeenCalled();
    });
});
