<?php

declare(strict_types=1);

/**
 * Tourist tax: dated scales and calculation breakdown
 * (SPECIFICATIONS.md §63).
 */

return [
    'title' => 'Tourist tax',
    'intro' => 'A scale is voted, takes effect on a date, then is replaced. Every rule therefore carries its validity '
        . 'period, and a stay already booked keeps the scale that applied on its arrival.',
    'enabled' => 'The tourist tax is collected.',
    'disabled' => 'The tourist tax is not collected.',
    'configure' => 'Configure',
    'current' => 'In force',
    'empty' => 'No dated scale: the configuration acts as the current scale.',
    'new_rule' => 'New scale',
    'rule_created' => 'Scale saved.',
    'rule_deleted' => 'Scale deleted.',
    'overlap_warning' => 'Two scales overlap for the same classification: the amount would depend on the order of the '
        . 'rows.',
    'field' => [
        'period' => 'Period',
        'effective_from' => 'Takes effect on',
        'effective_to' => 'Until',
        'effective_to_help' => 'Leave empty while no next scale is known.',
        'classification' => 'Classification',
        'territory' => 'Territory',
        'per_adult_night' => 'Per adult per night',
        'cap' => 'Cap per stay',
        'taxable_from_age' => 'Taxable from age',
        'source' => 'Official source',
        'notes' => 'Note',
    ],
    'explain' => [
        'title' => 'Tourist tax calculation',
        'per_adult_night' => 'Per adult per night',
        'adults' => 'Adults',
        'exempt' => 'Exempt people',
        'nights' => 'Nights',
        'cap' => 'Cap applied',
        'total' => 'Total',
        'exemption_note' => 'Minors are exempt (article L. 2333-31 of the French general code of local authorities).',
    ],
    'error' => [
        'effective_from' => 'The effective date is required.',
        'period' => 'The end date cannot precede the effective date.',
        'amount' => 'Amounts must be positive numbers.',
    ],
];
