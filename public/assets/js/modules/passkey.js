/**
 * Clés d'accès (WebAuthn) côté navigateur.
 *
 * Le module est découpé en fonctions pures (encodage base64url, conversion des
 * options serveur en options `navigator.credentials`, sérialisation de la
 * réponse) et en amorces DOM. Les fonctions pures sont testées par Vitest sans
 * navigateur ; l'API WebAuthn elle-même est vérifiée par les tests E2E via
 * l'authentificateur virtuel.
 */

const BASE64URL = /^[A-Za-z0-9_-]*$/;

export function base64UrlToBytes(value) {
    const input = String(value ?? '');
    if (!BASE64URL.test(input)) {
        throw new Error('invalid base64url');
    }
    const padded = input.replaceAll('-', '+').replaceAll('_', '/') + '==='.slice((input.length + 3) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.codePointAt(i);
    }
    return bytes;
}

export function bytesToBase64Url(buffer) {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCodePoint(byte);
    }
    // `={1,3}$` plutôt que `=+$` : le remplissage base64 ne dépasse jamais
    // trois signes, et un quantificateur borné retire le retour arrière que
    // `+` autorise sur une chaîne qui n'en contiendrait que.
    return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/={1,3}$/, '');
}

export function isSupported(scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    return Boolean(
        target.PublicKeyCredential
        && typeof target.navigator?.credentials?.create === 'function'
    );
}

/**
 * Convertit les options JSON du serveur en options WebAuthn (champs binaires).
 */
export function toCreationOptions(options) {
    const source = options || {};
    const user = source.user || {};
    return {
        challenge: base64UrlToBytes(source.challenge),
        rp: source.rp || {},
        user: {
            id: base64UrlToBytes(user.id),
            name: String(user.name ?? ''),
            displayName: String(user.displayName ?? user.name ?? '')
        },
        pubKeyCredParams: Array.isArray(source.pubKeyCredParams) ? source.pubKeyCredParams : [],
        timeout: Number(source.timeout) || undefined,
        attestation: source.attestation || 'none',
        excludeCredentials: (source.excludeCredentials || []).map((credential) => ({
            type: credential.type || 'public-key',
            id: base64UrlToBytes(credential.id)
        })),
        authenticatorSelection: source.authenticatorSelection || {}
    };
}

export function toRequestOptions(options) {
    const source = options || {};
    return {
        challenge: base64UrlToBytes(source.challenge),
        rpId: source.rpId,
        timeout: Number(source.timeout) || undefined,
        userVerification: source.userVerification || 'preferred',
        allowCredentials: (source.allowCredentials || []).map((credential) => ({
            type: credential.type || 'public-key',
            id: base64UrlToBytes(credential.id)
        }))
    };
}

export function serialiseAttestation(credential) {
    const response = credential.response;
    const transports = typeof response.getTransports === 'function' ? response.getTransports() : [];
    return {
        id: credential.id,
        type: credential.type,
        clientDataJSON: bytesToBase64Url(response.clientDataJSON),
        attestationObject: bytesToBase64Url(response.attestationObject),
        transports: Array.isArray(transports) ? transports : []
    };
}

export function serialiseAssertion(credential) {
    const response = credential.response;
    return {
        id: credential.id,
        type: credential.type,
        clientDataJSON: bytesToBase64Url(response.clientDataJSON),
        authenticatorData: bytesToBase64Url(response.authenticatorData),
        signature: bytesToBase64Url(response.signature),
        userHandle: response.userHandle ? bytesToBase64Url(response.userHandle) : null
    };
}

/**
 * Appel JSON authentifié par le jeton CSRF de la page.
 */
export async function postJson(url, csrf, payload, fetcher) {
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

function status(element, message, variant) {
    if (!element) {
        return;
    }
    element.textContent = message;
    element.className = 'small mt-2 mb-0 ' + (variant === 'error' ? 'text-danger' : 'text-success');
}

/**
 * Bouton « Ajouter une clé d'accès » de la page compte.
 */
export function initPasskeyRegistration(root, scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    const button = root.querySelector('[data-passkey-register]');
    if (!button) {
        return false;
    }

    const unsupported = root.querySelector('[data-passkey-unsupported]');
    const feedback = root.querySelector('[data-passkey-status]');
    if (!isSupported(target)) {
        button.disabled = true;
        if (unsupported) {
            unsupported.hidden = false;
        }
        return false;
    }

    const csrf = button.dataset.csrf || '';
    const base = button.dataset.base || '';

    button.addEventListener('click', async () => {
        button.disabled = true;
        status(feedback, button.dataset.busy || '…', 'info');
        try {
            const options = await postJson(base + '/api/passkeys/register/options', csrf, {}, target.fetch);
            if (options.status !== 200) {
                throw new Error(options.body.error || button.dataset.errorLabel || 'error');
            }
            const credential = await target.navigator.credentials.create({
                publicKey: toCreationOptions(options.body)
            });
            const labelInput = root.querySelector('[data-passkey-label]');
            const result = await postJson(
                base + '/api/passkeys/register',
                csrf,
                { response: serialiseAttestation(credential), label: labelInput ? labelInput.value : '' },
                target.fetch
            );
            if (!result.body.ok) {
                throw new Error(result.body.error || button.dataset.errorLabel || 'error');
            }
            status(feedback, button.dataset.successLabel || '', 'success');
            target.location.reload();
        } catch (error) {
            status(feedback, error?.message ? error.message : String(error), 'error');
            button.disabled = false;
        }
    });

    return true;
}

/**
 * Bouton « Se connecter avec une clé d'accès » de la page de connexion.
 */
export function initPasskeySignIn(root, scope) {
    const target = scope || (typeof window === 'undefined' ? {} : window);
    const button = root.querySelector('[data-passkey-signin]');
    if (!button) {
        return false;
    }

    const feedback = root.querySelector('[data-passkey-signin-status]');
    if (!isSupported(target)) {
        button.hidden = true;
        return false;
    }
    button.hidden = false;

    const csrf = button.dataset.csrf || '';
    const base = button.dataset.base || '';

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const options = await postJson(base + '/api/passkeys/login/options', csrf, {}, target.fetch);
            if (options.status !== 200) {
                throw new Error(options.body.error || 'error');
            }
            const credential = await target.navigator.credentials.get({
                publicKey: toRequestOptions(options.body)
            });
            const result = await postJson(
                base + '/api/passkeys/login',
                csrf,
                { response: serialiseAssertion(credential) },
                target.fetch
            );
            if (!result.body.ok) {
                throw new Error(result.body.error || 'error');
            }
            target.location.assign(result.body.redirect);
        } catch (error) {
            status(feedback, error?.message ? error.message : String(error), 'error');
            button.disabled = false;
        }
    });

    return true;
}
