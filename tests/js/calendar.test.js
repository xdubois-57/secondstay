import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import {
    createSelection,
    fetchQuote,
    formatDay,
    formatMoney,
    highlightedDays,
    initCalendar,
    isComplete,
    nightCount,
    quoteUrl,
    selectDay
} from '../../public/assets/js/modules/calendar.js';

describe('sélection d’une plage', () => {
    it('le premier clic pose l’arrivée', () => {
        const selection = selectDay(createSelection(), '2026-07-12');

        expect(selection).toEqual({ arrival: '2026-07-12', departure: null });
        expect(isComplete(selection)).toBe(false);
    });

    it('le second clic postérieur pose le départ', () => {
        const selection = selectDay(selectDay(createSelection(), '2026-07-12'), '2026-07-19');

        expect(selection).toEqual({ arrival: '2026-07-12', departure: '2026-07-19' });
        expect(isComplete(selection)).toBe(true);
        expect(nightCount(selection)).toBe(7);
    });

    it('un second clic antérieur recommence la sélection', () => {
        const selection = selectDay(selectDay(createSelection(), '2026-07-19'), '2026-07-12');

        expect(selection).toEqual({ arrival: '2026-07-12', departure: null });
    });

    it('cliquer deux fois le même jour ne crée pas de séjour de zéro nuit', () => {
        const selection = selectDay(selectDay(createSelection(), '2026-07-12'), '2026-07-12');

        expect(selection.departure).toBeNull();
        expect(nightCount(selection)).toBe(0);
    });

    it('un troisième clic repart d’une arrivée', () => {
        let selection = selectDay(createSelection(), '2026-07-12');
        selection = selectDay(selection, '2026-07-19');
        selection = selectDay(selection, '2026-08-01');

        expect(selection).toEqual({ arrival: '2026-08-01', departure: null });
    });

    it('compte les nuits à travers un changement d’heure', () => {
        // Passage à l'heure d'été dans la nuit du 28 au 29 mars 2026.
        expect(nightCount({ arrival: '2026-03-27', departure: '2026-03-31' })).toBe(4);
        // Et à l'heure d'hiver fin octobre.
        expect(nightCount({ arrival: '2026-10-24', departure: '2026-10-27' })).toBe(3);
    });

    it('compte les nuits par-dessus une fin d’année et un 29 février', () => {
        expect(nightCount({ arrival: '2026-12-28', departure: '2027-01-04' })).toBe(7);
        expect(nightCount({ arrival: '2028-02-27', departure: '2028-03-02' })).toBe(4);
    });
});

describe('jours mis en évidence', () => {
    it('n’éclaire rien sans sélection', () => {
        expect(highlightedDays(createSelection())).toEqual([]);
    });

    it('éclaire la seule arrivée tant que le départ manque', () => {
        expect(highlightedDays({ arrival: '2026-07-12', departure: null })).toEqual(['2026-07-12']);
    });

    it('éclaire les nuits et le jour de départ', () => {
        const days = highlightedDays({ arrival: '2026-07-12', departure: '2026-07-15' });

        expect(days).toEqual(['2026-07-12', '2026-07-13', '2026-07-14', '2026-07-15']);
    });

    it('traverse un changement de mois', () => {
        const days = highlightedDays({ arrival: '2026-07-30', departure: '2026-08-02' });

        expect(days).toEqual(['2026-07-30', '2026-07-31', '2026-08-01', '2026-08-02']);
    });
});

describe('formatage localisé', () => {
    it('affiche le même montant selon les règles de chaque langue', () => {
        const rendered = ['fr-FR', 'en-GB', 'nl-NL', 'de-DE'].map((locale) => formatMoney(133000, locale, 'EUR'));

        for (const value of rendered) {
            // Les chiffres sont identiques : seule la présentation change.
            expect(value.replace(/\D+/gu, '')).toBe('133000');
        }
        expect(new Set(rendered).size).toBeGreaterThan(1);
    });

    it('formate une date sans décalage de fuseau', () => {
        expect(formatDay('2026-07-12', 'fr-FR')).toContain('12');
        expect(formatDay('2026-01-01', 'fr-FR')).toContain('1');
        // Une date proche de minuit ne doit pas glisser d'un jour.
        expect(formatDay('2026-12-31', 'en-GB')).toContain('31');
    });
});

describe('appel de devis', () => {
    it('construit une URL complète', () => {
        const url = quoteUrl('/app', { arrival: '2026-07-12', departure: '2026-07-19' }, { adults: 4, children: 1 });

        expect(url).toContain('/app/api/quote?');
        expect(url).toContain('arrival=2026-07-12');
        expect(url).toContain('departure=2026-07-19');
        expect(url).toContain('adults=4');
        expect(url).toContain('children=1');
        expect(url).toContain('infants=0');
    });

    it('applique des valeurs par défaut raisonnables', () => {
        const url = quoteUrl('', { arrival: '2026-07-12', departure: '2026-07-19' }, null);

        expect(url).toContain('adults=2');
        expect(url).toContain('children=0');
    });

    it('ne casse pas sur une réponse illisible', async () => {
        const fetcher = vi.fn(async () => ({
            json: async () => {
                throw new Error('not json');
            }
        }));

        await expect(
            fetchQuote('', { arrival: '2026-07-12', departure: '2026-07-19' }, null, fetcher)
        ).resolves.toEqual({ ok: false, errors: [], quote: null, conflicts: [] });
    });
});

describe('amorce DOM', () => {
    let document;

    const markup = `
        <div data-calendar data-base="/app" data-locale="fr-FR" data-currency="EUR" data-month="2026-07">
            <button data-day="2026-07-12" data-state="free" data-price="12000"></button>
            <button data-day="2026-07-13" data-state="free" data-price="12000"></button>
            <button data-day="2026-07-14" data-state="blocked" data-price="12000" disabled></button>
            <button data-day="2026-07-19" data-state="free" data-price="25000"></button>
        </div>
        <div data-quote hidden>
            <span data-quote-range></span>
            <span data-quote-nights></span>
            <span data-quote-accommodation></span>
            <span data-quote-cleaning-label hidden></span>
            <span data-quote-cleaning hidden></span>
            <span data-quote-total></span>
            <p data-quote-errors></p>
            <button data-quote-reset></button>
        </div>`;

    beforeEach(() => {
        document = new JSDOM('<!doctype html><html><body></body></html>').window.document;
        document.body.innerHTML = markup;
    });

    function fetcherReturning(payload) {
        return vi.fn(async () => ({ status: 200, json: async () => payload }));
    }

    it('ne fait rien sur une page sans calendrier', () => {
        document.body.innerHTML = '<p>rien</p>';
        expect(initCalendar(document, {})).toBe(false);
    });

    it('affiche le total renvoyé par le serveur', async () => {
        const fetcher = fetcherReturning({
            ok: true,
            errors: [],
            conflicts: [],
            quote: {
                arrival: '2026-07-12',
                departure: '2026-07-19',
                night_count: 7,
                accommodation_cents: 123000,
                cleaning_cents: 10000,
                total_cents: 133000
            }
        });

        expect(initCalendar(document, { fetch: fetcher })).toBe(true);

        document.querySelector('[data-day="2026-07-12"]').click();
        expect(document.querySelector('[data-quote]').hidden).toBe(true);

        document.querySelector('[data-day="2026-07-19"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-quote]').hidden).toBe(false));

        expect(fetcher.mock.calls[0][0]).toContain('/app/api/quote?arrival=2026-07-12&departure=2026-07-19');
        expect(document.querySelector('[data-quote-nights]').textContent).toBe('7');
        expect(document.querySelector('[data-quote-total]').dataset.cents).toBe('133000');
        expect(document.querySelector('[data-quote-total]').textContent.replace(/\D+/gu, '')).toBe('133000');
        expect(document.querySelector('[data-quote-cleaning]').hidden).toBe(false);
        expect(document.querySelector('[data-quote]').dataset.ok).toBe('1');
    });

    it('met en évidence les nuits sélectionnées', async () => {
        initCalendar(document, { fetch: fetcherReturning({ ok: true, errors: [], conflicts: [], quote: null }) });

        document.querySelector('[data-day="2026-07-12"]').click();
        expect(document.querySelector('[data-day="2026-07-12"]').dataset.selected).toBe('1');
        expect(document.querySelector('[data-day="2026-07-13"]').dataset.selected).toBe('0');

        document.querySelector('[data-day="2026-07-19"]').click();
        await vi.waitFor(() =>
            expect(document.querySelector('[data-day="2026-07-13"]').dataset.selected).toBe('1')
        );
        expect(document.querySelector('[data-day="2026-07-12"]').dataset.edge).toBe('1');
        expect(document.querySelector('[data-day="2026-07-19"]').dataset.edge).toBe('1');
        expect(document.querySelector('[data-day="2026-07-13"]').dataset.edge).toBe('0');
    });

    it('ignore une nuit indisponible', () => {
        initCalendar(document, { fetch: vi.fn() });

        document.querySelector('[data-day="2026-07-14"]').click();

        expect(document.querySelector('[data-day="2026-07-14"]').dataset.selected).toBeUndefined();
        expect(document.querySelector('[data-quote]').hidden).toBe(true);
    });

    it('affiche les erreurs traduites du serveur', async () => {
        const fetcher = fetcherReturning({
            ok: false,
            errors: ['Ces dates ne sont pas disponibles.'],
            conflicts: ['2026-07-14'],
            quote: {
                arrival: '2026-07-12',
                departure: '2026-07-19',
                night_count: 7,
                accommodation_cents: 123000,
                cleaning_cents: 0,
                total_cents: 123000
            }
        });

        initCalendar(document, { fetch: fetcher });
        document.querySelector('[data-day="2026-07-12"]').click();
        document.querySelector('[data-day="2026-07-19"]').click();

        await vi.waitFor(() =>
            expect(document.querySelector('[data-quote-errors]').textContent).toBe(
                'Ces dates ne sont pas disponibles.'
            )
        );
        expect(document.querySelector('[data-quote]').dataset.ok).toBe('0');
        // Le ménage absent n'apparaît pas.
        expect(document.querySelector('[data-quote-cleaning]').hidden).toBe(true);
    });

    it('le bouton de reprise efface la sélection', async () => {
        initCalendar(document, {
            fetch: fetcherReturning({ ok: true, errors: [], conflicts: [], quote: null })
        });

        document.querySelector('[data-day="2026-07-12"]').click();
        document.querySelector('[data-day="2026-07-19"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-quote]').hidden).toBe(false));

        document.querySelector('[data-quote-reset]').click();

        expect(document.querySelector('[data-quote]').hidden).toBe(true);
        expect(document.querySelector('[data-day="2026-07-12"]').dataset.selected).toBe('0');
    });
});
