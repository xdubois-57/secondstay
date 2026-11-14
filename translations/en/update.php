<?php

declare(strict_types=1);

return [
    'error' => [
        'asset_missing' => 'Update artifact not found.',
        'asset_unreadable' => 'Update artifact unreadable.',
        'asset_no_version' => 'The artifact does not contain a valid version.',
        'artifact' => 'The artifact does not comply with the release policy.',
        'digest_missing' => 'The release publishes no SHA-256 digest: its integrity cannot be verified, so it is not '
            . 'installed.',
        'digest_mismatch' => 'The downloaded archive does not match the published digest: it is not installed.',
    ],
];
