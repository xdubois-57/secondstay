<?php

declare(strict_types=1);

/**
 * Libellés et aides des réglages typés.
 *
 * Chaque réglage expose un libellé et une aide explicite : l'objectif est que
 * le propriétaire comprenne l'impact du réglage sans documentation externe.
 */

return [
    'property' => [
        'siret' => [
            'label' => 'SIRET',
            'help' => 'Numéro d’identification, reporté sur le contrat lorsqu’il est renseigné.',
        ],
        'name' => [
            'label' => 'Nom du logement',
            'help' => 'Nom affiché publiquement pour votre résidence secondaire.',
        ],
        'address_line1' => [
            'label' => 'Adresse (ligne 1)',
            'help' => 'Numéro et rue du logement.',
        ],
        'address_line2' => [
            'label' => 'Adresse (ligne 2)',
            'help' => 'Complément d’adresse : lieu-dit, bâtiment, étage.',
        ],
        'postal_code' => [
            'label' => 'Code postal',
            'help' => 'Code postal français du logement.',
        ],
        'city' => [
            'label' => 'Commune',
            'help' => 'Commune du logement. Elle sert aussi à la taxe de séjour.',
        ],
        'country' => [
            'label' => 'Pays',
            'help' => 'SecondStay est spécialisé pour la France.',
        ],
        'latitude' => [
            'label' => 'Latitude',
            'help' => 'Coordonnée utilisée pour les contenus locaux et l’accès.',
        ],
        'longitude' => [
            'label' => 'Longitude',
            'help' => 'Coordonnée utilisée pour les contenus locaux et l’accès.',
        ],
        'contact_email' => [
            'label' => 'E-mail de contact',
            'help' => 'Adresse affichée aux voyageurs pour les questions générales.',
        ],
        'contact_phone' => [
            'label' => 'Téléphone de contact',
            'help' => 'Numéro affiché aux voyageurs.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Langue par défaut',
            'help' => 'Langue utilisée quand aucune préférence n’est connue.',
        ],
        'timezone' => [
            'label' => 'Fuseau horaire',
            'help' => 'Fuseau utilisé pour les horaires d’arrivée, de départ et les rappels.',
        ],
        'public_url' => [
            'label' => 'URL publique',
            'help' => 'Adresse publique du site, utilisée dans les e-mails et les liens.',
        ],
        'season' => [
            'label' => 'Saison affichée',
            'help' => 'Automatique suit la date ; forcez été ou hiver pour une présentation fixe.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Nombre minimal de nuits',
            'help' => 'Durée minimale acceptée pour un séjour.',
        ],
        'max_guests' => [
            'label' => 'Capacité maximale',
            'help' => 'Nombre total de voyageurs accepté, bébés compris selon votre règle.',
        ],
        'checkin_time' => [
            'label' => 'Heure d’arrivée',
            'help' => 'Heure à partir de laquelle le logement est disponible.',
        ],
        'checkout_time' => [
            'label' => 'Heure de départ',
            'help' => 'Heure limite de libération du logement.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Règle samedi-samedi',
            'help' => 'Impose des séjours du samedi au samedi. Désactivable.',
        ],
        'hold_minutes' => [
            'label' => 'Durée de réservation temporaire',
            'help' => 'Minutes pendant lesquelles des dates restent bloquées avant confirmation.',
        ],
        'requires_approval' => [
            'label' => 'Validation par le propriétaire',
            'help' => 'Chaque demande de réservation attend votre accord. Désactivez ce réglage pour confirmer automatiquement les séjours disponibles.',
        ],
        'allow_waitlist' => [
            'label' => 'Liste d’attente',
            'help' => 'Un visiteur peut demander à être prévenu si des dates indisponibles se libèrent.',
        ],
        'min_adults' => [
            'label' => 'Adultes minimum',
            'help' => 'Nombre minimal d’adultes par séjour.',
        ],
        'max_children' => [
            'label' => 'Enfants maximum',
            'help' => 'Nombre maximal d’enfants acceptés en plus des adultes.',
        ],
        'max_infants' => [
            'label' => 'Bébés maximum',
            'help' => 'Les bébés ne comptent pas dans la capacité de couchage.',
        ],
        'night_multiple' => [
            'label' => 'Tranches de nuits',
            'help' => 'Impose une durée multiple de ce nombre. 0 désactive la contrainte, 7 impose des semaines entières.',
        ],
        'max_nights' => [
            'label' => 'Durée maximale',
            'help' => 'Nombre maximal de nuits par séjour.',
        ],
        'arrival_weekday' => [
            'label' => 'Jour d’arrivée imposé',
            'help' => 'Restreint les arrivées à un seul jour de la semaine. Le réglage samedi-samedi reste prioritaire.',
        ],
        'advance_days' => [
            'label' => 'Délai de prévenance',
            'help' => 'Nombre de jours minimum entre aujourd’hui et une arrivée.',
        ],
        'horizon_days' => [
            'label' => 'Horizon de réservation',
            'help' => 'Nombre de jours au-delà desquels le calendrier n’est pas encore ouvert.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Prix par nuit par défaut',
            'help' => 'Utilisé pour toute date sans tarif spécifique.',
        ],
        'cleaning_mode' => [
            'label' => 'Mode de ménage',
            'help' => 'Aucun, optionnel ou obligatoire. Par défaut obligatoire.',
        ],
        'cleaning_price' => [
            'label' => 'Prix du ménage',
            'help' => 'Montant du forfait ménage. Valeur par défaut : 100 €.',
        ],
        'deposit_percent' => [
            'label' => 'Acompte (%)',
            'help' => 'Part du séjour demandée pour confirmer la réservation.',
        ],
        'security_deposit' => [
            'label' => 'Caution',
            'help' => 'Montant de la caution demandée avant le séjour.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Maintenance planifiée',
            'help' => 'Ferme le site public. L’administration reste accessible.',
        ],
        'message' => [
            'label' => 'Message de maintenance',
            'help' => 'Note interne expliquant la raison de la maintenance.',
        ],
    ],
    'backup' => [
        'retention_count' => [
            'label' => 'Sauvegardes conservées',
            'help' => 'Nombre de sauvegardes gardées avant suppression automatique.',
        ],
        'include_media' => [
            'label' => 'Inclure les médias',
            'help' => 'Ajoute photos, documents et pièces jointes à la sauvegarde.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Canal de mise à jour',
            'help' => 'Stable installe uniquement les versions publiées.',
        ],
        'auto_install' => [
            'label' => 'Mise à jour automatique',
            'help' => 'Installe automatiquement les nouvelles versions validées.',
        ],
        'repository' => [
            'label' => 'Dépôt des releases',
            'help' => 'Dépôt GitHub fournissant les artefacts installables.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Niveau de journalisation',
            'help' => 'Seuil minimal des messages enregistrés.',
        ],
        'retention_days' => [
            'label' => 'Rétention des journaux (jours)',
            'help' => 'Durée de conservation avant purge automatique.',
        ],
    ],
    'error' => [
        'required' => 'Ce réglage est obligatoire.',
        'unknown' => 'Réglage inconnu.',
        'integer' => 'Saisissez un nombre entier.',
        'decimal' => 'Saisissez un nombre décimal.',
        'money' => 'Saisissez un montant valide, par exemple 100,00.',
        'enum' => 'Valeur non autorisée.',
        'email' => 'Adresse e-mail invalide.',
        'url' => 'URL invalide.',
        'url_scheme' => 'Seules les URL http(s) sont acceptées.',
        'date' => 'Date invalide (format attendu : AAAA-MM-JJ).',
        'time' => 'Heure invalide (format attendu : HH:MM).',
        'duration' => 'Durée invalide (en minutes).',
        'json' => 'JSON invalide.',
        'iban' => 'IBAN invalide : vérifiez la clé de contrôle.',
        'bic' => 'BIC invalide (8 ou 11 caractères).',
        'currency' => 'Devise invalide : code ISO 4217 à trois lettres attendu.',
        'too_long' => 'Valeur trop longue.',
        'too_small' => 'Valeur trop petite.',
        'too_large' => 'Valeur trop grande.',
    ],
    'mail' => [
        'from_address' => [
            'label' => 'Adresse d’expédition',
            'help' => 'Adresse affichée comme expéditeur des e-mails du site.',
        ],
        'from_name' => [
            'label' => 'Nom d’expéditeur',
            'help' => 'Nom affiché à côté de l’adresse d’expédition.',
        ],
        'reply_to' => [
            'label' => 'Adresse de réponse',
            'help' => 'Boîte surveillée qui reçoit les réponses des voyageurs.',
        ],
        'smtp_host' => [
            'label' => 'Serveur SMTP',
            'help' => 'Hôte fourni par votre service d’envoi d’e-mails.',
        ],
        'smtp_port' => [
            'label' => 'Port SMTP',
            'help' => '587 avec STARTTLS, 465 avec TLS implicite.',
        ],
        'smtp_encryption' => [
            'label' => 'Chiffrement SMTP',
            'help' => 'STARTTLS est recommandé. Le certificat du serveur est toujours vérifié.',
        ],
        'smtp_username' => [
            'label' => 'Utilisateur SMTP',
            'help' => 'Identifiant d’authentification auprès du serveur SMTP.',
        ],
        'smtp_password' => [
            'label' => 'Mot de passe SMTP',
            'help' => 'Chiffré au repos et jamais réaffiché. Laissez vide pour conserver la valeur actuelle.',
        ],
        'dkim_selector' => [
            'label' => 'Sélecteur DKIM',
            'help' => 'Sélecteur fourni par votre service d’envoi (souvent « default » ou « mail »). Il sert uniquement au diagnostic DNS : la signature reste assurée par le fournisseur.',
        ],
    ],
    'notification' => [
        'push_enabled' => [
            'label' => 'Notifications push',
            'help' => 'Autorise les navigateurs à recevoir des notifications. L’e-mail reste envoyé dans tous les cas.',
        ],
        'retention_days' => [
            'label' => 'Conservation du journal des notifications',
            'help' => 'Durée de conservation des traces d’envoi, en jours.',
        ],
    ],
    'push' => [
        'subject' => [
            'label' => 'Contact push',
            'help' => 'Adresse e-mail ou URL de contact transmise aux services de push, comme l’exige la norme. À défaut, l’adresse d’expédition est utilisée.',
        ],
        'vapid_public' => [
            'label' => 'Clé publique VAPID',
            'help' => 'Générée par l’installation et transmise aux navigateurs. La remplacer invalide tous les abonnements existants.',
        ],
        'vapid_private' => [
            'label' => 'Clé privée VAPID',
            'help' => 'Chiffrée au repos et jamais réaffichée. Elle signe les envois vers les services de push.',
        ],
    ],
    'account' => [
        'allow_signup' => [
            'label' => 'Autoriser les inscriptions',
            'help' => 'Permet aux voyageurs de créer un compte depuis le site public.',
        ],
        'allow_passkeys' => [
            'label' => 'Autoriser les clés d’accès',
            'help' => 'Active la connexion sans mot de passe par passkey (WebAuthn).',
        ],
        'require_email_confirmation' => [
            'label' => 'Confirmation d’e-mail obligatoire',
            'help' => 'Le compte reste inactif tant que l’adresse n’est pas confirmée.',
        ],
    ],
    'payment' => [
        'provider' => [
            'label' => 'Fournisseur de paiement',
            'help' => 'Mollie encaisse en ligne et confirme automatiquement la réservation. Sans fournisseur, seul le virement reste possible.',
        ],
        'mollie_api_key' => [
            'label' => 'Clé d’API Mollie',
            'help' => 'Chiffrée au repos et jamais réaffichée. Une clé « test_ » n’encaisse rien réellement.',
        ],
        'balance_days_before' => [
            'label' => 'Solde dû (jours avant l’arrivée)',
            'help' => 'Le solde est exigible ce nombre de jours avant l’arrivée, ou immédiatement pour une réservation plus tardive.',
        ],
        'transfer_enabled' => [
            'label' => 'Autoriser le virement bancaire',
            'help' => 'Affiche l’IBAN et le QR code EPC. Un virement ne confirme jamais seul la réservation.',
        ],
        'beneficiary_name' => [
            'label' => 'Bénéficiaire du virement',
            'help' => 'Nom du titulaire du compte, tel qu’il apparaîtra dans l’application bancaire du voyageur.',
        ],
        'iban' => [
            'label' => 'IBAN',
            'help' => 'IBAN du compte à créditer. La clé de contrôle est vérifiée avant enregistrement.',
        ],
        'bic' => [
            'label' => 'BIC',
            'help' => 'Facultatif. Certaines banques le réclament encore pour les virements hors zone SEPA.',
        ],
        'currency' => [
            'label' => 'Devise',
            'help' => 'Code ISO 4217 à trois lettres, EUR par défaut.',
        ],
    ],
    'tax' => [
        'tourist_enabled' => [
            'label' => 'Percevoir la taxe de séjour',
            'help' => 'Ajoute la taxe de séjour à l’échéancier de chaque réservation.',
        ],
        'tourist_per_adult_night' => [
            'label' => 'Taxe par adulte et par nuit',
            'help' => 'Montant perçu pour chaque adulte et chaque nuit. Les mineurs en sont exonérés.',
        ],
        'tourist_cap_per_stay' => [
            'label' => 'Plafond par séjour',
            'help' => 'Montant maximal perçu pour un séjour. Zéro signifie aucun plafond.',
        ],
    ],
    'imap' => [
        'enabled' => [
            'label' => 'Relever la boîte de réception',
            'help' => 'Importe périodiquement les réponses des voyageurs et leurs pièces jointes.',
        ],
        'host' => [
            'label' => 'Serveur IMAP',
            'help' => 'Nom du serveur de la boîte dédiée au logement.',
        ],
        'port' => [
            'label' => 'Port IMAP',
            'help' => '993 pour une connexion chiffrée, 143 avec STARTTLS.',
        ],
        'encryption' => [
            'label' => 'Chiffrement IMAP',
            'help' => 'TLS implicite recommandé. Aucun chiffrement n’est à éviter.',
        ],
        'username' => [
            'label' => 'Identifiant IMAP',
            'help' => 'Compte de la boîte relevée.',
        ],
        'password' => [
            'label' => 'Mot de passe IMAP',
            'help' => 'Chiffré au repos et jamais réaffiché.',
        ],
        'mailbox' => [
            'label' => 'Dossier relevé',
            'help' => 'INBOX sauf si les réponses arrivent dans un dossier dédié.',
        ],
        'reply_address' => [
            'label' => 'Adresse de réponse',
            'help' => 'Adresse annoncée aux voyageurs. Elle est étiquetée par séjour pour rattacher automatiquement les réponses.',
        ],
        'uid_validity' => [
            'label' => 'Identifiant de validité',
            'help' => 'Renseigné par la synchronisation. S’il change, la boîte a été renumérotée et la relève repart du début.',
        ],
        'batch_size' => [
            'label' => 'Messages par relève',
            'help' => 'Nombre de messages traités à chaque passage. Une relève doit toujours se terminer.',
        ],
    ],
    'legal' => [
        'terms_version' => [
            'label' => 'Version des conditions',
            'help' => 'Figée dans chaque contrat accepté. Changez-la lorsque le texte des conditions change.',
        ],
        'mediator_name' => [
            'label' => 'Médiateur de la consommation',
            'help' => 'Nom du médiateur mentionné dans le contrat.',
        ],
        'mediator_url' => [
            'label' => 'Site du médiateur',
            'help' => 'Adresse à laquelle le voyageur peut saisir le médiateur.',
        ],
    ],
];
