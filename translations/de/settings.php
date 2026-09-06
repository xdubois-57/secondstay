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
            'help' => 'Registriernummer, im Vertrag ausgewiesen, sofern ausgefüllt.',
        ],
        'name' => [
            'label' => 'Name des Objekts',
            'help' => 'Öffentlich angezeigter Name Ihres Ferienhauses.',
        ],
        'address_line1' => [
            'label' => 'Adresse (Zeile 1)',
            'help' => 'Hausnummer und Straße des Objekts.',
        ],
        'address_line2' => [
            'label' => 'Adresse (Zeile 2)',
            'help' => 'Adresszusatz: Ortsteil, Gebäude, Etage.',
        ],
        'postal_code' => [
            'label' => 'Postleitzahl',
            'help' => 'Französische Postleitzahl des Objekts.',
        ],
        'city' => [
            'label' => 'Gemeinde',
            'help' => 'Gemeinde des Objekts. Wird auch für die Kurtaxe verwendet.',
        ],
        'country' => [
            'label' => 'Land',
            'help' => 'SecondStay ist auf Frankreich spezialisiert.',
        ],
        'latitude' => [
            'label' => 'Breitengrad',
            'help' => 'Koordinate für lokale Inhalte und Anfahrt.',
        ],
        'longitude' => [
            'label' => 'Längengrad',
            'help' => 'Koordinate für lokale Inhalte und Anfahrt.',
        ],
        'contact_email' => [
            'label' => 'Kontakt-E-Mail',
            'help' => 'Adresse, die Gästen für allgemeine Fragen angezeigt wird.',
        ],
        'contact_phone' => [
            'label' => 'Kontakttelefon',
            'help' => 'Nummer, die Gästen angezeigt wird.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Standardsprache',
            'help' => 'Sprache, wenn keine Präferenz bekannt ist.',
        ],
        'timezone' => [
            'label' => 'Zeitzone',
            'help' => 'Zone für An- und Abreisezeiten sowie Erinnerungen.',
        ],
        'public_url' => [
            'label' => 'Öffentliche URL',
            'help' => 'Öffentliche Adresse der Website, in E-Mails und Links verwendet.',
        ],
        'season' => [
            'label' => 'Angezeigte Saison',
            'help' => 'Automatisch folgt dem Datum; Sommer oder Winter erzwingt eine feste Darstellung.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Mindestanzahl Nächte',
            'help' => 'Minimal akzeptierte Aufenthaltsdauer.',
        ],
        'max_guests' => [
            'label' => 'Maximale Kapazität',
            'help' => 'Gesamtzahl akzeptierter Gäste, Babys je nach Ihrer Regel inbegriffen.',
        ],
        'checkin_time' => [
            'label' => 'Anreisezeit',
            'help' => 'Uhrzeit, ab der das Objekt verfügbar ist.',
        ],
        'checkout_time' => [
            'label' => 'Abreisezeit',
            'help' => 'Späteste Uhrzeit für die Räumung des Objekts.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Samstag-zu-Samstag-Regel',
            'help' => 'Erzwingt Aufenthalte von Samstag bis Samstag. Deaktivierbar.',
        ],
        'hold_minutes' => [
            'label' => 'Dauer der vorläufigen Reservierung',
            'help' => 'Minuten, in denen Termine bis zur Bestätigung blockiert bleiben.',
        ],
        'requires_approval' => [
            'label' => 'Freigabe durch die Eigentümer',
            'help' => 'Jede Buchungsanfrage wartet auf Ihre Zustimmung. Deaktivieren Sie dies, um verfügbare '
                . 'Aufenthalte automatisch zu bestätigen.',
        ],
        'allow_waitlist' => [
            'label' => 'Warteliste',
            'help' => 'Ein Besucher kann darum bitten, benachrichtigt zu werden, wenn belegte Termine frei werden.',
        ],
        'min_adults' => [
            'label' => 'Mindestanzahl Erwachsene',
            'help' => 'Mindestanzahl Erwachsener pro Aufenthalt.',
        ],
        'max_children' => [
            'label' => 'Höchstzahl Kinder',
            'help' => 'Höchstzahl der Kinder zusätzlich zu den Erwachsenen.',
        ],
        'max_infants' => [
            'label' => 'Höchstzahl Kleinkinder',
            'help' => 'Kleinkinder zählen nicht zur Schlafkapazität.',
        ],
        'night_multiple' => [
            'label' => 'Nächteblöcke',
            'help' => 'Erzwingt eine Dauer als Vielfaches dieser Zahl. 0 deaktiviert die Regel, 7 erzwingt ganze '
                . 'Wochen.',
        ],
        'max_nights' => [
            'label' => 'Maximale Dauer',
            'help' => 'Höchstzahl der Nächte pro Aufenthalt.',
        ],
        'arrival_weekday' => [
            'label' => 'Fester Anreisetag',
            'help' => 'Beschränkt Anreisen auf einen Wochentag. Die Einstellung Samstag-Samstag hat Vorrang.',
        ],
        'advance_days' => [
            'label' => 'Vorlaufzeit',
            'help' => 'Mindestanzahl Tage zwischen heute und einer Anreise.',
        ],
        'horizon_days' => [
            'label' => 'Buchungshorizont',
            'help' => 'Anzahl Tage, ab denen der Kalender noch nicht geöffnet ist.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Standardpreis pro Nacht',
            'help' => 'Gilt für jedes Datum ohne besonderen Tarif.',
        ],
        'cleaning_mode' => [
            'label' => 'Reinigungsmodus',
            'help' => 'Keine, optional oder verpflichtend. Standardmäßig verpflichtend.',
        ],
        'cleaning_price' => [
            'label' => 'Reinigungspreis',
            'help' => 'Betrag der Reinigungspauschale. Standardwert: 100 €.',
        ],
        'deposit_percent' => [
            'label' => 'Anzahlung (%)',
            'help' => 'Anteil des Aufenthalts, der zur Bestätigung erforderlich ist.',
        ],
        'security_deposit' => [
            'label' => 'Kaution',
            'help' => 'Kautionsbetrag, der vor dem Aufenthalt verlangt wird.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Geplante Wartung',
            'help' => 'Schließt die öffentliche Website. Die Verwaltung bleibt erreichbar.',
        ],
        'message' => [
            'label' => 'Wartungsmeldung',
            'help' => 'Interne Notiz zur Begründung der Wartung.',
        ],
    ],
    'scheduler' => [
        'http_token' => [
            'label' => 'Token für den Planer',
            'help' => 'Nur ausfüllen, wenn Ihr Hoster Cron ausschließlich per URL anbietet. Leer existiert die '
                . 'Auslöse-URL nicht.',
        ],
    ],
    'backup' => [
        'auto_enabled' => [
            'label' => 'Automatische Sicherung',
            'help' => 'Lässt den Planer täglich eine Sicherung erstellen.',
        ],
        'retention_count' => [
            'label' => 'Aufbewahrte Sicherungen',
            'help' => 'Anzahl der Sicherungen vor automatischer Löschung.',
        ],
        'include_media' => [
            'label' => 'Medien einschließen',
            'help' => 'Fügt Fotos, Dokumente und Anhänge zur Sicherung hinzu.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Aktualisierungskanal',
            'help' => 'Stabil installiert nur veröffentlichte Versionen.',
        ],
        'auto_install' => [
            'label' => 'Automatische Aktualisierung',
            'help' => 'Installiert neue geprüfte Versionen automatisch.',
        ],
        'repository' => [
            'label' => 'Release-Repository',
            'help' => 'GitHub-Repository mit installierbaren Artefakten.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Protokollierungsstufe',
            'help' => 'Mindestschwere protokollierter Meldungen.',
        ],
        'retention_days' => [
            'label' => 'Protokollaufbewahrung (Tage)',
            'help' => 'Aufbewahrungsdauer vor automatischer Bereinigung.',
        ],
    ],
    'error' => [
        'token_too_short' => 'Das Token muss mindestens 32 Zeichen lang sein.',
        'required' => 'Diese Einstellung ist erforderlich.',
        'unknown' => 'Unbekannte Einstellung.',
        'integer' => 'Geben Sie eine ganze Zahl ein.',
        'decimal' => 'Geben Sie eine Dezimalzahl ein.',
        'money' => 'Geben Sie einen gültigen Betrag ein, zum Beispiel 100,00.',
        'enum' => 'Wert nicht zulässig.',
        'email' => 'Ungültige E-Mail-Adresse.',
        'url' => 'Ungültige URL.',
        'url_scheme' => 'Nur http(s)-URLs werden akzeptiert.',
        'date' => 'Ungültiges Datum (erwartetes Format: JJJJ-MM-TT).',
        'time' => 'Ungültige Uhrzeit (erwartetes Format: HH:MM).',
        'duration' => 'Ungültige Dauer (in Minuten).',
        'json' => 'Ungültiges JSON.',
        'iban' => 'Ungültige IBAN: Prüfziffer kontrollieren.',
        'bic' => 'Ungültiger BIC (8 oder 11 Zeichen).',
        'currency' => 'Ungültige Währung: dreistelliger ISO-4217-Code erwartet.',
        'color' => 'Ungültige Farbe: Verwenden Sie das Format #rrggbb.',
        'too_long' => 'Wert ist zu lang.',
        'too_small' => 'Wert ist zu klein.',
        'too_large' => 'Wert ist zu groß.',
    ],
    'mail' => [
        'from_address' => [
            'label' => 'Absenderadresse',
            'help' => 'Adresse, die als Absender der E-Mails angezeigt wird.',
        ],
        'from_name' => [
            'label' => 'Absendername',
            'help' => 'Name, der neben der Absenderadresse angezeigt wird.',
        ],
        'reply_to' => [
            'label' => 'Antwortadresse',
            'help' => 'Überwachtes Postfach, das Antworten der Gäste empfängt.',
        ],
        'smtp_host' => [
            'label' => 'SMTP-Server',
            'help' => 'Host Ihres E-Mail-Versanddienstes.',
        ],
        'smtp_port' => [
            'label' => 'SMTP-Port',
            'help' => '587 mit STARTTLS, 465 mit implizitem TLS.',
        ],
        'smtp_encryption' => [
            'label' => 'SMTP-Verschlüsselung',
            'help' => 'STARTTLS wird empfohlen. Das Serverzertifikat wird immer geprüft.',
        ],
        'smtp_username' => [
            'label' => 'SMTP-Benutzer',
            'help' => 'Anmeldename für den SMTP-Server.',
        ],
        'smtp_password' => [
            'label' => 'SMTP-Passwort',
            'help' => 'Verschlüsselt gespeichert und nie erneut angezeigt. Leer lassen, um den aktuellen Wert zu '
                . 'behalten.',
        ],
        'dkim_selector' => [
            'label' => 'DKIM-Selektor',
            'help' => 'Vom Versanddienst vorgegebener Selektor (oft „default“ oder „mail“). Er dient nur der '
                . 'DNS-Diagnose: Das Signieren bleibt Sache des Anbieters.',
        ],
    ],
    'notification' => [
        'reminder_days' => [
            'label' => 'Erinnerung an den Aufenthalt (Tage vor Anreise)',
            'help' => 'Anzahl der Tage zwischen dem Versand der Erinnerung und der Anreise des Gastes.',
        ],
        'push_enabled' => [
            'label' => 'Push-Benachrichtigungen',
            'help' => 'Erlaubt Browsern, Benachrichtigungen zu empfangen. Die E-Mail wird in jedem Fall versendet.',
        ],
        'retention_days' => [
            'label' => 'Aufbewahrung des Benachrichtigungsprotokolls',
            'help' => 'Aufbewahrungsdauer der Versandspuren in Tagen.',
        ],
    ],
    'push' => [
        'subject' => [
            'label' => 'Push-Kontakt',
            'help' => 'Kontakt-E-Mail-Adresse oder URL, die den Push-Diensten übermittelt wird, wie es die Norm '
                . 'verlangt. Ist das Feld leer, wird die Absenderadresse verwendet.',
        ],
        'vapid_public' => [
            'label' => 'Öffentlicher VAPID-Schlüssel',
            'help' => 'Von der Installation erzeugt und an die Browser übergeben. Ein Austausch macht alle bestehenden '
                . 'Abonnements ungültig.',
        ],
        'vapid_private' => [
            'label' => 'Privater VAPID-Schlüssel',
            'help' => 'Verschlüsselt gespeichert und nie erneut angezeigt. Er signiert die Zustellungen an die '
                . 'Push-Dienste.',
        ],
    ],
    'account' => [
        'allow_signup' => [
            'label' => 'Registrierungen zulassen',
            'help' => 'Erlaubt Gästen, über die öffentliche Website ein Konto zu erstellen.',
        ],
        'allow_passkeys' => [
            'label' => 'Passkeys zulassen',
            'help' => 'Aktiviert die passwortlose Anmeldung mit Passkeys (WebAuthn).',
        ],
        'require_email_confirmation' => [
            'label' => 'E-Mail-Bestätigung erforderlich',
            'help' => 'Das Konto bleibt inaktiv, bis die Adresse bestätigt ist.',
        ],
    ],
    'payment' => [
        'provider' => [
            'label' => 'Zahlungsanbieter',
            'help' => 'Mollie zieht online ein und bestätigt die Buchung automatisch. Ohne Anbieter bleibt nur die '
                . 'Überweisung.',
        ],
        'mollie_api_key' => [
            'label' => 'Mollie-API-Schlüssel',
            'help' => 'Verschlüsselt gespeichert und nie erneut angezeigt. Ein „test_“-Schlüssel zieht nichts wirklich '
                . 'ein.',
        ],
        'balance_days_before' => [
            'label' => 'Restbetrag fällig (Tage vor Anreise)',
            'help' => 'Der Restbetrag wird so viele Tage vor der Anreise fällig, bei späterer Buchung sofort.',
        ],
        'transfer_enabled' => [
            'label' => 'Banküberweisung erlauben',
            'help' => 'Zeigt IBAN und EPC-QR-Code. Eine Überweisung bestätigt eine Buchung nie von allein.',
        ],
        'beneficiary_name' => [
            'label' => 'Zahlungsempfänger',
            'help' => 'Name des Kontoinhabers, so wie er in der Banking-App des Gastes erscheint.',
        ],
        'iban' => [
            'label' => 'IBAN',
            'help' => 'IBAN des zu begünstigenden Kontos. Die Prüfziffer wird vor dem Speichern geprüft.',
        ],
        'bic' => [
            'label' => 'BIC',
            'help' => 'Optional. Manche Banken verlangen ihn noch für Überweisungen außerhalb des SEPA-Raums.',
        ],
        'currency' => [
            'label' => 'Währung',
            'help' => 'Dreistelliger ISO-4217-Code, standardmäßig EUR.',
        ],
    ],
    'tax' => [
        'territory' => [
            'label' => 'Erhebungsgebiet',
            'help' => 'Gemeinde oder Verband, der die Kurtaxe erhebt.',
        ],
        'classification' => [
            'label' => 'Einstufung der Unterkunft',
            'help' => 'Der Tarif hängt von der Einstufung als touristische Ferienwohnung ab.',
        ],
        'tourist_enabled' => [
            'label' => 'Kurtaxe erheben',
            'help' => 'Fügt die Kurtaxe dem Zahlungsplan jeder Buchung hinzu.',
        ],
        'tourist_per_adult_night' => [
            'label' => 'Kurtaxe je Erwachsenem und Nacht',
            'help' => 'Betrag je Erwachsenem und Nacht. Minderjährige sind befreit.',
        ],
        'tourist_cap_per_stay' => [
            'label' => 'Obergrenze je Aufenthalt',
            'help' => 'Höchstbetrag für einen Aufenthalt. Null bedeutet keine Obergrenze.',
        ],
    ],
    'imap' => [
        'enabled' => [
            'label' => 'Postfach abrufen',
            'help' => 'Holt regelmäßig die Antworten der Gäste und ihre Anhänge ab.',
        ],
        'host' => [
            'label' => 'IMAP-Server',
            'help' => 'Hostname des Postfachs der Unterkunft.',
        ],
        'port' => [
            'label' => 'IMAP-Port',
            'help' => '993 für eine verschlüsselte Verbindung, 143 mit STARTTLS.',
        ],
        'encryption' => [
            'label' => 'IMAP-Verschlüsselung',
            'help' => 'Implizites TLS wird empfohlen. Ohne Verschlüsselung ist abzuraten.',
        ],
        'username' => [
            'label' => 'IMAP-Benutzername',
            'help' => 'Konto des abgerufenen Postfachs.',
        ],
        'password' => [
            'label' => 'IMAP-Passwort',
            'help' => 'Verschlüsselt gespeichert und nie erneut angezeigt.',
        ],
        'mailbox' => [
            'label' => 'Abgerufener Ordner',
            'help' => 'INBOX, sofern Antworten nicht in einem eigenen Ordner landen.',
        ],
        'reply_address' => [
            'label' => 'Antwortadresse',
            'help' => 'Den Gästen angezeigte Adresse. Sie wird je Buchung markiert, damit Antworten sich selbst '
                . 'zuordnen.',
        ],
        'uid_validity' => [
            'label' => 'Gültigkeitskennung',
            'help' => 'Wird von der Synchronisation gesetzt. Ändert sie sich, wurde das Postfach neu nummeriert und '
                . 'der Abruf beginnt von vorn.',
        ],
        'batch_size' => [
            'label' => 'Nachrichten je Abruf',
            'help' => 'Wie viele Nachrichten ein Durchlauf verarbeitet. Ein Abruf muss immer enden.',
        ],
    ],
    'legal' => [
        'terms_version' => [
            'label' => 'Version der Bedingungen',
            'help' => 'In jedem angenommenen Vertrag festgehalten. Ändern Sie sie, wenn sich der Text ändert.',
        ],
        'mediator_name' => [
            'label' => 'Verbraucherschlichter',
            'help' => 'Name des im Vertrag genannten Schlichters.',
        ],
        'mediator_url' => [
            'label' => 'Website des Schlichters',
            'help' => 'Wo der Gast eine Streitigkeit vorlegen kann.',
        ],
    ],
    'operations' => [
        'default_manager' => [
            'label' => 'Standard-Ansprechpartner vor Ort',
            'help' => 'Konto-ID des Ansprechpartners für Aufenthalte ohne ausdrückliche Zuweisung. Null bedeutet '
                . 'keiner.',
        ],
        'prepare_days' => [
            'label' => 'Vorbereitungsfenster (Tage)',
            'help' => 'Wie viele Tage vor der Anreise ein Aufenthalt in „Zu erledigen“ erscheint.',
        ],
        'calendar_enabled' => [
            'label' => 'Private Kalender veröffentlichen',
            'help' => 'Aktiviert die ICS-Feeds für Verwaltung, Ansprechpartner und Gäste.',
        ],
    ],
    'inspection' => [
        'report_window_hours' => [
            'label' => 'Meldefrist bei Anreise (Stunden)',
            'help' => 'Wie lange der Gast nach der Anreise Zeit hat, eine Abweichung zu melden.',
        ],
        'guest_enabled' => [
            'label' => 'Übergabeprotokoll durch den Gast',
            'help' => 'Öffnet die Formulare für Anreise und Abreise in „Mein Aufenthalt“.',
        ],
    ],
    'compliance' => [
        'police_record_enabled' => [
            'label' => 'Meldeschein',
            'help' => 'Nur aktivieren, wenn die Pflicht gilt. Solange sie aus ist, wird nichts erhoben.',
        ],
        'police_retention_days' => [
            'label' => 'Aufbewahrung der Meldescheine (Tage)',
            'help' => 'Wie lange nach der Abreise der Meldeschein automatisch gelöscht wird.',
        ],
    ],
    'llm' => [
        'enabled' => [
            'label' => 'Lokale Inhalte aktivieren',
            'help' => 'Erstellt Aktivitätsvorschläge für kommende Aufenthalte aus den von Ihnen angegebenen Quellen.',
        ],
        'provider' => [
            'label' => 'Anbieter',
            'help' => 'Ohne konfigurierten Anbieter wird keine Aktivität erstellt — und keine erfunden.',
        ],
        'api_key' => [
            'label' => 'API-Schlüssel',
            'help' => 'Verschlüsselt gespeichert und nie erneut angezeigt.',
        ],
        'model' => [
            'label' => 'Modell',
            'help' => 'Kennung des für die Generierung verwendeten Modells.',
        ],
        'prompt' => [
            'label' => 'Anweisung',
            'help' => 'Ihre freie Anweisung. Ort, Jahreszeit, Daten, Quellen und Format werden automatisch ergänzt.',
        ],
        'window_weeks' => [
            'label' => 'Fenster vor der Anreise (Wochen)',
            'help' => 'Wie lange vor der Anreise die Inhalte eines Aufenthalts erstellt werden.',
        ],
        'refresh_days' => [
            'label' => 'Aktualisierung (Tage)',
            'help' => 'Abstand zwischen zwei Generierungen bis zum Aufenthalt.',
        ],
    ],
    'pwa' => [
        'theme_color' => [
            'label' => 'Themenfarbe',
            'help' => 'Farbton der Systemleiste, sobald die App installiert ist. Format #rrggbb.',
        ],
        'background_color' => [
            'label' => 'Hintergrundfarbe',
            'help' => 'Wird beim Start angezeigt, vor der ersten Darstellung. Format #rrggbb.',
        ],
    ],
    'quota' => [
        'media_mb' => ['label' => 'Kontingent Medien (MB)', 'help' => 'Null bedeutet keine Grenze.'],
        'documents_mb' => ['label' => 'Kontingent Dokumente (MB)', 'help' => 'Null bedeutet keine Grenze.'],
        'backups_mb' => ['label' => 'Kontingent Sicherungen (MB)', 'help' => 'Null bedeutet keine Grenze.'],
        'attachments_mb' => ['label' => 'Kontingent Anhänge (MB)', 'help' => 'Null bedeutet keine Grenze.'],
    ],
];
