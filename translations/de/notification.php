<?php

declare(strict_types=1);

/**
 * Notifications : sujet d'e-mail, titre et texte poussés, libellé d'action.
 *
 * Chaque événement expose les mêmes clés : ajouter un événement ne demande
 * aucun gabarit supplémentaire.
 */

return [
    'title' => 'Benachrichtigungen',
    'intro' => 'Wählen Sie, wie Sie informiert werden möchten. Wichtige Nachrichten zu Ihrem Aufenthalt werden immer per E-Mail versendet.',
    'send_test' => 'Testbenachrichtigung senden',
    'test_sent' => 'Testbenachrichtigung gesendet.',
    'test_no_device' => 'Kein angemeldetes Gerät für diesen Test.',
    'saved' => 'Benachrichtigungseinstellungen gespeichert.',
    'devices' => 'Geräte, die Benachrichtigungen erhalten',
    'no_device' => 'Kein angemeldetes Gerät.',
    'channel' => [
        'email' => 'E-Mail',
        'push' => 'Push-Benachrichtigungen',
    ],
    'push' => [
        'enable' => 'Benachrichtigungen auf diesem Gerät aktivieren',
        'disable' => 'Auf diesem Gerät deaktivieren',
        'enabled' => 'Benachrichtigungen auf diesem Gerät aktiviert.',
        'disabled' => 'Benachrichtigungen auf diesem Gerät deaktiviert.',
        'unsupported' => 'Ihr Browser unterstützt keine Benachrichtigungen.',
        'denied' => 'Benachrichtigungen wurden in den Browsereinstellungen blockiert.',
        'unavailable' => 'Push-Benachrichtigungen sind auf dieser Website nicht aktiviert.',
    ],
    'mail' => [
        'preferences' => 'Sie können Ihre Benachrichtigungen in Ihrem Kundenbereich einstellen.',
    ],
    'test' => [
        'subject' => 'Testbenachrichtigung',
        'title' => 'Testbenachrichtigung',
        'body' => 'Wenn Sie dies lesen, empfängt dieses Gerät Benachrichtigungen.',
        'mail_body' => 'Diese Nachricht bestätigt, dass Ihre Adresse Benachrichtigungen von {property} empfängt.',
        'action' => 'Meinen Bereich öffnen',
    ],
    'account_confirmed' => [
        'subject' => 'Ihr Konto ist aktiv',
        'title' => 'Willkommen {first_name}',
        'body' => 'Willkommen {first_name}, Ihr Konto für {property} ist bestätigt.',
        'mail_body' => 'Ihre E-Mail-Adresse ist bestätigt: Sie können Ihren Aufenthalt in Ihrem Kundenbereich verfolgen.',
        'action' => 'Meinen Bereich öffnen',
    ],
    'booking_created' => [
        'subject' => 'Buchungsanfrage erfasst',
        'title' => 'Anfrage erfasst',
        'body' => 'Ihre Anfrage für {property} wurde erfasst.',
        'mail_body' => 'Wir haben Ihre Buchungsanfrage erhalten. Sie erhalten eine Bestätigung, sobald sie freigegeben ist.',
        'action' => 'Meine Buchung ansehen',
    ],
    'booking_confirmed' => [
        'subject' => 'Buchung bestätigt',
        'title' => 'Buchung bestätigt',
        'body' => 'Ihr Aufenthalt in {property} ist bestätigt.',
        'mail_body' => 'Ihr Aufenthalt ist bestätigt. Praktische Hinweise stehen vor Ihrer Anreise bereit.',
        'action' => 'Meine Buchung ansehen',
    ],
    'payment_received' => [
        'subject' => 'Zahlung erhalten',
        'title' => 'Zahlung erhalten',
        'body' => 'Ihre Zahlung für {property} wurde erfasst.',
        'mail_body' => 'Ihre Zahlung wurde erfasst. Der Beleg liegt in Ihren Dokumenten.',
        'action' => 'Meine Dokumente ansehen',
    ],
    'stay_reminder' => [
        'subject' => 'Ihr Aufenthalt steht bevor',
        'title' => 'Ihr Aufenthalt steht bevor',
        'body' => 'Ihr Aufenthalt in {property} beginnt bald.',
        'mail_body' => 'Anreisehinweise, Zugangscodes und das Willkommensbuch finden Sie in Ihrem Bereich.',
        'action' => 'Aufenthalt vorbereiten',
    ],
    'arrival' => [
        'subject' => 'Anreisetag',
        'title' => 'Willkommen',
        'body' => 'Willkommen in {property}.',
        'mail_body' => 'Alles für Ihre Anreise finden Sie in Ihrem Bereich.',
        'action' => 'Anreisehinweise ansehen',
    ],
    'departure' => [
        'subject' => 'Abreisetag',
        'title' => 'Abreisetag',
        'body' => 'Ihre Abreise aus {property} ist für heute geplant.',
        'mail_body' => 'Bitte befolgen Sie die Abreisehinweise in Ihrem Bereich.',
        'action' => 'Abreisehinweise ansehen',
    ],
    'incident' => [
        'subject' => 'Vorfall gemeldet',
        'title' => 'Vorfall gemeldet',
        'body' => 'In {property} wurde ein Vorfall gemeldet.',
        'mail_body' => 'Soeben wurde ein Vorfall gemeldet. Öffnen Sie den Eintrag, um über das weitere Vorgehen zu entscheiden.',
        'action' => 'Vorfall ansehen',
    ],
    'task_assigned' => [
        'subject' => 'Neue Aufgabe',
        'title' => 'Neue Aufgabe',
        'body' => 'Ihnen wurde eine Aufgabe für {property} zugewiesen.',
        'mail_body' => 'Ihnen wurde soeben eine Aufgabe zugewiesen. Sie ist in Ihrem Dashboard sichtbar.',
        'action' => 'Aufgabe ansehen',
    ],
];
