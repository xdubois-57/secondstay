<?php

declare(strict_types=1);

return [
    'signup' => [
        'title' => 'Créer un compte',
        'intro' => 'Un compte vous permet de suivre votre réservation, vos documents et votre séjour.',
        'action' => 'Créer mon compte',
        'accept_terms' => 'J’accepte les',
        'already_registered' => 'J’ai déjà un compte',
        'sent_title' => 'Vérifiez votre boîte mail',
        'sent_message' => 'Si une inscription est possible pour {email}, un message vient d’être envoyé à cette '
            . 'adresse.',
        'sent_hint' => 'Le lien de confirmation reste valable sept jours.',
    ],
    'confirm' => [
        'title' => 'Confirmation d’adresse e-mail',
        'success' => 'Votre adresse e-mail est confirmée. Bienvenue.',
    ],
    'forgot' => [
        'title' => 'Mot de passe oublié',
        'intro' => 'Indiquez votre adresse e-mail : si un compte existe, vous recevrez un lien de réinitialisation.',
        'action' => 'Envoyer le lien',
        'sent' => 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d’être envoyé.',
    ],
    'reset' => [
        'title' => 'Nouveau mot de passe',
        'new_password' => 'Nouveau mot de passe',
        'action' => 'Enregistrer le mot de passe',
        'success' => 'Mot de passe modifié. Vous pouvez vous connecter.',
    ],
    'profile' => [
        'title' => 'Mon compte',
        'identity' => 'Mes informations',
        'locale' => 'Langue préférée',
        'locale_help' => 'Vos e-mails et notifications utilisent cette langue.',
        'saved' => 'Informations enregistrées.',
    ],
    'password' => [
        'title' => 'Mot de passe',
        'current' => 'Mot de passe actuel',
        'new' => 'Nouveau mot de passe',
        'action' => 'Changer le mot de passe',
        'changed' => 'Mot de passe modifié. Vos autres appareils ont été déconnectés.',
    ],
    'sessions' => [
        'title' => 'Appareils connectés',
        'current' => 'appareil actuel',
        'last_seen' => 'vu le',
        'unknown_device' => 'Appareil inconnu',
        'revoke_others' => 'Déconnecter les autres appareils',
        'revoked' => 'Les autres appareils ont été déconnectés.',
    ],
    'passkey' => [
        'title' => 'Clés d’accès (passkeys)',
        'intro' => 'Une clé d’accès remplace le mot de passe : elle utilise l’empreinte, le visage ou le code de votre '
            . 'appareil.',
        'add' => 'Ajouter une clé d’accès',
        'remove' => 'Supprimer',
        'removed' => 'Clé d’accès supprimée.',
        'not_found' => 'Clé d’accès introuvable.',
        'added' => 'ajoutée le',
        'last_used' => 'dernière utilisation',
        'empty' => 'Aucune clé d’accès enregistrée.',
        'label_placeholder' => 'Nom de l’appareil',
        'unsupported' => 'Votre navigateur ne prend pas en charge les clés d’accès.',
        'registered' => 'Clé d’accès enregistrée.',
        'sign_in' => 'Se connecter avec une clé d’accès',
    ],
    'privacy' => [
        'title' => 'Mes données personnelles',
        'intro' => 'Vous pouvez exporter vos données à tout moment ou demander la suppression de votre compte.',
        'export' => 'Exporter mes données (JSON)',
        'consent_terms' => 'Conditions générales',
        'consent_privacy' => 'Politique de confidentialité',
    ],
    'delete' => [
        'warning' => 'La suppression anonymise définitivement votre compte. Les données conservées pour obligation '
            . 'légale restent anonymisées.',
        'action' => 'Supprimer mon compte',
        'done' => 'Votre compte a été supprimé.',
    ],
    'error' => [
        'required' => 'Ce champ est obligatoire.',
        'email_invalid' => 'Adresse e-mail invalide.',
        'phone_invalid' => 'Numéro de téléphone invalide.',
        'password_mismatch' => 'Les deux mots de passe ne correspondent pas.',
        'current_password' => 'Mot de passe actuel incorrect.',
        'terms_required' => 'Vous devez accepter les conditions pour créer un compte.',
        'token_invalid' => 'Ce lien est invalide ou a expiré.',
        'rate_limited' => 'Trop de tentatives. Réessayez plus tard.',
        'administrator_delete' => 'Un administrateur doit d’abord transmettre son rôle avant de supprimer son compte.',
    ],
];
