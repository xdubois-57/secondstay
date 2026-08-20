<?php

declare(strict_types=1);

/**
 * Calendriers privés au format iCalendar.
 */

return [
    'title' => 'Calendriers privés',
    'intro' => 'Ces adresses sont personnelles : elles donnent accès au calendrier sans mot de passe. Ne les partagez pas.',
    'scope' => [
        'admin' => 'Administration',
        'manager' => 'Responsable local',
        'customer' => 'Mon séjour',
    ],
    'feed' => [
        'admin' => 'Séjours — {property}',
        'manager' => 'Interventions — {property}',
        'customer' => 'Mon séjour — {property}',
    ],
    'event' => [
        'stay' => 'Séjour {reference}',
    ],
    'action' => [
        'create' => 'Créer un lien',
        'revoke' => 'Révoquer',
        'copy' => 'Adresse du calendrier',
        'subscribe' => 'S’abonner au calendrier',
    ],
    'created' => 'Lien de calendrier créé. Copiez-le maintenant : il ne sera plus affiché.',
    'revoked' => 'Lien de calendrier révoqué.',
    'once' => 'Cette adresse n’est affichée qu’une fois.',
    'never_used' => 'Jamais utilisé',
    'last_used' => 'Dernière utilisation',
    'label' => 'Libellé',
    'created_at' => 'Créé le',
    'empty' => 'Aucun lien de calendrier actif.',
    'error' => [
        'not_found' => 'Lien de calendrier introuvable.',
        'disabled' => 'Les calendriers privés sont désactivés.',
    ],
];
