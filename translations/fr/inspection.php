<?php

declare(strict_types=1);

/**
 * États des lieux d’arrivée et de départ (SPECIFICATIONS.md §53).
 */

return [
    'title' => 'États des lieux',
    'kind' => [
        'checkin' => 'État des lieux d’arrivée',
        'checkout' => 'État des lieux de départ',
    ],
    'state' => [
        'legend' => 'Constat',
        'pending' => 'À vérifier',
        'ok' => 'Conforme',
        'anomaly' => 'Anomalie',
    ],
    'status' => [
        'open' => 'En cours',
        'completed' => 'Terminé',
    ],
    'zone' => [
        'entrance' => 'Entrée',
        'living_room' => 'Séjour',
        'kitchen' => 'Cuisine',
        'bedrooms' => 'Chambres',
        'bathrooms' => 'Salles de bain',
        'outdoor' => 'Extérieur',
        'meters' => 'Compteurs',
    ],
    'checkin_intro' => 'Signalez ce qui ne va pas dans les {hours} heures suivant votre arrivée. Si tout est conforme, vous n’avez rien à faire.',
    'checkout_intro' => 'Au départ, une photo est obligatoire pour chaque zone qui le demande.',
    'note' => 'Commentaire',
    'photo' => 'Photo',
    'photo_required' => 'Photo obligatoire',
    'photo_n' => 'Photo {index}',
    'save' => 'Enregistrer la zone',
    'complete' => 'Terminer l’état des lieux',
    'saved' => 'Zone enregistrée.',
    'completed' => 'État des lieux terminé.',
    'open_incident' => 'Signaler un incident',
    'no_zone' => 'Aucune zone n’est encore définie pour ce logement.',
    'done_on' => 'Terminé le {date}.',
    'not_started' => 'Non commencé',
    'not_started_help' => 'Aucun constat n’a encore été enregistré pour ce moment du séjour.',
    'error' => [
        'completed' => 'Cet état des lieux est terminé : il ne peut plus être modifié.',
        'unknown_zone' => 'Zone inconnue.',
        'not_a_photo' => 'Seules des photos sont acceptées ici.',
        'photos_required' => 'Les photos des zones requises sont obligatoires au départ.',
        'incomplete' => 'Toutes les zones doivent être renseignées.',
        'not_an_anomaly' => 'Un incident ne peut être ouvert que sur une zone déclarée en anomalie.',
        'code' => 'Le code de la zone est obligatoire.',
    ],
    'admin' => [
        'title' => 'Zones et photos de référence',
        'intro' => 'Définissez les zones du logement, leur ordre, leurs consignes et celles qui exigent une photo au départ.',
        'completeness' => 'Noms personnalisés',
        'completeness_help' => 'Une zone sans nom personnalisé utilise le libellé intégré, déjà disponible dans les quatre langues.',
        'no_zone' => 'Aucune zone n’est définie.',
        'seed' => 'Créer les zones proposées',
        'seeded' => 'Zones proposées créées.',
        'already_seeded' => 'Des zones existent déjà : rien n’a été créé.',
        'saved' => 'Zone enregistrée.',
        'reference_added' => 'Photo de référence ajoutée.',
        'name' => 'Nom de la zone',
        'position' => 'Ordre',
        'instructions' => 'Consignes',
        'reference_note' => 'Note de référence',
        'active' => 'Active',
        'reference_photos' => 'Photos de référence',
        'no_reference' => 'Aucune photo de référence.',
        'add_reference' => 'Ajouter une photo de référence',
        'new_zone' => 'Nouvelle zone',
        'code' => 'Code',
        'code_help' => 'Identifiant stable, en minuscules, indépendant de la langue.',
        'detail' => 'Voir le détail',
    ],
];
