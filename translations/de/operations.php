<?php

declare(strict_types=1);

/**
 * Betrieb: Ansprechpartner vor Ort, Checklisten und „Zu erledigen“.
 */

return [
    'title' => 'Betrieb',
    'todo' => [
        'title' => 'Zu erledigen',
        'empty' => 'Nichts erfordert Ihre Aufmerksamkeit.',
        'bookings_to_confirm' => 'Zu bestätigende Anfragen',
        'payments_overdue' => 'Überfällige Fälligkeiten',
        'deposits_to_return' => 'Zurückzuzahlende Kautionen',
        'mail_unlinked' => 'Nicht zugeordnete Nachrichten',
        'stays_to_prepare' => 'Vorzubereitende Aufenthalte',
        'incidents_open' => 'Offene Vorfälle',
        'compliance_to_verify' => 'Konformität zu prüfen',
        'migrations_pending' => 'Ausstehende Migrationen',
    ],
    'phase' => [
        'before' => 'Vor dem Aufenthalt',
        'departure' => 'Bei der Abreise',
    ],
    'item' => [
        'contract' => 'Vertrag angenommen',
        'deposit' => 'Anzahlung eingezogen',
        'balance' => 'Restbetrag eingezogen',
        'security_deposit' => 'Kaution erhalten',
        'manager' => 'Ansprechpartner zugewiesen',
        'cleaning_scheduled' => 'Reinigung geplant',
        'access_shared' => 'Zugangsdaten übermittelt',
        'welcome_sent' => 'Willkommensnachricht gesendet',
        'inventory_done' => 'Übergabeprotokoll erstellt',
        'incidents_reviewed' => 'Vorfälle geprüft',
        'cleaning_done' => 'Reinigung erledigt',
        'deposit_settled' => 'Kaution abgerechnet',
    ],
    'manager' => [
        'title' => 'Ansprechpartner vor Ort',
        'contact' => 'Ansprechpartner vor Ort',
        'assign' => 'Zuweisen',
        'assigned' => 'Ansprechpartner zugewiesen.',
        'unassigned' => 'Kein Ansprechpartner',
        'default' => 'Standard-Ansprechpartner',
        'none' => '— keiner —',
        'my_stays' => 'Meine Aufenthalte',
        'empty' => 'Ihnen ist kein Aufenthalt zugewiesen.',
    ],
    'checklist' => [
        'title' => 'Checkliste',
        'progress' => '{done} von {total}',
        'derived' => 'Automatisch verfolgt',
        'save' => 'Speichern',
        'updated' => 'Checkliste aktualisiert.',
        'note' => 'Anmerkung',
    ],
    'prepare' => [
        'title' => 'Vorzubereitende Aufenthalte',
        'empty' => 'Kein bevorstehender Aufenthalt wartet auf Vorbereitung.',
        'arrival' => 'Anreise',
        'remaining' => 'Noch zu erledigen',
    ],
    'error' => [
        'unknown_item' => 'Unbekannter Checklistenpunkt.',
        'manager_invalid' => 'Dieses Konto ist kein Ansprechpartner vor Ort.',
        'booking_not_found' => 'Aufenthalt nicht gefunden.',
    ],
];
