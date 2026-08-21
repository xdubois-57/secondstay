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
    'import' => [
        'title' => 'Calendriers externes importés',
        'intro' => 'Les nuits vendues sur une autre plateforme deviennent des indisponibilités. Un flux importé ne crée jamais de réservation et ne touche jamais aux blocages saisis à la main.',
        'url' => 'Adresse du flux',
        'label' => 'Libellé',
        'provider_label' => 'Plateforme',
        'last_sync' => 'Dernière synchronisation',
        'events' => 'Nuits bloquées',
        'empty' => 'Aucun calendrier externe importé.',
        'never_synced' => 'Jamais synchronisé',
        'added' => 'Calendrier externe ajouté. Lancez une synchronisation pour importer les nuits.',
        'deleted' => 'Calendrier externe supprimé, avec les blocages qui en venaient.',
        'synced' => 'Calendriers externes synchronisés.',
        'partial' => 'Certains calendriers externes n’ont pas répondu ; les blocages existants sont conservés.',
        'nothing' => 'Aucun calendrier externe actif à synchroniser.',
        'action' => [
            'add' => 'Ajouter',
            'sync' => 'Synchroniser',
            'delete' => 'Supprimer',
        ],
        'provider' => [
            'airbnb' => 'Airbnb',
            'booking' => 'Booking.com',
            'abritel' => 'Abritel',
            'other' => 'Autre',
        ],
        'status' => [
            'ok' => 'À jour',
            'blocked' => 'Adresse refusée',
            'not_a_calendar' => 'Pas un calendrier',
            'unavailable' => 'Indisponible',
        ],
        'error' => [
            'duplicate' => 'Ce flux est déjà importé.',
            'inactive' => 'Ce calendrier est désactivé.',
            'blocked' => 'Adresse refusée : le flux ne peut pas être récupéré.',
            'not_a_calendar' => 'La réponse n’est pas un calendrier iCalendar.',
            'unavailable' => 'Flux indisponible : la synchronisation a échoué.',
        ],
    ],
    'error' => [
        'not_found' => 'Lien de calendrier introuvable.',
        'disabled' => 'Les calendriers privés sont désactivés.',
    ],
];
