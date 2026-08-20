-- SecondStay — « Mon séjour », informations pratiques et liens invité.

-- Informations pratiques du logement (SPECIFICATIONS.md §44 et §45).
--
-- Une ligne par bloc et par langue : le livret d'accueil existe réellement en
-- quatre langues, il n'est pas traduit à la volée.
CREATE TABLE `stay_info` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(32)     NOT NULL,
    `locale`     VARCHAR(5)      NOT NULL,
    `title`      VARCHAR(190)    NOT NULL DEFAULT '',
    `body`       MEDIUMTEXT      NULL,
    -- Un bloc peut ne concerner qu'une phase du séjour.
    `phase`      VARCHAR(16)     NOT NULL DEFAULT 'any',
    `position`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `published`  TINYINT(1)      NOT NULL DEFAULT 1,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_stay_info` (`code`, `locale`),
    KEY `idx_stay_info_phase` (`phase`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secrets d'accès : mot de passe Wi-Fi, code de boîte à clés, code d'alarme.
--
-- Chiffrés au repos comme n'importe quel secret de l'installation, et jamais
-- publiés hors de la fenêtre du séjour.
CREATE TABLE `stay_secret` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(32)     NOT NULL,
    `value`      TEXT            NULL,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_stay_secret` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liens invité (SPECIFICATIONS.md §46).
--
-- Comme les jetons de calendrier, seule l'empreinte est stockée. Un lien
-- invité expire, se révoque, et ne donne accès qu'aux informations pratiques
-- d'un séjour : ni finances, ni documents, ni compte.
CREATE TABLE `guest_link` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`   INT UNSIGNED    NOT NULL,
    `token_hash`   CHAR(64)        NOT NULL,
    `label`        VARCHAR(120)    NOT NULL DEFAULT '',
    `locale`       VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `created_by`   INT UNSIGNED    NULL,
    `created_at`   DATETIME        NOT NULL,
    `expires_at`   DATETIME        NOT NULL,
    `last_used_at` DATETIME        NULL,
    `revoked_at`   DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_guest_link` (`token_hash`),
    KEY `idx_guest_link_booking` (`booking_id`, `revoked_at`),
    CONSTRAINT `fk_guest_link_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
