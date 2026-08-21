<?php

declare(strict_types=1);

/**
 * Rétention et purge des données (SPECIFICATIONS.md §65).
 */

return [
    'retention' => 'Durées de conservation',
    'retention_intro' => 'Protéger des données ne suffit pas : il faut aussi ne pas les garder au-delà de ce qui les justifie.',
    'kept_intro' => 'Les séjours, paiements, contrats acceptés et états des lieux ne sont jamais purgés automatiquement : ce sont des pièces contractuelles, et leur suppression reste une décision humaine.',
    'purge_now' => 'Appliquer maintenant',
    'purged' => 'Rétention appliquée.',
    'nothing_to_purge' => 'Rien à purger.',
    'days' => '{days} jours',
    'category' => [
        'logs' => 'Journaux applicatifs',
        'notifications' => 'Journal des notifications',
        'guest_links' => 'Liens invité expirés',
        'webhooks' => 'Notifications de paiement',
        'police_records' => 'Fiches de police',
    ],
];
