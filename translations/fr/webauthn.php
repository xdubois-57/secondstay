<?php

declare(strict_types=1);

return [
    'error' => [
        'generic' => 'La clé d’accès n’a pas pu être vérifiée.',
        'unsupported_algorithm' => 'Algorithme de clé non supporté.',
        'invalid_key' => 'Clé publique invalide.',
        'invalid_attestation' => 'Attestation invalide.',
        'invalid_authenticator_data' => 'Données d’authentificateur invalides.',
        'invalid_client_data' => 'Données client invalides.',
        'invalid_encoding' => 'Encodage invalide.',
        'relying_party_mismatch' => 'Le site ne correspond pas à celui de la clé.',
        'origin_mismatch' => 'Origine non autorisée.',
        'cross_origin' => 'Requête inter-origines refusée.',
        'type_mismatch' => 'Type d’opération inattendu.',
        'challenge_mismatch' => 'Défi de sécurité invalide.',
        'challenge_expired' => 'Le délai est dépassé, recommencez.',
        'no_challenge' => 'Aucune demande en cours.',
        'user_not_present' => 'Confirmation utilisateur absente.',
        'no_credential' => 'Aucune clé d’accès fournie.',
        'unknown_credential' => 'Clé d’accès inconnue.',
        'already_registered' => 'Cette clé d’accès est déjà enregistrée.',
        'bad_signature' => 'Signature invalide.',
        'counter_replay' => 'Compteur de signature incohérent : la clé pourrait être clonée.',
    ],
];
