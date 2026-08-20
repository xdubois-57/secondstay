<?php

declare(strict_types=1);

/**
 * « Mon séjour aujourd’hui », livret d’accueil et liens invité.
 */

return [
    'title' => 'Mon séjour',
    'today' => 'Mon séjour aujourd’hui',
    'reference' => 'Référence',
    'dates' => 'Dates',
    'checkin' => 'Arrivée à partir de',
    'checkout' => 'Départ avant',
    'phase' => [
        'before' => 'Avant votre séjour',
        'arrival' => 'Jour de l’arrivée',
        'during' => 'Pendant votre séjour',
        'departure' => 'Jour du départ',
        'after' => 'Après votre séjour',
    ],
    'countdown' => [
        'today' => 'C’est aujourd’hui.',
        'tomorrow' => 'C’est demain.',
        'days' => 'Dans {count} jour|Dans {count} jours',
        'past' => 'Séjour terminé.',
    ],
    'block' => [
        'welcome' => 'Bienvenue',
        'access' => 'Arriver et entrer',
        'wifi' => 'Wi-Fi',
        'appliances' => 'Équipements',
        'waste' => 'Déchets et tri',
        'rules' => 'Règles de la maison',
        'safety' => 'Sécurité',
        'checkout' => 'Avant de partir',
    ],
    'secret' => [
        'title' => 'Codes d’accès',
        'wifi_password' => 'Mot de passe Wi-Fi',
        'key_box' => 'Boîte à clés',
        'alarm' => 'Alarme',
        'gate' => 'Portail',
        'hidden' => 'Les codes d’accès apparaîtront ici le jour de votre arrivée.',
        'shown_during' => 'Visibles pendant votre séjour uniquement.',
    ],
    'manager' => [
        'title' => 'Contact sur place',
        'none' => 'Aucun contact local n’est encore désigné.',
    ],
    'offline' => [
        'ready' => 'Cette page reste consultable sans réseau.',
        'stale' => 'Affichage hors ligne : les informations peuvent dater.',
    ],
    'guest' => [
        'title' => 'Partager avec mes invités',
        'intro' => 'Un lien invité donne accès à ces informations pratiques — et à rien d’autre : ni montants, ni documents, ni compte.',
        'create' => 'Créer un lien invité',
        'label' => 'Pour qui ?',
        'created' => 'Lien invité créé. Copiez-le maintenant : il ne sera plus affiché.',
        'revoked' => 'Lien invité révoqué.',
        'revoke' => 'Révoquer',
        'expires' => 'Expire le',
        'never_used' => 'Jamais utilisé',
        'empty' => 'Aucun lien invité actif.',
        'qr' => 'QR à imprimer',
        'qr_alt' => 'QR code du lien invité',
        'banner' => 'Vous consultez ce séjour avec un lien invité.',
    ],
    'admin' => [
        'title' => 'Livret d’accueil',
        'intro' => 'Ces textes s’affichent dans « Mon séjour » et derrière les liens invité. Ils sont consultables hors ligne.',
        'block_title' => 'Titre',
        'block_body' => 'Texte',
        'published' => 'Publié',
        'save' => 'Enregistrer',
        'saved' => 'Livret enregistré.',
        'secrets' => 'Codes d’accès',
        'secrets_intro' => 'Chiffrés au repos et jamais réaffichés. Laisser vide conserve la valeur existante.',
        'secrets_saved' => 'Codes d’accès enregistrés.',
        'clear' => 'Effacer',
        'not_set' => 'Non renseigné',
        'completeness' => 'Complétude',
        'language' => 'Langue',
    ],
    'error' => [
        'not_active' => 'Ce séjour n’est plus actif.',
        'link_not_found' => 'Lien invité introuvable.',
        'not_found' => 'Séjour introuvable.',
    ],
];
