/**
 * Notifications push côté navigateur.
 *
 * Le module enregistre le service worker, gère l'abonnement et le
 * désabonnement, et n'expose que des fonctions pures pour la partie
 * testable : conversion de clé et lecture d'état.
 *
 * Le résultat d'un abonnement ou d'un désabonnement s'affiche dans un toast
 * (`toast.js`), comme toute mise à jour faite sans recharger la page. Seul un
 * navigateur incapable de recevoir le push l'écrit en ligne : c'est un état
 * durable de la page, pas le résultat d'une action.
 */

import { showToast } from './toast.js';
import { documentOf } from './dom.js';

export function base64UrlToUint8Array(value) {
    const input = String(value ?? '');
    if (!/^[A-Za-z0-9_-]+$/.test(input)) {
        throw new Error('invalid application server key');
    }

    const padded = input.replaceAll('-', '+').replaceAll('_', '/') + '='.repeat((4 - (input.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.codePointAt(i);
    }

    return bytes;
}

export function arrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCodePoint(byte);
    }

    // `={1,3}$` plutôt que `=+$` : le remplissage base64 ne dépasse jamais
    // trois signes, et un quantificateur borné retire le retour arrière.
    return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/={1,3}$/, '');
}

export function isSupported(scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);

    return Boolean(
        target.navigator?.serviceWorker
        && target.PushManager
        && target.Notification
    );
}

/**
 * Transforme l'abonnement du navigateur en charge utile serveur.
 */
export function serialiseSubscription(subscription) {
    return {
        endpoint: subscription.endpoint,
        keys: {
            p256dh: arrayBufferToBase64Url(subscription.getKey('p256dh')),
            auth: arrayBufferToBase64Url(subscription.getKey('auth'))
        }
    };
}

async function postJson(url, csrf, payload, fetcher) {
    const call = fetcher || fetch;
    const response = await call(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
            Accept: 'application/json'
        },
        body: JSON.stringify(payload || {})
    });

    let body = {};
    try {
        body = await response.json();
    } catch (error) {
        body = {};
    }

    return { status: response.status, body };
}

/**
 * Enregistre le service worker. Sans lui, ni cache hors ligne ni push.
 */
export async function registerServiceWorker(scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    if (!target.navigator?.serviceWorker) {
        return null;
    }

    const base = target.document?.documentElement?.dataset.basePath || '';

    try {
        return await target.navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' });
    } catch (error) {
        return null;
    }
}

/**
 * Message d'état durable, écrit en ligne (navigateur non compatible).
 */
function report(element, key, variant) {
    if (!element) {
        return;
    }
    element.textContent = element.dataset[key] || '';
    element.className = 'small mt-2 mb-0 ' + (variant === 'error' ? 'text-danger' : 'text-success');
}

/**
 * Résultat d'une action : un toast, avec le texte que porte l'élément d'état
 * (traduit côté serveur) ou le message d'erreur reçu.
 */
function notify(root, target, element, key, variant, message) {
    const doc = documentOf(root);
    const text = message ?? (element?.dataset[key] || '');
    if (text !== '') {
        showToast(doc, target, text, { variant });
    }
}

/**
 * Boutons d'abonnement de la page compte.
 */
export function initPushControls(root, scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    const controls = root.querySelector('[data-push-controls]');
    if (!controls) {
        return false;
    }

    const subscribeButton = controls.querySelector('[data-push-subscribe]');
    const unsubscribeButton = controls.querySelector('[data-push-unsubscribe]');
    const status = root.querySelector('[data-push-status]');
    const counter = root.querySelector('[data-push-devices]');

    if (!isSupported(target)) {
        if (subscribeButton) {
            subscribeButton.disabled = true;
        }
        report(status, 'unsupported', 'error');
        return false;
    }

    const csrf = controls.dataset.csrf || '';
    const base = controls.dataset.base || '';

    const showState = (subscribed) => {
        if (subscribeButton) {
            subscribeButton.hidden = subscribed;
        }
        if (unsubscribeButton) {
            unsubscribeButton.hidden = !subscribed;
        }
    };

    const currentSubscription = async () => {
        const registration = await target.navigator.serviceWorker.ready;
        return registration.pushManager.getSubscription();
    };

    currentSubscription().then((subscription) => showState(Boolean(subscription))).catch(() => showState(false));

    if (subscribeButton) {
        subscribeButton.addEventListener('click', async () => {
            subscribeButton.disabled = true;
            try {
                const permission = await target.Notification.requestPermission();
                if (permission !== 'granted') {
                    notify(root, target, status, 'denied', 'error');
                    subscribeButton.disabled = false;
                    return;
                }

                const key = await postJson(base + '/api/push/key', csrf, {}, target.fetch);
                if (!key.body.key) {
                    throw new Error('missing key');
                }

                const registration = await target.navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: base64UrlToUint8Array(key.body.key)
                });

                const result = await postJson(
                    base + '/api/push/subscribe',
                    csrf,
                    serialiseSubscription(subscription),
                    target.fetch
                );
                if (!result.body.ok) {
                    throw new Error(result.body.error || 'error');
                }

                if (counter) {
                    counter.textContent = String(Number(counter.textContent || '0') + 1);
                }
                notify(root, target, status, 'enabled', 'success');
                showState(true);
            } catch (error) {
                notify(root, target, status, '', 'error', error?.message ? error.message : String(error));
            } finally {
                subscribeButton.disabled = false;
            }
        });
    }

    if (unsubscribeButton) {
        unsubscribeButton.addEventListener('click', async () => {
            unsubscribeButton.disabled = true;
            try {
                const subscription = await currentSubscription();
                if (subscription) {
                    await postJson(base + '/api/push/unsubscribe', csrf, { endpoint: subscription.endpoint }, target.fetch);
                    await subscription.unsubscribe();
                    if (counter) {
                        counter.textContent = String(Math.max(0, Number(counter.textContent || '1') - 1));
                    }
                }
                notify(root, target, status, 'disabled', 'success');
                showState(false);
            } catch (error) {
                notify(root, target, status, '', 'error', error?.message ? error.message : String(error));
            } finally {
                unsubscribeButton.disabled = false;
            }
        });
    }

    return true;
}
