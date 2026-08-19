/**
 * Boîte de réception factice exposée par l'application lorsque le transport
 * e-mail `fake` est actif. Aucune adresse ni aucun contenu réel n'y transite.
 */
export async function inbox(request, address) {
    const response = await request.get('/api/dev/mailbox');
    if (!response.ok()) {
        throw new Error(`Boîte de test indisponible (${response.status()}).`);
    }

    const { messages } = await response.json();
    const all = Array.isArray(messages) ? messages : [];

    return address ? all.filter((message) => message.to === address) : all;
}

/**
 * Attend l'arrivée d'un e-mail et renvoie le plus récent.
 */
export async function waitForMail(request, address, template, timeoutMs = 10000) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        const messages = await inbox(request, address);
        const match = messages.find((message) => !template || message.template === template);
        if (match) {
            return match;
        }
        await new Promise((resolve) => setTimeout(resolve, 200));
    }

    throw new Error(`Aucun e-mail « ${template || 'quelconque' } » reçu pour ${address}.`);
}

/**
 * Extrait le lien applicatif contenu dans un e-mail (confirmation, réinitialisation).
 */
export function linkFrom(message, pathFragment) {
    const pattern = new RegExp(`href="([^"]*${pathFragment}[^"]*)"`);
    const match = pattern.exec(message.html);
    if (!match) {
        throw new Error(`Aucun lien « ${pathFragment} » dans l'e-mail « ${message.template} ».`);
    }

    // Seul le chemin est conservé : le scénario reste sur l'instance testée
    // même si `site.public_url` désigne un domaine public.
    return match[1].replace(/&amp;/g, '&').replace(/^https?:\/\/[^/]+/, '');
}
