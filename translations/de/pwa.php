<?php

declare(strict_types=1);

/**
 * Application installable : manifeste, raccourcis et page hors ligne.
 */

return [
    'description' => 'Verfolgen Sie Ihren Aufenthalt in {property}: Verfügbarkeit, Dokumente und praktische Hinweise.',
    'install' => 'App installieren',
    'installed' => 'App installiert.',
    'shortcut' => [
        'account' => 'Mein Konto',
        'gallery' => 'Galerie',
    ],
    'offline' => [
        'title' => 'Sie sind offline',
        'message' => 'Diese Seite ist ohne Verbindung nicht verfügbar.',
        'available' => 'Offline verfügbar: bereits besuchte Seiten und die praktischen Hinweise zu Ihrem Aufenthalt.',
        'unavailable' => 'Offline nicht verfügbar: Buchung, Zahlung und persönliche Dokumente.',
    ],
    'error' => [
        'unsupported_size' => 'Nicht unterstützte Symbolgröße.',
        'cache_unavailable' => 'Symbol-Cache nicht verfügbar.',
        'generation_failed' => 'Symbolerstellung fehlgeschlagen.',
    ],
];
