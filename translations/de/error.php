<?php

declare(strict_types=1);

return [
    '400' => [
        'title' => 'Ungültige Anfrage',
        'message' => 'Die Anfrage konnte nicht verarbeitet werden.',
    ],
    '403' => [
        'title' => 'Zugriff verweigert',
        'message' => 'Sie haben nicht die erforderlichen Rechte für diese Ressource.',
    ],
    '404' => [
        'title' => 'Seite nicht gefunden',
        'message' => 'Die angeforderte Seite existiert nicht oder wurde verschoben.',
    ],
    '500' => [
        'title' => 'Interner Fehler',
        'message' => 'Ein unerwarteter Fehler ist aufgetreten. Der Vorfall wurde protokolliert.',
    ],
    '503' => [
        'title' => 'Wartung läuft',
        'message' => 'Die Website ist wegen Wartungsarbeiten vorübergehend nicht verfügbar.',
    ],
    'back_home' => 'Zurück zur Startseite',
    'reference' => 'Vorfallreferenz',
];
