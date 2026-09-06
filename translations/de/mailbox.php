<?php

declare(strict_types=1);

/**
 * Eingehende Post, Zuordnung zum Aufenthalt und Kommunikationsverlauf.
 */

return [
    'title' => 'Nachrichten',
    'inbound' => 'Eingehende Post',
    'timeline' => 'Schriftwechsel',
    'empty' => 'Keine Nachricht.',
    'unlinked' => 'Nicht zugeordnete Nachrichten',
    'unlinked_empty' => 'Alle empfangenen Nachrichten sind zugeordnet.',
    'from' => 'Von',
    'to' => 'An',
    'subject' => 'Betreff',
    'received' => 'Empfangen am',
    'sent' => 'Gesendet am',
    'attachments' => 'Anhänge',
    'direction' => [
        'inbound' => 'Empfangen',
        'outbound' => 'Gesendet',
    ],
    'link' => [
        'title' => 'Zuordnung',
        'none' => 'Nicht zugeordnet',
        'token' => 'Signierte Antwortadresse',
        'thread' => 'Thread-Kopfzeilen',
        'reference' => 'Genannte Referenz',
        'sender' => 'Absenderadresse',
        'manual' => 'Manuell zugeordnet',
    ],
    'action' => [
        'link' => 'Zuordnen',
        'sync' => 'Postfach abrufen',
        'view' => 'Nachricht ansehen',
        'reference' => 'Referenz des Aufenthalts',
    ],
    'sync' => [
        'done' => 'Abruf beendet: {imported} Nachricht(en), {linked} zugeordnet, {documents} Dokument(e).',
        'nothing' => 'Keine neue Nachricht.',
        'disabled' => 'Der Postfachabruf ist nicht aktiviert.',
    ],
    'linked' => 'Nachricht dem Aufenthalt zugeordnet.',
    'reply_address' => 'Antwortadresse',
    'reply_hint' => 'Antworten Sie einfach auf diese Nachricht: Ihre Antwort wird automatisch Ihrem Aufenthalt '
        . 'zugeordnet.',
    'error' => [
        'not_found' => 'Nachricht nicht gefunden.',
        'booking_not_found' => 'Aufenthalt nicht gefunden.',
        'not_configured' => 'Das Postfach ist nicht konfiguriert.',
        'connection_failed' => 'Verbindung zum Postfach nicht möglich.',
        'greeting' => 'Der Server hat nicht korrekt geantwortet.',
        'tls_failed' => 'Die gesicherte Verbindung ist fehlgeschlagen.',
        'command_failed' => 'Der Server hat den Befehl abgelehnt.',
        'connection_lost' => 'Verbindung unterbrochen.',
        'timeout' => 'Der Server antwortet nicht mehr.',
        'too_large' => 'Nachricht zu groß.',
        'write_failed' => 'Schreiben zum Server nicht möglich.',
    ],
    'verify' => [
        'ok' => 'Postfach erreichbar.',
    ],
];
