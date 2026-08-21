<?php

declare(strict_types=1);

/**
 * Tâches périodiques (ARCHITECTURE.md §23).
 */

return [
    'title' => 'Tâches périodiques',
    'intro' => 'Le produit ne fait tourner aucun processus permanent : une seule entrée cron déclenche tout ce qui est dû. Ajoutez-la chez votre hébergeur, aussi souvent qu’il l’autorise.',
    'command_path' => '/chemin/vers/secondstay/src/Scheduler/cron.php',
    'never' => 'jamais exécutée',
    'stale' => 'en retard',
    'every' => 'toutes les {minutes} minutes au plus',
    'column' => [
        'task' => 'Tâche',
        'last_run' => 'Dernière exécution',
        'result' => 'Résultat',
    ],
    'action' => [
        'run' => 'Exécuter',
    ],
    'flash' => [
        'done' => 'Tâche exécutée.',
        'skipped' => 'Tâche non exécutée : elle est désactivée ou déjà en cours.',
        'failed' => 'La tâche a échoué. Le détail est dans le journal.',
    ],
    'status' => [
        'never' => 'jamais exécutée',
        'ok' => 'réussie',
        'skipped' => 'ignorée',
        'error' => 'en échec',
    ],
    'task' => [
        'booking_holds' => 'Libération des verrous de réservation expirés',
        'inbound_mail' => 'Relève de la boîte de réception',
        'calendar_import' => 'Synchronisation des calendriers externes',
        'local_content' => 'Génération du contenu local',
        'stay_reminders' => 'Rappels de séjour, arrivées et départs',
        'retention' => 'Purge des données arrivées à échéance',
        'backup' => 'Sauvegarde automatique',
        'update_check' => 'Contrôle des mises à jour',
    ],
    'detail' => [
        'nothing' => 'Rien à faire.',
        'disabled' => 'Fonction désactivée dans les réglages.',
        'no_handler' => 'Aucun traitement n’est branché sur cette tâche.',
        'locked' => 'Une exécution est déjà en cours.',
        'exception' => 'Interrompue par une erreur ; voir le journal.',
        'holds_released' => '{count} verrou(x) libéré(s).',
        'mail_imported' => '{count} message(s) importé(s).',
        'mailbox_unreachable' => 'Boîte de réception injoignable.',
        'no_calendar' => 'Aucun calendrier externe déclaré.',
        'calendar_failed' => '{count} flux n’ont pas répondu.',
        'calendar_events' => '{count} événement(s) importé(s).',
        'local_content' => '{count} séjour(s) rafraîchi(s).',
        'local_content_failed' => '{count} séjour(s) n’ont pas pu être générés.',
        'reminders_sent' => '{count} notification(s) envoyée(s).',
        'purged' => '{count} enregistrement(s) purgé(s).',
        'backup_done' => 'Sauvegarde créée ; {count} ancienne(s) supprimée(s).',
        'update_available' => 'Une mise à jour est disponible.',
        'up_to_date' => 'Le produit est à jour.',
    ],
];
