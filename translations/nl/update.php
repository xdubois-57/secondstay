<?php

declare(strict_types=1);

return [
    'error' => [
        'asset_missing' => 'Update-artefact niet gevonden.',
        'asset_unreadable' => 'Update-artefact onleesbaar.',
        'asset_no_version' => 'Het artefact bevat geen geldige versie.',
        'asset_version_mismatch' => 'De versie in het artefact komt niet overeen met de aangekondigde versie: het '
            . 'wordt niet geïnstalleerd.',
        'artifact' => 'Het artefact voldoet niet aan het releasebeleid.',
        'digest_missing' => 'De release vermeldt geen SHA-256-vingerafdruk: de integriteit kan niet worden '
            . 'gecontroleerd, dus wordt ze niet geïnstalleerd.',
        'digest_mismatch' => 'Het gedownloade archief komt niet overeen met de vermelde vingerafdruk: het wordt niet '
            . 'geïnstalleerd.',
    ],
];
