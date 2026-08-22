-- SecondStay — planificateur de tâches périodiques (ARCHITECTURE.md §23).

-- État des tâches périodiques.
--
-- Le produit ne fait pas tourner de worker permanent : une seule entrée cron
-- appelle `src/Scheduler/cron.php`, qui exécute ce qui est dû. Cette table
-- porte donc **tout** l'état du planificateur — dernière exécution, résultat,
-- verrou — parce que le processus, lui, ne survit pas d'un appel à l'autre.
--
-- `locked_until` est un verrou à échéance plutôt qu'un drapeau : un processus
-- tué par l'hébergeur au milieu d'une tâche ne doit pas bloquer celle-ci pour
-- toujours. Le verrou est pris par un `UPDATE` conditionnel, seule primitive
-- atomique dont on dispose sans dépendance supplémentaire.
CREATE TABLE `scheduled_task` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `code`             VARCHAR(48)       NOT NULL,
    `last_run_at`      DATETIME          NULL,
    `last_status`      VARCHAR(16)       NOT NULL DEFAULT 'never',
    `last_detail`      VARCHAR(128)      NOT NULL DEFAULT '',
    `last_count`       INT UNSIGNED      NOT NULL DEFAULT 0,
    `last_duration_ms` INT UNSIGNED      NOT NULL DEFAULT 0,
    `locked_until`     DATETIME          NULL,
    `consecutive_failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `runs`             INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_scheduled_task_code` (`code`),
    KEY `idx_scheduled_task_run` (`last_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
