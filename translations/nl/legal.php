<?php

declare(strict_types=1);

/**
 * Geversioneerde juridische teksten en toestemmingen (SPECIFICATIONS.md §65).
 */

return [
    'title' => 'Geversioneerde juridische teksten',
    'intro' => 'Een versie publiceren legt de tekst van elke taal vast. Een reservering bewaart dan de versie en de taal die werkelijk zijn aanvaard, ook als de tekst later verandert.',
    'type' => [
        'terms' => 'Algemene voorwaarden',
        'privacy' => 'Privacy',
        'house_rules' => 'Huisreglement',
    ],
    'version' => 'Versie',
    'publish' => 'Publiceren',
    'publish_help' => 'De gepubliceerde tekst is die van de redactionele pagina’s op het moment van publicatie.',
    'published' => 'Versie in de vier talen gepubliceerd.',
    'published_partial' => 'Versie gepubliceerd, maar voor sommige talen was er geen tekst.',
    'accepted' => 'Aanvaarde teksten',
    'none_accepted' => 'Voor dit verblijf is geen tekst aanvaard.',
    'error' => [
        'version_required' => 'Het versienummer is verplicht.',
        'no_text' => 'Niets te publiceren: vul eerst de bijbehorende pagina in.',
        'already_published' => 'Deze versie bestaat al: een gepubliceerde versie wordt nooit herschreven.',
    ],
];
