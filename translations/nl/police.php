<?php

declare(strict_types=1);

/**
 * Individuele politiefiche (SPECIFICATIONS.md §64).
 */

return [
    'title' => 'Politiefiches',
    'record' => 'Politiefiche',
    'open_record' => 'Fiche openen',
    'intro' => 'De individuele fiche is alleen in bepaalde gevallen vereist. Zolang ze uit staat, worden geen identiteitsgegevens verzameld.',
    'record_intro' => 'De gegevens zijn versleuteld en worden aan het einde van de bewaartermijn automatisch gewist.',
    'enabled' => 'De politiefiche wordt gevraagd voor de betrokken verblijven.',
    'disabled' => 'De politiefiche is uitgeschakeld: er wordt niets verzameld.',
    'configure' => 'Configureren',
    'records' => 'Bewaarde fiches',
    'empty' => 'Geen fiche bewaard.',
    'unreadable' => 'Onleesbare fiche',
    'saved' => 'Fiche opgeslagen.',
    'deleted' => 'Fiche verwijderd.',
    'purge_after' => 'Gewist op {date}',
    'retention' => 'Bewaring: {days} dagen na het vertrek.',
    'field' => [
        'last_name' => 'Naam',
        'first_names' => 'Voornamen',
        'birth_date' => 'Geboortedatum',
        'birth_place' => 'Geboorteplaats',
        'nationality' => 'Nationaliteit',
        'home_address' => 'Gewone verblijfplaats',
        'arrival_date' => 'Aankomstdatum',
        'departure_date' => 'Verwachte vertrekdatum',
    ],
    'error' => [
        'disabled' => 'De politiefiche is niet ingeschakeld.',
        'incomplete' => 'Naam, voornamen, geboortedatum en nationaliteit zijn verplicht.',
    ],
];
