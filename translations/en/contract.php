<?php

declare(strict_types=1);

/**
 * Rental agreement: PDF content and acceptance journey.
 */

return [
    'pdf' => [
        'title' => 'Seasonal rental agreement',
        'subject' => 'Rental agreement for a furnished holiday let',
        'reference' => 'Reference',
        'version' => 'Template version {version} — language {locale}',
        'acceptance_notice' => 'Acceptance of this agreement is recorded electronically, with its date, version and '
            . 'language.',
    ],
    'section' => [
        'parties' => 'The parties',
        'property' => 'The property',
        'stay' => 'The stay',
        'amounts' => 'The amounts',
    ],
    'field' => [
        'owner' => 'Owner',
        'owner_address' => 'Owner’s address',
        'siret' => 'SIRET',
        'guest' => 'Traveller',
        'guest_email' => 'E-mail address',
        'guest_phone' => 'Telephone',
        'address' => 'Address',
        'capacity' => 'Capacity',
        'arrival' => 'Arrival',
        'departure' => 'Departure',
        'nights' => 'Duration',
        'occupants' => 'Occupants',
        'accommodation' => 'Accommodation',
        'cleaning' => 'Cleaning',
        'discount' => 'Discount',
        'total' => 'Stay total',
        'security_deposit' => 'Security deposit',
        'terms_version' => 'Applicable terms and conditions: version {version}.',
    ],
    'table' => [
        'component' => 'Component',
        'due_on' => 'Due',
        'amount' => 'Amount',
    ],
    'value' => [
        'occupants' => '{adults} adult(s), {children} child(ren), {infants} infant(s)',
        'guests' => '{count} person|{count} people',
        'nights' => '{count} night|{count} nights',
    ],
    'clause' => [
        'cancellation' => [
            'title' => 'Cancellation',
            'body' => 'The traveller may cancel at any time. Amounts already paid are refunded or retained according '
                . 'to the terms and conditions in force at the date of booking, whose version is stated below. If the '
                . 'owner cancels, every amount paid is refunded in full.',
        ],
        'inventory' => [
            'title' => 'Inventory',
            'body' => 'An inventory is drawn up on arrival and on departure. Without an observation from the traveller '
                . 'within twenty-four hours of arrival, the property is deemed to match the arrival inventory.',
        ],
        'rules' => [
            'title' => 'Use of the property',
            'body' => 'The property is let furnished, for temporary residential use only. The number of occupants may '
                . 'not exceed the stated capacity. Subletting is prohibited.',
        ],
        'liability' => [
            'title' => 'Liability and insurance',
            'body' => 'The traveller is answerable for damage caused during the stay and declares being covered by '
                . 'public liability insurance for the duration of the rental.',
        ],
        'data' => [
            'title' => 'Personal data',
            'body' => 'The information collected is used solely to manage the rental. The traveller has a right of '
                . 'access, rectification, portability and erasure, exercisable from their personal area.',
        ],
    ],
    'accept' => [
        'title' => 'Rental agreement',
        'read' => 'Read the agreement',
        'action' => 'I accept the agreement',
        'accepted' => 'Agreement accepted',
        'accepted_on' => 'Accepted on {date}',
        'accepted_version' => 'Version {version}, language {locale}',
        'pending' => 'The agreement is available: read it, then accept it to continue.',
        'confirm' => 'By ticking this box, I accept the agreement as presented to me.',
        'success' => 'Agreement accepted. Thank you.',
        'intact' => 'The accepted document is intact.',
        'altered' => 'The accepted document no longer matches its fingerprint.',
    ],
    'error' => [
        'not_owner' => 'This agreement does not concern you.',
        'already_accepted' => 'This agreement has already been accepted.',
        'unavailable' => 'The agreement could not be produced.',
        'not_accepted' => 'The agreement must be accepted.',
    ],
];
