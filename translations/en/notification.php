<?php

declare(strict_types=1);

/**
 * Notifications : sujet d'e-mail, titre et texte poussés, libellé d'action.
 *
 * Chaque événement expose les mêmes clés : ajouter un événement ne demande
 * aucun gabarit supplémentaire.
 */

return [
    'title' => 'Notifications',
    'intro' => 'Choose how you want to be told. Important messages about your stay are always sent by email.',
    'send_test' => 'Send a test notification',
    'test_sent' => 'Test notification sent.',
    'test_no_device' => 'No subscribed device for this test.',
    'saved' => 'Notification preferences saved.',
    'devices' => 'Devices receiving notifications',
    'no_device' => 'No subscribed device.',
    'channel' => [
        'email' => 'Email',
        'push' => 'Push notifications',
    ],
    'push' => [
        'enable' => 'Enable notifications on this device',
        'disable' => 'Disable on this device',
        'enabled' => 'Notifications enabled on this device.',
        'disabled' => 'Notifications disabled on this device.',
        'unsupported' => 'Your browser does not support notifications.',
        'denied' => 'Notifications were blocked in the browser settings.',
        'unavailable' => 'Push notifications are not enabled on this site.',
    ],
    'mail' => [
        'preferences' => 'You can adjust your notifications from your account area.',
    ],
    'test' => [
        'subject' => 'Test notification',
        'title' => 'Test notification',
        'body' => 'If you can read this, this device does receive notifications.',
        'mail_body' => 'This message confirms that your address does receive notifications from {property}.',
        'action' => 'Open my account',
    ],
    'account_confirmed' => [
        'subject' => 'Your account is active',
        'title' => 'Welcome {first_name}',
        'body' => 'Welcome {first_name}, your {property} account is confirmed.',
        'mail_body' => 'Your email address is confirmed: you can follow your stay from your account area.',
        'action' => 'Open my account',
    ],
    'booking_created' => [
        'subject' => 'Booking request recorded',
        'title' => 'Request recorded',
        'body' => 'Your request for {property} has been recorded.',
        'mail_body' => 'We have received your booking request. You will get a confirmation as soon as it is approved.',
        'action' => 'View my booking',
    ],
    'booking_confirmed' => [
        'subject' => 'Booking confirmed',
        'title' => 'Booking confirmed',
        'body' => 'Your stay at {property} is confirmed.',
        'mail_body' => 'Your stay is confirmed. Practical information will be available before you arrive.',
        'action' => 'View my booking',
    ],
    'payment_received' => [
        'subject' => 'Payment received',
        'title' => 'Payment received',
        'body' => 'Your payment for {property} has been recorded.',
        'mail_body' => 'Your payment has been recorded. The receipt is available in your documents.',
        'action' => 'View my documents',
    ],
    'stay_reminder' => [
        'subject' => 'Your stay is coming up',
        'title' => 'Your stay is coming up',
        'body' => 'Your stay at {property} starts soon.',
        'mail_body' => 'Arrival information, access codes and the welcome book are in your account area.',
        'action' => 'Prepare my stay',
    ],
    'arrival' => [
        'subject' => 'Arrival day',
        'title' => 'Welcome',
        'body' => 'Welcome to {property}.',
        'mail_body' => 'Everything you need for your arrival is available in your account area.',
        'action' => 'View arrival information',
    ],
    'departure' => [
        'subject' => 'Departure day',
        'title' => 'Departure day',
        'body' => 'Your departure from {property} is scheduled today.',
        'mail_body' => 'Please follow the departure instructions shown in your account area.',
        'action' => 'View departure instructions',
    ],
    'incident' => [
        'subject' => 'Incident reported',
        'title' => 'Incident reported',
        'body' => 'An incident has been reported at {property}.',
        'mail_body' => 'An incident has just been reported. Open the record to decide what happens next.',
        'action' => 'View the incident',
    ],
    'task_assigned' => [
        'subject' => 'New task',
        'title' => 'New task',
        'body' => 'A task has been assigned to you for {property}.',
        'mail_body' => 'A task has just been assigned to you. It is visible on your dashboard.',
        'action' => 'View the task',
    ],
];
