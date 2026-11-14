<?php

declare(strict_types=1);

return [
    'error' => [
        'asset_missing' => 'Aktualisierungsartefakt nicht gefunden.',
        'asset_unreadable' => 'Aktualisierungsartefakt nicht lesbar.',
        'asset_no_version' => 'Das Artefakt enthält keine gültige Version.',
        'artifact' => 'Das Artefakt entspricht nicht der Release-Richtlinie.',
        'digest_missing' => 'Das Release nennt keine SHA-256-Prüfsumme: Seine Integrität lässt sich nicht prüfen, es wird nicht installiert.',
        'digest_mismatch' => 'Das heruntergeladene Archiv stimmt nicht mit der angegebenen Prüfsumme überein: Es wird nicht installiert.',
    ],
];
