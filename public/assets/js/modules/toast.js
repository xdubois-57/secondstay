/**
 * Toasts — le retour unique d'une mise à jour faite sans recharger la page.
 *
 * Même composant que ScoutMagic, au pixel près : un toast Bootstrap
 * `text-bg-{ton}` sans bordure, dans un conteneur fixé en bas à droite, avec
 * un bouton de fermeture blanc. Un toast ressemble au site, s'empile,
 * n'attend personne, et s'annonce aux lecteurs d'écran : le conteneur est une
 * région `aria-live="polite"` — une région affirmative couperait la phrase en
 * cours pour ce qui est surtout une confirmation.
 *
 * Le message est toujours posé en `textContent` : un message n'injecte jamais
 * de balisage. Sans Bootstrap (script absent), le toast s'affiche quand même
 * et se retire de lui-même à l'échéance.
 */

const TONES = { success: 'success', error: 'danger', warning: 'warning', info: 'info' };

/**
 * @param {Document} doc
 * @returns {HTMLElement}
 */
function ensureContainer(doc) {
    const existing = /** @type {HTMLElement|null} */ (doc.querySelector('[data-toast-container]'));
    if (existing) {
        return existing;
    }
    const container = doc.createElement('div');
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    container.setAttribute('aria-live', 'polite');
    container.dataset.toastContainer = '';
    doc.body.appendChild(container);
    return container;
}

/**
 * Affiche un toast.
 *
 * @param {Document} doc
 * @param {Window|undefined} win    fenêtre portant `bootstrap.Toast`, si chargé
 * @param {string} message          texte brut
 * @param {{variant?: 'success'|'error'|'warning'|'info', delayMs?: number,
 *          action?: {label: string, onClick: () => void}}} [options]
 *        `variant` vaut `success` par défaut ; une erreur reste plus
 *        longtemps (8 s contre 4 s), c'est celle qu'on relit.
 * @returns {HTMLElement} l'élément du toast
 */
export function showToast(doc, win, message, options = {}) {
    const variant = options.variant || 'success';
    const tone = TONES[variant] || 'success';
    const delayMs = options.delayMs || (variant === 'error' ? 8000 : 4000);

    const toast = doc.createElement('div');
    toast.className = `toast align-items-center text-bg-${tone} border-0 show`;
    toast.setAttribute('role', 'status');
    toast.dataset.toastVariant = variant;

    const flex = doc.createElement('div');
    flex.className = 'd-flex';
    const body = doc.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;
    const close = doc.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('aria-label', doc.body?.dataset.toastCloseLabel || 'Fermer');
    flex.appendChild(body);

    /** @type {HTMLButtonElement|null} */
    let actionButton = null;
    if (options.action?.label) {
        actionButton = doc.createElement('button');
        actionButton.type = 'button';
        actionButton.className = 'btn btn-sm btn-light my-auto me-2';
        actionButton.textContent = options.action.label;
        flex.appendChild(actionButton);
    }
    flex.appendChild(close);
    toast.appendChild(flex);
    ensureContainer(doc).appendChild(toast);

    /** @type {() => void} */
    let dismiss;
    const Toast = /** @type {any} */ (win)?.bootstrap?.Toast;
    if (Toast) {
        const instance = new Toast(toast, { delay: delayMs });
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
        dismiss = () => instance.hide();
        instance.show();
    } else {
        const timer = setTimeout(() => toast.remove(), delayMs);
        dismiss = () => {
            clearTimeout(timer);
            toast.remove();
        };
    }
    close.addEventListener('click', () => dismiss());
    if (actionButton && options.action) {
        const onAction = options.action.onClick;
        actionButton.addEventListener('click', () => {
            dismiss();
            onAction();
        });
    }

    return toast;
}
