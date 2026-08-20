<?php

declare(strict_types=1);

/**
 * Contenus par défaut créés à l'installation et libellés du module Contenu.
 *
 * Ces textes sont un point de départ rédactionnel : ils sont copiés en base
 * à l'installation et restent entièrement modifiables par le propriétaire.
 */

return [
    'default' => [
        'home' => [
            'menu' => 'Accueil',
            'title' => 'Bienvenue',
            'lead' => 'Une maison de vacances en France, louée directement par ses propriétaires.',
            'body' => '<p>Vous êtes ici chez des particuliers, pas dans une chaîne. Nous louons une seule maison, celle que nous connaissons par cœur, et nous répondons nous-mêmes à vos questions.</p><p>Vous trouverez sur ce site les disponibilités réelles, les tarifs sans commission, les règles du séjour et toutes les informations pratiques. La réservation se fait en ligne, le contrat est généré automatiquement, et vos documents restent accessibles dans votre espace.</p>',
        ],
        'property' => [
            'menu' => 'Le logement',
            'title' => 'Le logement',
            'lead' => 'Les pièces, les équipements et la capacité d’accueil.',
            'body' => '<p>Décrivez ici les pièces, le couchage, la cuisine, le chauffage, l’extérieur et les équipements fournis. Indiquez ce qui est inclus dans le prix et ce qui ne l’est pas.</p><p>Cette page est modifiable depuis l’administration, dans les quatre langues du site.</p>',
        ],
        'availability' => [
            'menu' => 'Disponibilités',
            'title' => 'Disponibilités',
            'lead' => 'Les périodes libres et les règles de séjour.',
            'body' => '<p>Le calendrier public indique les dates libres et occupées, ainsi que les règles applicables : durée minimale, jour d’arrivée et de départ, capacité maximale.</p><p>Les disponibilités affichées tiennent compte des réservations confirmées, des réservations en cours et des périodes bloquées par le propriétaire.</p>',
        ],
        'rates' => [
            'menu' => 'Tarifs',
            'title' => 'Tarifs et conditions',
            'lead' => 'Prix par nuit, ménage, caution et taxe de séjour.',
            'body' => '<p>Le prix est calculé nuit par nuit : chaque date peut avoir son propre tarif. Le total affiché avant réservation est celui que vous paierez.</p><p>Le ménage de fin de séjour est facturé séparément. Une caution est demandée avant l’arrivée et restituée après l’état des lieux de sortie. La taxe de séjour est collectée pour le compte de la commune.</p>',
        ],
        'gallery' => [
            'menu' => 'Galerie',
            'title' => 'Galerie',
            'lead' => 'Les photos du logement et des environs.',
            'body' => '<p>Les photos sont classées par catégorie et peuvent varier selon la saison affichée. Cliquez sur une image pour l’agrandir.</p>',
        ],
        'activities' => [
            'menu' => 'Activités',
            'title' => 'Activités autour du logement',
            'lead' => 'Que faire pendant votre séjour, selon la saison.',
            'body' => '<p>Randonnées, marchés, plages, stations, sites remarquables : présentez ici ce qui mérite le détour autour du logement.</p><p>Les activités liées à des dates précises apparaîtront automatiquement dans votre espace séjour lorsque vos dates seront connues.</p>',
        ],
        'access' => [
            'menu' => 'Accès',
            'title' => 'Comment venir',
            'lead' => 'Itinéraire, stationnement et arrivée sur place.',
            'body' => '<p>Indiquez ici l’itinéraire routier, la gare ou l’aéroport le plus proche, le stationnement et les particularités du dernier kilomètre.</p><p>Les instructions précises d’arrivée (codes, boîte à clés, contact du responsable local) sont communiquées dans votre espace séjour, jamais publiquement.</p>',
        ],
        'contact' => [
            'menu' => 'Contact',
            'title' => 'Nous écrire',
            'lead' => 'Une question avant de réserver ? Écrivez-nous.',
            'body' => '<p>Nous répondons personnellement, en français, anglais, néerlandais ou allemand.</p><p>Pour une demande liée à une réservation existante, répondez directement au dernier e-mail reçu : votre message sera rattaché automatiquement au bon dossier.</p>',
        ],
        'legal_notice' => [
            'menu' => 'Mentions légales',
            'title' => 'Mentions légales',
            'lead' => 'Éditeur du site, hébergement et responsabilités.',
            'body' => '<p>Complétez cette page avec l’identité de l’éditeur, l’adresse, le numéro SIRET le cas échéant, le nom de l’hébergeur et ses coordonnées.</p><p>Ces informations sont obligatoires pour un site proposant une location en France.</p>',
        ],
        'privacy' => [
            'menu' => 'Confidentialité',
            'title' => 'Protection des données',
            'lead' => 'Quelles données sont collectées, pourquoi et pendant combien de temps.',
            'body' => '<p>Nous collectons uniquement ce qui est nécessaire à la réservation, au contrat, au séjour et aux obligations légales : identité, coordonnées, dates de séjour, documents et échanges liés au dossier.</p><p>Vous pouvez à tout moment demander l’accès à vos données, leur rectification, leur export ou leur suppression, sous réserve des durées de conservation légales.</p>',
        ],
        'terms' => [
            'menu' => 'Conditions générales',
            'title' => 'Conditions générales de location',
            'lead' => 'Réservation, paiement, annulation et responsabilités.',
            'body' => '<p>Complétez cette page avec vos conditions : montant de l’acompte, échéance du solde, caution, politique d’annulation, état des lieux, règles du logement et médiation de la consommation.</p><p>La version acceptée par un client est conservée telle qu’elle lui a été présentée, dans sa langue, et n’est jamais réécrite après coup.</p>',
        ],
    ],
    'season' => [
        'all' => 'Toutes saisons',
        'summer' => 'Été',
        'winter' => 'Hiver',
    ],
    'kind' => [
        'home' => 'Accueil',
        'page' => 'Page',
        'gallery' => 'Galerie',
        'legal' => 'Page légale',
        'contact' => 'Contact',
        'availability' => 'Disponibilités',
        'rates' => 'Tarifs',
    ],
    'error' => [
        'slug_required' => 'L’identifiant d’URL est obligatoire.',
        'slug_taken' => 'Cet identifiant d’URL est déjà utilisé.',
        'not_found' => 'Page introuvable.',
        'parent_self' => 'Une page ne peut pas être son propre parent.',
        'system_page' => 'Cette page fait partie du socle et ne peut pas être supprimée.',
    ],
    'gallery' => [
        'empty' => 'Aucune photo publiée pour le moment.',
        'all' => 'Toutes',
        'open' => 'Agrandir l’image',
        'previous' => 'Image précédente',
        'next' => 'Image suivante',
        'counter' => 'Image {index} sur {total}',
    ],
    'contact_intro' => 'Écrivez-nous à l’adresse indiquée ci-dessous.',
    'no_contact' => 'Aucune adresse de contact n’est encore configurée.',
    'fallback_notice' => 'Cette page n’est pas encore traduite dans votre langue : le texte affiché provient de la langue par défaut du site.',
    'legal_footer' => 'Informations légales',
];
