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

function prefersDark() {
    return Boolean(window.matchMedia?.('(prefers-color-scheme: dark)').matches);
}

function initTheme() {
    const root = document.documentElement;
    let mode = readStoredTheme(window.localStorage);
    applyTheme(root, mode, prefersDark());

    const toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        toggle.addEventListener('click', () => {
            mode = nextTheme(root.dataset.themeMode || 'auto');
            storeTheme(window.localStorage, mode);
            applyTheme(root, mode, prefersDark());
            const label = queryElement(toggle, '[data-theme-label]');
            if (label?.dataset[mode]) {
                label.textContent = label.dataset[mode];
            }
        });
    }

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
    registerServiceWorker(window);
    document.documentElement.dataset.jsReady = 'true';
});
