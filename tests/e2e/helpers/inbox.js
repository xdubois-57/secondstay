/**
 * Boîte de réception factice, active lorsque le serveur tourne avec
 * `SECONDSTAY_IMAP_PROVIDER=fake`. Aucun serveur IMAP n'est nécessaire.
 */

/**
 * Compose un message MIME avec une pièce jointe, tel qu'un client de
 * messagerie en produirait.
 */
export function replyWithAttachment({ from, to, subject, body, filename, contents, inReplyTo }) {
    const boundary = 'limite-secondstay';
    const encoded = Buffer.from(contents, 'binary').toString('base64').replace(/(.{76})/g, '$1\r\n');

    const headers = [
        `From: ${from}`,
        `To: ${to}`,
        `Subject: ${subject}`,
        'MIME-Version: 1.0',
        `Content-Type: multipart/mixed; boundary="${boundary}"`
    ];

    if (inReplyTo) {
        headers.push(`In-Reply-To: <${inReplyTo}>`);
    }

    return [
        ...headers,
        '',
        `--${boundary}`,
        'Content-Type: text/plain; charset=UTF-8',
        '',
        body,
        `--${boundary}`,
        `Content-Type: application/pdf; name="${filename}"`,
        `Content-Disposition: attachment; filename="${filename}"`,
        'Content-Transfer-Encoding: base64',
        '',
        encoded,
        `--${boundary}--`,
        ''
    ].join('\r\n');
}

/**
 * Dépose un message dans la boîte factice, comme le ferait le serveur de
 * messagerie du logement.
 */
export async function deliverToInbox(request, raw) {
    const response = await request.post('/webhook/dev/inbox', {
        headers: { 'Content-Type': 'message/rfc822' },
        data: raw
    });

    if (!response.ok()) {
        throw new Error(`Dépôt refusé (${response.status()}) : ${await response.text()}`);
    }

    return response.json();
}

/** Un PDF minimal mais réellement reconnu comme tel. */
export function samplePdf(marker = 'contrat signe') {
    return `%PDF-1.4\n1 0 obj\n<< /Marque (${marker}) >>\nendobj\ntrailer\n<< >>\n%%EOF\n`;
}
