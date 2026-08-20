<?php

declare(strict_types=1);

/**
 * „Mein Aufenthalt heute“, Willkommensmappe und Gästelinks.
 */

return [
    'title' => 'Mein Aufenthalt',
    'today' => 'Mein Aufenthalt heute',
    'reference' => 'Referenz',
    'dates' => 'Daten',
    'checkin' => 'Anreise ab',
    'checkout' => 'Abreise vor',
    'phase' => [
        'before' => 'Vor Ihrem Aufenthalt',
        'arrival' => 'Anreisetag',
        'during' => 'Während Ihres Aufenthalts',
        'departure' => 'Abreisetag',
        'after' => 'Nach Ihrem Aufenthalt',
    ],
    'countdown' => [
        'today' => 'Es ist heute.',
        'tomorrow' => 'Es ist morgen.',
        'days' => 'In {count} Tag|In {count} Tagen',
        'past' => 'Aufenthalt beendet.',
    ],
    'block' => [
        'welcome' => 'Willkommen',
        'access' => 'Ankommen und hineinkommen',
        'wifi' => 'WLAN',
        'appliances' => 'Geräte',
        'waste' => 'Abfall und Trennung',
        'rules' => 'Hausordnung',
        'safety' => 'Sicherheit',
        'checkout' => 'Bevor Sie abreisen',
    ],
    'secret' => [
        'title' => 'Zugangscodes',
        'wifi_password' => 'WLAN-Passwort',
        'key_box' => 'Schlüsselkasten',
        'alarm' => 'Alarmanlage',
        'gate' => 'Tor',
        'hidden' => 'Die Zugangscodes erscheinen hier an Ihrem Anreisetag.',
        'shown_during' => 'Nur während Ihres Aufenthalts sichtbar.',
    ],
    'manager' => [
        'title' => 'Kontakt vor Ort',
        'none' => 'Es ist noch kein Ansprechpartner vor Ort benannt.',
    ],
    'offline' => [
        'ready' => 'Diese Seite bleibt ohne Netz abrufbar.',
        'stale' => 'Offline-Ansicht: die Angaben können veraltet sein.',
    ],
    'guest' => [
        'title' => 'Mit meinen Gästen teilen',
        'intro' => 'Ein Gästelink gibt Zugang zu diesen praktischen Angaben — und zu sonst nichts: keine Beträge, keine Dokumente, kein Konto.',
        'create' => 'Gästelink erstellen',
        'label' => 'Für wen?',
        'created' => 'Gästelink erstellt. Kopieren Sie ihn jetzt: er wird nicht erneut angezeigt.',
        'revoked' => 'Gästelink widerrufen.',
        'revoke' => 'Widerrufen',
        'expires' => 'Läuft ab am',
        'never_used' => 'Nie verwendet',
        'empty' => 'Kein aktiver Gästelink.',
        'qr' => 'QR-Code zum Ausdrucken',
        'qr_alt' => 'QR-Code des Gästelinks',
        'banner' => 'Sie sehen diesen Aufenthalt über einen Gästelink.',
    ],
    'admin' => [
        'title' => 'Willkommensmappe',
        'intro' => 'Diese Texte erscheinen in „Mein Aufenthalt“ und hinter Gästelinks. Sie sind offline verfügbar.',
        'block_title' => 'Titel',
        'block_body' => 'Text',
        'published' => 'Veröffentlicht',
        'save' => 'Speichern',
        'saved' => 'Willkommensmappe gespeichert.',
        'secrets' => 'Zugangscodes',
        'secrets_intro' => 'Verschlüsselt gespeichert und nie erneut angezeigt. Ein leeres Feld behält den bestehenden Wert.',
        'secrets_saved' => 'Zugangscodes gespeichert.',
        'clear' => 'Löschen',
        'not_set' => 'Nicht gesetzt',
        'completeness' => 'Vollständigkeit',
        'language' => 'Sprache',
    ],
    'error' => [
        'not_active' => 'Dieser Aufenthalt ist nicht mehr aktiv.',
        'link_not_found' => 'Gästelink nicht gefunden.',
        'not_found' => 'Aufenthalt nicht gefunden.',
    ],
];
