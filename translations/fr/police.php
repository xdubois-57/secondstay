<?php

declare(strict_types=1);

/**
 * Fiche individuelle de police (SPECIFICATIONS.md §64).
 */

return [
    'title' => 'Fiches de police',
    'record' => 'Fiche de police',
    'open_record' => 'Ouvrir la fiche',
    'intro' => 'La fiche individuelle n’est exigée que dans certains cas. Tant qu’elle n’est pas activée, aucune '
        . 'donnée d’identité n’est collectée.',
    'record_intro' => 'Les données sont chiffrées et effacées automatiquement à l’échéance de conservation.',
    'enabled' => 'La fiche de police est demandée pour les séjours concernés.',
    'disabled' => 'La fiche de police est désactivée : rien n’est collecté.',
    'configure' => 'Configurer',
    'records' => 'Fiches enregistrées',
    'empty' => 'Aucune fiche enregistrée.',
    'unreadable' => 'Fiche illisible',
    'saved' => 'Fiche enregistrée.',
    'deleted' => 'Fiche supprimée.',
    'purge_after' => 'Effacement le {date}',
    'retention' => 'Conservation : {days} jours après le départ.',
    'field' => [
        'last_name' => 'Nom',
        'first_names' => 'Prénoms',
        'birth_date' => 'Date de naissance',
        'birth_place' => 'Lieu de naissance',
        'nationality' => 'Nationalité',
        'home_address' => 'Domicile habituel',
        'arrival_date' => 'Date d’arrivée',
        'departure_date' => 'Date de départ prévue',
    ],
    'error' => [
        'disabled' => 'La fiche de police n’est pas activée.',
        'incomplete' => 'Nom, prénoms, date de naissance et nationalité sont obligatoires.',
    ],
];
