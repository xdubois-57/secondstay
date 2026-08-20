<?php

declare(strict_types=1);

/**
 * Arrival and departure inspections (SPECIFICATIONS.md §53).
 */

return [
    'title' => 'Inspections',
    'kind' => [
        'checkin' => 'Arrival inspection',
        'checkout' => 'Departure inspection',
    ],
    'state' => [
        'legend' => 'Finding',
        'pending' => 'To check',
        'ok' => 'As expected',
        'anomaly' => 'Issue found',
    ],
    'status' => [
        'open' => 'In progress',
        'completed' => 'Completed',
    ],
    'zone' => [
        'entrance' => 'Entrance',
        'living_room' => 'Living room',
        'kitchen' => 'Kitchen',
        'bedrooms' => 'Bedrooms',
        'bathrooms' => 'Bathrooms',
        'outdoor' => 'Outdoor',
        'meters' => 'Meters',
    ],
    'checkin_intro' => 'Report anything that is not as expected within {hours} hours of your arrival. If everything is fine, there is nothing to do.',
    'checkout_intro' => 'On departure, a photo is required for every zone that asks for one.',
    'note' => 'Comment',
    'photo' => 'Photo',
    'photo_required' => 'Photo required',
    'photo_n' => 'Photo {index}',
    'save' => 'Save this zone',
    'complete' => 'Complete the inspection',
    'saved' => 'Zone saved.',
    'completed' => 'Inspection completed.',
    'open_incident' => 'Report an incident',
    'no_zone' => 'No zone has been defined for this property yet.',
    'done_on' => 'Completed on {date}.',
    'not_started' => 'Not started',
    'not_started_help' => 'Nothing has been recorded yet for this part of the stay.',
    'error' => [
        'completed' => 'This inspection is complete and can no longer be changed.',
        'unknown_zone' => 'Unknown zone.',
        'not_a_photo' => 'Only photos are accepted here.',
        'photos_required' => 'Photos of the required zones are mandatory on departure.',
        'incomplete' => 'Every zone must be filled in.',
        'not_an_anomaly' => 'An incident can only be opened on a zone marked as an issue.',
        'code' => 'The zone code is required.',
    ],
    'admin' => [
        'title' => 'Zones and reference photos',
        'intro' => 'Define the zones of the property, their order, their instructions, and which ones require a photo on departure.',
        'completeness' => 'Custom names',
        'completeness_help' => 'A zone with no custom name uses the built-in label, already available in all four languages.',
        'no_zone' => 'No zone defined.',
        'seed' => 'Create the suggested zones',
        'seeded' => 'Suggested zones created.',
        'already_seeded' => 'Zones already exist: nothing was created.',
        'saved' => 'Zone saved.',
        'reference_added' => 'Reference photo added.',
        'name' => 'Zone name',
        'position' => 'Order',
        'instructions' => 'Instructions',
        'reference_note' => 'Reference note',
        'active' => 'Active',
        'reference_photos' => 'Reference photos',
        'no_reference' => 'No reference photo.',
        'add_reference' => 'Add a reference photo',
        'new_zone' => 'New zone',
        'code' => 'Code',
        'code_help' => 'Stable lowercase identifier, independent of the language.',
        'detail' => 'View details',
    ],
];
