import { describe, expect, it } from 'vitest';
import {
    FALLBACK_LOCALE,
    SUPPORTED_LOCALES,
    isSupportedLocale,
    localisePath,
    normaliseLocale
} from '../../public/assets/js/modules/locale.js';

describe('locale', () => {
    it('supports exactly fr, en, nl and de', () => {
        expect(SUPPORTED_LOCALES).toEqual(['fr', 'en', 'nl', 'de']);
        expect(FALLBACK_LOCALE).toBe('fr');
    });

    it('detects supported locales', () => {
        expect(isSupportedLocale('nl')).toBe(true);
        expect(isSupportedLocale('DE')).toBe(true);
        expect(isSupportedLocale('es')).toBe(false);
        expect(isSupportedLocale(undefined)).toBe(false);
    });

    it('normalises regional tags', () => {
        expect(normaliseLocale('nl-BE')).toBe('nl');
        expect(normaliseLocale('de_AT')).toBe('de');
        expect(normaliseLocale('  EN  ')).toBe('en');
        expect(normaliseLocale('es-ES')).toBeNull();
        expect(normaliseLocale('')).toBeNull();
    });

    it('replaces the locale prefix of a path', () => {
        expect(localisePath('/fr/tarifs', 'de')).toBe('/de/tarifs');
        expect(localisePath('/tarifs', 'nl')).toBe('/nl/tarifs');
        expect(localisePath('/', 'en')).toBe('/en/');
        expect(localisePath('/fr/', 'en')).toBe('/en/');
    });

    it('preserves the query string', () => {
        expect(localisePath('/fr/recherche?q=mer', 'de')).toBe('/de/recherche?q=mer');
    });

    it('never produces an absolute or protocol-relative URL', () => {
        expect(localisePath('//evil.example.com/', 'fr')).toBe('/fr/');
        expect(localisePath('https://evil.example.com/', 'fr')).toBe('/fr/');
        expect(localisePath(null, 'fr')).toBe('/fr/');
    });
});
