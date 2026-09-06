<?php

declare(strict_types=1);

/**
 * Contenus par défaut créés à l'installation et libellés du module Contenu.
 *
 * Ces textes sont un point de départ rédactionnel : ils sont copiés en base
 * à l'installation et restent entièrement modifiables par le propriétaire.
 */

return [
    'default' => [
        'home' => [
            'menu' => 'Home',
            'title' => 'Welcome',
            'lead' => 'A holiday home in France, rented directly by its owners.',
            'body' => '<p>You are dealing with private owners, not a chain. We rent a single house, the one we know '
                . 'inside out, and we answer your questions ourselves.</p><p>On this site you will find real '
                . 'availability, commission-free rates, the house rules and all practical information. Booking happens '
                . 'online, the contract is generated automatically, and your documents stay available in your '
                . 'account.</p>',
        ],
        'property' => [
            'menu' => 'The property',
            'title' => 'The property',
            'lead' => 'Rooms, equipment and how many guests the house sleeps.',
            'body' => '<p>Describe the rooms, sleeping arrangements, kitchen, heating, outdoor space and the equipment '
                . 'provided. State clearly what is included in the price and what is not.</p><p>This page can be '
                . 'edited from the administration area, in all four site languages.</p>',
        ],
        'availability' => [
            'menu' => 'Availability',
            'title' => 'Availability',
            'lead' => 'Free periods and the rules that apply to a stay.',
            'body' => '<p>The public calendar shows which dates are free or taken, together with the applicable rules: '
                . 'minimum length of stay, arrival and departure days, maximum capacity.</p><p>Displayed availability '
                . 'accounts for confirmed bookings, bookings in progress and periods blocked by the owner.</p>',
        ],
        'rates' => [
            'menu' => 'Rates',
            'title' => 'Rates and conditions',
            'lead' => 'Nightly price, cleaning, security deposit and tourist tax.',
            'body' => '<p>The price is calculated night by night: each date can carry its own rate. The total shown '
                . 'before booking is the total you will pay.</p><p>End-of-stay cleaning is charged separately. A '
                . 'security deposit is requested before arrival and returned after the check-out inspection. The '
                . 'tourist tax is collected on behalf of the municipality.</p>',
        ],
        'gallery' => [
            'menu' => 'Gallery',
            'title' => 'Gallery',
            'lead' => 'Photos of the property and its surroundings.',
            'body' => '<p>Photos are grouped by category and may vary with the displayed season. Click an image to '
                . 'enlarge it.</p>',
        ],
        'activities' => [
            'menu' => 'Activities',
            'title' => 'Things to do nearby',
            'lead' => 'What to do during your stay, season by season.',
            'body' => '<p>Walks, markets, beaches, ski resorts, notable sites: describe here what is worth the detour '
                . 'around the house.</p><p>Date-specific activities will appear automatically in your stay area once '
                . 'your dates are known.</p>',
        ],
        'access' => [
            'menu' => 'Getting there',
            'title' => 'How to reach us',
            'lead' => 'Directions, parking and arrival on site.',
            'body' => '<p>Describe the driving route, the nearest station or airport, parking and anything specific '
                . 'about the last kilometre.</p><p>Precise arrival instructions (codes, key box, local manager '
                . 'contact) are provided in your stay area, never publicly.</p>',
        ],
        'contact' => [
            'menu' => 'Contact',
            'title' => 'Get in touch',
            'lead' => 'A question before booking? Write to us.',
            'body' => '<p>We answer personally, in French, English, Dutch or German.</p><p>For anything related to an '
                . 'existing booking, simply reply to the last email you received: your message will be attached to the '
                . 'right file automatically.</p>',
        ],
        'legal_notice' => [
            'menu' => 'Legal notice',
            'title' => 'Legal notice',
            'lead' => 'Site publisher, hosting and liability.',
            'body' => '<p>Complete this page with the publisher\'s identity, address, SIRET number where applicable, '
                . 'and the hosting provider\'s name and contact details.</p><p>This information is mandatory for a '
                . 'site offering a rental in France.</p>',
        ],
        'privacy' => [
            'menu' => 'Privacy',
            'title' => 'Data protection',
            'lead' => 'What data is collected, why, and for how long.',
            'body' => '<p>We collect only what is required for booking, the contract, the stay and legal obligations: '
                . 'identity, contact details, stay dates, documents and messages attached to the file.</p><p>You may '
                . 'at any time request access to your data, its correction, export or deletion, subject to statutory '
                . 'retention periods.</p>',
        ],
        'terms' => [
            'menu' => 'Terms and conditions',
            'title' => 'Rental terms and conditions',
            'lead' => 'Booking, payment, cancellation and liability.',
            'body' => '<p>Complete this page with your own terms: deposit amount, balance due date, security deposit, '
                . 'cancellation policy, inspections, house rules and consumer mediation.</p><p>The version a guest '
                . 'accepts is kept exactly as it was shown to them, in their language, and is never rewritten '
                . 'afterwards.</p>',
        ],
    ],
    'season' => [
        'all' => 'All seasons',
        'summer' => 'Summer',
        'winter' => 'Winter',
    ],
    'kind' => [
        'home' => 'Home',
        'page' => 'Page',
        'gallery' => 'Gallery',
        'legal' => 'Legal page',
        'contact' => 'Contact',
        'availability' => 'Availability',
        'rates' => 'Rates',
    ],
    'error' => [
        'slug_required' => 'The URL identifier is required.',
        'slug_taken' => 'This URL identifier is already used.',
        'not_found' => 'Page not found.',
        'parent_self' => 'A page cannot be its own parent.',
        'system_page' => 'This page is part of the core and cannot be deleted.',
    ],
    'gallery' => [
        'empty' => 'No photo published yet.',
        'all' => 'All',
        'open' => 'Enlarge image',
        'previous' => 'Previous image',
        'next' => 'Next image',
        'counter' => 'Image {index} of {total}',
    ],
    'contact_intro' => 'Write to us at the address below.',
    'no_contact' => 'No contact address has been configured yet.',
    'fallback_notice' => 'This page is not translated into your language yet: the text shown comes from the default '
        . 'language of the site.',
    'legal_footer' => 'Legal information',
];
