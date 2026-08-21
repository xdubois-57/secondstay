<?php

declare(strict_types=1);

/**
 * Individual police record (SPECIFICATIONS.md §64).
 */

return [
    'title' => 'Police records',
    'record' => 'Police record',
    'open_record' => 'Open the record',
    'intro' => 'The individual record is required only in certain cases. While it is off, no identity data is collected.',
    'record_intro' => 'The data is encrypted and erased automatically when the retention period ends.',
    'enabled' => 'The police record is requested for the stays concerned.',
    'disabled' => 'The police record is disabled: nothing is collected.',
    'configure' => 'Configure',
    'records' => 'Stored records',
    'empty' => 'No record stored.',
    'unreadable' => 'Unreadable record',
    'saved' => 'Record saved.',
    'deleted' => 'Record deleted.',
    'purge_after' => 'Erased on {date}',
    'retention' => 'Retention: {days} days after departure.',
    'field' => [
        'last_name' => 'Surname',
        'first_names' => 'First names',
        'birth_date' => 'Date of birth',
        'birth_place' => 'Place of birth',
        'nationality' => 'Nationality',
        'home_address' => 'Usual address',
        'arrival_date' => 'Arrival date',
        'departure_date' => 'Expected departure date',
    ],
    'error' => [
        'disabled' => 'The police record is not enabled.',
        'incomplete' => 'Surname, first names, date of birth and nationality are required.',
    ],
];
