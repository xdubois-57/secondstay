/**
 * Fournisseur de paiement factice, actif lorsque le serveur tourne avec
 * `SECONDSTAY_PAYMENT_PROVIDER=fake`. Aucun compte marchand, aucune clé et
 * aucun réseau sortant ne sont nécessaires.
 */

/** État des paiements ouverts chez le fournisseur factice. */
export async function providerPayments(request) {
    const response = await request.get('/api/dev/payments');
    if (!response.ok()) {
        throw new Error(`Fournisseur de test indisponible (${response.status()}).`);
    }

    const { payments } = await response.json();

    return Array.isArray(payments) ? payments : [];
}

/**
 * Fait évoluer l'état **chez le fournisseur**, comme le ferait un
 * encaissement réel. L'application n'en sait encore rien : seule la
 * notification la mettra à jour.
 */
export async function settleAtProvider(page, reference, status = 'paid') {
    const result = await page.evaluate(async ({ reference, status }) => {
        const token = document.querySelector('input[name="_csrf"]')?.value ?? '';
        const response = await fetch('/api/dev/payments/settle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token },
            body: new URLSearchParams({ reference, status }).toString()
        });

        return { status: response.status, body: await response.text() };
    }, { reference, status });

    if (result.status !== 200 || !result.body.includes('"ok":true')) {
        throw new Error(`Le fournisseur de test a refusé « ${reference} » : ${result.status} ${result.body}`);
    }
}

/**
 * Envoie la notification du fournisseur, exactement comme Mollie le ferait :
 * un simple identifiant, sans jeton CSRF et sans état applicatif.
 */
export async function deliverWebhook(request, reference) {
    const response = await request.post('/webhook/payment', {
        form: { id: reference }
    });

    if (!response.ok()) {
        throw new Error(`Le webhook a échoué (${response.status()}) : ${await response.text()}`);
    }

    return response.json();
}
