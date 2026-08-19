/**
 * Visionneuse de galerie, sans dépendance externe.
 *
 * La logique de navigation est pure et testable : le module DOM se contente de
 * l'appliquer.
 */

export function createLightboxState(items) {
    const list = Array.isArray(items) ? items : [];
    return { items: list, index: 0, open: false };
}

export function openAt(state, index) {
    if (state.items.length === 0) {
        return { ...state, open: false, index: 0 };
    }
    const bounded = Math.min(Math.max(Number(index) || 0, 0), state.items.length - 1);
    return { ...state, index: bounded, open: true };
}

export function next(state) {
    if (state.items.length === 0) {
        return state;
    }
    return { ...state, index: (state.index + 1) % state.items.length };
}

export function previous(state) {
    if (state.items.length === 0) {
        return state;
    }
    return { ...state, index: (state.index - 1 + state.items.length) % state.items.length };
}

export function close(state) {
    return { ...state, open: false };
}

export function current(state) {
    return state.items[state.index] ?? null;
}

export function initGalleryLightbox(root, document) {
    const gallery = root.querySelector('[data-lightbox-gallery]');
    const dialog = root.querySelector('[data-lightbox]');
    if (!gallery || !dialog) {
        return null;
    }

    const triggers = Array.from(gallery.querySelectorAll('[data-lightbox-open]'));
    const items = triggers.map((trigger) => ({
        src: trigger.dataset.full,
        caption: trigger.dataset.caption || '',
        alt: trigger.querySelector('img')?.getAttribute('alt') || ''
    }));

    let state = createLightboxState(items);

    const image = dialog.querySelector('[data-lightbox-image]');
    const caption = dialog.querySelector('[data-lightbox-caption]');

    function render() {
        const item = current(state);
        if (!item) {
            return;
        }
        image.setAttribute('src', item.src);
        image.setAttribute('alt', item.alt);
        caption.textContent = item.caption;
        dialog.hidden = !state.open;
        if (state.open) {
            dialog.dataset.index = String(state.index);
        }
    }

    triggers.forEach((trigger, index) => {
        trigger.addEventListener('click', () => {
            state = openAt(state, index);
            render();
            dialog.querySelector('[data-lightbox-close]')?.focus();
        });
    });

    dialog.querySelector('[data-lightbox-close]')?.addEventListener('click', () => {
        state = close(state);
        dialog.hidden = true;
        triggers[state.index]?.focus();
    });
    dialog.querySelector('[data-lightbox-next]')?.addEventListener('click', () => {
        state = next(state);
        render();
    });
    dialog.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => {
        state = previous(state);
        render();
    });

    document.addEventListener('keydown', (event) => {
        if (dialog.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            state = close(state);
            dialog.hidden = true;
        } else if (event.key === 'ArrowRight') {
            state = next(state);
            render();
        } else if (event.key === 'ArrowLeft') {
            state = previous(state);
            render();
        }
    });

    return { getState: () => state };
}
