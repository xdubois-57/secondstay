-- SecondStay — contenus éditoriaux traduisibles, galerie et menu multi-niveaux.

CREATE TABLE `content_page` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`    INT UNSIGNED NULL,
    `slug`         VARCHAR(120) NOT NULL,
    `kind`         VARCHAR(24)  NOT NULL DEFAULT 'page',
    `season`       VARCHAR(12)  NOT NULL DEFAULT 'all',
    `position`     INT          NOT NULL DEFAULT 0,
    `is_published` TINYINT(1)   NOT NULL DEFAULT 0,
    `show_in_menu` TINYINT(1)   NOT NULL DEFAULT 1,
    `is_system`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL,
    `updated_at`   DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_page_slug` (`slug`),
    KEY `idx_page_parent` (`parent_id`),
    KEY `idx_page_published` (`is_published`),
    CONSTRAINT `fk_page_parent` FOREIGN KEY (`parent_id`) REFERENCES `content_page` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `content_translation` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content_page_id`  INT UNSIGNED NOT NULL,
    `locale`           VARCHAR(5)   NOT NULL,
    `title`            VARCHAR(190) NOT NULL DEFAULT '',
    `menu_label`       VARCHAR(120) NOT NULL DEFAULT '',
    `lead`             TEXT         NULL,
    `body`             LONGTEXT     NULL,
    `meta_title`       VARCHAR(190) NOT NULL DEFAULT '',
    `meta_description` VARCHAR(320) NOT NULL DEFAULT '',
    `updated_at`       DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_translation_page_locale` (`content_page_id`, `locale`),
    CONSTRAINT `fk_translation_page` FOREIGN KEY (`content_page_id`) REFERENCES `content_page` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `filename`          VARCHAR(190)    NOT NULL,
    `original_filename` VARCHAR(190)    NOT NULL DEFAULT '',
    `mime_type`         VARCHAR(80)     NOT NULL,
    `size_bytes`        INT UNSIGNED    NOT NULL DEFAULT 0,
    `width`             INT UNSIGNED    NOT NULL DEFAULT 0,
    `height`            INT UNSIGNED    NOT NULL DEFAULT 0,
    `category`          VARCHAR(48)     NOT NULL DEFAULT 'general',
    `season`            VARCHAR(12)     NOT NULL DEFAULT 'all',
    `position`          INT             NOT NULL DEFAULT 0,
    `is_published`      TINYINT(1)      NOT NULL DEFAULT 1,
    `is_private`        TINYINT(1)      NOT NULL DEFAULT 0,
    `hash`              CHAR(64)        NOT NULL DEFAULT '',
    `created_at`        DATETIME        NOT NULL,
    `updated_at`        DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_media_filename` (`filename`),
    KEY `idx_media_category` (`category`),
    KEY `idx_media_published` (`is_published`),
    KEY `idx_media_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_translation` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_id`   INT UNSIGNED NOT NULL,
    `locale`     VARCHAR(5)   NOT NULL,
    `caption`    VARCHAR(255) NOT NULL DEFAULT '',
    `alt_text`   VARCHAR(255) NOT NULL DEFAULT '',
    `updated_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_media_locale` (`media_id`, `locale`),
    CONSTRAINT `fk_media_translation` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
