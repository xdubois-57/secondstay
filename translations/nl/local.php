<?php

declare(strict_types=1);

/**
 * Gegenereerde lokale inhoud (SPECIFICATIONS.md §56 tot §59).
 */

return [
    'admin' => [
        'title' => 'Lokale inhoud',
        'intro' => 'Geef de te lezen pagina’s op, schrijf uw instructie en voer een test uit. Het systeem voegt de locatie, het seizoen, de verblijfsdata, de bronnen en het verwachte formaat toe.',
    ],
    'enabled' => 'Lokale inhoud wordt gemaakt voor komende verblijven.',
    'disabled' => 'Lokale inhoud is uitgeschakeld.',
    'not_configured' => 'Geen aanbieder geconfigureerd: er wordt geen activiteit gemaakt.',
    'configure' => 'Configureren',
    'sources' => 'Gelezen bronnen',
    'no_source' => 'Geen bron. Voeg minstens één openbare pagina toe.',
    'add_source' => 'Toevoegen',
    'activate' => 'Inschakelen',
    'deactivate' => 'Uitschakelen',
    'source_added' => 'Bron toegevoegd.',
    'source_added_unresolved' => 'Bron toegevoegd, maar het adres is nog niet bereikbaar.',
    'source_updated' => 'Bron bijgewerkt.',
    'source_deleted' => 'Bron verwijderd.',
    'prompt' => 'Instructie',
    'prompt_intro' => 'Deze tekst is van u. Het systeem voegt automatisch de locatie, het seizoen, de exacte data, de bronnen en het uitvoerschema toe.',
    'prompt_saved' => 'Instructie opgeslagen.',
    'suggest_prompt' => 'Prompt genereren op basis van de locatie',
    'run' => 'Generatie',
    'test' => 'Testen',
    'tested' => 'Test uitgevoerd.',
    'refresh' => 'Komende verblijven verversen',
    'refreshed' => 'Verblijven ververst.',
    'nothing_due' => 'Geen verblijf valt binnen het venster.',
    'runs' => 'Recente uitvoeringen',
    'no_run' => 'Nog geen uitvoering.',
    'run_summary' => '{sources} bron(nen), {items} activiteit(en)',
    'window' => 'De generatie start {weeks} weken vóór de aankomst en wordt daarna om de {days} dagen ververst.',
    'due' => '{count} verblijf/verblijven te verversen.',
    'status' => [
        'running' => 'Bezig',
        'done' => 'Afgerond',
        'failed' => 'Mislukt',
    ],
    'field' => [
        'url' => 'Adres van de pagina',
        'label' => 'Label',
        'prompt' => 'Uw instructie',
    ],
    'source' => [
        'never_fetched' => 'Nooit gelezen',
        'status' => [
            'ok' => 'Met succes gelezen',
            'blocked' => 'Adres geweigerd',
            'empty' => 'Lege pagina',
        ],
    ],
    'category' => [
        'market' => 'Markt',
        'festival' => 'Feest',
        'museum' => 'Museum',
        'nature' => 'Natuur',
        'sport' => 'Sport',
        'food' => 'Gastronomie',
        'other' => 'Andere',
    ],
    'group' => [
        'book_ahead' => 'Vooraf reserveren',
        'this_week' => 'Tijdens uw verblijf',
    ],
    'verified_on' => 'gecontroleerd op {date}',
    'stay' => [
        'title' => 'In de buurt',
        'disclaimer' => 'Deze suggesties komen uit de vermelde bronnen en zijn gecontroleerd op de aangegeven datum. Bevestig uren en beschikbaarheid bij de organisator.',
    ],
    'suggested_prompt' => 'Stel activiteiten voor rond {location} voor de gasten van {property}: markten, lokale feesten, musea, wandelingen en goede adressen om te eten. Geef voorrang aan wat te voet of binnen dertig minuten rijden bereikbaar is, en meld wat gereserveerd moet worden.',
    'error' => [
        'no_location' => 'Vul eerst de gemeente van de woning in bij de configuratie.',
        'duplicate' => 'Dit adres staat al in de lijst.',
    ],
];
