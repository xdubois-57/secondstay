import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import {
    base64UrlToBytes,
    bytesToBase64Url,
    initPasskeyRegistration,
    initPasskeySignIn,
    isSupported,
    postJson,
    serialiseAssertion,
    serialiseAttestation,
    toCreationOptions,
    toRequestOptions
} from '../../public/assets/js/modules/passkey.js';

function bytes(...values) {
    return new Uint8Array(values);
}

describe('encodage base64url', () => {
    it('fait l’aller-retour sans perte pour toutes les longueurs de reste', () => {
        for (let length = 0; length < 12; length += 1) {
            const source = new Uint8Array(length);
            for (let i = 0; i < length; i += 1) {
                source[i] = (i * 37 + 11) % 256;
            }
            const encoded = bytesToBase64Url(source);
            expect(encoded).not.toMatch(/[+/=]/);
            expect(Array.from(base64UrlToBytes(encoded))).toEqual(Array.from(source));
        }
    });

    it('accepte un ArrayBuffer comme un Uint8Array', () => {
        const source = bytes(251, 252, 253);
        expect(bytesToBase64Url(source.buffer)).toBe(bytesToBase64Url(source));
    });

    it('refuse une chaîne qui n’est pas du base64url', () => {
        expect(() => base64UrlToBytes('abc+def')).toThrow();
        expect(() => base64UrlToBytes('abc/def')).toThrow();
        expect(() => base64UrlToBytes('abc=')).toThrow();
    });
});

describe('détection du support', () => {
    it('exige PublicKeyCredential et navigator.credentials.create', () => {
        expect(isSupported({})).toBe(false);
        expect(isSupported({ PublicKeyCredential: function () {} })).toBe(false);
        expect(
            isSupported({
                PublicKeyCredential: function () {},
                navigator: { credentials: { create: () => {} } }
            })
        ).toBe(true);
    });
});

describe('conversion des options serveur', () => {
    const options = {
        challenge: bytesToBase64Url(bytes(1, 2, 3, 4)),
        rp: { id: 'localhost', name: 'SecondStay' },
        user: { id: bytesToBase64Url(bytes(9, 9)), name: 'a@b.test', displayName: 'A B' },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        timeout: 300000,
        attestation: 'none',
        excludeCredentials: [{ type: 'public-key', id: bytesToBase64Url(bytes(7)) }],
        authenticatorSelection: { userVerification: 'preferred' }
    };

    it('décode les champs binaires de création', () => {
        const result = toCreationOptions(options);

        expect(result.challenge).toBeInstanceOf(Uint8Array);
        expect(Array.from(result.challenge)).toEqual([1, 2, 3, 4]);
        expect(Array.from(result.user.id)).toEqual([9, 9]);
        expect(Array.from(result.excludeCredentials[0].id)).toEqual([7]);
        expect(result.rp.id).toBe('localhost');
        expect(result.attestation).toBe('none');
    });

    it('décode les champs binaires d’authentification', () => {
        const result = toRequestOptions({
            challenge: bytesToBase64Url(bytes(5, 6)),
            rpId: 'localhost',
            allowCredentials: [{ id: bytesToBase64Url(bytes(3)) }]
        });

        expect(Array.from(result.challenge)).toEqual([5, 6]);
        expect(result.allowCredentials[0].type).toBe('public-key');
        expect(Array.from(result.allowCredentials[0].id)).toEqual([3]);
        expect(result.userVerification).toBe('preferred');
    });

    it('tolère une absence de listes de clés', () => {
        expect(toCreationOptions({ challenge: '', user: { id: '' } }).excludeCredentials).toEqual([]);
        expect(toRequestOptions({ challenge: '' }).allowCredentials).toEqual([]);
    });
});

describe('sérialisation des réponses', () => {
    it('encode une attestation avec ses transports', () => {
        const payload = serialiseAttestation({
            id: 'credential-id',
            type: 'public-key',
            response: {
                clientDataJSON: bytes(1, 2).buffer,
                attestationObject: bytes(3, 4).buffer,
                getTransports: () => ['internal']
            }
        });

        expect(payload.id).toBe('credential-id');
        expect(payload.clientDataJSON).toBe(bytesToBase64Url(bytes(1, 2)));
        expect(payload.attestationObject).toBe(bytesToBase64Url(bytes(3, 4)));
        expect(payload.transports).toEqual(['internal']);
    });

    it('tolère un authentificateur sans getTransports', () => {
        const payload = serialiseAttestation({
            id: 'x',
            type: 'public-key',
            response: { clientDataJSON: bytes(1).buffer, attestationObject: bytes(2).buffer }
        });

        expect(payload.transports).toEqual([]);
    });

    it('encode une assertion et son userHandle optionnel', () => {
        const base = {
            id: 'y',
            type: 'public-key',
            response: {
                clientDataJSON: bytes(1).buffer,
                authenticatorData: bytes(2).buffer,
                signature: bytes(3).buffer,
                userHandle: null
            }
        };

        expect(serialiseAssertion(base).userHandle).toBeNull();

        base.response.userHandle = bytes(8).buffer;
        expect(serialiseAssertion(base).userHandle).toBe(bytesToBase64Url(bytes(8)));
    });
});

describe('appel JSON', () => {
    it('transmet le jeton CSRF et les cookies de même origine', async () => {
        const fetcher = vi.fn(async () => ({ status: 200, json: async () => ({ ok: true }) }));

        const result = await postJson('/api/passkeys/login', 'token-123', { a: 1 }, fetcher);

        expect(result).toEqual({ status: 200, body: { ok: true } });
        const [url, init] = fetcher.mock.calls[0];
        expect(url).toBe('/api/passkeys/login');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-CSRF-Token']).toBe('token-123');
        expect(init.body).toBe('{"a":1}');
    });

    it('ne casse pas sur une réponse non JSON', async () => {
        const fetcher = vi.fn(async () => ({
            status: 500,
            json: async () => {
                throw new Error('not json');
            }
        }));

        await expect(postJson('/x', 't', {}, fetcher)).resolves.toEqual({ status: 500, body: {} });
    });
});

describe('amorces DOM', () => {
    let dom;
    let document;

    beforeEach(() => {
        dom = new JSDOM('<!doctype html><html><body></body></html>');
        document = dom.window.document;
    });

    function supportedScope(fetcher, credentials) {
        return {
            PublicKeyCredential: function () {},
            navigator: { credentials },
            fetch: fetcher,
            location: { reload: vi.fn(), assign: vi.fn() }
        };
    }

    it('désactive le bouton et affiche l’avertissement sans support navigateur', () => {
        document.body.innerHTML = `
            <button data-passkey-register data-csrf="t"></button>
            <p data-passkey-status></p>
            <p data-passkey-unsupported hidden></p>`;

        expect(initPasskeyRegistration(document, {})).toBe(false);
        expect(document.querySelector('[data-passkey-register]').disabled).toBe(true);
        expect(document.querySelector('[data-passkey-unsupported]').hidden).toBe(false);
    });

    it('ne fait rien si la page ne contient pas de bouton', () => {
        expect(initPasskeyRegistration(document, {})).toBe(false);
        expect(initPasskeySignIn(document, {})).toBe(false);
    });

    it('enregistre une clé puis recharge la page', async () => {
        document.body.innerHTML = `
            <button data-passkey-register data-csrf="tok" data-base="/app"
                    data-success-label="ok"></button>
            <input data-passkey-label value="Téléphone">
            <p data-passkey-status></p>`;

        const fetcher = vi.fn(async (url) => ({
            status: 200,
            json: async () =>
                url.endsWith('/register')
                    ? { ok: true }
                    : {
                        challenge: bytesToBase64Url(bytes(1)),
                        user: { id: bytesToBase64Url(bytes(2)), name: 'a@b.test' }
                    }
        }));
        const create = vi.fn(async () => ({
            id: 'cred',
            type: 'public-key',
            response: { clientDataJSON: bytes(1).buffer, attestationObject: bytes(2).buffer }
        }));
        const scope = supportedScope(fetcher, { create });

        expect(initPasskeyRegistration(document, scope)).toBe(true);
        document.querySelector('[data-passkey-register]').click();
        await vi.waitFor(() => expect(scope.location.reload).toHaveBeenCalled());

        expect(fetcher.mock.calls[0][0]).toBe('/app/api/passkeys/register/options');
        expect(fetcher.mock.calls[1][0]).toBe('/app/api/passkeys/register');
        expect(JSON.parse(fetcher.mock.calls[1][1].body).label).toBe('Téléphone');
        expect(document.querySelector('[data-passkey-status]').textContent).toBe('ok');
    });

    it('affiche l’erreur traduite du serveur et réactive le bouton', async () => {
        document.body.innerHTML = `
            <button data-passkey-register data-csrf="tok"></button>
            <p data-passkey-status></p>`;

        const fetcher = vi.fn(async () => ({
            status: 200,
            json: async () => ({ ok: false, error: 'Cette clé d’accès est déjà enregistrée.' })
        }));
        const scope = supportedScope(fetcher, {
            create: async () => ({
                id: 'c',
                type: 'public-key',
                response: { clientDataJSON: bytes(1).buffer, attestationObject: bytes(2).buffer }
            })
        });

        initPasskeyRegistration(document, scope);
        const button = document.querySelector('[data-passkey-register]');
        button.click();

        await vi.waitFor(() => expect(button.disabled).toBe(false));
        expect(document.querySelector('[data-passkey-status]').textContent).toBe(
            'Cette clé d’accès est déjà enregistrée.'
        );
        expect(scope.location.reload).not.toHaveBeenCalled();
    });

    it('masque le bouton de connexion quand le navigateur ne sait pas faire', () => {
        document.body.innerHTML = '<button data-passkey-signin hidden></button>';

        expect(initPasskeySignIn(document, {})).toBe(false);
        expect(document.querySelector('[data-passkey-signin]').hidden).toBe(true);
    });

    it('révèle le bouton puis redirige après une authentification réussie', async () => {
        document.body.innerHTML = `
            <button data-passkey-signin hidden data-csrf="tok"></button>
            <p data-passkey-signin-status></p>`;

        const fetcher = vi.fn(async (url) => ({
            status: 200,
            json: async () =>
                url.endsWith('/login')
                    ? { ok: true, redirect: '/fr/account' }
                    : { challenge: bytesToBase64Url(bytes(1)), rpId: 'localhost' }
        }));
        const scope = supportedScope(fetcher, {
            create: () => {},
            get: async () => ({
                id: 'c',
                type: 'public-key',
                response: {
                    clientDataJSON: bytes(1).buffer,
                    authenticatorData: bytes(2).buffer,
                    signature: bytes(3).buffer,
                    userHandle: null
                }
            })
        });

        expect(initPasskeySignIn(document, scope)).toBe(true);
        expect(document.querySelector('[data-passkey-signin]').hidden).toBe(false);

        document.querySelector('[data-passkey-signin]').click();
        await vi.waitFor(() => expect(scope.location.assign).toHaveBeenCalledWith('/fr/account'));
    });

    it('signale un rejet de l’authentificateur sans quitter la page', async () => {
        document.body.innerHTML = `
            <button data-passkey-signin hidden data-csrf="tok"></button>
            <p data-passkey-signin-status></p>`;

        const scope = supportedScope(
            vi.fn(async () => ({ status: 200, json: async () => ({ challenge: bytesToBase64Url(bytes(1)) }) })),
            {
                create: () => {},
                get: async () => {
                    throw new Error('NotAllowedError');
                }
            }
        );

        initPasskeySignIn(document, scope);
        const button = document.querySelector('[data-passkey-signin]');
        button.click();

        await vi.waitFor(() => expect(button.disabled).toBe(false));
        expect(document.querySelector('[data-passkey-signin-status]').textContent).toBe('NotAllowedError');
        expect(scope.location.assign).not.toHaveBeenCalled();
    });
});
