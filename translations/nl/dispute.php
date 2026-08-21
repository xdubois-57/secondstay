<?php

declare(strict_types=1);

/**
 * Geschillen verbonden aan een verblijf (SPECIFICATIONS.md §68).
 */

return [
    'title' => 'Geschillen',
    'intro' => 'Een geschil verzamelt wat het product al heeft vastgelegd — ingehouden waarborg, eindinspectie, incidenten, aanvaard contract — zodat het gesprek op gedateerde feiten steunt.',
    'empty' => 'Geen geschil.',
    'evidence' => 'Stukken in het dossier',
    'actions' => 'Vervolgstap',
    'history' => 'Geschiedenis',
    'no_transition' => 'Vanuit deze status is geen statuswijziging mogelijk.',
    'opened' => 'Geschil geopend.',
    'updated' => 'Geschil bijgewerkt.',
    'open_title' => 'Een geschil openen',
    'filter' => [
        'all' => 'Alle',
    ],
    'field' => [
        'summary' => 'Onderwerp',
        'booking' => 'Verblijf',
        'claimed' => 'Geëist bedrag',
        'settled' => 'Geregeld bedrag',
        'waived' => 'Kwijtgescholden bedrag',
        'status' => 'Status',
        'resolution' => 'Hoe het is opgelost',
        'note' => 'Een bericht toevoegen',
        'kind' => 'Aard',
    ],
    'kind' => [
        'deposit' => 'Inhouding op waarborg',
        'damage' => 'Schade',
        'payment' => 'Betaling',
        'other' => 'Andere',
    ],
    'status' => [
        'open' => 'Open',
        'discussing' => 'In bespreking',
        'resolved' => 'Opgelost',
    ],
    'action' => [
        'discussing' => 'Naar bespreking',
        'resolved' => 'Geschil afsluiten',
        'open' => 'Geschil openen',
    ],
    'event' => [
        'opened' => 'Geschil geopend',
        'discussing' => 'Naar bespreking gezet',
        'resolved' => 'Geschil opgelost',
        'comment' => 'Bericht',
    ],
    'evidence_field' => [
        'deposit' => 'Ingehouden waarborg',
        'checkout' => 'Eindinspectie uitgevoerd',
        'anomalies' => 'Afwijkingen bij vertrek',
        'photos' => 'Foto’s in het dossier',
        'incidents' => 'Geregistreerde incidenten',
        'contract' => 'Contract aanvaard',
    ],
    'error' => [
        'summary_required' => 'Beschrijf het onderwerp van het geschil.',
        'above_deposit' => 'De geëiste inhouding overschrijdt de werkelijk ingehouden waarborg.',
        'amount' => 'Ongeldig bedrag.',
        'already_open' => 'Er bestaat al een geschil van deze aard voor dit verblijf.',
        'transition' => 'Deze statuswijziging is niet toegestaan.',
        'resolution_required' => 'Leg uit hoe het geschil is opgelost.',
        'settlement' => 'Het geregelde bedrag moet tussen nul en het geëiste bedrag liggen.',
        'note_required' => 'Typ een bericht voordat u het toevoegt.',
    ],
];
