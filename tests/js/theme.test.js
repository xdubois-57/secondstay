import { describe, expect, it } from 'vitest';
import {
    THEME_ORDER,
    THEME_STORAGE_KEY,
    applyTheme,
    isValidTheme,
    nextTheme,
    readStoredTheme,
    resolveTheme,
    storeTheme
} from '../../public/assets/js/modules/theme.js';

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => {
            data[key] = String(value);
        },
        data
    };
}

function fakeRoot() {
    const attributes = {};

    // `dataset` est modélisé fidèlement, et non simulé par un simple objet :
    // le code de production y écrit `themeMode`, et ce que la page porte
    // réellement est `data-theme-mode`. Un double qui stockerait la clé
    // camelCase telle quelle laisserait passer une erreur de conversion, et
    // les assertions ci-dessous portent justement sur le nom rendu.
    const dataset = new Proxy({}, {
        set: (_target, key, value) => {
            attributes['data-' + String(key).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())] = String(value);
            return true;
        },
        get: (_target, key) => attributes['data-' + String(key).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())]
    });

    return {
        attributes,
        dataset,
        setAttribute: (name, value) => {
            attributes[name] = value;
        },
        getAttribute: (name) => attributes[name] ?? null
    };
}

describe('theme', () => {
    it('cycles through auto, light and dark', () => {
        expect(THEME_ORDER).toEqual(['auto', 'light', 'dark']);
        expect(nextTheme('auto')).toBe('light');
        expect(nextTheme('light')).toBe('dark');
        expect(nextTheme('dark')).toBe('auto');
        expect(nextTheme('unknown')).toBe('auto');
    });

    it('validates theme names', () => {
        expect(isValidTheme('light')).toBe(true);
        expect(isValidTheme('neon')).toBe(false);
        expect(isValidTheme(null)).toBe(false);
    });

    it('resolves auto from the system preference', () => {
        expect(resolveTheme('auto', true)).toBe('dark');
        expect(resolveTheme('auto', false)).toBe('light');
        expect(resolveTheme('light', true)).toBe('light');
        expect(resolveTheme('dark', false)).toBe('dark');
    });

    it('reads a stored theme and defaults to auto', () => {
        expect(readStoredTheme(fakeStorage({ [THEME_STORAGE_KEY]: 'dark' }))).toBe('dark');
        expect(readStoredTheme(fakeStorage({ [THEME_STORAGE_KEY]: 'neon' }))).toBe('auto');
        expect(readStoredTheme(null)).toBe('auto');
    });

    it('never throws when storage is unavailable', () => {
        const broken = {
            getItem() {
                throw new Error('denied');
            },
            setItem() {
                throw new Error('denied');
            }
        };
        expect(readStoredTheme(broken)).toBe('auto');
        expect(storeTheme(broken, 'dark')).toBe(false);
    });

    it('refuses to store an invalid theme', () => {
        const storage = fakeStorage();
        expect(storeTheme(storage, 'neon')).toBe(false);
        expect(storage.data[THEME_STORAGE_KEY]).toBeUndefined();
    });

    it('applies the theme on the root element', () => {
        const root = fakeRoot();
        expect(applyTheme(root, 'auto', true)).toBe('dark');
        expect(root.getAttribute('data-bs-theme')).toBe('dark');
        expect(root.getAttribute('data-theme-mode')).toBe('auto');
    });
});
