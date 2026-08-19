<?php

declare(strict_types=1);

/**
 * Application installable : manifeste, raccourcis et page hors ligne.
 */

return [
    'description' => 'Follow your stay at {property}: availability, documents and practical information.',
    'install' => 'Install the app',
    'installed' => 'App installed.',
    'shortcut' => [
        'account' => 'My account',
        'gallery' => 'Gallery',
    ],
    'offline' => [
        'title' => 'You are offline',
        'message' => 'This page is not available without a connection.',
        'available' => 'Available offline: pages you already visited and the practical information for your stay.',
        'unavailable' => 'Unavailable offline: booking, payment and personal documents.',
    ],
    'error' => [
        'unsupported_size' => 'Unsupported icon size.',
        'cache_unavailable' => 'Icon cache unavailable.',
        'generation_failed' => 'Icon generation failed.',
    ],
];
