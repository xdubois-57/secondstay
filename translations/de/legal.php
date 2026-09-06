<?php

declare(strict_types=1);

/**
 * Versionierte Rechtstexte und Einwilligungen (SPECIFICATIONS.md §65).
 */

return [
    'title' => 'Versionierte Rechtstexte',
    'intro' => 'Eine Version zu veröffentlichen friert den Text jeder Sprache ein. Eine Buchung bewahrt dann die '
        . 'tatsächlich angenommene Version und Sprache, auch wenn der Text sich später ändert.',
    'type' => [
        'terms' => 'Allgemeine Geschäftsbedingungen',
        'privacy' => 'Datenschutz',
        'house_rules' => 'Hausordnung',
    ],
    'version' => 'Version',
    'publish' => 'Veröffentlichen',
    'publish_help' => 'Veröffentlicht wird der Text der redaktionellen Seiten zum Zeitpunkt der Veröffentlichung.',
    'published' => 'Version in allen vier Sprachen veröffentlicht.',
    'published_partial' => 'Version veröffentlicht, für einige Sprachen fehlte jedoch der Text.',
    'accepted' => 'Angenommene Texte',
    'none_accepted' => 'Für diesen Aufenthalt wurde kein Text angenommen.',
    'error' => [
        'version_required' => 'Die Versionsnummer ist erforderlich.',
        'no_text' => 'Nichts zu veröffentlichen: füllen Sie zuerst die entsprechende Seite aus.',
        'already_published' => 'Diese Version besteht bereits: eine veröffentlichte Version wird nie überschrieben.',
    ],
];
