<?php

declare(strict_types=1);

return [
    'signup' => [
        'title' => 'Create an account',
        'intro' => 'An account lets you follow your booking, your documents and your stay.',
        'action' => 'Create my account',
        'accept_terms' => 'I accept the',
        'already_registered' => 'I already have an account',
        'sent_title' => 'Check your inbox',
        'sent_message' => 'If a registration is possible for {email}, a message has just been sent to that address.',
        'sent_hint' => 'The confirmation link stays valid for seven days.',
    ],
    'confirm' => [
        'title' => 'Email confirmation',
        'success' => 'Your email address is confirmed. Welcome.',
    ],
    'forgot' => [
        'title' => 'Forgotten password',
        'intro' => 'Enter your email address: if an account exists, you will receive a reset link.',
        'action' => 'Send the link',
        'sent' => 'If an account exists for this address, a reset link has just been sent.',
    ],
    'reset' => [
        'title' => 'New password',
        'new_password' => 'New password',
        'action' => 'Save the password',
        'success' => 'Password changed. You can sign in.',
    ],
    'profile' => [
        'title' => 'My account',
        'identity' => 'My details',
        'locale' => 'Preferred language',
        'locale_help' => 'Your emails and notifications use this language.',
        'saved' => 'Details saved.',
    ],
    'password' => [
        'title' => 'Password',
        'current' => 'Current password',
        'new' => 'New password',
        'action' => 'Change password',
        'changed' => 'Password changed. Your other devices have been signed out.',
    ],
    'sessions' => [
        'title' => 'Signed-in devices',
        'current' => 'current device',
        'last_seen' => 'last seen',
        'unknown_device' => 'Unknown device',
        'revoke_others' => 'Sign out other devices',
        'revoked' => 'The other devices have been signed out.',
    ],
    'passkey' => [
        'title' => 'Passkeys',
        'intro' => 'A passkey replaces the password: it uses your device fingerprint, face or code.',
        'add' => 'Add a passkey',
        'remove' => 'Remove',
        'removed' => 'Passkey removed.',
        'not_found' => 'Passkey not found.',
        'added' => 'added on',
        'last_used' => 'last used',
        'empty' => 'No passkey registered.',
        'label_placeholder' => 'Device name',
        'unsupported' => 'Your browser does not support passkeys.',
        'registered' => 'Passkey registered.',
        'sign_in' => 'Sign in with a passkey',
    ],
    'privacy' => [
        'title' => 'My personal data',
        'intro' => 'You can export your data at any time or request the deletion of your account.',
        'export' => 'Export my data (JSON)',
        'consent_terms' => 'Terms and conditions',
        'consent_privacy' => 'Privacy policy',
    ],
    'delete' => [
        'warning' => 'Deletion permanently anonymises your account. Data kept for legal obligations remains '
            . 'anonymised.',
        'action' => 'Delete my account',
        'done' => 'Your account has been deleted.',
    ],
    'error' => [
        'required' => 'This field is required.',
        'email_invalid' => 'Invalid email address.',
        'phone_invalid' => 'Invalid phone number.',
        'password_mismatch' => 'The two passwords do not match.',
        'current_password' => 'Current password is incorrect.',
        'terms_required' => 'You must accept the terms to create an account.',
        'token_invalid' => 'This link is invalid or has expired.',
        'rate_limited' => 'Too many attempts. Try again later.',
        'administrator_delete' => 'An administrator must first hand over their role before deleting their account.',
    ],
];
