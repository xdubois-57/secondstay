<?php

declare(strict_types=1);

/**
 * Libellés et aides des réglages typés.
 *
 * Chaque réglage expose un libellé et une aide explicite : l'objectif est que
 * le propriétaire comprenne l'impact du réglage sans documentation externe.
 */

return [
    'property' => [
        'name' => [
            'label' => 'Naam van de woning',
            'help' => 'Naam die publiek wordt getoond voor uw vakantiewoning.',
        ],
        'address_line1' => [
            'label' => 'Adres (regel 1)',
            'help' => 'Huisnummer en straat van de woning.',
        ],
        'address_line2' => [
            'label' => 'Adres (regel 2)',
            'help' => 'Aanvullende adresgegevens: gehucht, gebouw, verdieping.',
        ],
        'postal_code' => [
            'label' => 'Postcode',
            'help' => 'Franse postcode van de woning.',
        ],
        'city' => [
            'label' => 'Gemeente',
            'help' => 'Gemeente van de woning. Wordt ook gebruikt voor de toeristenbelasting.',
        ],
        'country' => [
            'label' => 'Land',
            'help' => 'SecondStay is gespecialiseerd voor Frankrijk.',
        ],
        'latitude' => [
            'label' => 'Breedtegraad',
            'help' => 'Coördinaat voor lokale inhoud en bereikbaarheid.',
        ],
        'longitude' => [
            'label' => 'Lengtegraad',
            'help' => 'Coördinaat voor lokale inhoud en bereikbaarheid.',
        ],
        'contact_email' => [
            'label' => 'Contact-e-mail',
            'help' => 'Adres dat aan gasten wordt getoond voor algemene vragen.',
        ],
        'contact_phone' => [
            'label' => 'Contacttelefoon',
            'help' => 'Nummer dat aan gasten wordt getoond.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Standaardtaal',
            'help' => 'Taal die wordt gebruikt als geen voorkeur bekend is.',
        ],
        'timezone' => [
            'label' => 'Tijdzone',
            'help' => 'Zone voor aankomst- en vertrektijden en herinneringen.',
        ],
        'public_url' => [
            'label' => 'Publieke URL',
            'help' => 'Openbaar adres van de site, gebruikt in e-mails en links.',
        ],
        'season' => [
            'label' => 'Weergegeven seizoen',
            'help' => 'Automatisch volgt de datum; forceer zomer of winter voor een vaste presentatie.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Minimum aantal nachten',
            'help' => 'Minimale geaccepteerde verblijfsduur.',
        ],
        'max_guests' => [
            'label' => 'Maximale capaciteit',
            'help' => 'Totaal aantal geaccepteerde gasten, baby’s inbegrepen volgens uw regel.',
        ],
        'checkin_time' => [
            'label' => 'Aankomsttijd',
            'help' => 'Tijd vanaf wanneer de woning beschikbaar is.',
        ],
        'checkout_time' => [
            'label' => 'Vertrektijd',
            'help' => 'Uiterste tijd om de woning te verlaten.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Zaterdag-tot-zaterdagregel',
            'help' => 'Verplicht verblijven van zaterdag tot zaterdag. Uitschakelbaar.',
        ],
        'hold_minutes' => [
            'label' => 'Duur van tijdelijke reservering',
            'help' => 'Minuten dat data geblokkeerd blijven vóór bevestiging.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Standaard prijs per nacht',
            'help' => 'Gebruikt voor elke datum zonder specifiek tarief.',
        ],
        'cleaning_mode' => [
            'label' => 'Schoonmaakmodus',
            'help' => 'Geen, optioneel of verplicht. Standaard verplicht.',
        ],
        'cleaning_price' => [
            'label' => 'Schoonmaakprijs',
            'help' => 'Bedrag van de schoonmaakkosten. Standaardwaarde: € 100.',
        ],
        'deposit_percent' => [
            'label' => 'Aanbetaling (%)',
            'help' => 'Deel van het verblijf dat nodig is om de reservering te bevestigen.',
        ],
        'security_deposit' => [
            'label' => 'Waarborgsom',
            'help' => 'Bedrag van de waarborg vóór het verblijf.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Gepland onderhoud',
            'help' => 'Sluit de publieke site. Beheer blijft bereikbaar.',
        ],
        'message' => [
            'label' => 'Onderhoudsbericht',
            'help' => 'Interne notitie met de reden voor het onderhoud.',
        ],
    ],
    'backup' => [
        'retention_count' => [
            'label' => 'Bewaarde back-ups',
            'help' => 'Aantal back-ups dat wordt bewaard vóór automatische verwijdering.',
        ],
        'include_media' => [
            'label' => 'Media opnemen',
            'help' => 'Voegt foto’s, documenten en bijlagen toe aan de back-up.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Updatekanaal',
            'help' => 'Stabiel installeert alleen gepubliceerde versies.',
        ],
        'auto_install' => [
            'label' => 'Automatische update',
            'help' => 'Installeert automatisch nieuwe gevalideerde versies.',
        ],
        'repository' => [
            'label' => 'Release-repository',
            'help' => 'GitHub-repository met installeerbare artefacten.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Logniveau',
            'help' => 'Minimale ernst van vastgelegde berichten.',
        ],
        'retention_days' => [
            'label' => 'Bewaartermijn logboek (dagen)',
            'help' => 'Bewaarperiode vóór automatische opschoning.',
        ],
    ],
    'error' => [
        'required' => 'Deze instelling is verplicht.',
        'unknown' => 'Onbekende instelling.',
        'integer' => 'Voer een geheel getal in.',
        'decimal' => 'Voer een decimaal getal in.',
        'money' => 'Voer een geldig bedrag in, bijvoorbeeld 100,00.',
        'enum' => 'Waarde niet toegestaan.',
        'email' => 'Ongeldig e-mailadres.',
        'url' => 'Ongeldige URL.',
        'url_scheme' => 'Alleen http(s)-URL’s worden geaccepteerd.',
        'date' => 'Ongeldige datum (verwacht formaat: JJJJ-MM-DD).',
        'time' => 'Ongeldige tijd (verwacht formaat: UU:MM).',
        'duration' => 'Ongeldige duur (in minuten).',
        'json' => 'Ongeldige JSON.',
        'too_long' => 'Waarde is te lang.',
        'too_small' => 'Waarde is te klein.',
        'too_large' => 'Waarde is te groot.',
    ],
];
