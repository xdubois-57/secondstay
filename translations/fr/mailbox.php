<?php

declare(strict_types=1);

/**
 * Courrier entrant, rattachement au séjour et timeline de communication.
 */

return [
    'title' => 'Messages',
    'inbound' => 'Courrier entrant',
    'timeline' => 'Échanges',
    'empty' => 'Aucun message.',
    'unlinked' => 'Messages non rattachés',
    'unlinked_empty' => 'Tous les messages reçus sont rattachés.',
    'from' => 'De',
    'to' => 'À',
    'subject' => 'Objet',
    'received' => 'Reçu le',
    'sent' => 'Envoyé le',
    'attachments' => 'Pièces jointes',
    'direction' => [
        'inbound' => 'Reçu',
        'outbound' => 'Envoyé',
    ],
    'link' => [
        'title' => 'Rattachement',
        'none' => 'Non rattaché',
        'token' => 'Adresse de réponse signée',
        'thread' => 'En-têtes de fil',
        'reference' => 'Référence citée',
        'sender' => 'Adresse de l’expéditeur',
        'manual' => 'Rattaché manuellement',
    ],
    'action' => [
        'link' => 'Rattacher',
        'sync' => 'Relever la boîte',
        'view' => 'Voir le message',
        'reference' => 'Référence du séjour',
    ],
    'sync' => [
        'done' => 'Relève terminée : {imported} message(s), {linked} rattaché(s), {documents} document(s).',
        'nothing' => 'Aucun nouveau message.',
        'disabled' => 'La relève de la boîte n’est pas activée.',
    ],
    'linked' => 'Message rattaché au séjour.',
    'reply_address' => 'Adresse de réponse',
    'reply_hint' => 'Répondez à ce message : votre réponse sera automatiquement rattachée à votre séjour.',
    'error' => [
        'not_found' => 'Message introuvable.',
        'booking_not_found' => 'Séjour introuvable.',
        'not_configured' => 'La boîte de réception n’est pas configurée.',
        'connection_failed' => 'Connexion à la boîte impossible.',
        'greeting' => 'Le serveur n’a pas répondu correctement.',
        'tls_failed' => 'La connexion sécurisée a échoué.',
        'command_failed' => 'Le serveur a refusé la commande.',
        'connection_lost' => 'Connexion interrompue.',
        'timeout' => 'Le serveur ne répond plus.',
        'too_large' => 'Message trop volumineux.',
        'write_failed' => 'Écriture impossible vers le serveur.',
    ],
    'verify' => [
        'ok' => 'Boîte accessible.',
    ],
];
