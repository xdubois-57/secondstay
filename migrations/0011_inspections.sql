-- SecondStay — états des lieux et incidents.

-- Zones du logement (SPECIFICATIONS.md §53).
--
-- L'ordre est celui du parcours réel dans le logement : un état des lieux se
-- fait pièce après pièce, pas dans l'ordre alphabétique.
CREATE TABLE `inspection_zone` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`             VARCHAR(32)     NOT NULL,
    `position`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Une photo au départ peut être exigée zone par zone.
    `photo_required`   TINYINT(1)      NOT NULL DEFAULT 0,
    `active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `reference_note`   VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at`       DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_zone_code` (`code`),
    KEY `idx_zone_order` (`active`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libellés des zones, une ligne par langue : les formulaires d'état des lieux
-- sont remplis sur place, dans la langue du voyageur.
CREATE TABLE `inspection_zone_translation` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `zone_id`      INT UNSIGNED    NOT NULL,
    `locale`       VARCHAR(5)      NOT NULL,
    `name`         VARCHAR(190)    NOT NULL DEFAULT '',
    `instructions` TEXT            NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_zone_locale` (`zone_id`, `locale`),
    CONSTRAINT `fk_zone_translation` FOREIGN KEY (`zone_id`)
        REFERENCES `inspection_zone` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos de référence d'une zone : l'état attendu, tel que le propriétaire
-- l'a photographié une fois pour toutes.
CREATE TABLE `inspection_reference` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `zone_id`     INT UNSIGNED    NOT NULL,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_reference_zone` (`zone_id`, `position`),
    CONSTRAINT `fk_reference_zone` FOREIGN KEY (`zone_id`)
        REFERENCES `inspection_zone` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reference_document` FOREIGN KEY (`document_id`)
        REFERENCES `document` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- État des lieux d'un séjour : un à l'arrivée, un au départ.
CREATE TABLE `inspection` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`   INT UNSIGNED    NOT NULL,
    `kind`         VARCHAR(16)     NOT NULL,
    `status`       VARCHAR(16)     NOT NULL DEFAULT 'open',
    `locale`       VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `started_at`   DATETIME        NOT NULL,
    `completed_at` DATETIME        NULL,
    `completed_by` INT UNSIGNED    NULL,
    `summary`      VARCHAR(255)    NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    -- Un seul état des lieux d'arrivée et un seul de départ par séjour.
    UNIQUE KEY `uniq_inspection` (`booking_id`, `kind`),
    CONSTRAINT `fk_inspection_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Constat par zone.
CREATE TABLE `inspection_entry` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `inspection_id` INT UNSIGNED    NOT NULL,
    `zone_id`       INT UNSIGNED    NOT NULL,
    `state`         VARCHAR(16)     NOT NULL DEFAULT 'pending',
    `note`          TEXT            NULL,
    `updated_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_entry` (`inspection_id`, `zone_id`),
    CONSTRAINT `fk_entry_inspection` FOREIGN KEY (`inspection_id`)
        REFERENCES `inspection` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entry_zone` FOREIGN KEY (`zone_id`)
        REFERENCES `inspection_zone` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos prises pendant un état des lieux. Le fichier lui-même est un
-- document ordinaire : même stockage, même contrôle d'accès.
CREATE TABLE `inspection_photo` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `entry_id`    INT UNSIGNED    NOT NULL,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_photo_entry` (`entry_id`),
    CONSTRAINT `fk_photo_entry` FOREIGN KEY (`entry_id`)
        REFERENCES `inspection_entry` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_photo_document` FOREIGN KEY (`document_id`)
        REFERENCES `document` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Incidents (SPECIFICATIONS.md §54).
CREATE TABLE `incident` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED    NULL,
    `zone_id`     INT UNSIGNED    NULL,
    `severity`    VARCHAR(16)     NOT NULL DEFAULT 'normal',
    `status`      VARCHAR(16)     NOT NULL DEFAULT 'reported',
    `title`       VARCHAR(190)    NOT NULL,
    `description` TEXT            NULL,
    `locale`      VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `reported_by` INT UNSIGNED    NULL,
    `assigned_to` INT UNSIGNED    NULL,
    `created_at`  DATETIME        NOT NULL,
    `updated_at`  DATETIME        NOT NULL,
    `resolved_at` DATETIME        NULL,
    PRIMARY KEY (`id`),
    KEY `idx_incident_status` (`status`, `created_at`),
    KEY `idx_incident_booking` (`booking_id`),
    CONSTRAINT `fk_incident_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_incident_zone` FOREIGN KEY (`zone_id`)
        REFERENCES `inspection_zone` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique d'un incident : qui a fait quoi, et quand.
CREATE TABLE `incident_event` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `incident_id` INT UNSIGNED    NOT NULL,
    `type`        VARCHAR(32)     NOT NULL,
    `note`        VARCHAR(255)    NOT NULL DEFAULT '',
    `actor_id`    INT UNSIGNED    NULL,
    `actor_label` VARCHAR(190)    NOT NULL DEFAULT '',
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_incident_event` (`incident_id`, `id`),
    CONSTRAINT `fk_incident_event` FOREIGN KEY (`incident_id`)
        REFERENCES `incident` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos d'un incident.
CREATE TABLE `incident_photo` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `incident_id` INT UNSIGNED    NOT NULL,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_incident_photo` (`incident_id`),
    CONSTRAINT `fk_incident_photo_incident` FOREIGN KEY (`incident_id`)
        REFERENCES `incident` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_incident_photo_document` FOREIGN KEY (`document_id`)
        REFERENCES `document` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
