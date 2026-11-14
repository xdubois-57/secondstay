<?php

declare(strict_types=1);

return [
    'error' => [
        'asset_missing' => 'Artefact de mise à jour introuvable.',
        'asset_unreadable' => 'Artefact de mise à jour illisible.',
        'asset_no_version' => 'L’artefact ne contient pas de version valide.',
        'artifact' => 'L’artefact ne respecte pas la politique de release.',
        'digest_missing' => 'La release n’annonce pas d’empreinte SHA-256 : son intégrité ne peut pas être vérifiée, elle n’est pas installée.',
        'digest_mismatch' => 'L’archive téléchargée ne correspond pas à l’empreinte annoncée : elle n’est pas installée.',
    ],
];
