<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Sign in',
        'action' => 'Sign in',
        'welcome' => 'You are signed in.',
        'invalid_credentials' => 'Incorrect email address or password.',
        'rate_limited' => 'Too many attempts. Try again in a few minutes.',
        'account_pending' => 'This account is not activated yet.',
        'account_suspended' => 'This account is suspended.',
    ],
    'logout' => [
        'action' => 'Sign out',
        'done' => 'You are signed out.',
    ],
    'field' => [
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirm' => 'Confirm password',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
    ],
    'password' => [
        'strength' => 'Password strength',
        'requirements' => 'At least {length} characters, with an uppercase letter, a lowercase letter and a digit.',
        'too_short' => 'The password is too short.',
        'needs_uppercase' => 'Add at least one uppercase letter.',
        'needs_lowercase' => 'Add at least one lowercase letter.',
        'needs_digit' => 'Add at least one digit.',
        'too_repetitive' => 'The password is too repetitive.',
    ],
    'role' => [
        'customer' => 'Guest',
        'local_manager' => 'Local manager',
        'administrator' => 'Administrator',
    ],
];
