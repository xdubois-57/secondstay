<?php

declare(strict_types=1);

/**
 * Notifications : sujet d'e-mail, titre et texte poussés, libellé d'action.
 *
 * Chaque événement expose les mêmes clés : ajouter un événement ne demande
 * aucun gabarit supplémentaire.
 */

return [
    'title' => 'Meldingen',
    'intro' => 'Kies hoe u op de hoogte wilt worden gebracht. Belangrijke berichten over uw verblijf worden altijd per '
        . 'e-mail verstuurd.',
    'send_test' => 'Een testmelding versturen',
    'test_sent' => 'Testmelding verzonden.',
    'test_no_device' => 'Geen geabonneerd apparaat voor deze test.',
    'saved' => 'Meldingsvoorkeuren opgeslagen.',
    'devices' => 'Apparaten die meldingen ontvangen',
    'no_device' => 'Geen geabonneerd apparaat.',
    'channel' => [
        'email' => 'E-mail',
        'push' => 'Pushmeldingen',
    ],
    'push' => [
        'enable' => 'Meldingen op dit apparaat inschakelen',
        'disable' => 'Uitschakelen op dit apparaat',
        'enabled' => 'Meldingen ingeschakeld op dit apparaat.',
        'disabled' => 'Meldingen uitgeschakeld op dit apparaat.',
        'unsupported' => 'Uw browser ondersteunt geen meldingen.',
        'denied' => 'Meldingen zijn geblokkeerd in de browserinstellingen.',
        'unavailable' => 'Pushmeldingen zijn niet ingeschakeld op deze site.',
    ],
    'mail' => [
        'preferences' => 'U kunt uw meldingen aanpassen in uw klantomgeving.',
    ],
    'test' => [
        'subject' => 'Testmelding',
        'title' => 'Testmelding',
        'body' => 'Als u dit leest, ontvangt dit apparaat inderdaad meldingen.',
        'mail_body' => 'Dit bericht bevestigt dat uw adres inderdaad meldingen van {property} ontvangt.',
        'action' => 'Mijn omgeving openen',
    ],
    'account_confirmed' => [
        'subject' => 'Uw account is actief',
        'title' => 'Welkom {first_name}',
        'body' => 'Welkom {first_name}, uw account voor {property} is bevestigd.',
        'mail_body' => 'Uw e-mailadres is bevestigd: u kunt uw verblijf volgen in uw klantomgeving.',
        'action' => 'Mijn omgeving openen',
    ],
    'booking_created' => [
        'subject' => 'Reserveringsaanvraag geregistreerd',
        'title' => 'Aanvraag geregistreerd',
        'body' => 'Uw aanvraag voor {property} is geregistreerd.',
        'mail_body' => 'Wij hebben uw reserveringsaanvraag ontvangen. U krijgt een bevestiging zodra deze is '
            . 'goedgekeurd.',
        'action' => 'Mijn reservering bekijken',
    ],
    'booking_confirmed' => [
        'subject' => 'Reservering bevestigd',
        'title' => 'Reservering bevestigd',
        'body' => 'Uw verblijf in {property} is bevestigd.',
        'mail_body' => 'Uw verblijf is bevestigd. Praktische informatie is beschikbaar vóór uw aankomst.',
        'action' => 'Mijn reservering bekijken',
    ],
    'payment_received' => [
        'subject' => 'Betaling ontvangen',
        'title' => 'Betaling ontvangen',
        'body' => 'Uw betaling voor {property} is geregistreerd.',
        'mail_body' => 'Uw betaling is geregistreerd. Het bewijs staat bij uw documenten.',
        'action' => 'Mijn documenten bekijken',
    ],
    'stay_reminder' => [
        'subject' => 'Uw verblijf komt eraan',
        'title' => 'Uw verblijf komt eraan',
        'body' => 'Uw verblijf in {property} begint binnenkort.',
        'mail_body' => 'Aankomstinformatie, toegangscodes en het welkomstboek staan in uw omgeving.',
        'action' => 'Mijn verblijf voorbereiden',
    ],
    'arrival' => [
        'subject' => 'Aankomstdag',
        'title' => 'Welkom',
        'body' => 'Welkom in {property}.',
        'mail_body' => 'Alles wat u nodig hebt voor uw aankomst staat in uw omgeving.',
        'action' => 'Aankomstinformatie bekijken',
    ],
    'departure' => [
        'subject' => 'Vertrekdag',
        'title' => 'Vertrekdag',
        'body' => 'Uw vertrek uit {property} staat vandaag gepland.',
        'mail_body' => 'Volg de vertrekinstructies in uw omgeving.',
        'action' => 'Vertrekinstructies bekijken',
    ],
    'incident' => [
        'subject' => 'Incident gemeld',
        'title' => 'Incident gemeld',
        'body' => 'Er is een incident gemeld in {property}.',
        'mail_body' => 'Er is zojuist een incident gemeld. Open het dossier om te beslissen wat er gebeurt.',
        'action' => 'Incident bekijken',
    ],
    'task_assigned' => [
        'subject' => 'Nieuwe taak',
        'title' => 'Nieuwe taak',
        'body' => 'Er is een taak aan u toegewezen voor {property}.',
        'mail_body' => 'Er is zojuist een taak aan u toegewezen. Ze is zichtbaar op uw dashboard.',
        'action' => 'Taak bekijken',
    ],
];
