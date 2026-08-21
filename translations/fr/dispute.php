<?php

declare(strict_types=1);

/**
 * Litiges liés à un séjour (SPECIFICATIONS.md §68).
 */

return [
    'title' => 'Litiges',
    'intro' => 'Un litige rassemble ce que le produit a déjà collecté — caution détenue, état des lieux de départ, incidents, contrat accepté — pour que la discussion s’appuie sur des faits datés.',
    'empty' => 'Aucun litige.',
    'evidence' => 'Pièces au dossier',
    'actions' => 'Suite à donner',
    'history' => 'Historique',
    'no_transition' => 'Aucun changement d’état possible depuis cet état.',
    'opened' => 'Litige ouvert.',
    'updated' => 'Litige mis à jour.',
    'open_title' => 'Ouvrir un litige',
    'filter' => [
        'all' => 'Tous',
    ],
    'field' => [
        'summary' => 'Objet',
        'booking' => 'Séjour',
        'claimed' => 'Montant réclamé',
        'settled' => 'Montant réglé',
        'waived' => 'Montant abandonné',
        'status' => 'État',
        'resolution' => 'Explication de la résolution',
        'note' => 'Ajouter un échange',
        'kind' => 'Nature',
    ],
    'kind' => [
        'deposit' => 'Retenue sur caution',
        'damage' => 'Dégradation',
        'payment' => 'Paiement',
        'other' => 'Autre',
    ],
    'status' => [
        'open' => 'Ouvert',
        'discussing' => 'En discussion',
        'resolved' => 'Résolu',
    ],
    'action' => [
        'discussing' => 'Passer en discussion',
        'resolved' => 'Clore le litige',
        'open' => 'Ouvrir le litige',
    ],
    'event' => [
        'opened' => 'Litige ouvert',
        'discussing' => 'Passé en discussion',
        'resolved' => 'Litige résolu',
        'comment' => 'Échange',
    ],
    'evidence_field' => [
        'deposit' => 'Caution détenue',
        'checkout' => 'État des lieux de départ réalisé',
        'anomalies' => 'Anomalies relevées au départ',
        'photos' => 'Photos versées au dossier',
        'incidents' => 'Incidents enregistrés',
        'contract' => 'Contrat accepté',
    ],
    'error' => [
        'summary_required' => 'Décrivez l’objet du litige.',
        'above_deposit' => 'La retenue réclamée dépasse la caution réellement détenue.',
        'amount' => 'Montant invalide.',
        'already_open' => 'Un litige de cette nature existe déjà sur ce séjour.',
        'transition' => 'Ce changement d’état n’est pas autorisé.',
        'resolution_required' => 'Expliquez comment le litige a été résolu.',
        'settlement' => 'Le montant réglé doit être compris entre zéro et le montant réclamé.',
        'note_required' => 'Saisissez un échange avant de l’ajouter.',
    ],
];
