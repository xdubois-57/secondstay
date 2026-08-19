<?php

declare(strict_types=1);

return [
    'footer' => [
        'automatic' => 'This message is sent automatically. You can reply: your answer will be read.',
    ],
    'account_confirmation' => [
        'subject' => 'Confirm your email address',
        'heading' => 'Hello {first_name},',
        'intro' => 'Confirm your email address to activate your account.',
        'button' => 'Confirm my address',
        'fallback' => 'If the button does not work, copy this link into your browser:',
        'ignore' => 'If you did not request this, please ignore this message.',
    ],
    'password_reset' => [
        'subject' => 'Reset your password',
        'heading' => 'Reset your password',
        'intro' => 'This link stays valid for {hours} hour(s).',
        'button' => 'Choose a new password',
        'ignore' => 'If you did not request this, your password stays unchanged.',
    ],
    'account_exists' => [
        'subject' => 'Your account already exists',
        'heading' => 'An account already exists for this address',
        'intro' => 'Someone just tried to create an account with your address. If it was you, sign in or reset your password.',
        'button' => 'Reset my password',
        'ignore' => 'Otherwise, no action is required.',
    ],
    'verify' => [
        'ok' => 'SMTP connection successful.',
    ],
    'error' => [
        'not_configured' => 'The email service is not configured.',
        'connection_failed' => 'Could not connect to the SMTP server.',
        'tls_failed' => 'TLS encryption could not be established.',
        'write_failed' => 'Could not write to the SMTP server.',
        'no_response' => 'The SMTP server did not answer.',
        'rejected' => 'The SMTP server rejected the message.',
        'unexpected_response' => 'Unexpected SMTP response.',
    ],
];
