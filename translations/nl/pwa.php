<?php

declare(strict_types=1);

/**
 * Application installable : manifeste, raccourcis et page hors ligne.
 */

return [
    'description' => 'Volg uw verblijf in {property}: beschikbaarheid, documenten en praktische informatie.',
    'install' => 'De app installeren',
    'installed' => 'App geïnstalleerd.',
    'shortcut' => [
        'account' => 'Mijn account',
        'gallery' => 'Galerij',
    ],
    'offline' => [
        'title' => 'U bent offline',
        'message' => 'Deze pagina is niet beschikbaar zonder verbinding.',
        'available' => 'Offline beschikbaar: reeds bezochte pagina’s en de praktische informatie over uw verblijf.',
        'unavailable' => 'Offline niet beschikbaar: reserveren, betalen en persoonlijke documenten.',
    ],
    'error' => [
        'unsupported_size' => 'Niet-ondersteunde pictogramgrootte.',
        'cache_unavailable' => 'Pictogramcache niet beschikbaar.',
        'generation_failed' => 'Aanmaken van pictogram mislukt.',
    ],
];
