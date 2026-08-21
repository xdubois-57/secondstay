<?php

declare(strict_types=1);

/**
 * Toeristenbelasting: gedateerde tarieven en uitleg van de berekening
 * (SPECIFICATIONS.md §63).
 */

return [
    'title' => 'Toeristenbelasting',
    'intro' => 'Een tarief wordt vastgesteld, treedt op een datum in werking en wordt daarna vervangen. Elke regel draagt dus haar geldigheidsperiode, en een reeds geboekt verblijf behoudt het tarief dat bij aankomst gold.',
    'enabled' => 'De toeristenbelasting wordt geïnd.',
    'disabled' => 'De toeristenbelasting wordt niet geïnd.',
    'configure' => 'Configureren',
    'current' => 'Van kracht',
    'empty' => 'Geen gedateerd tarief: de configuratie geldt als huidig tarief.',
    'new_rule' => 'Nieuw tarief',
    'rule_created' => 'Tarief opgeslagen.',
    'rule_deleted' => 'Tarief verwijderd.',
    'overlap_warning' => 'Twee tarieven overlappen voor dezelfde classificatie: het bedrag zou afhangen van de volgorde van de rijen.',
    'field' => [
        'period' => 'Periode',
        'effective_from' => 'Treedt in werking op',
        'effective_to' => 'Tot',
        'effective_to_help' => 'Laat leeg zolang er geen volgend tarief bekend is.',
        'classification' => 'Classificatie',
        'territory' => 'Gebied',
        'per_adult_night' => 'Per volwassene per nacht',
        'cap' => 'Maximum per verblijf',
        'taxable_from_age' => 'Belastbaar vanaf',
        'source' => 'Officiële bron',
        'notes' => 'Nota',
    ],
    'explain' => [
        'title' => 'Berekening van de toeristenbelasting',
        'per_adult_night' => 'Per volwassene per nacht',
        'adults' => 'Volwassenen',
        'exempt' => 'Vrijgestelde personen',
        'nights' => 'Nachten',
        'cap' => 'Toegepast maximum',
        'total' => 'Totaal',
        'exemption_note' => 'Minderjarigen zijn vrijgesteld (artikel L. 2333-31 van de Franse code générale des collectivités territoriales).',
    ],
    'error' => [
        'effective_from' => 'De ingangsdatum is verplicht.',
        'period' => 'De einddatum kan niet vóór de ingangsdatum liggen.',
        'amount' => 'Bedragen moeten positieve getallen zijn.',
    ],
];
