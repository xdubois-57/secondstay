/**
 * Helpers de langue partagés entre le rendu serveur et le client.
 * La liste doit rester alignée sur SecondStay\I18n\Locales::ALL.
 */

export const SUPPORTED_LOCALES = ['fr', 'en', 'nl', 'de'];
export const FALLBACK_LOCALE = 'fr';

export function isSupportedLocale(locale) {
    return typeof locale === 'string' && SUPPORTED_LOCALES.includes(locale.toLowerCase());
}

export function normaliseLocale(locale) {
    if (typeof locale !== 'string' || locale.trim() === '') {
        return null;
    }
    const primary = locale.trim().toLowerCase().replace('_', '-').split('-')[0];
    return isSupportedLocale(primary) ? primary : null;
}

/**
 * Remplace (ou ajoute) le préfixe de langue d'un chemin local.
 * Ne produit jamais d'URL absolue : pas de redirection ouverte.
 */
export function localisePath(path, locale) {
    const target = normaliseLocale(locale) || FALLBACK_LOCALE;
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) {
        return '/' + target + '/';
    }

    const [withoutQuery, query] = path.split('?');
    const segments = withoutQuery.split('/').filter((segment) => segment !== '');

    if (segments.length > 0 && isSupportedLocale(segments[0])) {
        segments[0] = target;
    } else {
        segments.unshift(target);
    }

    if (segments.length === 1) {
        return query ? '/' + target + '/?' + query : '/' + target + '/';
    }

    const rebuilt = '/' + segments.join('/');
    return query ? rebuilt + '?' + query : rebuilt;
}
