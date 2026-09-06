<?php

declare(strict_types=1);

/**
 * Huurovereenkomst: inhoud van de pdf en aanvaardingstraject.
 */

return [
    'pdf' => [
        'title' => 'Huurovereenkomst voor vakantieverblijf',
        'subject' => 'Huurovereenkomst voor een gemeubileerde vakantiewoning',
        'reference' => 'Referentie',
        'version' => 'Modelversie {version} — taal {locale}',
        'acceptance_notice' => 'De aanvaarding van deze overeenkomst wordt elektronisch vastgelegd, met datum, versie '
            . 'en taal.',
    ],
    'section' => [
        'parties' => 'De partijen',
        'property' => 'De woning',
        'stay' => 'Het verblijf',
        'amounts' => 'De bedragen',
    ],
    'field' => [
        'owner' => 'Eigenaar',
        'owner_address' => 'Adres van de eigenaar',
        'siret' => 'SIRET',
        'guest' => 'Reiziger',
        'guest_email' => 'E-mailadres',
        'guest_phone' => 'Telefoon',
        'address' => 'Adres',
        'capacity' => 'Capaciteit',
        'arrival' => 'Aankomst',
        'departure' => 'Vertrek',
        'nights' => 'Duur',
        'occupants' => 'Bewoners',
        'accommodation' => 'Verblijf',
        'cleaning' => 'Schoonmaak',
        'discount' => 'Korting',
        'total' => 'Totaal van het verblijf',
        'security_deposit' => 'Waarborg',
        'terms_version' => 'Geldende algemene voorwaarden: versie {version}.',
    ],
    'table' => [
        'component' => 'Onderdeel',
        'due_on' => 'Vervaldatum',
        'amount' => 'Bedrag',
    ],
    'value' => [
        'occupants' => '{adults} volwassene(n), {children} kind(eren), {infants} baby(’s)',
        'guests' => '{count} persoon|{count} personen',
        'nights' => '{count} nacht|{count} nachten',
    ],
    'clause' => [
        'cancellation' => [
            'title' => 'Annulering',
            'body' => 'De reiziger kan op elk moment annuleren. Reeds betaalde bedragen worden terugbetaald of '
                . 'ingehouden volgens de algemene voorwaarden die golden op de datum van de reservering, waarvan de '
                . 'versie hieronder staat. Annuleert de eigenaar, dan wordt alles wat betaald is volledig '
                . 'terugbetaald.',
        ],
        'inventory' => [
            'title' => 'Plaatsbeschrijving',
            'body' => 'Bij aankomst en bij vertrek wordt een plaatsbeschrijving opgemaakt. Blijft een opmerking van de '
                . 'reiziger binnen vierentwintig uur na aankomst uit, dan wordt de woning geacht overeen te stemmen '
                . 'met de plaatsbeschrijving bij aankomst.',
        ],
        'rules' => [
            'title' => 'Gebruik van de woning',
            'body' => 'De woning wordt gemeubileerd verhuurd, uitsluitend voor tijdelijke bewoning. Het aantal '
                . 'bewoners mag de vermelde capaciteit niet overschrijden. Onderverhuur is verboden.',
        ],
        'liability' => [
            'title' => 'Aansprakelijkheid en verzekering',
            'body' => 'De reiziger is aansprakelijk voor schade tijdens het verblijf en verklaart voor de duur van de '
                . 'huur verzekerd te zijn voor wettelijke aansprakelijkheid.',
        ],
        'data' => [
            'title' => 'Persoonsgegevens',
            'body' => 'De verzamelde gegevens dienen uitsluitend voor het beheer van de verhuur. De reiziger heeft '
                . 'recht op inzage, verbetering, overdraagbaarheid en verwijdering, uit te oefenen vanuit zijn '
                . 'persoonlijke omgeving.',
        ],
    ],
    'accept' => [
        'title' => 'Huurovereenkomst',
        'read' => 'Overeenkomst lezen',
        'action' => 'Ik aanvaard de overeenkomst',
        'accepted' => 'Overeenkomst aanvaard',
        'accepted_on' => 'Aanvaard op {date}',
        'accepted_version' => 'Versie {version}, taal {locale}',
        'pending' => 'De overeenkomst is beschikbaar: lees ze en aanvaard ze om verder te gaan.',
        'confirm' => 'Door dit vakje aan te vinken aanvaard ik de overeenkomst zoals ze mij wordt getoond.',
        'success' => 'Overeenkomst aanvaard. Dank u.',
        'intact' => 'Het aanvaarde document is ongewijzigd.',
        'altered' => 'Het aanvaarde document komt niet meer overeen met zijn vingerafdruk.',
    ],
    'error' => [
        'not_owner' => 'Deze overeenkomst betreft u niet.',
        'already_accepted' => 'Deze overeenkomst is al aanvaard.',
        'unavailable' => 'De overeenkomst kon niet worden opgemaakt.',
        'not_accepted' => 'De overeenkomst moet worden aanvaard.',
    ],
];
