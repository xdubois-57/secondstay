/**
 * Calendrier de disponibilités : sélection d'une plage et total en direct.
 *
 * Le navigateur ne calcule aucun prix. Il sélectionne des dates, demande un
 * devis au serveur et l'affiche : le total montré pendant la sélection est
 * donc exactement celui qui sera facturé.
 *
 * Le formatage (montants, dates) est localisé côté navigateur avec `Intl`,
 * mais la logique financière reste des entiers de centimes.
 */

export function createSelection() {
    return { arrival: null, departure: null };
}

/**
 * Applique un clic sur un jour.
 *
 * Premier clic : arrivée. Second clic postérieur : départ. Un second clic
 * antérieur ou égal recommence une sélection à cette date — c'est ce qu'un
 * visiteur attend quand il « remonte » dans le calendrier.
 */
export function selectDay(selection, day) {
    if (!selection.arrival || selection.departure) {
        return { arrival: day, departure: null };
    }

    if (day <= selection.arrival) {
        return { arrival: day, departure: null };
    }

    return { arrival: selection.arrival, departure: day };
}

export function isComplete(selection) {
    return Boolean(selection.arrival && selection.departure);
}

/**
 * Jours à mettre en évidence : l'arrivée, les nuits, et le jour de départ.
 */
export function highlightedDays(selection) {
    if (!selection.arrival) {
        return [];
    }
    if (!selection.departure) {
        return [selection.arrival];
    }

    const days = [];
    const cursor = new Date(selection.arrival + 'T00:00:00Z');
    const end = new Date(selection.departure + 'T00:00:00Z');

    while (cursor <= end) {
        days.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return days;
}

/**
 * Nombre de nuits d'une sélection complète.
 */
export function nightCount(selection) {
    if (!isComplete(selection)) {
        return 0;
    }

    const start = Date.parse(selection.arrival + 'T00:00:00Z');
    const end = Date.parse(selection.departure + 'T00:00:00Z');

    return Math.round((end - start) / 86400000);
}

export function formatMoney(cents, locale, currency) {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currency || 'EUR'
    }).format(cents / 100);
}

export function formatDay(day, locale) {
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC'
    }).format(new Date(day + 'T00:00:00Z'));
}

export function quoteUrl(base, selection, guests) {
    const parameters = new URLSearchParams({
        arrival: selection.arrival,
        departure: selection.departure,
        adults: String((guests && guests.adults) || 2),
        children: String((guests && guests.children) || 0),
        infants: String((guests && guests.infants) || 0)
    });

    return base + '/api/quote?' + parameters.toString();
}

export async function fetchQuote(base, selection, guests, fetcher) {
    const call = fetcher || fetch;
    const response = await call(quoteUrl(base, selection, guests), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
    });

    try {
        return await response.json();
    } catch (error) {
        return { ok: false, errors: [], quote: null, conflicts: [] };
    }
}

/**
 * Amorce DOM du calendrier public.
 */
export function initCalendar(root, scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    const calendar = root.querySelector('[data-calendar]');
    if (!calendar) {
        return false;
    }

    const base = calendar.dataset.base || '';
    const locale = calendar.dataset.locale || 'fr';
    const currency = calendar.dataset.currency || 'EUR';

    const panel = root.querySelector('[data-quote]');
    const fields = {
        range: root.querySelector('[data-quote-range]'),
        nights: root.querySelector('[data-quote-nights]'),
        accommodation: root.querySelector('[data-quote-accommodation]'),
        cleaning: root.querySelector('[data-quote-cleaning]'),
        cleaningLabel: root.querySelector('[data-quote-cleaning-label]'),
        total: root.querySelector('[data-quote-total]'),
        errors: root.querySelector('[data-quote-errors]'),
        arrival: root.querySelector('[data-quote-arrival]'),
        departure: root.querySelector('[data-quote-departure]')
    };

    let selection = createSelection();

    const paint = () => {
        const highlighted = highlightedDays(selection);
        for (const button of root.querySelectorAll('[data-day]')) {
            const day = button.dataset.day;
            button.dataset.selected = highlighted.indexOf(day) === -1 ? '0' : '1';
            button.dataset.edge = day === selection.arrival || day === selection.departure ? '1' : '0';
        }
    };

    const clear = () => {
        selection = createSelection();
        paint();
        if (panel) {
            panel.hidden = true;
        }
    };

    const show = (result) => {
        if (!panel) {
            return;
        }

        panel.hidden = false;

        const quote = result.quote;
        if (quote) {
            if (fields.range) {
                fields.range.textContent =
                    formatDay(quote.arrival, locale) + ' – ' + formatDay(quote.departure, locale);
            }
            if (fields.nights) {
                fields.nights.textContent = String(quote.night_count);
                fields.nights.dataset.nights = String(quote.night_count);
            }
            if (fields.accommodation) {
                fields.accommodation.textContent = formatMoney(quote.accommodation_cents, locale, currency);
            }
            if (fields.total) {
                fields.total.textContent = formatMoney(quote.total_cents, locale, currency);
                fields.total.dataset.cents = String(quote.total_cents);
            }
            if (fields.cleaning && fields.cleaningLabel) {
                const visible = quote.cleaning_cents > 0;
                fields.cleaning.hidden = !visible;
                fields.cleaningLabel.hidden = !visible;
                fields.cleaning.textContent = formatMoney(quote.cleaning_cents, locale, currency);
            }
        }

        if (fields.errors) {
            fields.errors.textContent = (result.errors || []).join(' ');
            fields.errors.className = 'small mt-3 mb-0 ' + (result.ok ? 'text-body-secondary' : 'text-danger');
        }

        // Le formulaire de réservation reprend la sélection : le parcours
        // repart des mêmes dates que le devis affiché.
        if (fields.arrival && fields.departure && quote) {
            fields.arrival.value = quote.arrival;
            fields.departure.value = quote.departure;
        }

        const book = root.querySelector('[data-quote-book]');
        if (book) {
            book.hidden = !result.ok;
        }

        panel.dataset.ok = result.ok ? '1' : '0';
    };

    calendar.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-day]');
        if (!button || button.disabled) {
            return;
        }

        selection = selectDay(selection, button.dataset.day);
        paint();

        if (!isComplete(selection)) {
            if (panel) {
                panel.hidden = true;
            }
            return;
        }

        show(await fetchQuote(base, selection, null, target.fetch));
    });

    const reset = root.querySelector('[data-quote-reset]');
    if (reset) {
        reset.addEventListener('click', clear);
    }

    return true;
}
