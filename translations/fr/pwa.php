<?php

declare(strict_types=1);

/**
 * Application installable : manifeste, raccourcis et page hors ligne.
 */

return [
    'description' => 'Suivez votre séjour à {property} : disponibilités, documents et informations pratiques.',
    'install' => 'Installer l’application',
    'installed' => 'Application installée.',
    'shortcut' => [
        'account' => 'Mon compte',
        'gallery' => 'Galerie',
    ],
    'offline' => [
        'title' => 'Vous êtes hors ligne',
        'message' => 'Cette page n’est pas disponible sans connexion.',
        'available' => 'Consultables hors ligne : les pages déjà visitées et les informations pratiques de votre séjour.',
        'unavailable' => 'Indisponibles hors ligne : la réservation, le paiement et les documents personnels.',
    ],
    'error' => [
        'unsupported_size' => 'Taille d’icône non supportée.',
        'cache_unavailable' => 'Cache d’icônes inaccessible.',
        'generation_failed' => 'Génération d’icône impossible.',
    ],
];
