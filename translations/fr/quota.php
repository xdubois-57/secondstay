<?php

declare(strict_types=1);

/**
 * Quotas de stockage (ROADMAP.md itération 14).
 */

return [
    'title' => 'Espace de stockage',
    'mb' => 'Mo',
    'usage' => 'Espace utilisé pour cette catégorie',
    'no_limit' => 'aucune limite',
    'configure' => 'Régler les quotas',
    'category' => [
        'media' => 'Médias',
        'documents' => 'Documents',
        'backups' => 'Sauvegardes',
        'mail-attachments' => 'Pièces jointes',
    ],
    'error' => [
        'media' => 'Le quota des médias est atteint. Libérez de l’espace ou augmentez la limite avant d’ajouter un '
            . 'fichier.',
        'documents' => 'Le quota des documents est atteint. Libérez de l’espace ou augmentez la limite avant d’ajouter '
            . 'un fichier.',
        'backups' => 'Le quota des sauvegardes est atteint. Supprimez d’anciennes sauvegardes ou augmentez la limite.',
        'mail-attachments' => 'Le quota des pièces jointes est atteint.',
    ],
];
