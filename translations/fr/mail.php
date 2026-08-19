<?php

declare(strict_types=1);

return [
    'footer' => [
        'automatic' => 'Ce message est envoyé automatiquement. Vous pouvez y répondre : votre réponse sera lue.',
    ],
    'account_confirmation' => [
        'subject' => 'Confirmez votre adresse e-mail',
        'heading' => 'Bonjour {first_name},',
        'intro' => 'Confirmez votre adresse e-mail pour activer votre compte.',
        'button' => 'Confirmer mon adresse',
        'fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.',
    ],
    'password_reset' => [
        'subject' => 'Réinitialisation de votre mot de passe',
        'heading' => 'Réinitialiser votre mot de passe',
        'intro' => 'Ce lien reste valable {hours} heure(s).',
        'button' => 'Choisir un nouveau mot de passe',
        'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, votre mot de passe reste inchangé.',
    ],
    'account_exists' => [
        'subject' => 'Votre compte existe déjà',
        'heading' => 'Un compte existe déjà pour cette adresse',
        'intro' => 'Quelqu’un vient de tenter de créer un compte avec votre adresse. Si c’était vous, connectez-vous ou réinitialisez votre mot de passe.',
        'button' => 'Réinitialiser mon mot de passe',
        'ignore' => 'Sinon, aucune action n’est nécessaire.',
    ],
    'verify' => [
        'ok' => 'Connexion SMTP réussie.',
    ],
    'error' => [
        'not_configured' => 'Le service e-mail n’est pas configuré.',
        'connection_failed' => 'Connexion au serveur SMTP impossible.',
        'tls_failed' => 'Le chiffrement TLS n’a pas pu être établi.',
        'write_failed' => 'Écriture impossible vers le serveur SMTP.',
        'no_response' => 'Le serveur SMTP n’a pas répondu.',
        'rejected' => 'Le serveur SMTP a rejeté le message.',
        'unexpected_response' => 'Réponse SMTP inattendue.',
    ],
];
