<?php

declare(strict_types=1);

/**
 * Contrat de location : contenu du PDF et parcours d’acceptation.
 */

return [
    'pdf' => [
        'title' => 'Contrat de location saisonnière',
        'subject' => 'Contrat de location d’un meublé de tourisme',
        'reference' => 'Référence',
        'version' => 'Modèle version {version} — langue {locale}',
        'acceptance_notice' => 'L’acceptation de ce contrat est enregistrée par voie électronique, avec sa date, sa '
            . 'version et sa langue.',
    ],
    'section' => [
        'parties' => 'Les parties',
        'property' => 'Le logement',
        'stay' => 'Le séjour',
        'amounts' => 'Les montants',
    ],
    'field' => [
        'owner' => 'Propriétaire',
        'owner_address' => 'Adresse du propriétaire',
        'siret' => 'SIRET',
        'guest' => 'Voyageur',
        'guest_email' => 'Adresse électronique',
        'guest_phone' => 'Téléphone',
        'address' => 'Adresse',
        'capacity' => 'Capacité',
        'arrival' => 'Arrivée',
        'departure' => 'Départ',
        'nights' => 'Durée',
        'occupants' => 'Occupants',
        'accommodation' => 'Hébergement',
        'cleaning' => 'Ménage',
        'discount' => 'Remise',
        'total' => 'Total du séjour',
        'security_deposit' => 'Caution',
        'terms_version' => 'Conditions générales en vigueur : version {version}.',
    ],
    'table' => [
        'component' => 'Composant',
        'due_on' => 'Échéance',
        'amount' => 'Montant',
    ],
    'value' => [
        'occupants' => '{adults} adulte(s), {children} enfant(s), {infants} bébé(s)',
        'guests' => '{count} personne|{count} personnes',
        'nights' => '{count} nuit|{count} nuits',
    ],
    'clause' => [
        'cancellation' => [
            'title' => 'Annulation',
            'body' => 'Le voyageur peut annuler à tout moment. Les sommes déjà versées sont restituées ou retenues '
                . 'selon les conditions générales en vigueur à la date de la réservation, dont la version est indiquée '
                . 'ci-dessous. En cas d’annulation par le propriétaire, l’intégralité des sommes versées est '
                . 'restituée.',
        ],
        'inventory' => [
            'title' => 'État des lieux',
            'body' => 'Un état des lieux est établi à l’arrivée et au départ. À défaut d’observation du voyageur dans '
                . 'les vingt-quatre heures suivant son arrivée, le logement est réputé conforme à l’état des lieux '
                . 'd’entrée.',
        ],
        'rules' => [
            'title' => 'Occupation du logement',
            'body' => 'Le logement est loué meublé, à usage exclusif d’habitation temporaire. Le nombre d’occupants ne '
                . 'peut excéder la capacité indiquée. La sous-location est interdite.',
        ],
        'liability' => [
            'title' => 'Responsabilité et assurance',
            'body' => 'Le voyageur répond des dommages causés pendant le séjour. Il déclare être couvert par une '
                . 'assurance de responsabilité civile pour la durée de la location.',
        ],
        'data' => [
            'title' => 'Données personnelles',
            'body' => 'Les informations recueillies servent exclusivement à la gestion de la location. Le voyageur '
                . 'dispose d’un droit d’accès, de rectification, de portabilité et de suppression, qu’il peut exercer '
                . 'depuis son espace personnel.',
        ],
    ],
    'accept' => [
        'title' => 'Contrat de location',
        'read' => 'Lire le contrat',
        'action' => 'J’accepte le contrat',
        'accepted' => 'Contrat accepté',
        'accepted_on' => 'Accepté le {date}',
        'accepted_version' => 'Version {version}, langue {locale}',
        'pending' => 'Le contrat est disponible : lisez-le, puis acceptez-le pour poursuivre.',
        'confirm' => 'En cochant cette case, j’accepte le contrat tel qu’il m’est présenté.',
        'success' => 'Contrat accepté. Merci.',
        'intact' => 'Le document accepté est intact.',
        'altered' => 'Le document accepté ne correspond plus à son empreinte.',
    ],
    'error' => [
        'not_owner' => 'Ce contrat ne vous concerne pas.',
        'already_accepted' => 'Ce contrat a déjà été accepté.',
        'unavailable' => 'Le contrat n’a pas pu être produit.',
        'not_accepted' => 'Le contrat doit être accepté.',
    ],
];
