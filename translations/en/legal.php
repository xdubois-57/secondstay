<?php

declare(strict_types=1);

/**
 * Versioned legal texts and consents (SPECIFICATIONS.md §65).
 */

return [
    'title' => 'Versioned legal texts',
    'intro' => 'Publishing a version freezes the text of every language. A booking then keeps the version and language '
        . 'that were actually accepted, even if the text changes later.',
    'type' => [
        'terms' => 'Terms and conditions',
        'privacy' => 'Privacy',
        'house_rules' => 'House rules',
    ],
    'version' => 'Version',
    'publish' => 'Publish',
    'publish_help' => 'The published text is the one on the editorial pages, at the moment of publication.',
    'published' => 'Version published in all four languages.',
    'published_partial' => 'Version published, but some languages had no text.',
    'accepted' => 'Accepted texts',
    'none_accepted' => 'No text has been accepted for this stay.',
    'error' => [
        'version_required' => 'The version number is required.',
        'no_text' => 'Nothing to publish: fill in the corresponding page first.',
        'already_published' => 'This version already exists: a published version is never rewritten.',
    ],
];
