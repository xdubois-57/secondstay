/**
 * SecondStay — point d'entrée client (module ES, aucune étape de build).
 */
import { applyTheme, nextTheme, readStoredTheme, storeTheme } from './modules/theme.js';
import { evaluatePassword, levelClass } from './modules/password.js';

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

function initPasswordStrength() {
    document.querySelectorAll('[data-password-input]').forEach((input) => {
        const container = input.closest('.col-12, .mb-3, form') || document;
        const bar = container.querySelector('[data-password-strength]');
        if (!bar) {
            return;
        }
        const progress = bar.parentElement;
        const update = () => {
            const result = evaluatePassword(input.value);
            bar.style.width = result.score + '%';
            bar.className = 'progress-bar ' + levelClass(result.level);
            bar.dataset.level = result.level;
            if (progress) {
                progress.setAttribute('aria-valuenow', String(result.score));
            }
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
    document.documentElement.setAttribute('data-js-ready', 'true');
});
