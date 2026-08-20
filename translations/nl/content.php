<?php

declare(strict_types=1);

/**
 * Contenus par défaut créés à l'installation et libellés du module Contenu.
 *
 * Ces textes sont un point de départ rédactionnel : ils sont copiés en base
 * à l'installation et restent entièrement modifiables par le propriétaire.
 */

return [
    'default' => [
        'home' => [
            'menu' => 'Home',
            'title' => 'Welkom',
            'lead' => 'Een vakantiewoning in Frankrijk, rechtstreeks verhuurd door de eigenaars.',
            'body' => '<p>U hebt hier te maken met particuliere eigenaars, niet met een keten. Wij verhuren één enkel huis, dat we door en door kennen, en we beantwoorden uw vragen zelf.</p><p>Op deze site vindt u de werkelijke beschikbaarheid, tarieven zonder commissie, de huisregels en alle praktische informatie. Reserveren gebeurt online, het contract wordt automatisch aangemaakt en uw documenten blijven beschikbaar in uw account.</p>',
        ],
        'property' => [
            'menu' => 'De woning',
            'title' => 'De woning',
            'lead' => 'Kamers, uitrusting en het aantal gasten dat het huis kan ontvangen.',
            'body' => '<p>Beschrijf hier de kamers, de slaapplaatsen, de keuken, de verwarming, de buitenruimte en de aanwezige uitrusting. Vermeld duidelijk wat wel en niet in de prijs zit.</p><p>Deze pagina kan vanuit het beheergedeelte in alle vier de talen worden aangepast.</p>',
        ],
        'availability' => [
            'menu' => 'Beschikbaarheid',
            'title' => 'Beschikbaarheid',
            'lead' => 'Vrije periodes en de regels voor een verblijf.',
            'body' => '<p>De publieke kalender toont welke data vrij of bezet zijn, samen met de geldende regels: minimale verblijfsduur, aankomst- en vertrekdagen, maximale capaciteit.</p><p>De getoonde beschikbaarheid houdt rekening met bevestigde reserveringen, lopende reserveringen en door de eigenaar geblokkeerde periodes.</p>',
        ],
        'rates' => [
            'menu' => 'Tarieven',
            'title' => 'Tarieven en voorwaarden',
            'lead' => 'Prijs per nacht, schoonmaak, waarborg en toeristenbelasting.',
            'body' => '<p>De prijs wordt per nacht berekend: elke datum kan een eigen tarief hebben. Het totaal dat vóór de reservering wordt getoond, is wat u betaalt.</p><p>De eindschoonmaak wordt apart in rekening gebracht. Vóór aankomst wordt een waarborg gevraagd, die na de eindinspectie wordt terugbetaald. De toeristenbelasting wordt geïnd namens de gemeente.</p>',
        ],
        'gallery' => [
            'menu' => 'Galerij',
            'title' => 'Galerij',
            'lead' => 'Foto’s van de woning en de omgeving.',
            'body' => '<p>De foto’s zijn per categorie gegroepeerd en kunnen per weergegeven seizoen verschillen. Klik op een afbeelding om die te vergroten.</p>',
        ],
        'activities' => [
            'menu' => 'Activiteiten',
            'title' => 'Activiteiten in de buurt',
            'lead' => 'Wat te doen tijdens uw verblijf, per seizoen.',
            'body' => '<p>Wandelingen, markten, stranden, skigebieden, bezienswaardigheden: beschrijf hier wat de moeite waard is rond het huis.</p><p>Activiteiten met vaste data verschijnen automatisch in uw verblijfsruimte zodra uw data bekend zijn.</p>',
        ],
        'access' => [
            'menu' => 'Bereikbaarheid',
            'title' => 'Hoe u ons bereikt',
            'lead' => 'Route, parkeren en aankomst ter plaatse.',
            'body' => '<p>Beschrijf hier de autoroute, het dichtstbijzijnde station of vliegveld, parkeren en bijzonderheden van de laatste kilometer.</p><p>Precieze aankomstinstructies (codes, sleutelkluis, contact van de lokale beheerder) staan in uw verblijfsruimte, nooit openbaar.</p>',
        ],
        'contact' => [
            'menu' => 'Contact',
            'title' => 'Neem contact op',
            'lead' => 'Een vraag vóór het boeken? Schrijf ons.',
            'body' => '<p>Wij antwoorden persoonlijk, in het Frans, Engels, Nederlands of Duits.</p><p>Voor vragen over een bestaande reservering kunt u gewoon antwoorden op de laatste e-mail die u ontving: uw bericht wordt automatisch aan het juiste dossier gekoppeld.</p>',
        ],
        'legal_notice' => [
            'menu' => 'Juridische informatie',
            'title' => 'Juridische informatie',
            'lead' => 'Uitgever van de site, hosting en aansprakelijkheid.',
            'body' => '<p>Vul deze pagina aan met de identiteit van de uitgever, het adres, indien van toepassing het SIRET-nummer, en de naam en contactgegevens van de hostingprovider.</p><p>Deze informatie is verplicht voor een site die een verhuur in Frankrijk aanbiedt.</p>',
        ],
        'privacy' => [
            'menu' => 'Privacy',
            'title' => 'Gegevensbescherming',
            'lead' => 'Welke gegevens worden verzameld, waarom en hoelang.',
            'body' => '<p>Wij verzamelen alleen wat nodig is voor de reservering, het contract, het verblijf en wettelijke verplichtingen: identiteit, contactgegevens, verblijfsdata, documenten en berichten bij het dossier.</p><p>U kunt op elk moment inzage, correctie, export of verwijdering van uw gegevens vragen, binnen de wettelijke bewaartermijnen.</p>',
        ],
        'terms' => [
            'menu' => 'Algemene voorwaarden',
            'title' => 'Algemene huurvoorwaarden',
            'lead' => 'Reservering, betaling, annulering en aansprakelijkheid.',
            'body' => '<p>Vul deze pagina aan met uw eigen voorwaarden: aanbetaling, vervaldatum van het saldo, waarborg, annuleringsbeleid, inspecties, huisregels en consumentenbemiddeling.</p><p>De versie die een gast aanvaardt, wordt bewaard precies zoals ze werd getoond, in zijn taal, en wordt nadien nooit herschreven.</p>',
        ],
    ],
    'season' => [
        'all' => 'Alle seizoenen',
        'summer' => 'Zomer',
        'winter' => 'Winter',
    ],
    'kind' => [
        'home' => 'Home',
        'page' => 'Pagina',
        'gallery' => 'Galerij',
        'legal' => 'Juridische pagina',
        'contact' => 'Contact',
        'availability' => 'Beschikbaarheid',
        'rates' => 'Tarieven',
    ],
    'error' => [
        'slug_required' => 'De URL-identificatie is verplicht.',
        'slug_taken' => 'Deze URL-identificatie is al in gebruik.',
        'not_found' => 'Pagina niet gevonden.',
        'parent_self' => 'Een pagina kan niet haar eigen bovenliggende pagina zijn.',
        'system_page' => 'Deze pagina hoort bij de basis en kan niet worden verwijderd.',
    ],
    'gallery' => [
        'empty' => 'Nog geen foto gepubliceerd.',
        'all' => 'Alle',
        'open' => 'Afbeelding vergroten',
        'previous' => 'Vorige afbeelding',
        'next' => 'Volgende afbeelding',
        'counter' => 'Afbeelding {index} van {total}',
    ],
    'contact_intro' => 'Schrijf ons op het onderstaande adres.',
    'no_contact' => 'Er is nog geen contactadres ingesteld.',
    'fallback_notice' => 'Deze pagina is nog niet vertaald in uw taal: de getoonde tekst komt uit de standaardtaal van de site.',
    'legal_footer' => 'Juridische informatie',
];
