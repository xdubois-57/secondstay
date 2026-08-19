<?php

declare(strict_types=1);

/**
 * Libellés et aides des réglages typés.
 *
 * Chaque réglage expose un libellé et une aide explicite : l'objectif est que
 * le propriétaire comprenne l'impact du réglage sans documentation externe.
 */

return [
    'property' => [
        'name' => [
            'label' => 'Nom du logement',
            'help' => 'Nom affiché publiquement pour votre résidence secondaire.',
        ],
        'address_line1' => [
            'label' => 'Adresse (ligne 1)',
            'help' => 'Numéro et rue du logement.',
        ],
        'address_line2' => [
            'label' => 'Adresse (ligne 2)',
            'help' => 'Complément d’adresse : lieu-dit, bâtiment, étage.',
        ],
        'postal_code' => [
            'label' => 'Code postal',
            'help' => 'Code postal français du logement.',
        ],
        'city' => [
            'label' => 'Commune',
            'help' => 'Commune du logement. Elle sert aussi à la taxe de séjour.',
        ],
        'country' => [
            'label' => 'Pays',
            'help' => 'SecondStay est spécialisé pour la France.',
        ],
        'latitude' => [
            'label' => 'Latitude',
            'help' => 'Coordonnée utilisée pour les contenus locaux et l’accès.',
        ],
        'longitude' => [
            'label' => 'Longitude',
            'help' => 'Coordonnée utilisée pour les contenus locaux et l’accès.',
        ],
        'contact_email' => [
            'label' => 'E-mail de contact',
            'help' => 'Adresse affichée aux voyageurs pour les questions générales.',
        ],
        'contact_phone' => [
            'label' => 'Téléphone de contact',
            'help' => 'Numéro affiché aux voyageurs.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Langue par défaut',
            'help' => 'Langue utilisée quand aucune préférence n’est connue.',
        ],
        'timezone' => [
            'label' => 'Fuseau horaire',
            'help' => 'Fuseau utilisé pour les horaires d’arrivée, de départ et les rappels.',
        ],
        'public_url' => [
            'label' => 'URL publique',
            'help' => 'Adresse publique du site, utilisée dans les e-mails et les liens.',
        ],
        'season' => [
            'label' => 'Saison affichée',
            'help' => 'Automatique suit la date ; forcez été ou hiver pour une présentation fixe.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Nombre minimal de nuits',
            'help' => 'Durée minimale acceptée pour un séjour.',
        ],
        'max_guests' => [
            'label' => 'Capacité maximale',
            'help' => 'Nombre total de voyageurs accepté, bébés compris selon votre règle.',
        ],
        'checkin_time' => [
            'label' => 'Heure d’arrivée',
            'help' => 'Heure à partir de laquelle le logement est disponible.',
        ],
        'checkout_time' => [
            'label' => 'Heure de départ',
            'help' => 'Heure limite de libération du logement.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Règle samedi-samedi',
            'help' => 'Impose des séjours du samedi au samedi. Désactivable.',
        ],
        'hold_minutes' => [
            'label' => 'Durée de réservation temporaire',
            'help' => 'Minutes pendant lesquelles des dates restent bloquées avant confirmation.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Prix par nuit par défaut',
            'help' => 'Utilisé pour toute date sans tarif spécifique.',
        ],
        'cleaning_mode' => [
            'label' => 'Mode de ménage',
            'help' => 'Aucun, optionnel ou obligatoire. Par défaut obligatoire.',
        ],
        'cleaning_price' => [
            'label' => 'Prix du ménage',
            'help' => 'Montant du forfait ménage. Valeur par défaut : 100 €.',
        ],
        'deposit_percent' => [
            'label' => 'Acompte (%)',
            'help' => 'Part du séjour demandée pour confirmer la réservation.',
        ],
        'security_deposit' => [
            'label' => 'Caution',
            'help' => 'Montant de la caution demandée avant le séjour.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Maintenance planifiée',
            'help' => 'Ferme le site public. L’administration reste accessible.',
        ],
        'message' => [
            'label' => 'Message de maintenance',
            'help' => 'Note interne expliquant la raison de la maintenance.',
        ],
    ],
    'backup' => [
        'retention_count' => [
            'label' => 'Sauvegardes conservées',
            'help' => 'Nombre de sauvegardes gardées avant suppression automatique.',
        ],
        'include_media' => [
            'label' => 'Inclure les médias',
            'help' => 'Ajoute photos, documents et pièces jointes à la sauvegarde.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Canal de mise à jour',
            'help' => 'Stable installe uniquement les versions publiées.',
        ],
        'auto_install' => [
            'label' => 'Mise à jour automatique',
            'help' => 'Installe automatiquement les nouvelles versions validées.',
        ],
        'repository' => [
            'label' => 'Dépôt des releases',
            'help' => 'Dépôt GitHub fournissant les artefacts installables.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Niveau de journalisation',
            'help' => 'Seuil minimal des messages enregistrés.',
        ],
        'retention_days' => [
            'label' => 'Rétention des journaux (jours)',
            'help' => 'Durée de conservation avant purge automatique.',
        ],
    ],
    'error' => [
        'required' => 'Ce réglage est obligatoire.',
        'unknown' => 'Réglage inconnu.',
        'integer' => 'Saisissez un nombre entier.',
        'decimal' => 'Saisissez un nombre décimal.',
        'money' => 'Saisissez un montant valide, par exemple 100,00.',
        'enum' => 'Valeur non autorisée.',
        'email' => 'Adresse e-mail invalide.',
        'url' => 'URL invalide.',
        'url_scheme' => 'Seules les URL http(s) sont acceptées.',
        'date' => 'Date invalide (format attendu : AAAA-MM-JJ).',
        'time' => 'Heure invalide (format attendu : HH:MM).',
        'duration' => 'Durée invalide (en minutes).',
        'json' => 'JSON invalide.',
        'too_long' => 'Valeur trop longue.',
        'too_small' => 'Valeur trop petite.',
        'too_large' => 'Valeur trop grande.',
    ],
];
