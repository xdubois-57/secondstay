<?php

declare(strict_types=1);

/**
 * Dokumente, die zu einem Aufenthalt gehören.
 */

return [
    'title' => 'Dokumente',
    'empty' => 'Noch kein Dokument zu diesem Aufenthalt.',
    'name' => 'Name',
    'column_kind' => 'Art',
    'column_source' => 'Herkunft',
    'size' => 'Größe',
    'added' => 'Hinzugefügt am',
    'sender' => 'Absender',
    'download' => 'Herunterladen',
    'upload' => 'Dokument hinzufügen',
    'file' => 'Datei',
    'reclassify' => 'Neu einordnen',
    'delete' => 'Löschen',
    'uploaded' => 'Dokument hinzugefügt.',
    'reclassified' => 'Dokument neu eingeordnet.',
    'deleted' => 'Dokument gelöscht.',
    'booking' => 'Aufenthalt',
    'unassigned' => 'Nicht zugeordnet',
    'fingerprint' => 'Prüfsumme',
    'kind' => [
        'contract' => 'Vertrag',
        'signed_contract' => 'Unterschriebener Vertrag',
        'description' => 'Beschreibung',
        'receipt' => 'Quittung',
        'invoice' => 'Rechnung',
        'proof' => 'Nachweis',
        'inventory' => 'Übergabeprotokoll',
        'incident' => 'Vorfall',
        'attachment' => 'Anhang',
        'other' => 'Sonstiges',
    ],
    'source' => [
        'generated' => 'Erzeugt',
        'upload' => 'Hochgeladen',
        'mail' => 'Per E-Mail erhalten',
    ],
    'error' => [
        'empty' => 'Die Datei ist leer.',
        'too_large' => 'Die Datei überschreitet die zulässige Größe.',
        'type' => 'Dieser Dateityp wird nicht akzeptiert.',
        'not_found' => 'Dokument nicht gefunden.',
        'unreadable' => 'Die Datei ist auf dem Server nicht auffindbar.',
        'upload_failed' => 'Der Upload ist fehlgeschlagen.',
    ],
];
