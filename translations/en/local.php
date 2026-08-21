<?php

declare(strict_types=1);

/**
 * Generated local content (SPECIFICATIONS.md §56 to §59).
 */

return [
    'admin' => [
        'title' => 'Local content',
        'intro' => 'List the pages to read, write your instruction, then run a test. The system adds the location, the season, the stay dates, the sources and the expected format.',
    ],
    'enabled' => 'Local content is produced for upcoming stays.',
    'disabled' => 'Local content is disabled.',
    'not_configured' => 'No provider configured: no activity is produced.',
    'configure' => 'Configure',
    'sources' => 'Sources read',
    'no_source' => 'No source. Add at least one public page.',
    'add_source' => 'Add',
    'activate' => 'Enable',
    'deactivate' => 'Disable',
    'source_added' => 'Source added.',
    'source_added_unresolved' => 'Source added, but its address does not resolve yet.',
    'source_updated' => 'Source updated.',
    'source_deleted' => 'Source deleted.',
    'prompt' => 'Instruction',
    'prompt_intro' => 'This text is yours. The system adds the location, the season, the exact dates, the sources and the output schema automatically.',
    'prompt_saved' => 'Instruction saved.',
    'suggest_prompt' => 'Generate the prompt from the location',
    'run' => 'Generation',
    'test' => 'Test',
    'tested' => 'Test completed.',
    'refresh' => 'Refresh upcoming stays',
    'refreshed' => 'Stays refreshed.',
    'nothing_due' => 'No stay is inside the window.',
    'runs' => 'Recent runs',
    'no_run' => 'No run yet.',
    'run_summary' => '{sources} source(s), {items} activity/activities',
    'window' => 'Generation starts {weeks} weeks before arrival, then refreshes every {days} days.',
    'due' => '{count} stay(s) to refresh.',
    'status' => [
        'running' => 'Running',
        'done' => 'Completed',
        'failed' => 'Failed',
    ],
    'field' => [
        'url' => 'Page address',
        'label' => 'Label',
        'prompt' => 'Your instruction',
    ],
    'source' => [
        'never_fetched' => 'Never read',
        'status' => [
            'ok' => 'Read successfully',
            'blocked' => 'Address refused',
            'empty' => 'Empty page',
        ],
    ],
    'category' => [
        'market' => 'Market',
        'festival' => 'Festival',
        'museum' => 'Museum',
        'nature' => 'Nature',
        'sport' => 'Sport',
        'food' => 'Food',
        'other' => 'Other',
    ],
    'group' => [
        'book_ahead' => 'Book ahead',
        'this_week' => 'During your stay',
    ],
    'verified_on' => 'verified on {date}',
    'stay' => [
        'title' => 'Around you',
        'disclaimer' => 'These suggestions come from the sources cited and were verified on the date shown. Confirm times and availability with the organiser.',
    ],
    'suggested_prompt' => 'Suggest activities around {location} for travellers staying at {property}: markets, local festivals, museums, walks and good places to eat. Favour what can be reached on foot or within thirty minutes by car, and flag anything that needs booking.',
    'error' => [
        'no_location' => 'Set the property’s town in the configuration first.',
        'duplicate' => 'This address is already in the list.',
    ],
];
