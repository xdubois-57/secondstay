<?php

declare(strict_types=1);

/**
 * Betalingen: schema, inning, waarborg en SEPA-overschrijving.
 */

return [
    'kind' => [
        'deposit' => 'Voorschot',
        'balance' => 'Saldo',
        'security_deposit' => 'Waarborg',
        'cleaning' => 'Schoonmaak',
        'tourist_tax' => 'Toeristenbelasting',
        'adjustment' => 'Correctie',
        'refund' => 'Terugbetaling',
    ],
    'status' => [
        'pending' => 'In afwachting',
        'authorized' => 'Geautoriseerd',
        'paid' => 'Betaald',
        'failed' => 'Mislukt',
        'cancelled' => 'Geannuleerd',
        'refunded' => 'Terugbetaald',
        'partially_refunded' => 'Gedeeltelijk terugbetaald',
    ],
    'hold' => [
        'none' => '—',
        'to_pay' => 'Te betalen',
        'received' => 'Ontvangen',
        'to_return' => 'Terug te storten',
        'returned' => 'Teruggestort',
        'partially_retained' => 'Gedeeltelijk ingehouden',
    ],
    'schedule' => [
        'title' => 'Betaalschema',
        'empty' => 'Er wordt nog geen betaling verwacht.',
        'due_on' => 'Vervaldatum',
        'amount' => 'Bedrag',
        'component' => 'Onderdeel',
        'state' => 'Status',
        'overdue' => 'Achterstallig',
        'total_due' => 'Nog te betalen',
        'total_paid' => 'Reeds betaald',
    ],
    'action' => [
        'pay_online' => 'Online betalen',
        'pay_transfer' => 'Betalen via overschrijving',
        'back_to_booking' => 'Terug naar de reservering',
        'record' => 'Ontvangst registreren',
        'refund' => 'Terugbetalen',
        'schedule' => 'Schema opnieuw berekenen',
        'mark_to_return' => 'Waarborg als terug te storten markeren',
    ],
    'transfer' => [
        'title' => 'Betalen via overschrijving',
        'intro' => 'Scan deze QR-code met uw bankapp of neem de gegevens hieronder over.',
        'beneficiary' => 'Begunstigde',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'reference' => 'Te vermelden mededeling',
        'qr_alt' => 'QR-code voor SEPA-overschrijving',
        'notice' => 'Een overschrijving duurt een tot twee werkdagen. De reservering wordt na controle bevestigd.',
    ],
    'return' => [
        'title' => 'Resultaat van de betaling',
        'paid' => 'Uw betaling is goed ontvangen.',
        'pending' => 'Uw betaling wordt verwerkt. Deze pagina wordt bijgewerkt zodra ze bevestigd is.',
        'failed' => 'De betaling is niet gelukt. U kunt het opnieuw proberen.',
    ],
    'admin' => [
        'title' => 'Betalingen',
        'outstanding' => 'Openstaande termijnen',
        'held_deposits' => 'Aangehouden waarborgen',
        'webhooks' => 'Ontvangen meldingen',
        'booking' => 'Reservering',
        'provider' => 'Provider',
        'provider_ready' => 'Provider geconfigureerd',
        'provider_missing' => 'Geen provider geconfigureerd: alleen overschrijvingen en handmatige inning zijn '
            . 'mogelijk.',
        'confirm_booking' => 'Ook de reservering bevestigen',
        'reason' => 'Reden',
        'scheduled' => 'Schema bijgewerkt.',
        'recorded' => 'Ontvangst geregistreerd.',
        'refunded' => 'Terugbetaling uitgevoerd.',
        'hold_updated' => 'Waarborg bijgewerkt.',
        'empty' => 'Momenteel valt er niets te innen.',
        'received_at' => 'Ontvangen op',
    ],
    'error' => [
        'already_settled' => 'Deze betaling is al voldaan.',
        'not_settled' => 'Deze betaling is niet geïnd.',
        'not_configured' => 'Er is geen betaalprovider geconfigureerd.',
        'invalid_webhook' => 'Onleesbare melding.',
        'provider_unreachable' => 'De betaalprovider is onbereikbaar.',
        'refund_amount' => 'Ongeldig terugbetalingsbedrag.',
        'hold_transition' => 'Deze overgang van de waarborg is niet toegestaan.',
        'not_found' => 'Betaling niet gevonden.',
    ],
];
