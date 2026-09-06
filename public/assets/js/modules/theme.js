/**
 * Gestion du thème clair / sombre / automatique.
 * Aucune étape de build : ce fichier est un module ES chargé tel quel.
 */

export const THEME_STORAGE_KEY = 'secondstay.theme';
export const THEME_ORDER = ['auto', 'light', 'dark'];

export function isValidTheme(mode) {
    return THEME_ORDER.includes(mode);
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
        const value = storage?.getItem(THEME_STORAGE_KEY);
        return isValidTheme(value) ? value : 'auto';
    } catch {
        // Un navigateur qui refuse le stockage — fenêtre privée, cookies
        // bloqués — n'est pas une panne : le thème automatique est le défaut,
        // et c'est exactement ce qu'il faut rendre.
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
    } catch {
        // Même raison qu'à la lecture : ne pas pouvoir mémoriser la préférence
        // ne casse rien, l'appelant apprend seulement qu'elle n'a pas été
        // retenue.
        return false;
    }
}

export function applyTheme(root, mode, prefersDark) {
    const effective = resolveTheme(mode, prefersDark);
    root.dataset.themeMode = mode;
    root.dataset.bsTheme = effective;
    return effective;
}
