-- SecondStay — calendriers externes, litiges et provenance des blocages.

-- Calendriers importés (SPECIFICATIONS.md §52).
--
-- Airbnb, Booking, Abritel ou tout autre flux ICS public : les événements
-- bloquent les nuits et **gardent leur provenance**, pour qu'un blocage
-- importé ne soit jamais confondu avec une décision du propriétaire.
CREATE TABLE `external_calendar` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `url`          VARCHAR(500)    NOT NULL,
    `label`        VARCHAR(190)    NOT NULL DEFAULT '',
    `provider`     VARCHAR(32)     NOT NULL DEFAULT 'other',
    `active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `last_sync_at` DATETIME        NULL,
    `last_status`  VARCHAR(48)     NOT NULL DEFAULT '',
    `last_events`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_external_calendar_url` (`url`),
    KEY `idx_external_calendar_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenance d'un blocage.
--
-- `source_id` distingue ce qui vient d'un flux de ce que le propriétaire a
-- décidé lui-même : une synchronisation ne peut alors effacer que ses propres
-- lignes. `external_uid` rend l'import idempotent — le même événement importé
-- deux fois reste un seul blocage.
ALTER TABLE `availability_block`
    ADD COLUMN `source_id` INT UNSIGNED NULL AFTER `kind`,
    ADD COLUMN `external_uid` VARCHAR(190) NOT NULL DEFAULT '' AFTER `source_id`,
    ADD KEY `idx_block_source` (`source_id`),
    ADD CONSTRAINT `fk_block_source` FOREIGN KEY (`source_id`)
        REFERENCES `external_calendar` (`id`) ON DELETE CASCADE;

-- Litiges : la discussion qui suit un séjour, quand elle a lieu.
--
-- Un litige rassemble ce que le produit a déjà collecté — caution, états des
-- lieux, incidents, contrat accepté — au lieu de le laisser éparpillé dans des
-- e-mails.
CREATE TABLE `dispute` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`   INT UNSIGNED    NOT NULL,
    `kind`         VARCHAR(32)     NOT NULL DEFAULT 'deposit',
    `status`       VARCHAR(24)     NOT NULL DEFAULT 'open',
    `claimed_cents` INT UNSIGNED   NOT NULL DEFAULT 0,
    `settled_cents` INT UNSIGNED   NOT NULL DEFAULT 0,
    `currency`     CHAR(3)         NOT NULL DEFAULT 'EUR',
    `summary`      VARCHAR(190)    NOT NULL DEFAULT '',
    `resolution`   TEXT            NULL,
    `locale`       VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `opened_by`    INT UNSIGNED    NULL,
    `opened_at`    DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    `resolved_at`  DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_dispute_booking_kind` (`booking_id`, `kind`),
    KEY `idx_dispute_status` (`status`, `opened_at`),
    CONSTRAINT `fk_dispute_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique d'un litige, en ajout seul.
CREATE TABLE `dispute_event` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `dispute_id`  INT UNSIGNED    NOT NULL,
    `type`        VARCHAR(32)     NOT NULL,
    `note`        VARCHAR(255)    NOT NULL DEFAULT '',
    `actor_id`    INT UNSIGNED    NULL,
    `actor_label` VARCHAR(190)    NOT NULL DEFAULT '',
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dispute_event` (`dispute_id`, `id`),
    CONSTRAINT `fk_dispute_event` FOREIGN KEY (`dispute_id`)
        REFERENCES `dispute` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
