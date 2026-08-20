<?php

declare(strict_types=1);

/**
 * „Mijn verblijf vandaag”, welkomstboek en gastenlinks.
 */

return [
    'title' => 'Mijn verblijf',
    'today' => 'Mijn verblijf vandaag',
    'reference' => 'Referentie',
    'dates' => 'Data',
    'checkin' => 'Aankomst vanaf',
    'checkout' => 'Vertrek voor',
    'phase' => [
        'before' => 'Voor uw verblijf',
        'arrival' => 'Dag van aankomst',
        'during' => 'Tijdens uw verblijf',
        'departure' => 'Dag van vertrek',
        'after' => 'Na uw verblijf',
    ],
    'countdown' => [
        'today' => 'Het is vandaag.',
        'tomorrow' => 'Het is morgen.',
        'days' => 'Over {count} dag|Over {count} dagen',
        'past' => 'Verblijf afgelopen.',
    ],
    'block' => [
        'welcome' => 'Welkom',
        'access' => 'Aankomen en binnenkomen',
        'wifi' => 'Wifi',
        'appliances' => 'Apparatuur',
        'waste' => 'Afval en sortering',
        'rules' => 'Huisregels',
        'safety' => 'Veiligheid',
        'checkout' => 'Voor u vertrekt',
    ],
    'secret' => [
        'title' => 'Toegangscodes',
        'wifi_password' => 'Wifiwachtwoord',
        'key_box' => 'Sleutelkluis',
        'alarm' => 'Alarm',
        'gate' => 'Poort',
        'hidden' => 'De toegangscodes verschijnen hier op de dag van uw aankomst.',
        'shown_during' => 'Alleen zichtbaar tijdens uw verblijf.',
    ],
    'manager' => [
        'title' => 'Contact ter plaatse',
        'none' => 'Er is nog geen lokaal contact aangeduid.',
    ],
    'offline' => [
        'ready' => 'Deze pagina blijft raadpleegbaar zonder netwerk.',
        'stale' => 'Offlineweergave: de informatie kan verouderd zijn.',
    ],
    'guest' => [
        'title' => 'Delen met mijn gasten',
        'intro' => 'Een gastenlink geeft toegang tot deze praktische informatie — en tot niets anders: geen bedragen, geen documenten, geen account.',
        'create' => 'Gastenlink aanmaken',
        'label' => 'Voor wie?',
        'created' => 'Gastenlink aangemaakt. Kopieer hem nu: hij wordt niet opnieuw getoond.',
        'revoked' => 'Gastenlink ingetrokken.',
        'revoke' => 'Intrekken',
        'expires' => 'Vervalt op',
        'never_used' => 'Nooit gebruikt',
        'empty' => 'Geen actieve gastenlink.',
        'qr' => 'QR-code om af te drukken',
        'qr_alt' => 'QR-code van de gastenlink',
        'banner' => 'U bekijkt dit verblijf via een gastenlink.',
    ],
    'admin' => [
        'title' => 'Welkomstboek',
        'intro' => 'Deze teksten verschijnen in „Mijn verblijf” en achter gastenlinks. Ze zijn offline beschikbaar.',
        'block_title' => 'Titel',
        'block_body' => 'Tekst',
        'published' => 'Gepubliceerd',
        'save' => 'Opslaan',
        'saved' => 'Welkomstboek opgeslagen.',
        'secrets' => 'Toegangscodes',
        'secrets_intro' => 'Versleuteld opgeslagen en nooit opnieuw getoond. Een leeg veld behoudt de bestaande waarde.',
        'secrets_saved' => 'Toegangscodes opgeslagen.',
        'clear' => 'Wissen',
        'not_set' => 'Niet ingevuld',
        'completeness' => 'Volledigheid',
        'language' => 'Taal',
    ],
    'error' => [
        'not_active' => 'Dit verblijf is niet meer actief.',
        'link_not_found' => 'Gastenlink niet gevonden.',
        'not_found' => 'Verblijf niet gevonden.',
    ],
];
