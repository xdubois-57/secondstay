<?php

declare(strict_types=1);

/**
 * Exploitatie: lokale beheerder, checklists en het „Te doen”-overzicht.
 */

return [
    'title' => 'Exploitatie',
    'todo' => [
        'title' => 'Te doen',
        'empty' => 'Er vraagt niets uw aandacht.',
        'bookings_to_confirm' => 'Aanvragen te bevestigen',
        'payments_overdue' => 'Achterstallige termijnen',
        'deposits_to_return' => 'Terug te storten waarborgen',
        'mail_unlinked' => 'Niet-gekoppelde berichten',
        'stays_to_prepare' => 'Voor te bereiden verblijven',
        'migrations_pending' => 'Openstaande migraties',
    ],
    'phase' => [
        'before' => 'Voor het verblijf',
        'departure' => 'Bij vertrek',
    ],
    'item' => [
        'contract' => 'Overeenkomst aanvaard',
        'deposit' => 'Voorschot geïnd',
        'balance' => 'Saldo geïnd',
        'security_deposit' => 'Waarborg ontvangen',
        'manager' => 'Beheerder toegewezen',
        'cleaning_scheduled' => 'Schoonmaak gepland',
        'access_shared' => 'Toegangsgegevens verstuurd',
        'welcome_sent' => 'Welkomstbericht verstuurd',
        'inventory_done' => 'Plaatsbeschrijving opgemaakt',
        'incidents_reviewed' => 'Incidenten nagekeken',
        'cleaning_done' => 'Schoonmaak uitgevoerd',
        'deposit_settled' => 'Waarborg afgehandeld',
    ],
    'manager' => [
        'title' => 'Lokale beheerder',
        'contact' => 'Lokale beheerder',
        'assign' => 'Toewijzen',
        'assigned' => 'Beheerder toegewezen.',
        'unassigned' => 'Geen beheerder',
        'default' => 'Standaardbeheerder',
        'none' => '— geen —',
        'my_stays' => 'Mijn verblijven',
        'empty' => 'Er is u geen verblijf toegewezen.',
    ],
    'checklist' => [
        'title' => 'Checklist',
        'progress' => '{done} van {total}',
        'derived' => 'Automatisch opgevolgd',
        'save' => 'Opslaan',
        'updated' => 'Checklist bijgewerkt.',
        'note' => 'Opmerking',
    ],
    'prepare' => [
        'title' => 'Voor te bereiden verblijven',
        'empty' => 'Geen nakend verblijf wacht op voorbereiding.',
        'arrival' => 'Aankomst',
        'remaining' => 'Nog te doen',
    ],
    'error' => [
        'unknown_item' => 'Onbekend checklistonderdeel.',
        'manager_invalid' => 'Dat account is geen lokale beheerder.',
        'booking_not_found' => 'Verblijf niet gevonden.',
    ],
];
