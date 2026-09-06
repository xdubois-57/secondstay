<?php

declare(strict_types=1);

/**
 * Assistent für die französische Konformität (SPECIFICATIONS.md §61 und §62).
 */

return [
    'title' => 'Französische Konformität',
    'intro' => 'Jedes Thema wird beschrieben und anschließend von Ihnen festgestellt: Status, offizielle Quelle, '
        . 'Prüfdatum und nächste Überprüfung.',
    'disclaimer' => 'Diese Hinweise sind Orientierung, keine Rechtsberatung. Die Regeln unterscheiden sich je Gemeinde '
        . 'und ändern sich: maßgeblich sind die offizielle Quelle und das Prüfdatum.',
    'saved' => 'Thema gespeichert.',
    'evidence_added' => 'Nachweis hinzugefügt.',
    'overdue' => 'Überprüfung überfällig',
    'managed_elsewhere' => 'Dieses Thema wird auf einem eigenen Bildschirm verwaltet.',
    'status' => [
        'compliant' => 'Konform',
        'to_verify' => 'Zu prüfen',
        'not_applicable' => 'Nicht anwendbar',
    ],
    'field' => [
        'definition' => 'Definition',
        'applicability' => 'Anwendbarkeit',
        'where' => 'Wo zu finden',
        'impact' => 'Auswirkung',
        'status' => 'Status',
        'value' => 'Wert',
        'notes' => 'Notizen',
        'source' => 'Offizielle Quelle',
        'last_verified' => 'Geprüft am',
        'next_review' => 'Nächste Überprüfung',
        'evidence' => 'Nachweis',
        'evidence_current' => 'Anhang ansehen',
    ],
    'error' => [
        'source' => 'Die Quelle muss eine Webadresse sein (http oder https).',
    ],
    'topic' => [
        'furnished_tourism' => [
            'label' => 'Touristische Ferienwohnung',
            'definition' => 'Status einer möblierten Wohnung, die an durchreisende Gäste vermietet wird, die dort '
                . 'keinen Wohnsitz begründen.',
            'applicability' => 'Gilt für jede saisonale Vermietung einer vollständigen möblierten Wohnung.',
            'where' => 'Bauamt oder Website der Gemeinde sowie der nationale Unternehmensdienst.',
            'impact' => 'Bestimmt die erforderliche Anmeldung, die anwendbare Steuer und die Informationspflichten.',
        ],
        'declaration' => [
            'label' => 'Anmeldung oder Registrierung bei der Gemeinde',
            'definition' => 'Anmeldung der Ferienwohnung bei der Gemeinde, teils mit einer anzuzeigenden '
                . 'Registriernummer.',
            'applicability' => 'In vielen Gemeinden verpflichtend; die Registrierung gilt dort, wo die Gemeinde sie '
                . 'eingeführt hat.',
            'where' => 'Gemeinde der Unterkunft, gegebenenfalls über deren Online-Schalter.',
            'impact' => 'Ohne Anmeldung drohen ein Bußgeld und das Fehlen der von Plattformen verlangten Nummer.',
        ],
        'siret' => [
            'label' => 'SIRET-Nummer',
            'definition' => 'Kennnummer der Tätigkeit der möblierten Vermietung.',
            'applicability' => 'Erforderlich, sobald die Tätigkeit angemeldet ist.',
            'where' => 'Zentraler Schalter für Unternehmensformalitäten.',
            'impact' => 'Erforderlich für Steuererklärungen und für die Rechnungsstellung.',
        ],
        'owner_status' => [
            'label' => 'Status des Vermieters',
            'definition' => 'Nicht gewerblicher oder gewerblicher Vermieter möblierter Wohnungen, je nach Einnahmen '
                . 'und deren Anteil am Einkommen.',
            'applicability' => 'Betrifft jeden Eigentümer, der möbliert vermietet.',
            'where' => 'Offizielle Steuerunterlagen und Ihre Steuerberatung.',
            'impact' => 'Bestimmt Besteuerung, Beiträge und Buchführungspflichten.',
        ],
        'residence_kind' => [
            'label' => 'Haupt- oder Zweitwohnsitz',
            'definition' => 'Einstufung der Wohnung nach ihrer Nutzung durch die Eigentümerschaft.',
            'applicability' => 'Betrifft jede Wohnung; dieses Produkt richtet sich an einen Zweitwohnsitz.',
            'where' => 'Steuerbescheid und Nutzungserklärung.',
            'impact' => 'Ein Hauptwohnsitz darf nur eine begrenzte Zahl von Tagen im Jahr vermietet werden.',
        ],
        'classification' => [
            'label' => 'Einstufung in Sternen',
            'definition' => 'Freiwillige Einstufung der Ferienwohnung, von einem bis fünf Sternen.',
            'applicability' => 'Freiwillig, aber maßgeblich für bestimmte Tarife und Freibeträge.',
            'where' => 'Akkreditierte Stelle, die den Einstufungsbesuch durchführt.',
            'impact' => 'Ändert den Kurtaxentarif und kann steuerliche Vorteile eröffnen.',
        ],
        'energy_diagnosis' => [
            'label' => 'Energieausweis',
            'definition' => 'Bewertung der Energieeffizienz der Wohnung.',
            'applicability' => 'Je nach Art und Dauer der Vermietung erforderlich; prüfen Sie Ihre Lage.',
            'where' => 'Zertifizierte Sachverständige.',
            'impact' => 'Kann die Vermietung bedingen und muss mitgeteilt werden, wo er verlangt wird.',
        ],
        'change_of_use' => [
            'label' => 'Nutzungsänderung',
            'definition' => 'Genehmigung, Wohnraum in touristische Unterkunft umzuwandeln.',
            'applicability' => 'In manchen Gemeinden erforderlich, oft in den größten oder angespanntesten.',
            'where' => 'Bauamt der Gemeinde.',
            'impact' => 'Vermieten ohne erforderliche Genehmigung führt zu einer hohen zivilrechtlichen Geldbuße.',
        ],
        'tourist_tax' => [
            'label' => 'Kurtaxe',
            'definition' => 'Beim Gast erhobene und an die Gebietskörperschaft abgeführte Abgabe.',
            'applicability' => 'Gilt dort, wo die Körperschaft sie eingeführt hat.',
            'where' => 'Zuständige Körperschaft, die Tarif und Abführungsfristen veröffentlicht.',
            'impact' => 'Der Tarif hängt von der Einstufung ab; die Abführung erfolgt regelmäßig und auf Erklärung.',
        ],
        'police_record' => [
            'label' => 'Individueller Meldeschein',
            'definition' => 'Bei der Anreise für bestimmte ausländische Gäste auszufüllender Schein.',
            'applicability' => 'Nur wenn die Pflicht Sie betrifft.',
            'where' => 'Präfektur oder zuständige Polizeidienststelle.',
            'impact' => 'Verlangt eine geregelte Erhebung, eine begrenzte Aufbewahrung und die Herausgabe auf '
                . 'Verlangen.',
        ],
        'contract' => [
            'label' => 'Vertrag über die saisonale Vermietung',
            'definition' => 'Schriftstück mit Unterkunft, Daten, Preis und Bedingungen.',
            'applicability' => 'Für eine saisonale Vermietung erforderlich.',
            'where' => 'Vorlage der Anwendung, ergänzt um Ihre eigenen Bedingungen.',
            'impact' => 'Ein klarer, angenommener Vertrag verhindert die meisten Streitigkeiten.',
        ],
        'cancellation' => [
            'label' => 'Stornobedingungen',
            'definition' => 'Regeln bei Stornierung durch den Gast oder durch Sie.',
            'applicability' => 'Immer: es sind Ihre Bedingungen, sie müssen schriftlich und angenommen sein.',
            'where' => 'Ihre Allgemeinen Geschäftsbedingungen, veröffentlicht und versioniert.',
            'impact' => 'Ohne schriftliche, angenommene Regeln wird jede Erstattung Einzelfallverhandlung.',
        ],
        'mediation' => [
            'label' => 'Verbraucherschlichtung',
            'definition' => 'Gütlicher Weg, der dem Gast im Streitfall angeboten wird.',
            'applicability' => 'Für Gewerbetreibende verpflichtend; anhand Ihres Status zu prüfen.',
            'where' => 'Gelistete Schlichtungsstelle, deren Name und Website mitzuteilen sind.',
            'impact' => 'Die Schlichtungsstelle muss in Ihren Bedingungen und auf Ihrer Website stehen.',
        ],
        'insurance' => [
            'label' => 'Versicherung',
            'definition' => 'Absicherung der Unterkunft und der Haftung aus der Vermietung.',
            'applicability' => 'Immer: Ihr Vertrag muss die saisonale Vermietung ausdrücklich abdecken.',
            'where' => 'Ihre Versicherung, mit ausdrücklicher Erwähnung im Vertrag.',
            'impact' => 'Ein nicht gedeckter Schaden bleibt an Ihnen hängen.',
        ],
        'local_risks' => [
            'label' => 'Information über Risiken',
            'definition' => 'Information des Gastes über natürliche und technologische Risiken der Umgebung.',
            'applicability' => 'Je nach Gemeinde und Zonierung.',
            'where' => 'Öffentlicher Dienst für Risikoinformation.',
            'impact' => 'Die Information muss verfügbar und aktuell sein, wo sie verlangt wird.',
        ],
        'clearing' => [
            'label' => 'Freischneiden',
            'definition' => 'Gesetzliche Pflicht, den Bewuchs rund um Gebäude zu entfernen.',
            'applicability' => 'In Gebieten mit Waldbrandrisiko.',
            'where' => 'Gemeinde und Präfektur, über die Verordnung des Departements.',
            'impact' => 'Ein Verstoß führt zu einem Bußgeld und zu Ihrer Haftung.',
        ],
        'winter_equipment' => [
            'label' => 'Winterausrüstung',
            'definition' => 'Pflicht zur Ausrüstung von Fahrzeugen in der Winterzeit.',
            'applicability' => 'In den Gemeinden, die unter die Bergregelung fallen.',
            'where' => 'Präfektur des Departements.',
            'impact' => 'Dem Gast vor der Anreise mitteilen: er wird kontrolliert.',
        ],
        'waste' => [
            'label' => 'Abfall',
            'definition' => 'Örtliche Regeln für Trennung, Bereitstellung und Abholung.',
            'applicability' => 'Immer, mit Regeln je Gemeinde.',
            'where' => 'Die für die Abholung zuständige Körperschaft.',
            'impact' => 'Klare Hinweise verhindern wildes Ablagern und Bußgelder.',
        ],
    ],
];
