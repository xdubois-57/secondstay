import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import { initDesktopMenus } from '../../public/assets/js/modules/nav.js';
import { initBreadcrumb } from '../../public/assets/js/modules/breadcrumb.js';
import { initNavRails } from '../../public/assets/js/modules/nav-rail.js';

const MARKUP = `
    <nav id="desktopNav">
        <button class="desktop-menu-btn active" data-menu-id="housing" aria-expanded="false"></button>
        <button class="desktop-menu-btn" data-menu-id="admin" aria-expanded="false"></button>
        <div class="desktop-megamenu d-none" data-megamenu-id="housing"><a href="#">a</a></div>
        <div class="desktop-megamenu d-none" data-megamenu-id="admin"><a href="#">b</a></div>
    </nav>
    <div id="navOffcanvas"></div>
    <div id="mob-admin"></div>
    <button data-open-menu="admin"></button>
    <p id="outside"></p>`;

describe('menus de bureau', () => {
    let window;
    let document;

    beforeEach(() => {
        window = new JSDOM(`<!doctype html><html><body>${MARKUP}</body></html>`).window;
        document = window.document;
    });

    const panel = (id) => document.querySelector(`[data-megamenu-id="${id}"]`);
    const tab = (id) => document.querySelector(`[data-menu-id="${id}"]`);

    it('ouvre un panneau au clic et le referme au second clic', () => {
        initDesktopMenus(document);
        tab('admin').click();
        expect(panel('admin').classList.contains('d-none')).toBe(false);
        expect(tab('admin').getAttribute('aria-expanded')).toBe('true');
        tab('admin').click();
        expect(panel('admin').classList.contains('d-none')).toBe(true);
        expect(tab('admin').getAttribute('aria-expanded')).toBe('false');
    });

    it('n’ouvre qu’un panneau à la fois et garde le soulignement du menu courant', () => {
        initDesktopMenus(document);
        tab('admin').click();
        tab('housing').click();
        expect(panel('admin').classList.contains('d-none')).toBe(true);
        expect(panel('housing').classList.contains('d-none')).toBe(false);
        tab('housing').click();
        expect(tab('housing').classList.contains('active')).toBe(true);
    });

    it('se referme sur Échap en rendant le focus à l’onglet', () => {
        initDesktopMenus(document);
        tab('admin').click();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
        expect(panel('admin').classList.contains('d-none')).toBe(true);
        expect(document.activeElement).toBe(tab('admin'));
    });

    it('se referme sur un appui hors de la barre, pas dedans', () => {
        initDesktopMenus(document);
        tab('admin').click();
        panel('admin').querySelector('a').dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true }));
        expect(panel('admin').classList.contains('d-none')).toBe(false);
        document.getElementById('outside').dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true }));
        expect(panel('admin').classList.contains('d-none')).toBe(true);
    });

    it('show() ouvre toujours, sans basculer', () => {
        const menus = initDesktopMenus(document);
        expect(menus.show('admin')).toBe(true);
        expect(menus.show('admin')).toBe(true);
        expect(panel('admin').classList.contains('d-none')).toBe(false);
        expect(menus.show('inconnu')).toBe(false);
    });
});

describe('fil d’Ariane', () => {
    it('ouvre le panneau du menu sur ordinateur', () => {
        const { window } = new JSDOM(`<!doctype html><html><body>${MARKUP}</body></html>`);
        const show = vi.fn();
        initBreadcrumb(window.document, window, { show });
        window.document.querySelector('[data-open-menu]').click();
        expect(show).toHaveBeenCalledWith('admin');
    });

    it('ouvre le panneau latéral et la section du menu sur téléphone', () => {
        const { window } = new JSDOM(`<!doctype html><html><body>${MARKUP}</body></html>`);
        window.document.getElementById('desktopNav').style.display = 'none';
        const offcanvasShow = vi.fn();
        const collapseShow = vi.fn();
        window.bootstrap = {
            Offcanvas: { getOrCreateInstance: vi.fn(() => ({ show: offcanvasShow })) },
            Collapse: { getOrCreateInstance: vi.fn(() => ({ show: collapseShow })) }
        };
        const show = vi.fn();
        initBreadcrumb(window.document, window, { show });
        window.document.querySelector('[data-open-menu]').click();
        expect(show).not.toHaveBeenCalled();
        const [section, options] = window.bootstrap.Collapse.getOrCreateInstance.mock.calls[0];
        expect(section).toBe(window.document.getElementById('mob-admin'));
        expect(options).toEqual({ toggle: false });
        expect(collapseShow).toHaveBeenCalled();
        expect(offcanvasShow).toHaveBeenCalled();
    });
});

describe('barre de sous-pages', () => {
    it('centre l’onglet courant, sans animation si l’utilisateur l’a demandé', () => {
        const { window } = new JSDOM(`<!doctype html><html><body>
            <nav class="nav-rail"><a class="nav-link" href="#">a</a><a class="nav-link" aria-current="page" href="#">b</a></nav>
        </body></html>`);
        const current = window.document.querySelector('[aria-current="page"]');
        current.scrollIntoView = vi.fn();
        window.matchMedia = vi.fn(() => ({ matches: true }));
        initNavRails(window.document, window);
        expect(current.scrollIntoView).toHaveBeenCalledWith({ behavior: 'auto', inline: 'center', block: 'nearest' });
    });
});
