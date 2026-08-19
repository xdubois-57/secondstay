/**
 * SecondStay — point d'entrée client (module ES, aucune étape de build).
 */
import { applyTheme, nextTheme, readStoredTheme, storeTheme } from './modules/theme.js';

function prefersDark() {
    return Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

function initTheme() {
    const root = document.documentElement;
    let mode = readStoredTheme(window.localStorage);
    applyTheme(root, mode, prefersDark());

    const toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        toggle.addEventListener('click', () => {
            mode = nextTheme(root.getAttribute('data-theme-mode') || 'auto');
            storeTheme(window.localStorage, mode);
            applyTheme(root, mode, prefersDark());
            const label = toggle.querySelector('[data-theme-label]');
            if (label && label.dataset[mode]) {
                label.textContent = label.dataset[mode];
            }
        });
    }

    if (window.matchMedia) {
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        if (query.addEventListener) {
            query.addEventListener('change', () => {
                if ((root.getAttribute('data-theme-mode') || 'auto') === 'auto') {
                    applyTheme(root, 'auto', prefersDark());
                }
            });
        }
    }
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
    document.documentElement.setAttribute('data-js-ready', 'true');
});
