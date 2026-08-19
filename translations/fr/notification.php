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
    'intro' => 'Choisissez comment vous souhaitez être prévenu. Les messages importants liés à votre séjour restent envoyés par e-mail.',
    'send_test' => 'Envoyer une notification de test',
    'test_sent' => 'Notification de test envoyée.',
    'test_no_device' => 'Aucun appareil abonné pour ce test.',
    'saved' => 'Préférences de notification enregistrées.',
    'devices' => 'Appareils recevant les notifications',
    'no_device' => 'Aucun appareil abonné.',
    'channel' => [
        'email' => 'E-mail',
        'push' => 'Notifications push',
    ],
    'push' => [
        'enable' => 'Activer les notifications sur cet appareil',
        'disable' => 'Désactiver sur cet appareil',
        'enabled' => 'Notifications activées sur cet appareil.',
        'disabled' => 'Notifications désactivées sur cet appareil.',
        'unsupported' => 'Votre navigateur ne prend pas en charge les notifications.',
        'denied' => 'Les notifications ont été refusées dans les réglages du navigateur.',
        'unavailable' => 'Les notifications push ne sont pas activées sur ce site.',
    ],
    'mail' => [
        'preferences' => 'Vous pouvez régler vos notifications depuis votre espace client.',
    ],
    'test' => [
        'subject' => 'Notification de test',
        'title' => 'Notification de test',
        'body' => 'Si vous lisez ceci, cet appareil reçoit bien les notifications.',
        'mail_body' => 'Cet envoi confirme que votre adresse reçoit bien les notifications de {property}.',
        'action' => 'Ouvrir mon espace',
    ],
    'account_confirmed' => [
        'subject' => 'Votre compte est actif',
        'title' => 'Bienvenue {first_name}',
        'body' => 'Bienvenue {first_name}, votre compte {property} est confirmé.',
        'mail_body' => 'Votre adresse e-mail est confirmée : vous pouvez suivre votre séjour depuis votre espace client.',
        'action' => 'Ouvrir mon espace',
    ],
    'booking_created' => [
        'subject' => 'Demande de réservation enregistrée',
        'title' => 'Demande enregistrée',
        'body' => 'Votre demande pour {property} est enregistrée.',
        'mail_body' => 'Nous avons bien reçu votre demande de réservation. Vous recevrez une confirmation dès qu’elle sera validée.',
        'action' => 'Voir ma réservation',
    ],
    'booking_confirmed' => [
        'subject' => 'Réservation confirmée',
        'title' => 'Réservation confirmée',
        'body' => 'Votre séjour à {property} est confirmé.',
        'mail_body' => 'Votre séjour est confirmé. Les informations pratiques seront disponibles avant votre arrivée.',
        'action' => 'Voir ma réservation',
    ],
    'payment_received' => [
        'subject' => 'Paiement reçu',
        'title' => 'Paiement reçu',
        'body' => 'Votre paiement pour {property} est enregistré.',
        'mail_body' => 'Votre paiement a bien été enregistré. Le reçu est disponible dans vos documents.',
        'action' => 'Voir mes documents',
    ],
    'stay_reminder' => [
        'subject' => 'Votre séjour approche',
        'title' => 'Votre séjour approche',
        'body' => 'Votre séjour à {property} commence bientôt.',
        'mail_body' => 'Retrouvez les informations d’arrivée, les codes d’accès et le livret d’accueil dans votre espace.',
        'action' => 'Préparer mon séjour',
    ],
    'arrival' => [
        'subject' => 'Jour d’arrivée',
        'title' => 'Bienvenue',
        'body' => 'Bienvenue à {property}.',
        'mail_body' => 'Toutes les informations utiles pour votre arrivée sont disponibles dans votre espace.',
        'action' => 'Voir les informations d’arrivée',
    ],
    'departure' => [
        'subject' => 'Jour de départ',
        'title' => 'Jour de départ',
        'body' => 'Votre départ de {property} est prévu aujourd’hui.',
        'mail_body' => 'Merci de suivre les consignes de départ indiquées dans votre espace.',
        'action' => 'Voir les consignes de départ',
    ],
    'incident' => [
        'subject' => 'Incident signalé',
        'title' => 'Incident signalé',
        'body' => 'Un incident a été signalé à {property}.',
        'mail_body' => 'Un incident vient d’être signalé. Consultez la fiche pour décider de la suite.',
        'action' => 'Voir l’incident',
    ],
    'task_assigned' => [
        'subject' => 'Nouvelle tâche',
        'title' => 'Nouvelle tâche',
        'body' => 'Une tâche vous a été confiée pour {property}.',
        'mail_body' => 'Une tâche vient de vous être assignée. Elle est visible dans votre tableau de bord.',
        'action' => 'Voir la tâche',
    ],
];
