<?php

declare(strict_types=1);

/**
 * Mietvertrag: Inhalt der PDF und Annahmeablauf.
 */

return [
    'pdf' => [
        'title' => 'Mietvertrag für Ferienunterkunft',
        'subject' => 'Mietvertrag für eine möblierte Ferienunterkunft',
        'reference' => 'Referenz',
        'version' => 'Vorlagenversion {version} — Sprache {locale}',
        'acceptance_notice' => 'Die Annahme dieses Vertrags wird elektronisch mit Datum, Version und Sprache '
            . 'festgehalten.',
    ],
    'section' => [
        'parties' => 'Die Parteien',
        'property' => 'Die Unterkunft',
        'stay' => 'Der Aufenthalt',
        'amounts' => 'Die Beträge',
    ],
    'field' => [
        'owner' => 'Eigentümer',
        'owner_address' => 'Anschrift des Eigentümers',
        'siret' => 'SIRET',
        'guest' => 'Gast',
        'guest_email' => 'E-Mail-Adresse',
        'guest_phone' => 'Telefon',
        'address' => 'Anschrift',
        'capacity' => 'Kapazität',
        'arrival' => 'Anreise',
        'departure' => 'Abreise',
        'nights' => 'Dauer',
        'occupants' => 'Belegung',
        'accommodation' => 'Unterkunft',
        'cleaning' => 'Endreinigung',
        'discount' => 'Rabatt',
        'total' => 'Gesamtbetrag des Aufenthalts',
        'security_deposit' => 'Kaution',
        'terms_version' => 'Geltende Allgemeine Geschäftsbedingungen: Version {version}.',
    ],
    'table' => [
        'component' => 'Bestandteil',
        'due_on' => 'Fällig am',
        'amount' => 'Betrag',
    ],
    'value' => [
        'occupants' => '{adults} Erwachsene(r), {children} Kind(er), {infants} Kleinkind(er)',
        'guests' => '{count} Person|{count} Personen',
        'nights' => '{count} Nacht|{count} Nächte',
    ],
    'clause' => [
        'cancellation' => [
            'title' => 'Stornierung',
            'body' => 'Der Gast kann jederzeit stornieren. Bereits gezahlte Beträge werden nach den zum '
                . 'Buchungszeitpunkt geltenden Allgemeinen Geschäftsbedingungen erstattet oder einbehalten; deren '
                . 'Version ist unten angegeben. Storniert der Eigentümer, werden alle gezahlten Beträge vollständig '
                . 'erstattet.',
        ],
        'inventory' => [
            'title' => 'Übergabeprotokoll',
            'body' => 'Bei An- und Abreise wird ein Übergabeprotokoll erstellt. Bleibt eine Rüge des Gastes innerhalb '
                . 'von vierundzwanzig Stunden nach Anreise aus, gilt die Unterkunft als dem Anreiseprotokoll '
                . 'entsprechend.',
        ],
        'rules' => [
            'title' => 'Nutzung der Unterkunft',
            'body' => 'Die Unterkunft wird möbliert und ausschließlich zur vorübergehenden Wohnnutzung vermietet. Die '
                . 'Zahl der Bewohner darf die angegebene Kapazität nicht überschreiten. Untervermietung ist untersagt.',
        ],
        'liability' => [
            'title' => 'Haftung und Versicherung',
            'body' => 'Der Gast haftet für während des Aufenthalts verursachte Schäden und erklärt, für die Dauer der '
                . 'Miete haftpflichtversichert zu sein.',
        ],
        'data' => [
            'title' => 'Personenbezogene Daten',
            'body' => 'Die erhobenen Daten dienen ausschließlich der Verwaltung der Vermietung. Der Gast hat ein Recht '
                . 'auf Auskunft, Berichtigung, Übertragbarkeit und Löschung, ausübbar über seinen persönlichen '
                . 'Bereich.',
        ],
    ],
    'accept' => [
        'title' => 'Mietvertrag',
        'read' => 'Vertrag lesen',
        'action' => 'Ich nehme den Vertrag an',
        'accepted' => 'Vertrag angenommen',
        'accepted_on' => 'Angenommen am {date}',
        'accepted_version' => 'Version {version}, Sprache {locale}',
        'pending' => 'Der Vertrag liegt vor: lesen Sie ihn und nehmen Sie ihn an, um fortzufahren.',
        'confirm' => 'Mit dem Setzen dieses Häkchens nehme ich den Vertrag so an, wie er mir vorgelegt wird.',
        'success' => 'Vertrag angenommen. Vielen Dank.',
        'intact' => 'Das angenommene Dokument ist unverändert.',
        'altered' => 'Das angenommene Dokument stimmt nicht mehr mit seiner Prüfsumme überein.',
    ],
    'error' => [
        'not_owner' => 'Dieser Vertrag betrifft Sie nicht.',
        'already_accepted' => 'Dieser Vertrag wurde bereits angenommen.',
        'unavailable' => 'Der Vertrag konnte nicht erstellt werden.',
        'not_accepted' => 'Der Vertrag muss angenommen werden.',
    ],
];
