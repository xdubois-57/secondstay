-- SecondStay — responsable local, checklists et calendriers privés.

-- Responsable affecté à un séjour (SPECIFICATIONS.md §48).
--
-- `ON DELETE SET NULL` : supprimer un compte ne doit jamais faire disparaître
-- un séjour, seulement son affectation.
ALTER TABLE `booking`
    ADD COLUMN `manager_id` INT UNSIGNED NULL AFTER `user_id`,
    ADD KEY `idx_booking_manager` (`manager_id`, `arrival`),
    ADD CONSTRAINT `fk_booking_manager` FOREIGN KEY (`manager_id`)
        REFERENCES `user` (`id`) ON DELETE SET NULL;

-- Flux ICS privés (SPECIFICATIONS.md §51).
--
-- Seule l'empreinte du jeton est stockée : une fuite de la base ne donne pas
-- accès aux calendriers. Le jeton en clair n'est montré qu'une fois, à sa
-- création.
CREATE TABLE `calendar_token` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `scope`        VARCHAR(16)     NOT NULL,
    `token_hash`   CHAR(64)        NOT NULL,
    `label`        VARCHAR(120)    NOT NULL DEFAULT '',
    `user_id`      INT UNSIGNED    NULL,
    `booking_id`   INT UNSIGNED    NULL,
    `created_at`   DATETIME        NOT NULL,
    `last_used_at` DATETIME        NULL,
    `revoked_at`   DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_calendar_token` (`token_hash`),
    KEY `idx_calendar_scope` (`scope`, `user_id`),
    CONSTRAINT `fk_calendar_token_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calendar_token_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tâches d'exploitation d'un séjour (SPECIFICATIONS.md §49).
--
-- Les éléments dérivés de l'état du séjour — contrat, acompte, solde, caution
-- — ne sont pas stockés : ils se lisent là où ils vivent, et les dupliquer
-- créerait deux vérités. Cette table ne porte que les tâches propres à
-- l'exploitation, cochées par un humain.
CREATE TABLE `booking_task` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED    NOT NULL,
    `phase`       VARCHAR(16)     NOT NULL DEFAULT 'before',
    `code`        VARCHAR(32)     NOT NULL,
    `label`       VARCHAR(190)    NOT NULL DEFAULT '',
    `done_at`     DATETIME        NULL,
    `done_by`     INT UNSIGNED    NULL,
    `note`        VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_booking_task` (`booking_id`, `code`),
    KEY `idx_task_phase` (`booking_id`, `phase`),
    CONSTRAINT `fk_task_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
