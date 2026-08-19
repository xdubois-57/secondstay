<?php

declare(strict_types=1);

return [
    'error' => [
        'generic' => 'The passkey could not be verified.',
        'unsupported_algorithm' => 'Unsupported key algorithm.',
        'invalid_key' => 'Invalid public key.',
        'invalid_attestation' => 'Invalid attestation.',
        'invalid_authenticator_data' => 'Invalid authenticator data.',
        'invalid_client_data' => 'Invalid client data.',
        'invalid_encoding' => 'Invalid encoding.',
        'relying_party_mismatch' => 'The site does not match the key.',
        'origin_mismatch' => 'Origin not allowed.',
        'cross_origin' => 'Cross-origin request refused.',
        'type_mismatch' => 'Unexpected operation type.',
        'challenge_mismatch' => 'Invalid security challenge.',
        'challenge_expired' => 'The delay has expired, please retry.',
        'no_challenge' => 'No request in progress.',
        'user_not_present' => 'Missing user confirmation.',
        'no_credential' => 'No passkey provided.',
        'unknown_credential' => 'Unknown passkey.',
        'already_registered' => 'This passkey is already registered.',
        'bad_signature' => 'Invalid signature.',
        'counter_replay' => 'Inconsistent signature counter: the key may be cloned.',
    ],
];
