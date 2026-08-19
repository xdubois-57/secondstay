import { describe, expect, it } from 'vitest';
import {
    formatDate,
    formatMoney,
    icuLocale,
    nightsBetween,
    parseIsoDate,
    toIsoDate
} from '../../public/assets/js/modules/format.js';

const nbsp = / | /g;

describe('format', () => {
    it('maps application locales to ICU locales', () => {
        expect(icuLocale('fr')).toBe('fr-FR');
        expect(icuLocale('nl')).toBe('nl-NL');
        expect(icuLocale('xx')).toBe('fr-FR');
    });

    it('formats money from integer cents in every locale', () => {
        expect(formatMoney(123456, 'fr').replace(nbsp, ' ')).toContain('1 234,56');
        expect(formatMoney(123456, 'de')).toContain('1.234,56');
        expect(formatMoney(123456, 'nl')).toContain('1.234,56');
        expect(formatMoney(123456, 'en')).toContain('1,234.56');
    });

    it('refuses non integer amounts', () => {
        expect(() => formatMoney(12.5, 'fr')).toThrow(TypeError);
    });

    it('formats dates per locale', () => {
        expect(formatDate('2026-07-04', 'fr', { dateStyle: 'long' })).toContain('juillet');
        expect(formatDate('2026-07-04', 'de', { dateStyle: 'long' })).toContain('Juli');
        expect(formatDate('not-a-date', 'fr')).toBe('');
    });

    it('parses and serialises ISO dates strictly', () => {
        expect(toIsoDate(parseIsoDate('2026-02-28'))).toBe('2026-02-28');
        expect(parseIsoDate('2026-02-30')).toBeNull();
        expect(parseIsoDate('04/07/2026')).toBeNull();
        expect(parseIsoDate(null)).toBeNull();
    });

    it('counts nights between two dates', () => {
        expect(nightsBetween('2026-07-04', '2026-07-11')).toBe(7);
        expect(nightsBetween('2026-07-11', '2026-07-04')).toBe(0);
        expect(nightsBetween('2026-07-04', '2026-07-04')).toBe(0);
        expect(nightsBetween('bad', '2026-07-04')).toBe(0);
    });
});
