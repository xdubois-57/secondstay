<?php

declare(strict_types=1);

return [
    '400' => [
        'title' => 'Requête invalide',
        'message' => 'La demande n’a pas pu être traitée.',
    ],
    '403' => [
        'title' => 'Accès refusé',
        'message' => 'Vous n’avez pas les droits nécessaires pour accéder à cette ressource.',
    ],
    '404' => [
        'title' => 'Page introuvable',
        'message' => 'La page demandée n’existe pas ou a été déplacée.',
    ],
    '500' => [
        'title' => 'Erreur interne',
        'message' => 'Une erreur inattendue est survenue. L’incident a été enregistré.',
    ],
    '503' => [
        'title' => 'Maintenance en cours',
        'message' => 'Le site est temporairement indisponible pour maintenance.',
    ],
    'back_home' => 'Revenir à l’accueil',
    'reference' => 'Référence de l’incident',
];
