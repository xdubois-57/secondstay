<?php

declare(strict_types=1);

return [
    '400' => [
        'title' => 'Invalid request',
        'message' => 'The request could not be processed.',
    ],
    '403' => [
        'title' => 'Access denied',
        'message' => 'You do not have the required permissions for this resource.',
    ],
    '404' => [
        'title' => 'Page not found',
        'message' => 'The requested page does not exist or has been moved.',
    ],
    '500' => [
        'title' => 'Internal error',
        'message' => 'An unexpected error occurred. The incident has been logged.',
    ],
    '503' => [
        'title' => 'Maintenance in progress',
        'message' => 'The site is temporarily unavailable for maintenance.',
    ],
    'back_home' => 'Back to home page',
    'reference' => 'Incident reference',
];
