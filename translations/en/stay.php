<?php

declare(strict_types=1);

/**
 * “My stay today”, welcome book and guest links.
 */

return [
    'title' => 'My stay',
    'today' => 'My stay today',
    'reference' => 'Reference',
    'dates' => 'Dates',
    'checkin' => 'Arrival from',
    'checkout' => 'Departure before',
    'phase' => [
        'before' => 'Before your stay',
        'arrival' => 'Arrival day',
        'during' => 'During your stay',
        'departure' => 'Departure day',
        'after' => 'After your stay',
    ],
    'countdown' => [
        'today' => 'It is today.',
        'tomorrow' => 'It is tomorrow.',
        'days' => 'In {count} day|In {count} days',
        'past' => 'Stay finished.',
    ],
    'block' => [
        'welcome' => 'Welcome',
        'access' => 'Getting there and getting in',
        'wifi' => 'Wi-Fi',
        'appliances' => 'Appliances',
        'waste' => 'Waste and recycling',
        'rules' => 'House rules',
        'safety' => 'Safety',
        'checkout' => 'Before you leave',
    ],
    'secret' => [
        'title' => 'Access codes',
        'wifi_password' => 'Wi-Fi password',
        'key_box' => 'Key box',
        'alarm' => 'Alarm',
        'gate' => 'Gate',
        'hidden' => 'Access codes will appear here on your arrival day.',
        'shown_during' => 'Visible during your stay only.',
    ],
    'manager' => [
        'title' => 'Contact on site',
        'none' => 'No local contact has been named yet.',
    ],
    'offline' => [
        'ready' => 'This page stays available without a network.',
        'stale' => 'Offline view: the information may be out of date.',
    ],
    'guest' => [
        'title' => 'Share with my guests',
        'intro' => 'A guest link gives access to this practical information — and nothing else: no amounts, no documents, no account.',
        'create' => 'Create a guest link',
        'label' => 'For whom?',
        'created' => 'Guest link created. Copy it now: it will not be shown again.',
        'revoked' => 'Guest link revoked.',
        'revoke' => 'Revoke',
        'expires' => 'Expires on',
        'never_used' => 'Never used',
        'empty' => 'No active guest link.',
        'qr' => 'QR code to print',
        'qr_alt' => 'Guest link QR code',
        'banner' => 'You are viewing this stay through a guest link.',
    ],
    'admin' => [
        'public' => 'Public address for a QR code',
        'public_help' => 'Publishes this block at a fixed address, readable without an account or a stay. Only enable it for text anyone may read: leave no access code and no Wi-Fi password in it.',
        'public_url' => 'Address to encode in the QR code',
        'qr_alt' => 'QR code of the public page',
        'title' => 'Welcome book',
        'intro' => 'These texts appear in “My stay” and behind guest links. They are available offline.',
        'block_title' => 'Title',
        'block_body' => 'Text',
        'published' => 'Published',
        'save' => 'Save',
        'saved' => 'Welcome book saved.',
        'secrets' => 'Access codes',
        'secrets_intro' => 'Encrypted at rest and never shown again. Leaving a field empty keeps the existing value.',
        'secrets_saved' => 'Access codes saved.',
        'clear' => 'Clear',
        'not_set' => 'Not set',
        'completeness' => 'Completeness',
        'language' => 'Language',
    ],
    'info' => [
        'fallback' => 'This text does not exist in your language yet; it is shown in the property’s language.',
        'notice' => 'Property information page. It carries no booking data.',
    ],
    'error' => [
        'not_active' => 'This stay is no longer active.',
        'link_not_found' => 'Guest link not found.',
        'not_found' => 'Stay not found.',
    ],
];
