<?php

declare(strict_types=1);

/**
 * Assistant conformité France (SPECIFICATIONS.md §61 et §62).
 *
 * Chaque sujet fournit quatre textes : ce que c'est, quand cela s'applique,
 * où trouver l'information, et ce qui change si l'on ne fait rien. Le produit
 * n'affirme jamais qu'une situation est conforme : il aide à la vérifier et
 * garde la trace de la vérification.
 */

return [
    'title' => 'Conformité France',
    'intro' => 'Chaque sujet est décrit, puis constaté par vous : statut, source officielle, date de vérification et prochaine échéance.',
    'disclaimer' => 'Ces informations sont des repères, pas un conseil juridique. La réglementation varie selon la commune et évolue : la source officielle et la date de vérification font foi.',
    'saved' => 'Sujet enregistré.',
    'evidence_added' => 'Pièce justificative ajoutée.',
    'overdue' => 'Revue dépassée',
    'managed_elsewhere' => 'Ce sujet se gère dans l’écran dédié.',
    'status' => [
        'compliant' => 'Conforme',
        'to_verify' => 'À vérifier',
        'not_applicable' => 'Non applicable',
    ],
    'field' => [
        'definition' => 'Définition',
        'applicability' => 'Applicabilité',
        'where' => 'Où trouver',
        'impact' => 'Impact',
        'status' => 'Statut',
        'value' => 'Valeur',
        'notes' => 'Notes',
        'source' => 'Source officielle',
        'last_verified' => 'Vérifié le',
        'next_review' => 'Prochaine revue',
        'evidence' => 'Pièce justificative',
        'evidence_current' => 'Voir la pièce jointe',
    ],
    'error' => [
        'source' => 'La source doit être une adresse web (http ou https).',
    ],
    'topic' => [
        'furnished_tourism' => [
            'label' => 'Meublé de tourisme',
            'definition' => 'Statut du logement loué meublé à une clientèle de passage qui n’y élit pas domicile.',
            'applicability' => 'S’applique à toute location saisonnière meublée d’un logement entier.',
            'where' => 'Service urbanisme ou site de la commune, et service public de l’économie.',
            'impact' => 'Détermine la déclaration à faire, la taxe applicable et les obligations d’information.',
        ],
        'declaration' => [
            'label' => 'Déclaration ou enregistrement en mairie',
            'definition' => 'Déclaration du meublé auprès de la commune, avec parfois un numéro d’enregistrement à afficher.',
            'applicability' => 'Obligatoire dans de nombreuses communes ; l’enregistrement l’est là où la commune l’a instauré.',
            'where' => 'Mairie de la commune du logement, guichet en ligne le cas échéant.',
            'impact' => 'L’absence de déclaration expose à une amende et empêche l’affichage du numéro exigé par les plateformes.',
        ],
        'siret' => [
            'label' => 'SIRET',
            'definition' => 'Numéro d’identification de l’activité de location meublée.',
            'applicability' => 'Requis dès lors que l’activité de location meublée est déclarée.',
            'where' => 'Guichet unique des formalités des entreprises.',
            'impact' => 'Nécessaire aux déclarations fiscales et à la facturation.',
        ],
        'owner_status' => [
            'label' => 'Statut du loueur',
            'definition' => 'Loueur en meublé non professionnel ou professionnel, selon les recettes et leur part dans les revenus.',
            'applicability' => 'Concerne tout propriétaire qui loue en meublé.',
            'where' => 'Documentation fiscale officielle et conseil de votre comptable.',
            'impact' => 'Change le régime d’imposition, les cotisations et les obligations comptables.',
        ],
        'residence_kind' => [
            'label' => 'Résidence principale ou secondaire',
            'definition' => 'Qualification du logement au regard de son occupation par le propriétaire.',
            'applicability' => 'Concerne tous les logements ; ce produit vise une résidence secondaire.',
            'where' => 'Avis de taxe d’habitation et déclaration d’occupation.',
            'impact' => 'Une résidence principale ne peut être louée que sur une durée limitée dans l’année.',
        ],
        'classification' => [
            'label' => 'Classement en étoiles',
            'definition' => 'Classement volontaire du meublé de tourisme, de une à cinq étoiles.',
            'applicability' => 'Facultatif, mais il conditionne certains barèmes et abattements.',
            'where' => 'Organisme accrédité pour la visite de classement.',
            'impact' => 'Modifie le barème de taxe de séjour et peut ouvrir des avantages fiscaux.',
        ],
        'energy_diagnosis' => [
            'label' => 'Diagnostic de performance énergétique',
            'definition' => 'Évaluation de la performance énergétique du logement.',
            'applicability' => 'Exigible selon la nature et la durée de la location ; à vérifier pour votre situation.',
            'where' => 'Diagnostiqueur certifié.',
            'impact' => 'Peut conditionner la mise en location et doit être communiqué quand il est exigé.',
        ],
        'change_of_use' => [
            'label' => 'Changement d’usage',
            'definition' => 'Autorisation de transformer un local d’habitation en meublé de tourisme.',
            'applicability' => 'Exigée dans certaines communes, souvent les plus grandes ou les plus tendues.',
            'where' => 'Service urbanisme de la commune.',
            'impact' => 'Louer sans autorisation là où elle est exigée expose à une amende civile élevée.',
        ],
        'tourist_tax' => [
            'label' => 'Taxe de séjour',
            'definition' => 'Taxe perçue auprès du voyageur et reversée à la collectivité.',
            'applicability' => 'S’applique là où la collectivité l’a instituée.',
            'where' => 'Collectivité compétente, qui publie le barème et les échéances de reversement.',
            'impact' => 'Le barème dépend du classement ; le reversement est périodique et déclaratif.',
        ],
        'police_record' => [
            'label' => 'Fiche individuelle de police',
            'definition' => 'Fiche renseignée à l’arrivée pour certains voyageurs étrangers.',
            'applicability' => 'Seulement si l’obligation vous concerne.',
            'where' => 'Préfecture ou service de police compétent.',
            'impact' => 'Impose une collecte encadrée, une conservation limitée et une remise sur demande.',
        ],
        'contract' => [
            'label' => 'Contrat de location saisonnière',
            'definition' => 'Écrit décrivant le logement, les dates, le prix et les conditions.',
            'applicability' => 'Requis pour une location saisonnière.',
            'where' => 'Modèle produit par l’application, complété par vos conditions.',
            'impact' => 'Un contrat clair et accepté évite l’essentiel des litiges.',
        ],
        'cancellation' => [
            'label' => 'Conditions d’annulation',
            'definition' => 'Règles applicables en cas d’annulation par le voyageur ou par vous.',
            'applicability' => 'Toujours : ce sont vos conditions, elles doivent être écrites et acceptées.',
            'where' => 'Vos conditions générales, publiées et versionnées.',
            'impact' => 'Sans règles écrites et acceptées, tout remboursement se négocie au cas par cas.',
        ],
        'mediation' => [
            'label' => 'Médiation de la consommation',
            'definition' => 'Recours amiable proposé au voyageur en cas de litige.',
            'applicability' => 'Obligatoire pour un professionnel ; à vérifier selon votre statut.',
            'where' => 'Médiateur référencé, dont le nom et le site doivent être communiqués.',
            'impact' => 'Le médiateur doit être indiqué dans vos conditions et sur votre site.',
        ],
        'insurance' => [
            'label' => 'Assurance',
            'definition' => 'Couverture du logement et de la responsabilité liée à la location.',
            'applicability' => 'Toujours : votre contrat doit couvrir explicitement la location saisonnière.',
            'where' => 'Votre assureur, mention expresse au contrat.',
            'impact' => 'Un sinistre non couvert reste à votre charge.',
        ],
        'local_risks' => [
            'label' => 'Information sur les risques',
            'definition' => 'Information du voyageur sur les risques naturels et technologiques du secteur.',
            'applicability' => 'Selon la commune et le zonage.',
            'where' => 'Service public dédié à l’information sur les risques.',
            'impact' => 'L’information doit être disponible et à jour quand elle est exigée.',
        ],
        'clearing' => [
            'label' => 'Débroussaillement',
            'definition' => 'Obligation légale de débroussaillement autour du bâti.',
            'applicability' => 'Dans les zones exposées au risque d’incendie.',
            'where' => 'Mairie et préfecture, arrêté départemental.',
            'impact' => 'Le non-respect expose à une amende et engage votre responsabilité.',
        ],
        'winter_equipment' => [
            'label' => 'Équipement hivernal',
            'definition' => 'Obligation d’équipement des véhicules en période hivernale.',
            'applicability' => 'Dans les communes concernées par la réglementation montagne.',
            'where' => 'Préfecture du département.',
            'impact' => 'À signaler au voyageur avant son arrivée : c’est lui qui sera contrôlé.',
        ],
        'waste' => [
            'label' => 'Déchets',
            'definition' => 'Règles locales de tri, de dépôt et de collecte.',
            'applicability' => 'Toujours, avec des règles propres à chaque commune.',
            'where' => 'Collectivité chargée de la collecte.',
            'impact' => 'Des consignes claires évitent les dépôts sauvages et les pénalités.',
        ],
    ],
];
