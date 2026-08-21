-- SecondStay — contenu local généré : sources, activités, exécutions.

-- Sources consultées pour produire le contenu local (SPECIFICATIONS.md §56).
--
-- Ce sont des URL simples saisies par le propriétaire : office de tourisme,
-- agenda de la commune, marché hebdomadaire. Rien n'est deviné.
CREATE TABLE `local_source` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `url`           VARCHAR(500)    NOT NULL,
    `label`         VARCHAR(190)    NOT NULL DEFAULT '',
    `active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `last_fetch_at` DATETIME        NULL,
    `last_status`   VARCHAR(48)     NOT NULL DEFAULT '',
    `created_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_local_source_url` (`url`),
    KEY `idx_local_source_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une exécution du pipeline : ce qui a été demandé, ce qui en est sorti.
--
-- Garder la trace des exécutions permet de dire au propriétaire *pourquoi*
-- une page est vide, plutôt que de le laisser deviner.
CREATE TABLE `local_generation` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED    NULL,
    `locale`      VARCHAR(5)      NOT NULL,
    `status`      VARCHAR(24)     NOT NULL,
    `provider`    VARCHAR(48)     NOT NULL DEFAULT '',
    `model`       VARCHAR(64)     NOT NULL DEFAULT '',
    `sources`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `items`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `error`       VARCHAR(190)    NOT NULL DEFAULT '',
    `range_start` DATE            NULL,
    `range_end`   DATE            NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_local_generation_booking` (`booking_id`, `created_at`),
    CONSTRAINT `fk_local_generation_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activités locales structurées (SPECIFICATIONS.md §58).
--
-- Chaque activité porte ses dates exactes, sa source et la date à laquelle
-- elle a été vérifiée : sans cela, elle ne pourrait pas être affichée.
CREATE TABLE `local_activity` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `generation_id`    INT UNSIGNED    NOT NULL,
    `booking_id`       INT UNSIGNED    NULL,
    `locale`           VARCHAR(5)      NOT NULL,
    `title`            VARCHAR(190)    NOT NULL,
    `summary`          TEXT            NULL,
    `category`         VARCHAR(32)     NOT NULL DEFAULT 'other',
    `starts_on`        DATE            NOT NULL,
    `ends_on`          DATE            NOT NULL,
    `booking_required` TINYINT(1)      NOT NULL DEFAULT 0,
    `location`         VARCHAR(190)    NOT NULL DEFAULT '',
    `source_url`       VARCHAR(500)    NOT NULL,
    `verified_on`      DATE            NOT NULL,
    `created_at`       DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_local_activity_window` (`booking_id`, `locale`, `starts_on`, `ends_on`),
    KEY `idx_local_activity_generation` (`generation_id`),
    CONSTRAINT `fk_local_activity_generation` FOREIGN KEY (`generation_id`)
        REFERENCES `local_generation` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_local_activity_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
