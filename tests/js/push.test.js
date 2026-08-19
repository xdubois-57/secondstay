import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import {
    arrayBufferToBase64Url,
    base64UrlToUint8Array,
    initPushControls,
    isSupported,
    registerServiceWorker,
    serialiseSubscription
} from '../../public/assets/js/modules/push.js';

function bytes(...values) {
    return new Uint8Array(values);
}

describe('clé serveur applicative', () => {
    it('décode une clé VAPID base64url en octets', () => {
        const key = arrayBufferToBase64Url(bytes(4, 200, 13, 255));
        expect(Array.from(base64UrlToUint8Array(key))).toEqual([4, 200, 13, 255]);
    });

    it('fait l’aller-retour pour toutes les longueurs de reste', () => {
        for (let length = 1; length < 10; length += 1) {
            const source = new Uint8Array(length);
            for (let i = 0; i < length; i += 1) {
                source[i] = (i * 53 + 7) % 256;
            }
            const encoded = arrayBufferToBase64Url(source);
            expect(encoded).not.toMatch(/[+/=]/);
            expect(Array.from(base64UrlToUint8Array(encoded))).toEqual(Array.from(source));
        }
    });

    it('refuse une clé qui n’est pas du base64url', () => {
        expect(() => base64UrlToUint8Array('abc+def')).toThrow();
        expect(() => base64UrlToUint8Array('abc=')).toThrow();
        expect(() => base64UrlToUint8Array('')).toThrow();
    });
});

describe('détection du support', () => {
    it('exige service worker, PushManager et Notification', () => {
        expect(isSupported({})).toBe(false);
        expect(isSupported({ navigator: { serviceWorker: {} } })).toBe(false);
        expect(isSupported({ navigator: { serviceWorker: {} }, PushManager: function () {} })).toBe(false);
        expect(
            isSupported({
                navigator: { serviceWorker: {} },
                PushManager: function () {},
                Notification: { requestPermission: () => {} }
            })
        ).toBe(true);
    });
});

describe('sérialisation de l’abonnement', () => {
    it('encode l’endpoint et les deux clés', () => {
        const payload = serialiseSubscription({
            endpoint: 'https://push.example.test/s/1',
            getKey: (name) => (name === 'p256dh' ? bytes(4, 1, 2).buffer : bytes(9, 9).buffer)
        });

        expect(payload.endpoint).toBe('https://push.example.test/s/1');
        expect(payload.keys.p256dh).toBe(arrayBufferToBase64Url(bytes(4, 1, 2)));
        expect(payload.keys.auth).toBe(arrayBufferToBase64Url(bytes(9, 9)));
    });
});

describe('enregistrement du service worker', () => {
    it('enregistre sw.js avec le préfixe d’installation', async () => {
        const register = vi.fn(async () => ({ scope: '/sejour/' }));
        const dom = new JSDOM('<!doctype html><html data-base-path="/sejour"><body></body></html>');

        const result = await registerServiceWorker({
            navigator: { serviceWorker: { register } },
            document: dom.window.document
        });

        expect(result).toEqual({ scope: '/sejour/' });
        expect(register).toHaveBeenCalledWith('/sejour/sw.js', { scope: '/sejour/' });
    });

    it('ne casse pas quand le navigateur refuse l’enregistrement', async () => {
        const dom = new JSDOM('<!doctype html><html><body></body></html>');

        await expect(
            registerServiceWorker({
                navigator: {
                    serviceWorker: {
                        register: async () => {
                            throw new Error('SecurityError');
                        }
                    }
                },
                document: dom.window.document
            })
        ).resolves.toBeNull();
    });

    it('ne fait rien sans service worker', async () => {
        await expect(registerServiceWorker({})).resolves.toBeNull();
    });
});

describe('boutons d’abonnement', () => {
    let document;

    const markup = `
        <div data-push-controls data-csrf="tok" data-base="/app">
            <button data-push-subscribe></button>
            <button data-push-unsubscribe hidden></button>
        </div>
        <span data-push-devices>0</span>
        <p data-push-status data-enabled="Activées" data-disabled="Désactivées"
           data-unsupported="Non supporté" data-denied="Refusé"></p>`;

    beforeEach(() => {
        document = new JSDOM('<!doctype html><html><body></body></html>').window.document;
        document.body.innerHTML = markup;
    });

    function scope({ permission = 'granted', subscription = null, fetcher } = {}) {
        const subscribe = vi.fn(async () => ({
            endpoint: 'https://push.example.test/s/1',
            getKey: () => bytes(1, 2, 3).buffer,
            unsubscribe: vi.fn(async () => true)
        }));

        return {
            PushManager: function () {},
            Notification: { requestPermission: vi.fn(async () => permission) },
            navigator: {
                serviceWorker: {
                    ready: Promise.resolve({
                        pushManager: {
                            subscribe,
                            getSubscription: async () => subscription
                        }
                    })
                }
            },
            fetch: fetcher,
            subscribeSpy: subscribe
        };
    }

    it('signale l’absence de support et désactive le bouton', () => {
        expect(initPushControls(document, {})).toBe(false);
        expect(document.querySelector('[data-push-subscribe]').disabled).toBe(true);
        expect(document.querySelector('[data-push-status]').textContent).toBe('Non supporté');
    });

    it('ne fait rien sur une page sans contrôle de notification', () => {
        document.body.innerHTML = '<p>rien</p>';
        expect(initPushControls(document, {})).toBe(false);
    });

    it('abonne l’appareil et transmet les clés au serveur', async () => {
        const fetcher = vi.fn(async (url) => ({
            status: 200,
            json: async () => (url.endsWith('/key') ? { key: 'QUJD' } : { ok: true })
        }));
        const target = scope({ fetcher });

        expect(initPushControls(document, target)).toBe(true);
        document.querySelector('[data-push-subscribe]').click();

        await vi.waitFor(() =>
            expect(document.querySelector('[data-push-status]').textContent).toBe('Activées')
        );

        expect(fetcher.mock.calls[0][0]).toBe('/app/api/push/key');
        expect(fetcher.mock.calls[1][0]).toBe('/app/api/push/subscribe');
        expect(fetcher.mock.calls[1][1].headers['X-CSRF-Token']).toBe('tok');

        const body = JSON.parse(fetcher.mock.calls[1][1].body);
        expect(body.endpoint).toBe('https://push.example.test/s/1');
        expect(body.keys.p256dh).toBe(arrayBufferToBase64Url(bytes(1, 2, 3)));

        // Le compteur d'appareils suit, et l'abonnement utilise la clé serveur.
        expect(document.querySelector('[data-push-devices]').textContent).toBe('1');
        expect(target.subscribeSpy.mock.calls[0][0].userVisibleOnly).toBe(true);
        expect(Array.from(target.subscribeSpy.mock.calls[0][0].applicationServerKey)).toEqual([65, 66, 67]);

        expect(document.querySelector('[data-push-subscribe]').hidden).toBe(true);
        expect(document.querySelector('[data-push-unsubscribe]').hidden).toBe(false);
    });

    it('n’abonne rien quand la permission est refusée', async () => {
        const fetcher = vi.fn();
        const target = scope({ permission: 'denied', fetcher });

        initPushControls(document, target);
        document.querySelector('[data-push-subscribe]').click();

        await vi.waitFor(() =>
            expect(document.querySelector('[data-push-status]').textContent).toBe('Refusé')
        );
        expect(fetcher).not.toHaveBeenCalled();
        expect(target.subscribeSpy).not.toHaveBeenCalled();
    });

    it('affiche l’erreur traduite renvoyée par le serveur', async () => {
        const fetcher = vi.fn(async (url) => ({
            status: url.endsWith('/key') ? 200 : 429,
            json: async () =>
                url.endsWith('/key')
                    ? { key: 'QUJD' }
                    : { ok: false, error: 'Trop d’appareils abonnés pour ce compte.' }
        }));

        initPushControls(document, scope({ fetcher }));
        document.querySelector('[data-push-subscribe]').click();

        await vi.waitFor(() =>
            expect(document.querySelector('[data-push-status]').textContent).toBe(
                'Trop d’appareils abonnés pour ce compte.'
            )
        );
        expect(document.querySelector('[data-push-devices]').textContent).toBe('0');
    });

    it('révèle le bouton de désabonnement quand un abonnement existe déjà', async () => {
        const unsubscribe = vi.fn(async () => true);
        const fetcher = vi.fn(async () => ({ status: 200, json: async () => ({ ok: true }) }));
        const target = scope({
            fetcher,
            subscription: {
                endpoint: 'https://push.example.test/s/1',
                unsubscribe,
                getKey: () => bytes(1).buffer
            }
        });
        document.querySelector('[data-push-devices]').textContent = '1';

        initPushControls(document, target);

        await vi.waitFor(() =>
            expect(document.querySelector('[data-push-unsubscribe]').hidden).toBe(false)
        );

        document.querySelector('[data-push-unsubscribe]').click();

        await vi.waitFor(() =>
            expect(document.querySelector('[data-push-status]').textContent).toBe('Désactivées')
        );
        expect(unsubscribe).toHaveBeenCalled();
        expect(JSON.parse(fetcher.mock.calls[0][1].body).endpoint).toBe('https://push.example.test/s/1');
        expect(document.querySelector('[data-push-devices]').textContent).toBe('0');
    });
});
