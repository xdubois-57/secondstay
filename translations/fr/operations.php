<?php

declare(strict_types=1);

/**
 * Exploitation : responsable local, checklists et tableau « À faire ».
 */

return [
    'title' => 'Exploitation',
    'todo' => [
        'title' => 'À faire',
        'empty' => 'Rien ne réclame votre attention.',
        'bookings_to_confirm' => 'Demandes à valider',
        'payments_overdue' => 'Échéances dépassées',
        'deposits_to_return' => 'Cautions à restituer',
        'mail_unlinked' => 'Messages non rattachés',
        'stays_to_prepare' => 'Séjours à préparer',
        'incidents_open' => 'Incidents ouverts',
        'compliance_to_verify' => 'Conformité à vérifier',
        'migrations_pending' => 'Migrations en attente',
    ],
    'phase' => [
        'before' => 'Avant le séjour',
        'departure' => 'Au départ',
    ],
    'item' => [
        'contract' => 'Contrat accepté',
        'deposit' => 'Acompte encaissé',
        'balance' => 'Solde encaissé',
        'security_deposit' => 'Caution reçue',
        'manager' => 'Responsable affecté',
        'cleaning_scheduled' => 'Ménage planifié',
        'access_shared' => 'Accès transmis',
        'welcome_sent' => 'Message d’accueil envoyé',
        'inventory_done' => 'État des lieux réalisé',
        'incidents_reviewed' => 'Incidents examinés',
        'cleaning_done' => 'Ménage effectué',
        'deposit_settled' => 'Caution soldée',
    ],
    'manager' => [
        'title' => 'Responsable local',
        'contact' => 'Responsable local',
        'assign' => 'Affecter',
        'assigned' => 'Responsable affecté.',
        'unassigned' => 'Aucun responsable',
        'default' => 'Responsable par défaut',
        'none' => '— aucun —',
        'my_stays' => 'Mes séjours',
        'empty' => 'Aucun séjour ne vous est affecté.',
    ],
    'checklist' => [
        'title' => 'Checklist',
        'progress' => '{done} sur {total}',
        'derived' => 'Suivi automatiquement',
        'save' => 'Enregistrer',
        'updated' => 'Checklist mise à jour.',
        'note' => 'Remarque',
    ],
    'prepare' => [
        'title' => 'Séjours à préparer',
        'empty' => 'Aucun séjour proche n’attend de préparation.',
        'arrival' => 'Arrivée',
        'remaining' => 'Reste à faire',
    ],
    'error' => [
        'unknown_item' => 'Élément de checklist inconnu.',
        'manager_invalid' => 'Ce compte n’est pas un responsable local.',
        'booking_not_found' => 'Séjour introuvable.',
    ],
];
