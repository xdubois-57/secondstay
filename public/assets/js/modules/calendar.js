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
    const start = Date.parse(selection.arrival + 'T00:00:00Z');
    const end = Date.parse(selection.departure + 'T00:00:00Z');

    // Tout est en UTC : un jour vaut exactement 86 400 000 ms, sans heure
    // d'été qui décalerait le pas. La variable de boucle avance donc
    // explicitement, plutôt qu'une date mutée en place dont on ne voit pas
    // qu'elle progresse.
    for (let time = start; time <= end; time += 86400000) {
        days.push(new Date(time).toISOString().slice(0, 10));
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
        adults: String(guests?.adults || 2),
        children: String(guests?.children || 0),
        infants: String(guests?.infants || 0)
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
            button.dataset.selected = highlighted.includes(day) ? '1' : '0';
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

    /**
     * Écrit un devis dans les champs du panneau.
     *
     * Extrait de `show()` : chacun de ces champs est facultatif — un gabarit
     * peut n'en afficher qu'une partie — et la suite de gardes qui en découle
     * portait à elle seule l'essentiel de la complexité de `show()`, dont le
     * propos est ailleurs : montrer le panneau, rendre les erreurs, reporter
     * la sélection dans le formulaire.
     */
    const paintQuote = (target, quote, quoteLocale, quoteCurrency) => {
        if (target.range) {
            target.range.textContent =
                formatDay(quote.arrival, quoteLocale) + ' – ' + formatDay(quote.departure, quoteLocale);
        }
        if (target.nights) {
            target.nights.textContent = String(quote.night_count);
            target.nights.dataset.nights = String(quote.night_count);
        }
        if (target.accommodation) {
            target.accommodation.textContent = formatMoney(quote.accommodation_cents, quoteLocale, quoteCurrency);
        }
        if (target.total) {
            target.total.textContent = formatMoney(quote.total_cents, quoteLocale, quoteCurrency);
            target.total.dataset.cents = String(quote.total_cents);
        }
        if (target.cleaning && target.cleaningLabel) {
            const visible = quote.cleaning_cents > 0;
            target.cleaning.hidden = !visible;
            target.cleaningLabel.hidden = !visible;
            target.cleaning.textContent = formatMoney(quote.cleaning_cents, quoteLocale, quoteCurrency);
        }
    };

    const show = (result) => {
        if (!panel) {
            return;
        }

        panel.hidden = false;

        const quote = result.quote;
        if (quote) {
            paintQuote(fields, quote, locale, currency);
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
