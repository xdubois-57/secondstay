/**
 * SecondStay — point d'entrée client (module ES, aucune étape de build).
 */
import { applyTheme, nextTheme, readStoredTheme, storeTheme } from './modules/theme.js';
import { asInput, asProgress, queryElement } from './modules/dom.js';
import { focusFirstInvalid } from './modules/forms.js';
import { evaluatePassword } from './modules/password.js';
import { initCalendar } from './modules/calendar.js';
import { initGalleryLightbox } from './modules/lightbox.js';
import { initPasskeyRegistration, initPasskeySignIn } from './modules/passkey.js';
import { initPushControls, registerServiceWorker } from './modules/push.js';
import { initDesktopMenus } from './modules/nav.js';
import { initNavRails } from './modules/nav-rail.js';
import { initBreadcrumb } from './modules/breadcrumb.js';
import { showToast } from './modules/toast.js';

/** Icône du bouton de thème pour chaque préférence. */
const THEME_ICONS = { auto: 'bi-circle-half', light: 'bi-sun', dark: 'bi-moon-stars' };

function prefersDark() {
    return Boolean(window.matchMedia?.('(prefers-color-scheme: dark)').matches);
}

function initTheme() {
    const root = document.documentElement;
    let mode = readStoredTheme(window.localStorage);
    applyTheme(root, mode, prefersDark());

    // Deux boutons — barre mobile et barre de bureau — pour une préférence.
    const toggles = Array.from(document.querySelectorAll('[data-theme-toggle]'));
    const label = (toggle, value) => {
        const name = toggle.dataset[value] || value;
        return (toggle.dataset.labelTemplate || '__mode__').replace('__mode__', name);
    };
    const render = (value) => {
        toggles.forEach((element) => {
            const toggle = /** @type {HTMLElement} */ (element);
            toggle.setAttribute('aria-label', label(toggle, value));
            const icon = queryElement(toggle, '[data-theme-toggle-icon]');
            if (icon) {
                icon.className = `bi ${THEME_ICONS[value] || THEME_ICONS.auto} fs-5`;
            }
        });
    };
    render(mode);
    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            mode = nextTheme(root.dataset.themeMode || 'auto');
            storeTheme(window.localStorage, mode);
            applyTheme(root, mode, prefersDark());
            render(mode);
        });
    });

    if (window.matchMedia) {
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        if (query.addEventListener) {
            query.addEventListener('change', () => {
                if ((root.dataset.themeMode || 'auto') === 'auto') {
                    applyTheme(root, 'auto', prefersDark());
                }
            });
        }
    }
}

function initPasswordStrength() {
    document.querySelectorAll('[data-password-input]').forEach((element) => {
        const input = asInput(element);
        const container = input.closest('.col-12, .mb-3, form') || document;
        const bar = queryElement(container, '[data-password-strength]');
        if (!bar) {
            return;
        }
        const meter = asProgress(bar);
        const update = () => {
            const result = evaluatePassword(input.value);
            // `<progress>` porte lui-même sa valeur et son nom accessible :
            // il n'y a plus ni largeur à calculer ni `aria-valuenow` à tenir
            // à jour. La couleur suit `data-level` depuis la feuille de
            // style, là où la présentation a sa place.
            meter.value = result.score;
            meter.dataset.level = result.level;
        };
        input.addEventListener('input', update);
        update();
    });
}

function ready(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback);
    } else {
        callback();
    }
}

ready(() => {
    initTheme();
    const desktopMenus = initDesktopMenus(document);
    initBreadcrumb(document, window, desktopMenus);
    initNavRails(document, window);
    // Le toast est le seul retour d'une mise à jour faite sans recharger la
    // page ; exposé pour les rares scripts qui ne sont pas des modules.
    /** @type {any} */ (window).SecondStayToast = {
        show: (message, options) => showToast(document, window, message, options)
    };
    initPasswordStrength();
    // Un formulaire renvoyé en erreur place le curseur sur le champ refusé :
    // sur un téléphone, il est souvent hors de l'écran.
    focusFirstInvalid(document);
    initGalleryLightbox(document, document);
    initCalendar(document, window);
    initPasskeyRegistration(document, window);
    initPasskeySignIn(document, window);
    initPushControls(document, window);
    // Le service worker apporte le cache hors ligne et la réception du push.
    registerServiceWorker(window).catch(() => null);
    document.documentElement.dataset.jsReady = 'true';
});
