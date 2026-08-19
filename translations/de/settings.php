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
            'label' => 'Name des Objekts',
            'help' => 'Öffentlich angezeigter Name Ihres Ferienhauses.',
        ],
        'address_line1' => [
            'label' => 'Adresse (Zeile 1)',
            'help' => 'Hausnummer und Straße des Objekts.',
        ],
        'address_line2' => [
            'label' => 'Adresse (Zeile 2)',
            'help' => 'Adresszusatz: Ortsteil, Gebäude, Etage.',
        ],
        'postal_code' => [
            'label' => 'Postleitzahl',
            'help' => 'Französische Postleitzahl des Objekts.',
        ],
        'city' => [
            'label' => 'Gemeinde',
            'help' => 'Gemeinde des Objekts. Wird auch für die Kurtaxe verwendet.',
        ],
        'country' => [
            'label' => 'Land',
            'help' => 'SecondStay ist auf Frankreich spezialisiert.',
        ],
        'latitude' => [
            'label' => 'Breitengrad',
            'help' => 'Koordinate für lokale Inhalte und Anfahrt.',
        ],
        'longitude' => [
            'label' => 'Längengrad',
            'help' => 'Koordinate für lokale Inhalte und Anfahrt.',
        ],
        'contact_email' => [
            'label' => 'Kontakt-E-Mail',
            'help' => 'Adresse, die Gästen für allgemeine Fragen angezeigt wird.',
        ],
        'contact_phone' => [
            'label' => 'Kontakttelefon',
            'help' => 'Nummer, die Gästen angezeigt wird.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Standardsprache',
            'help' => 'Sprache, wenn keine Präferenz bekannt ist.',
        ],
        'timezone' => [
            'label' => 'Zeitzone',
            'help' => 'Zone für An- und Abreisezeiten sowie Erinnerungen.',
        ],
        'public_url' => [
            'label' => 'Öffentliche URL',
            'help' => 'Öffentliche Adresse der Website, in E-Mails und Links verwendet.',
        ],
        'season' => [
            'label' => 'Angezeigte Saison',
            'help' => 'Automatisch folgt dem Datum; Sommer oder Winter erzwingt eine feste Darstellung.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Mindestanzahl Nächte',
            'help' => 'Minimal akzeptierte Aufenthaltsdauer.',
        ],
        'max_guests' => [
            'label' => 'Maximale Kapazität',
            'help' => 'Gesamtzahl akzeptierter Gäste, Babys je nach Ihrer Regel inbegriffen.',
        ],
        'checkin_time' => [
            'label' => 'Anreisezeit',
            'help' => 'Uhrzeit, ab der das Objekt verfügbar ist.',
        ],
        'checkout_time' => [
            'label' => 'Abreisezeit',
            'help' => 'Späteste Uhrzeit für die Räumung des Objekts.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Samstag-zu-Samstag-Regel',
            'help' => 'Erzwingt Aufenthalte von Samstag bis Samstag. Deaktivierbar.',
        ],
        'hold_minutes' => [
            'label' => 'Dauer der vorläufigen Reservierung',
            'help' => 'Minuten, in denen Termine bis zur Bestätigung blockiert bleiben.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Standardpreis pro Nacht',
            'help' => 'Gilt für jedes Datum ohne besonderen Tarif.',
        ],
        'cleaning_mode' => [
            'label' => 'Reinigungsmodus',
            'help' => 'Keine, optional oder verpflichtend. Standardmäßig verpflichtend.',
        ],
        'cleaning_price' => [
            'label' => 'Reinigungspreis',
            'help' => 'Betrag der Reinigungspauschale. Standardwert: 100 €.',
        ],
        'deposit_percent' => [
            'label' => 'Anzahlung (%)',
            'help' => 'Anteil des Aufenthalts, der zur Bestätigung erforderlich ist.',
        ],
        'security_deposit' => [
            'label' => 'Kaution',
            'help' => 'Kautionsbetrag, der vor dem Aufenthalt verlangt wird.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Geplante Wartung',
            'help' => 'Schließt die öffentliche Website. Die Verwaltung bleibt erreichbar.',
        ],
        'message' => [
            'label' => 'Wartungsmeldung',
            'help' => 'Interne Notiz zur Begründung der Wartung.',
        ],
    ],
    'backup' => [
        'retention_count' => [
            'label' => 'Aufbewahrte Sicherungen',
            'help' => 'Anzahl der Sicherungen vor automatischer Löschung.',
        ],
        'include_media' => [
            'label' => 'Medien einschließen',
            'help' => 'Fügt Fotos, Dokumente und Anhänge zur Sicherung hinzu.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Aktualisierungskanal',
            'help' => 'Stabil installiert nur veröffentlichte Versionen.',
        ],
        'auto_install' => [
            'label' => 'Automatische Aktualisierung',
            'help' => 'Installiert neue geprüfte Versionen automatisch.',
        ],
        'repository' => [
            'label' => 'Release-Repository',
            'help' => 'GitHub-Repository mit installierbaren Artefakten.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Protokollierungsstufe',
            'help' => 'Mindestschwere protokollierter Meldungen.',
        ],
        'retention_days' => [
            'label' => 'Protokollaufbewahrung (Tage)',
            'help' => 'Aufbewahrungsdauer vor automatischer Bereinigung.',
        ],
    ],
    'error' => [
        'required' => 'Diese Einstellung ist erforderlich.',
        'unknown' => 'Unbekannte Einstellung.',
        'integer' => 'Geben Sie eine ganze Zahl ein.',
        'decimal' => 'Geben Sie eine Dezimalzahl ein.',
        'money' => 'Geben Sie einen gültigen Betrag ein, zum Beispiel 100,00.',
        'enum' => 'Wert nicht zulässig.',
        'email' => 'Ungültige E-Mail-Adresse.',
        'url' => 'Ungültige URL.',
        'url_scheme' => 'Nur http(s)-URLs werden akzeptiert.',
        'date' => 'Ungültiges Datum (erwartetes Format: JJJJ-MM-TT).',
        'time' => 'Ungültige Uhrzeit (erwartetes Format: HH:MM).',
        'duration' => 'Ungültige Dauer (in Minuten).',
        'json' => 'Ungültiges JSON.',
        'too_long' => 'Wert ist zu lang.',
        'too_small' => 'Wert ist zu klein.',
        'too_large' => 'Wert ist zu groß.',
    ],
];
