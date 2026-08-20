<?php

declare(strict_types=1);

/**
 * Inkomende post, koppeling aan een verblijf en communicatietijdlijn.
 */

return [
    'title' => 'Berichten',
    'inbound' => 'Inkomende post',
    'timeline' => 'Uitwisselingen',
    'empty' => 'Geen bericht.',
    'unlinked' => 'Niet-gekoppelde berichten',
    'unlinked_empty' => 'Alle ontvangen berichten zijn gekoppeld.',
    'from' => 'Van',
    'to' => 'Aan',
    'subject' => 'Onderwerp',
    'received' => 'Ontvangen op',
    'sent' => 'Verzonden op',
    'attachments' => 'Bijlagen',
    'direction' => [
        'inbound' => 'Ontvangen',
        'outbound' => 'Verzonden',
    ],
    'link' => [
        'title' => 'Koppeling',
        'none' => 'Niet gekoppeld',
        'token' => 'Ondertekend antwoordadres',
        'thread' => 'Draadkoppen',
        'reference' => 'Vermelde referentie',
        'sender' => 'Adres van de afzender',
        'manual' => 'Handmatig gekoppeld',
    ],
    'action' => [
        'link' => 'Koppelen',
        'sync' => 'Postbus ophalen',
        'view' => 'Bericht bekijken',
        'reference' => 'Referentie van het verblijf',
    ],
    'sync' => [
        'done' => 'Ophalen klaar: {imported} bericht(en), {linked} gekoppeld, {documents} document(en).',
        'nothing' => 'Geen nieuw bericht.',
        'disabled' => 'Het ophalen van de postbus staat uit.',
    ],
    'linked' => 'Bericht aan het verblijf gekoppeld.',
    'reply_address' => 'Antwoordadres',
    'reply_hint' => 'Antwoord gewoon op dit bericht: uw antwoord wordt automatisch aan uw verblijf gekoppeld.',
    'error' => [
        'not_found' => 'Bericht niet gevonden.',
        'booking_not_found' => 'Verblijf niet gevonden.',
        'not_configured' => 'De postbus is niet geconfigureerd.',
        'connection_failed' => 'Verbinden met de postbus is mislukt.',
        'greeting' => 'De server antwoordde niet correct.',
        'tls_failed' => 'De beveiligde verbinding is mislukt.',
        'command_failed' => 'De server weigerde de opdracht.',
        'connection_lost' => 'Verbinding verbroken.',
        'timeout' => 'De server antwoordt niet meer.',
        'too_large' => 'Bericht te groot.',
        'write_failed' => 'Schrijven naar de server is mislukt.',
    ],
    'verify' => [
        'ok' => 'Postbus bereikbaar.',
    ],
];
