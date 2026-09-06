<?php

declare(strict_types=1);

/**
 * Franse conformiteitsassistent (SPECIFICATIONS.md §61 en §62).
 */

return [
    'title' => 'Franse conformiteit',
    'intro' => 'Elk onderwerp wordt beschreven en vervolgens door u vastgesteld: status, officiële bron, datum van '
        . 'verificatie en volgende herziening.',
    'disclaimer' => 'Deze informatie geeft richting, het is geen juridisch advies. De regels verschillen per gemeente '
        . 'en veranderen: de officiële bron en de datum van verificatie tellen.',
    'saved' => 'Onderwerp opgeslagen.',
    'evidence_added' => 'Bewijsstuk toegevoegd.',
    'overdue' => 'Herziening verlopen',
    'managed_elsewhere' => 'Dit onderwerp wordt op een eigen scherm beheerd.',
    'status' => [
        'compliant' => 'Conform',
        'to_verify' => 'Te controleren',
        'not_applicable' => 'Niet van toepassing',
    ],
    'field' => [
        'definition' => 'Definitie',
        'applicability' => 'Toepasselijkheid',
        'where' => 'Waar te vinden',
        'impact' => 'Impact',
        'status' => 'Status',
        'value' => 'Waarde',
        'notes' => 'Nota’s',
        'source' => 'Officiële bron',
        'last_verified' => 'Gecontroleerd op',
        'next_review' => 'Volgende herziening',
        'evidence' => 'Bewijsstuk',
        'evidence_current' => 'Bijlage bekijken',
    ],
    'error' => [
        'source' => 'De bron moet een webadres zijn (http of https).',
    ],
    'topic' => [
        'furnished_tourism' => [
            'label' => 'Toeristisch gemeubileerde woning',
            'definition' => 'Statuut van een gemeubileerde woning die wordt verhuurd aan reizigers die er niet gaan '
                . 'wonen.',
            'applicability' => 'Geldt voor elke seizoensverhuur van een volledige gemeubileerde woning.',
            'where' => 'Dienst stedenbouw of website van de gemeente, en de nationale ondernemingsdienst.',
            'impact' => 'Bepaalt welke aangifte nodig is, welke belasting geldt en wat moet worden meegedeeld.',
        ],
        'declaration' => [
            'label' => 'Aangifte of registratie bij de gemeente',
            'definition' => 'Aangifte van de gemeubileerde verhuur bij de gemeente, soms met een te tonen '
                . 'registratienummer.',
            'applicability' => 'Verplicht in veel gemeenten; registratie geldt waar de gemeente ze heeft ingevoerd.',
            'where' => 'De gemeente van de woning, via haar online loket indien aanwezig.',
            'impact' => 'Geen aangifte betekent een boete en geen registratienummer voor de platformen.',
        ],
        'siret' => [
            'label' => 'SIRET-nummer',
            'definition' => 'Identificatienummer van de activiteit gemeubileerde verhuur.',
            'applicability' => 'Vereist zodra de activiteit is aangegeven.',
            'where' => 'Het uniek loket voor ondernemingsformaliteiten.',
            'impact' => 'Nodig voor de belastingaangiften en de facturatie.',
        ],
        'owner_status' => [
            'label' => 'Statuut van de verhuurder',
            'definition' => 'Niet-professionele of professionele verhuurder van gemeubileerde woningen, afhankelijk '
                . 'van de ontvangsten en hun aandeel in het inkomen.',
            'applicability' => 'Betreft elke eigenaar die gemeubileerd verhuurt.',
            'where' => 'Officiële fiscale documentatie en uw boekhouder.',
            'impact' => 'Bepaalt het belastingstelsel, de bijdragen en de boekhoudkundige verplichtingen.',
        ],
        'residence_kind' => [
            'label' => 'Hoofd- of tweede verblijf',
            'definition' => 'Kwalificatie van de woning op basis van het gebruik door de eigenaar.',
            'applicability' => 'Betreft elke woning; dit product richt zich op een tweede verblijf.',
            'where' => 'Aanslagbiljet en aangifte van bewoning.',
            'impact' => 'Een hoofdverblijf mag slechts een beperkt aantal dagen per jaar worden verhuurd.',
        ],
        'classification' => [
            'label' => 'Classificatie in sterren',
            'definition' => 'Vrijwillige classificatie van de gemeubileerde woning, van één tot vijf sterren.',
            'applicability' => 'Facultatief, maar bepalend voor sommige tarieven en aftrekken.',
            'where' => 'Erkende instelling die het classificatiebezoek uitvoert.',
            'impact' => 'Wijzigt het tarief van de toeristenbelasting en kan fiscale voordelen openen.',
        ],
        'energy_diagnosis' => [
            'label' => 'Energieprestatiediagnose',
            'definition' => 'Beoordeling van de energieprestatie van de woning.',
            'applicability' => 'Vereist afhankelijk van de aard en de duur van de verhuur; controleer uw situatie.',
            'where' => 'Gecertificeerde deskundige.',
            'impact' => 'Kan de verhuur beïnvloeden en moet worden meegedeeld waar ze vereist is.',
        ],
        'change_of_use' => [
            'label' => 'Bestemmingswijziging',
            'definition' => 'Vergunning om een woonruimte om te vormen tot toeristisch logies.',
            'applicability' => 'Vereist in sommige gemeenten, vaak de grootste of de meest gespannen.',
            'where' => 'Dienst stedenbouw van de gemeente.',
            'impact' => 'Verhuren zonder vergunning waar ze vereist is, leidt tot een zware burgerlijke boete.',
        ],
        'tourist_tax' => [
            'label' => 'Toeristenbelasting',
            'definition' => 'Belasting die bij de reiziger wordt geïnd en aan de overheid wordt doorgestort.',
            'applicability' => 'Geldt waar de overheid ze heeft ingevoerd.',
            'where' => 'De bevoegde overheid, die het tarief en de betaaltermijnen publiceert.',
            'impact' => 'Het tarief hangt af van de classificatie; de doorstorting gebeurt periodiek en op aangifte.',
        ],
        'police_record' => [
            'label' => 'Individuele politiefiche',
            'definition' => 'Fiche die bij aankomst wordt ingevuld voor bepaalde buitenlandse reizigers.',
            'applicability' => 'Alleen als de verplichting op u van toepassing is.',
            'where' => 'Prefectuur of bevoegde politiedienst.',
            'impact' => 'Vereist een omkaderde verzameling, een beperkte bewaring en overhandiging op verzoek.',
        ],
        'contract' => [
            'label' => 'Overeenkomst seizoensverhuur',
            'definition' => 'Geschrift met de woning, de data, de prijs en de voorwaarden.',
            'applicability' => 'Vereist voor een seizoensverhuur.',
            'where' => 'Het model van de toepassing, aangevuld met uw eigen voorwaarden.',
            'impact' => 'Een duidelijke, aanvaarde overeenkomst voorkomt de meeste geschillen.',
        ],
        'cancellation' => [
            'label' => 'Annuleringsvoorwaarden',
            'definition' => 'Regels bij annulering door de reiziger of door u.',
            'applicability' => 'Altijd: het zijn uw voorwaarden, ze moeten geschreven en aanvaard zijn.',
            'where' => 'Uw algemene voorwaarden, gepubliceerd en geversioneerd.',
            'impact' => 'Zonder geschreven en aanvaarde regels wordt elke terugbetaling geval per geval onderhandeld.',
        ],
        'mediation' => [
            'label' => 'Consumentenbemiddeling',
            'definition' => 'Minnelijke weg die aan de reiziger wordt aangeboden bij een geschil.',
            'applicability' => 'Verplicht voor een professional; te controleren voor uw statuut.',
            'where' => 'Een erkende bemiddelaar, wiens naam en website moeten worden meegedeeld.',
            'impact' => 'De bemiddelaar moet in uw voorwaarden en op uw site vermeld staan.',
        ],
        'insurance' => [
            'label' => 'Verzekering',
            'definition' => 'Dekking van de woning en van de aansprakelijkheid uit de verhuur.',
            'applicability' => 'Altijd: uw polis moet seizoensverhuur uitdrukkelijk dekken.',
            'where' => 'Uw verzekeraar, met uitdrukkelijke vermelding in de polis.',
            'impact' => 'Een niet-gedekt schadegeval blijft voor uw rekening.',
        ],
        'local_risks' => [
            'label' => 'Informatie over risico’s',
            'definition' => 'De reiziger informeren over natuurlijke en technologische risico’s in de omgeving.',
            'applicability' => 'Afhankelijk van de gemeente en de zonering.',
            'where' => 'De openbare dienst voor risico-informatie.',
            'impact' => 'De informatie moet beschikbaar en actueel zijn waar ze vereist is.',
        ],
        'clearing' => [
            'label' => 'Vrijmaken van begroeiing',
            'definition' => 'Wettelijke verplichting om de begroeiing rond gebouwen te verwijderen.',
            'applicability' => 'In gebieden met brandgevaar.',
            'where' => 'Gemeente en prefectuur, via het departementale besluit.',
            'impact' => 'Niet-naleving leidt tot een boete en tot uw aansprakelijkheid.',
        ],
        'winter_equipment' => [
            'label' => 'Winteruitrusting',
            'definition' => 'Verplichte uitrusting van voertuigen tijdens de winterperiode.',
            'applicability' => 'In de gemeenten die onder de bergregelgeving vallen.',
            'where' => 'Prefectuur van het departement.',
            'impact' => 'Meld het de reiziger vóór aankomst: hij wordt gecontroleerd.',
        ],
        'waste' => [
            'label' => 'Afval',
            'definition' => 'Lokale regels voor sorteren, aanbieden en ophalen.',
            'applicability' => 'Altijd, met regels eigen aan elke gemeente.',
            'where' => 'De overheid die instaat voor de ophaling.',
            'impact' => 'Duidelijke instructies voorkomen sluikstorten en boetes.',
        ],
    ],
];
