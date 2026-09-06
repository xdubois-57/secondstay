<?php

declare(strict_types=1);

/**
 * Contenu local généré (SPECIFICATIONS.md §56 à §59).
 */

return [
    'admin' => [
        'title' => 'Contenu local',
        'intro' => 'Indiquez les pages à consulter, écrivez votre consigne, puis lancez un essai. Le système ajoute la '
            . 'localisation, la saison, les dates du séjour, les sources et le format attendu.',
    ],
    'enabled' => 'Le contenu local est produit pour les séjours à venir.',
    'disabled' => 'Le contenu local est désactivé.',
    'not_configured' => 'Aucun fournisseur n’est configuré : aucune activité n’est produite.',
    'configure' => 'Configurer',
    'sources' => 'Sources consultées',
    'no_source' => 'Aucune source. Ajoutez au moins une page publique.',
    'add_source' => 'Ajouter',
    'activate' => 'Activer',
    'deactivate' => 'Désactiver',
    'source_added' => 'Source ajoutée.',
    'source_added_unresolved' => 'Source ajoutée, mais son adresse ne répond pas encore.',
    'source_updated' => 'Source mise à jour.',
    'source_deleted' => 'Source supprimée.',
    'prompt' => 'Consigne',
    'prompt_intro' => 'Ce texte est le vôtre. Le système y ajoute automatiquement la localisation, la saison, les '
        . 'dates exactes, les sources et le schéma de sortie.',
    'prompt_saved' => 'Consigne enregistrée.',
    'suggest_prompt' => 'Générer le prompt à partir de la localisation',
    'run' => 'Génération',
    'test' => 'Essayer',
    'tested' => 'Essai réalisé.',
    'refresh' => 'Rafraîchir les séjours à venir',
    'refreshed' => 'Séjours rafraîchis.',
    'nothing_due' => 'Aucun séjour n’est dans la fenêtre.',
    'runs' => 'Dernières exécutions',
    'no_run' => 'Aucune exécution.',
    'run_summary' => '{sources} source(s), {items} activité(s)',
    'window' => 'La génération commence {weeks} semaines avant l’arrivée, puis se rafraîchit tous les {days} jours.',
    'due' => '{count} séjour(s) à rafraîchir.',
    'status' => [
        'running' => 'En cours',
        'done' => 'Terminée',
        'failed' => 'Échec',
    ],
    'field' => [
        'url' => 'Adresse de la page',
        'label' => 'Libellé',
        'prompt' => 'Votre consigne',
    ],
    'source' => [
        'never_fetched' => 'Jamais consultée',
        'status' => [
            'ok' => 'Consultée avec succès',
            'blocked' => 'Adresse refusée',
            'empty' => 'Page vide',
        ],
    ],
    'category' => [
        'market' => 'Marché',
        'festival' => 'Fête',
        'museum' => 'Musée',
        'nature' => 'Nature',
        'sport' => 'Sport',
        'food' => 'Gastronomie',
        'other' => 'Autre',
    ],
    'group' => [
        'book_ahead' => 'À réserver à l’avance',
        'this_week' => 'À faire pendant votre séjour',
    ],
    'verified_on' => 'vérifié le {date}',
    'stay' => [
        'title' => 'Autour de vous',
        'disclaimer' => 'Ces suggestions proviennent des sources citées et sont vérifiées à la date indiquée. '
            . 'Confirmez horaires et disponibilités auprès de l’organisateur.',
    ],
    'suggested_prompt' => 'Propose des activités autour de {location} pour les voyageurs de {property} : marchés, '
        . 'fêtes locales, musées, randonnées et bonnes tables. Privilégie ce qui se fait à pied ou à moins de trente '
        . 'minutes de route, et signale ce qui demande une réservation.',
    'error' => [
        'no_location' => 'Renseignez d’abord la ville du logement dans la configuration.',
        'duplicate' => 'Cette adresse est déjà dans la liste.',
    ],
];
