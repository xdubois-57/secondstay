/**
 * Dépose des pages HTML de test que le produit lira au lieu du réseau.
 *
 * L'endpoint n'existe que lorsque le fetcher de fixtures est activé par
 * variable d'environnement : le scénario joue donc le pipeline réel — même
 * extraction, même prompt, même validation — sans jamais sortir.
 */
export async function storePage(request, url, body) {
    const response = await request.post('/webhook/dev/http', {
        form: { url, body }
    });

    if (!response.ok()) {
        throw new Error(`Fixture refusée pour ${url} : ${response.status()}`);
    }
}

export async function purgePages(request) {
    await request.post('/webhook/dev/http/purge');
}

/**
 * Page d'agenda minimale : une activité par ligne, comme sur un vrai site.
 *
 * @param {Array<{title: string, start: string, end?: string, booking?: boolean}>} entries
 */
export function agendaPage(title, entries) {
    const items = entries
        .map((entry) => {
            const range = entry.end && entry.end !== entry.start
                ? `${entry.start} → ${entry.end}`
                : entry.start;
            const suffix = entry.booking ? ' (réservation)' : '';
            return `        <li>${entry.title} — ${range}${suffix}</li>`;
        })
        .join('\n');

    return [
        '<!doctype html>',
        '<html lang="fr"><head><title>' + title + '</title>',
        '<style>.hidden{display:none}</style>',
        '<script>console.log("bruit")</script>',
        '</head><body>',
        `    <h1>${title}</h1>`,
        '    <ul>',
        items,
        '    </ul>',
        '</body></html>'
    ].join('\n');
}
