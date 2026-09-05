/**
 * Formatage localisé côté client.
 * La logique financière canonique reste en centimes entiers.
 */

const ICU = { fr: 'fr-FR', en: 'en-GB', nl: 'nl-NL', de: 'de-DE' };

export function icuLocale(locale) {
    return ICU[locale] || ICU.fr;
}

export function formatMoney(cents, locale, currency = 'EUR') {
    if (!Number.isInteger(cents)) {
        throw new TypeError('Les montants doivent être exprimés en centimes entiers.');
    }
    return new Intl.NumberFormat(icuLocale(locale), {
        style: 'currency',
        currency
    }).format(cents / 100);
}

/**
 * @param {string|Date} isoDate
 * @param {string} locale
 * @param {Intl.DateTimeFormatOptions} [options]
 */
export function formatDate(isoDate, locale, options = { dateStyle: 'medium' }) {
    const date = isoDate instanceof Date ? isoDate : new Date(isoDate + 'T00:00:00');
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return new Intl.DateTimeFormat(icuLocale(locale), options).format(date);
}

export function parseIsoDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
        return null;
    }
    return date;
}

export function toIsoDate(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function nightsBetween(startIso, endIso) {
    const start = parseIsoDate(startIso);
    const end = parseIsoDate(endIso);
    if (!start || !end) {
        return 0;
    }
    const diff = end.getTime() - start.getTime();
    return diff <= 0 ? 0 : Math.round(diff / 86400000);
}
