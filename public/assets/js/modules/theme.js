/**
 * Gestion du thème clair / sombre / automatique.
 * Aucune étape de build : ce fichier est un module ES chargé tel quel.
 */

export const THEME_STORAGE_KEY = 'secondstay.theme';
export const THEME_ORDER = ['auto', 'light', 'dark'];

export function isValidTheme(mode) {
    return THEME_ORDER.indexOf(mode) !== -1;
}

export function nextTheme(mode) {
    const index = THEME_ORDER.indexOf(mode);
    return THEME_ORDER[(index + 1) % THEME_ORDER.length];
}

export function resolveTheme(mode, prefersDark) {
    if (mode === 'light' || mode === 'dark') {
        return mode;
    }
    return prefersDark ? 'dark' : 'light';
}

export function readStoredTheme(storage) {
    try {
        const value = storage && storage.getItem(THEME_STORAGE_KEY);
        return isValidTheme(value) ? value : 'auto';
    } catch (error) {
        return 'auto';
    }
}

export function storeTheme(storage, mode) {
    if (!isValidTheme(mode)) {
        return false;
    }
    try {
        storage.setItem(THEME_STORAGE_KEY, mode);
        return true;
    } catch (error) {
        return false;
    }
}

export function applyTheme(root, mode, prefersDark) {
    const effective = resolveTheme(mode, prefersDark);
    root.setAttribute('data-theme-mode', mode);
    root.setAttribute('data-bs-theme', effective);
    return effective;
}
