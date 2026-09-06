<?php

declare(strict_types=1);

/**
 * Libellés et aides des réglages typés.
 *
 * Chaque réglage expose un libellé et une aide explicite : l'objectif est que
 * le propriétaire comprenne l'impact du réglage sans documentation externe.
 */

return [
    'property' => [
        'siret' => [
            'label' => 'SIRET',
            'help' => 'Registratienummer, op het contract vermeld wanneer het is ingevuld.',
        ],
        'name' => [
            'label' => 'Naam van de woning',
            'help' => 'Naam die publiek wordt getoond voor uw vakantiewoning.',
        ],
        'address_line1' => [
            'label' => 'Adres (regel 1)',
            'help' => 'Huisnummer en straat van de woning.',
        ],
        'address_line2' => [
            'label' => 'Adres (regel 2)',
            'help' => 'Aanvullende adresgegevens: gehucht, gebouw, verdieping.',
        ],
        'postal_code' => [
            'label' => 'Postcode',
            'help' => 'Franse postcode van de woning.',
        ],
        'city' => [
            'label' => 'Gemeente',
            'help' => 'Gemeente van de woning. Wordt ook gebruikt voor de toeristenbelasting.',
        ],
        'country' => [
            'label' => 'Land',
            'help' => 'SecondStay is gespecialiseerd voor Frankrijk.',
        ],
        'latitude' => [
            'label' => 'Breedtegraad',
            'help' => 'Coördinaat voor lokale inhoud en bereikbaarheid.',
        ],
        'longitude' => [
            'label' => 'Lengtegraad',
            'help' => 'Coördinaat voor lokale inhoud en bereikbaarheid.',
        ],
        'contact_email' => [
            'label' => 'Contact-e-mail',
            'help' => 'Adres dat aan gasten wordt getoond voor algemene vragen.',
        ],
        'contact_phone' => [
            'label' => 'Contacttelefoon',
            'help' => 'Nummer dat aan gasten wordt getoond.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Standaardtaal',
            'help' => 'Taal die wordt gebruikt als geen voorkeur bekend is.',
        ],
        'timezone' => [
            'label' => 'Tijdzone',
            'help' => 'Zone voor aankomst- en vertrektijden en herinneringen.',
        ],
        'public_url' => [
            'label' => 'Publieke URL',
            'help' => 'Openbaar adres van de site, gebruikt in e-mails en links.',
        ],
        'season' => [
            'label' => 'Weergegeven seizoen',
            'help' => 'Automatisch volgt de datum; forceer zomer of winter voor een vaste presentatie.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Minimum aantal nachten',
            'help' => 'Minimale geaccepteerde verblijfsduur.',
        ],
        'max_guests' => [
            'label' => 'Maximale capaciteit',
            'help' => 'Totaal aantal geaccepteerde gasten, baby’s inbegrepen volgens uw regel.',
        ],
        'checkin_time' => [
            'label' => 'Aankomsttijd',
            'help' => 'Tijd vanaf wanneer de woning beschikbaar is.',
        ],
        'checkout_time' => [
            'label' => 'Vertrektijd',
            'help' => 'Uiterste tijd om de woning te verlaten.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Zaterdag-tot-zaterdagregel',
            'help' => 'Verplicht verblijven van zaterdag tot zaterdag. Uitschakelbaar.',
        ],
        'hold_minutes' => [
            'label' => 'Duur van tijdelijke reservering',
            'help' => 'Minuten dat data geblokkeerd blijven vóór bevestiging.',
        ],
        'requires_approval' => [
            'label' => 'Goedkeuring door de eigenaar',
            'help' => 'Elke reserveringsaanvraag wacht op uw akkoord. Schakel dit uit om beschikbare verblijven '
                . 'automatisch te bevestigen.',
        ],
        'allow_waitlist' => [
            'label' => 'Wachtlijst',
            'help' => 'Een bezoeker kan vragen om verwittigd te worden als onbeschikbare data vrijkomen.',
        ],
        'min_adults' => [
            'label' => 'Minimum volwassenen',
            'help' => 'Minimumaantal volwassenen per verblijf.',
        ],
        'max_children' => [
            'label' => 'Maximum kinderen',
            'help' => 'Maximumaantal kinderen bovenop de volwassenen.',
        ],
        'max_infants' => [
            'label' => 'Maximum baby’s',
            'help' => 'Baby’s tellen niet mee voor de slaapcapaciteit.',
        ],
        'night_multiple' => [
            'label' => 'Blokken van nachten',
            'help' => 'Legt een duur op die een veelvoud is van dit getal. 0 schakelt de regel uit, 7 legt hele weken '
                . 'op.',
        ],
        'max_nights' => [
            'label' => 'Maximale duur',
            'help' => 'Maximumaantal nachten per verblijf.',
        ],
        'arrival_weekday' => [
            'label' => 'Vaste aankomstdag',
            'help' => 'Beperkt aankomsten tot één weekdag. De instelling zaterdag-zaterdag heeft voorrang.',
        ],
        'advance_days' => [
            'label' => 'Opzegtermijn',
            'help' => 'Minimumaantal dagen tussen vandaag en een aankomst.',
        ],
        'horizon_days' => [
            'label' => 'Reserveringshorizon',
            'help' => 'Aantal dagen waarna de kalender nog niet geopend is.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Standaard prijs per nacht',
            'help' => 'Gebruikt voor elke datum zonder specifiek tarief.',
        ],
        'cleaning_mode' => [
            'label' => 'Schoonmaakmodus',
            'help' => 'Geen, optioneel of verplicht. Standaard verplicht.',
        ],
        'cleaning_price' => [
            'label' => 'Schoonmaakprijs',
            'help' => 'Bedrag van de schoonmaakkosten. Standaardwaarde: € 100.',
        ],
        'deposit_percent' => [
            'label' => 'Aanbetaling (%)',
            'help' => 'Deel van het verblijf dat nodig is om de reservering te bevestigen.',
        ],
        'security_deposit' => [
            'label' => 'Waarborgsom',
            'help' => 'Bedrag van de waarborg vóór het verblijf.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Gepland onderhoud',
            'help' => 'Sluit de publieke site. Beheer blijft bereikbaar.',
        ],
        'message' => [
            'label' => 'Onderhoudsbericht',
            'help' => 'Interne notitie met de reden voor het onderhoud.',
        ],
    ],
    'scheduler' => [
        'http_token' => [
            'label' => 'Token voor de planner',
            'help' => 'Vul dit alleen in als uw hostingprovider cron enkel via een URL aanbiedt. Leeg bestaat de '
                . 'start-URL niet.',
        ],
    ],
    'backup' => [
        'auto_enabled' => [
            'label' => 'Automatische back-up',
            'help' => 'Laat de planner elke dag een back-up maken.',
        ],
        'retention_count' => [
            'label' => 'Bewaarde back-ups',
            'help' => 'Aantal back-ups dat wordt bewaard vóór automatische verwijdering.',
        ],
        'include_media' => [
            'label' => 'Media opnemen',
            'help' => 'Voegt foto’s, documenten en bijlagen toe aan de back-up.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Updatekanaal',
            'help' => 'Stabiel installeert alleen gepubliceerde versies.',
        ],
        'auto_install' => [
            'label' => 'Automatische update',
            'help' => 'Installeert automatisch nieuwe gevalideerde versies.',
        ],
        'repository' => [
            'label' => 'Release-repository',
            'help' => 'GitHub-repository met installeerbare artefacten.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Logniveau',
            'help' => 'Minimale ernst van vastgelegde berichten.',
        ],
        'retention_days' => [
            'label' => 'Bewaartermijn logboek (dagen)',
            'help' => 'Bewaarperiode vóór automatische opschoning.',
        ],
    ],
    'error' => [
        'token_too_short' => 'Het token moet minstens 32 tekens lang zijn.',
        'required' => 'Deze instelling is verplicht.',
        'unknown' => 'Onbekende instelling.',
        'integer' => 'Voer een geheel getal in.',
        'decimal' => 'Voer een decimaal getal in.',
        'money' => 'Voer een geldig bedrag in, bijvoorbeeld 100,00.',
        'enum' => 'Waarde niet toegestaan.',
        'email' => 'Ongeldig e-mailadres.',
        'url' => 'Ongeldige URL.',
        'url_scheme' => 'Alleen http(s)-URL’s worden geaccepteerd.',
        'date' => 'Ongeldige datum (verwacht formaat: JJJJ-MM-DD).',
        'time' => 'Ongeldige tijd (verwacht formaat: UU:MM).',
        'duration' => 'Ongeldige duur (in minuten).',
        'json' => 'Ongeldige JSON.',
        'iban' => 'Ongeldig IBAN: controleer het controlegetal.',
        'bic' => 'Ongeldige BIC (8 of 11 tekens).',
        'currency' => 'Ongeldige valuta: een ISO 4217-code van drie letters wordt verwacht.',
        'color' => 'Ongeldige kleur: gebruik het formaat #rrggbb.',
        'too_long' => 'Waarde is te lang.',
        'too_small' => 'Waarde is te klein.',
        'too_large' => 'Waarde is te groot.',
    ],
    'mail' => [
        'from_address' => [
            'label' => 'Afzenderadres',
            'help' => 'Adres dat als afzender van de e-mails wordt getoond.',
        ],
        'from_name' => [
            'label' => 'Afzendernaam',
            'help' => 'Naam die naast het afzenderadres wordt getoond.',
        ],
        'reply_to' => [
            'label' => 'Antwoordadres',
            'help' => 'Bewaakte mailbox die antwoorden van gasten ontvangt.',
        ],
        'smtp_host' => [
            'label' => 'SMTP-server',
            'help' => 'Host van uw e-mailverzenddienst.',
        ],
        'smtp_port' => [
            'label' => 'SMTP-poort',
            'help' => '587 met STARTTLS, 465 met impliciete TLS.',
        ],
        'smtp_encryption' => [
            'label' => 'SMTP-versleuteling',
            'help' => 'STARTTLS wordt aanbevolen. Het servercertificaat wordt altijd gecontroleerd.',
        ],
        'smtp_username' => [
            'label' => 'SMTP-gebruiker',
            'help' => 'Aanmeldnaam voor de SMTP-server.',
        ],
        'smtp_password' => [
            'label' => 'SMTP-wachtwoord',
            'help' => 'Versleuteld opgeslagen en nooit opnieuw getoond. Laat leeg om de huidige waarde te behouden.',
        ],
        'dkim_selector' => [
            'label' => 'DKIM-selector',
            'help' => 'Selector van uw verzenddienst (vaak “default” of “mail”). Hij dient enkel voor de DNS-diagnose: '
                . 'het ondertekenen blijft de taak van de provider.',
        ],
    ],
    'notification' => [
        'reminder_days' => [
            'label' => 'Herinnering verblijf (dagen voor aankomst)',
            'help' => 'Aantal dagen tussen het versturen van de herinnering en de aankomst van de gast.',
        ],
        'push_enabled' => [
            'label' => 'Pushmeldingen',
            'help' => 'Laat browsers meldingen ontvangen. E-mail wordt in elk geval verstuurd.',
        ],
        'retention_days' => [
            'label' => 'Bewaartermijn meldingenlogboek',
            'help' => 'Hoe lang verzendsporen bewaard blijven, in dagen.',
        ],
    ],
    'push' => [
        'subject' => [
            'label' => 'Pushcontact',
            'help' => 'Contact-e-mailadres of URL die naar de pushdiensten wordt gestuurd, zoals de norm vereist. Bij '
                . 'leeg veld wordt het afzenderadres gebruikt.',
        ],
        'vapid_public' => [
            'label' => 'Openbare VAPID-sleutel',
            'help' => 'Door de installatie gegenereerd en aan browsers doorgegeven. Vervangen maakt alle bestaande '
                . 'abonnementen ongeldig.',
        ],
        'vapid_private' => [
            'label' => 'Privé VAPID-sleutel',
            'help' => 'Versleuteld opgeslagen en nooit opnieuw getoond. Hij ondertekent de verzendingen naar de '
                . 'pushdiensten.',
        ],
    ],
    'account' => [
        'allow_signup' => [
            'label' => 'Registraties toestaan',
            'help' => 'Laat gasten een account aanmaken vanaf de publieke site.',
        ],
        'allow_passkeys' => [
            'label' => 'Passkeys toestaan',
            'help' => 'Schakelt wachtwoordloos inloggen met passkeys (WebAuthn) in.',
        ],
        'require_email_confirmation' => [
            'label' => 'E-mailbevestiging verplicht',
            'help' => 'Het account blijft inactief tot het adres is bevestigd.',
        ],
    ],
    'payment' => [
        'provider' => [
            'label' => 'Betaalprovider',
            'help' => 'Mollie int online en bevestigt de reservering automatisch. Zonder provider blijft alleen de '
                . 'overschrijving over.',
        ],
        'mollie_api_key' => [
            'label' => 'Mollie API-sleutel',
            'help' => 'Versleuteld opgeslagen en nooit opnieuw getoond. Een „test_”-sleutel int niets echt.',
        ],
        'balance_days_before' => [
            'label' => 'Saldo verschuldigd (dagen voor aankomst)',
            'help' => 'Het saldo vervalt zoveel dagen voor aankomst, of onmiddellijk bij een latere reservering.',
        ],
        'transfer_enabled' => [
            'label' => 'Bankoverschrijving toestaan',
            'help' => 'Toont het IBAN en de EPC-QR-code. Een overschrijving bevestigt een reservering nooit vanzelf.',
        ],
        'beneficiary_name' => [
            'label' => 'Begunstigde van de overschrijving',
            'help' => 'Naam van de rekeninghouder, zoals die in de bankapp van de reiziger verschijnt.',
        ],
        'iban' => [
            'label' => 'IBAN',
            'help' => 'IBAN van de te crediteren rekening. Het controlegetal wordt vóór het opslaan gecontroleerd.',
        ],
        'bic' => [
            'label' => 'BIC',
            'help' => 'Optioneel. Sommige banken vragen er nog om voor overschrijvingen buiten de SEPA-zone.',
        ],
        'currency' => [
            'label' => 'Valuta',
            'help' => 'ISO 4217-code van drie letters, standaard EUR.',
        ],
    ],
    'tax' => [
        'territory' => [
            'label' => 'Belastinggebied',
            'help' => 'De gemeente of het samenwerkingsverband dat de toeristenbelasting int.',
        ],
        'classification' => [
            'label' => 'Classificatie van de woning',
            'help' => 'Het tarief hangt af van de classificatie als toeristisch gemeubileerde woning.',
        ],
        'tourist_enabled' => [
            'label' => 'Toeristenbelasting innen',
            'help' => 'Voegt de toeristenbelasting toe aan het betaalschema van elke reservering.',
        ],
        'tourist_per_adult_night' => [
            'label' => 'Belasting per volwassene per nacht',
            'help' => 'Bedrag per volwassene en per nacht. Minderjarigen zijn vrijgesteld.',
        ],
        'tourist_cap_per_stay' => [
            'label' => 'Maximum per verblijf',
            'help' => 'Maximaal bedrag voor één verblijf. Nul betekent geen maximum.',
        ],
    ],
    'imap' => [
        'enabled' => [
            'label' => 'Postbus ophalen',
            'help' => 'Haalt periodiek de antwoorden van reizigers en hun bijlagen op.',
        ],
        'host' => [
            'label' => 'IMAP-server',
            'help' => 'Hostnaam van de postbus van de woning.',
        ],
        'port' => [
            'label' => 'IMAP-poort',
            'help' => '993 voor een versleutelde verbinding, 143 met STARTTLS.',
        ],
        'encryption' => [
            'label' => 'IMAP-versleuteling',
            'help' => 'Impliciete TLS wordt aanbevolen. Geen versleuteling is af te raden.',
        ],
        'username' => [
            'label' => 'IMAP-gebruikersnaam',
            'help' => 'Account van de opgehaalde postbus.',
        ],
        'password' => [
            'label' => 'IMAP-wachtwoord',
            'help' => 'Versleuteld opgeslagen en nooit opnieuw getoond.',
        ],
        'mailbox' => [
            'label' => 'Opgehaalde map',
            'help' => 'INBOX, tenzij antwoorden in een aparte map aankomen.',
        ],
        'reply_address' => [
            'label' => 'Antwoordadres',
            'help' => 'Adres dat aan reizigers wordt getoond. Het krijgt per reservering een label zodat antwoorden '
                . 'zichzelf koppelen.',
        ],
        'uid_validity' => [
            'label' => 'Geldigheidskenmerk',
            'help' => 'Ingevuld door de synchronisatie. Verandert het, dan is de postbus hernummerd en begint het '
                . 'ophalen opnieuw.',
        ],
        'batch_size' => [
            'label' => 'Berichten per ophaalronde',
            'help' => 'Aantal berichten per ronde. Een ophaalronde moet altijd eindigen.',
        ],
    ],
    'legal' => [
        'terms_version' => [
            'label' => 'Versie van de voorwaarden',
            'help' => 'Vastgelegd in elk aanvaard contract. Wijzig ze wanneer de tekst verandert.',
        ],
        'mediator_name' => [
            'label' => 'Consumentenbemiddelaar',
            'help' => 'Naam van de bemiddelaar die in het contract wordt vermeld.',
        ],
        'mediator_url' => [
            'label' => 'Website van de bemiddelaar',
            'help' => 'Waar de reiziger een geschil kan voorleggen.',
        ],
    ],
    'operations' => [
        'default_manager' => [
            'label' => 'Standaard lokale beheerder',
            'help' => 'Account-id van de beheerder voor verblijven zonder expliciete toewijzing. Nul betekent geen.',
        ],
        'prepare_days' => [
            'label' => 'Voorbereidingsvenster (dagen)',
            'help' => 'Hoeveel dagen voor aankomst een verblijf in het „Te doen”-overzicht verschijnt.',
        ],
        'calendar_enabled' => [
            'label' => 'Privékalenders publiceren',
            'help' => 'Schakelt de ICS-feeds in voor beheer, beheerders en reizigers.',
        ],
    ],
    'inspection' => [
        'report_window_hours' => [
            'label' => 'Meldtermijn bij aankomst (uren)',
            'help' => 'Hoelang de reiziger de tijd heeft om na aankomst een afwijking te melden.',
        ],
        'guest_enabled' => [
            'label' => 'Plaatsbeschrijving door de reiziger',
            'help' => 'Opent de formulieren voor aankomst en vertrek vanuit „Mijn verblijf”.',
        ],
    ],
    'compliance' => [
        'police_record_enabled' => [
            'label' => 'Politiefiche',
            'help' => 'Alleen inschakelen als de verplichting geldt. Zolang ze uit staat wordt niets verzameld.',
        ],
        'police_retention_days' => [
            'label' => 'Bewaartermijn fiches (dagen)',
            'help' => 'Hoelang na het vertrek de fiche automatisch wordt gewist.',
        ],
    ],
    'llm' => [
        'enabled' => [
            'label' => 'Lokale inhoud inschakelen',
            'help' => 'Maakt activiteitensuggesties voor komende verblijven, op basis van de bronnen die u opgeeft.',
        ],
        'provider' => [
            'label' => 'Aanbieder',
            'help' => 'Zonder geconfigureerde aanbieder wordt geen activiteit gemaakt — en niets verzonnen.',
        ],
        'api_key' => [
            'label' => 'API-sleutel',
            'help' => 'Versleuteld bewaard en nooit opnieuw getoond.',
        ],
        'model' => [
            'label' => 'Model',
            'help' => 'Identificatie van het model dat voor de generatie wordt gebruikt.',
        ],
        'prompt' => [
            'label' => 'Instructie',
            'help' => 'Uw vrije instructie. Locatie, seizoen, data, bronnen en formaat worden automatisch toegevoegd.',
        ],
        'window_weeks' => [
            'label' => 'Venster vóór aankomst (weken)',
            'help' => 'Hoelang vóór de aankomst de inhoud van een verblijf wordt gemaakt.',
        ],
        'refresh_days' => [
            'label' => 'Verversing (dagen)',
            'help' => 'Interval tussen twee generaties, tot aan het verblijf.',
        ],
    ],
    'pwa' => [
        'theme_color' => [
            'label' => 'Themakleur',
            'help' => 'Tint van de systeembalk zodra de app is geïnstalleerd. Formaat #rrggbb.',
        ],
        'background_color' => [
            'label' => 'Achtergrondkleur',
            'help' => 'Wordt bij het starten getoond, vóór de eerste weergave. Formaat #rrggbb.',
        ],
    ],
    'quota' => [
        'media_mb' => ['label' => 'Quotum media (MB)', 'help' => 'Nul betekent geen limiet.'],
        'documents_mb' => ['label' => 'Quotum documenten (MB)', 'help' => 'Nul betekent geen limiet.'],
        'backups_mb' => ['label' => 'Quotum back-ups (MB)', 'help' => 'Nul betekent geen limiet.'],
        'attachments_mb' => ['label' => 'Quotum bijlagen (MB)', 'help' => 'Nul betekent geen limiet.'],
    ],
];
