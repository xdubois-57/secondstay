-- SecondStay — disponibilités et prix : tarifs par nuit et indisponibilités.

-- Un tarif par date : l'absence de ligne signifie « tarif par défaut ».
CREATE TABLE `rate_override` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `day`         DATE            NOT NULL,
    `price_cents` INT UNSIGNED    NOT NULL,
    `min_nights`  TINYINT UNSIGNED NULL,
    `note`        VARCHAR(190)    NOT NULL DEFAULT '',
    `updated_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_rate_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indisponibilités décidées côté propriétaire ou responsable local.
-- `end_day` est la dernière nuit occupée, pas le jour de départ.
CREATE TABLE `availability_block` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `start_day`  DATE         NOT NULL,
    `end_day`    DATE         NOT NULL,
    `kind`       VARCHAR(24)  NOT NULL DEFAULT 'owner',
    `label`      VARCHAR(190) NOT NULL DEFAULT '',
    `created_at` DATETIME     NOT NULL,
    `created_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_block_range` (`start_day`, `end_day`),
    CONSTRAINT `fk_block_user` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les pages système « disponibilités » et « tarifs » deviennent fonctionnelles :
-- elles conservent leur contenu éditorial et gagnent calendrier et règles.
UPDATE `content_page` SET `kind` = 'availability' WHERE `slug` = 'availability' AND `is_system` = 1;
UPDATE `content_page` SET `kind` = 'rates' WHERE `slug` = 'rates' AND `is_system` = 1;
