<?php

declare(strict_types=1);

/**
 * Textes légaux versionnés et consentements (SPECIFICATIONS.md §65).
 */

return [
    'title' => 'Textes légaux versionnés',
    'intro' => 'Publier une version fige le texte de chaque langue. Une réservation conserve alors la version et la '
        . 'langue réellement acceptées, même si le texte change ensuite.',
    'type' => [
        'terms' => 'Conditions générales',
        'privacy' => 'Confidentialité',
        'house_rules' => 'Règlement du logement',
    ],
    'version' => 'Version',
    'publish' => 'Publier',
    'publish_help' => 'Le texte publié est celui des pages éditoriales, au moment de la publication.',
    'published' => 'Version publiée dans les quatre langues.',
    'published_partial' => 'Version publiée, mais certaines langues n’avaient pas de texte.',
    'accepted' => 'Textes acceptés',
    'none_accepted' => 'Aucun texte n’a été accepté pour ce séjour.',
    'error' => [
        'version_required' => 'Le numéro de version est obligatoire.',
        'no_text' => 'Aucun texte à publier : renseignez d’abord la page correspondante.',
        'already_published' => 'Cette version existe déjà : une version publiée ne se réécrit pas.',
    ],
];
