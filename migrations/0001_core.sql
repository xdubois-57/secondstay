-- SecondStay — socle : réglages, comptes, sessions, journal technique et audit.

CREATE TABLE `setting` (
    `key`        VARCHAR(120)    NOT NULL,
    `value`      LONGTEXT        NULL,
    `is_secret`  TINYINT(1)      NOT NULL DEFAULT 0,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`             VARCHAR(190)    NOT NULL,
    `password_hash`     VARCHAR(255)    NULL,
    `first_name`        VARCHAR(120)    NOT NULL DEFAULT '',
    `last_name`         VARCHAR(120)    NOT NULL DEFAULT '',
    `phone`             VARCHAR(40)     NOT NULL DEFAULT '',
    `role`              VARCHAR(32)     NOT NULL DEFAULT 'customer',
    `locale`            VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `status`            VARCHAR(24)     NOT NULL DEFAULT 'pending',
    `email_verified_at` DATETIME        NULL,
    `last_login_at`     DATETIME        NULL,
    `created_at`        DATETIME        NOT NULL,
    `updated_at`        DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_email` (`email`),
    KEY `idx_user_role` (`role`),
    KEY `idx_user_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_session` (
    `id`           CHAR(64)     NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `created_at`   DATETIME     NOT NULL,
    `last_seen_at` DATETIME     NOT NULL,
    `expires_at`   DATETIME     NOT NULL,
    `revoked_at`   DATETIME     NULL,
    `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_session_user` (`user_id`),
    KEY `idx_session_expires` (`expires_at`),
    CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_log` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at`     DATETIME        NOT NULL,
    `level`          VARCHAR(16)     NOT NULL,
    `category`       VARCHAR(64)     NOT NULL,
    `message`        TEXT            NOT NULL,
    `context`        JSON            NULL,
    `user_id`        INT UNSIGNED    NULL,
    `correlation_id` CHAR(32)        NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_log_created` (`created_at`),
    KEY `idx_log_level` (`level`),
    KEY `idx_log_category` (`category`),
    KEY `idx_log_correlation` (`correlation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_event` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at`     DATETIME        NOT NULL,
    `actor_user_id`  INT UNSIGNED    NULL,
    `actor_label`    VARCHAR(190)    NOT NULL DEFAULT '',
    `action`         VARCHAR(96)     NOT NULL,
    `entity_type`    VARCHAR(64)     NOT NULL DEFAULT '',
    `entity_id`      VARCHAR(64)     NOT NULL DEFAULT '',
    `before_state`   JSON            NULL,
    `after_state`    JSON            NULL,
    `ip`             VARCHAR(45)     NOT NULL DEFAULT '',
    `correlation_id` CHAR(32)        NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rate_limit` (
    `bucket`       VARCHAR(190) NOT NULL,
    `window_start` DATETIME     NOT NULL,
    `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
