<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Connexion',
        'action' => 'Se connecter',
        'welcome' => 'Vous êtes connecté.',
        'invalid_credentials' => 'Adresse e-mail ou mot de passe incorrect.',
        'rate_limited' => 'Trop de tentatives. Réessayez dans quelques minutes.',
        'account_pending' => 'Ce compte n’est pas encore activé.',
        'or' => 'ou',
        'account_suspended' => 'Ce compte est suspendu.',
    ],
    'logout' => [
        'action' => 'Se déconnecter',
        'done' => 'Vous êtes déconnecté.',
    ],
    'field' => [
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'password_confirm' => 'Confirmation du mot de passe',
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'phone' => 'Téléphone',
    ],
    'password' => [
        'strength' => 'Robustesse du mot de passe',
        'requirements' => 'Au moins {length} caractères, avec majuscule, minuscule et chiffre.',
        'too_short' => 'Le mot de passe est trop court.',
        'needs_uppercase' => 'Ajoutez au moins une majuscule.',
        'needs_lowercase' => 'Ajoutez au moins une minuscule.',
        'needs_digit' => 'Ajoutez au moins un chiffre.',
        'too_repetitive' => 'Le mot de passe est trop répétitif.',
    ],
    'role' => [
        'customer' => 'Client',
        'local_manager' => 'Responsable local',
        'administrator' => 'Administrateur',
    ],
];
