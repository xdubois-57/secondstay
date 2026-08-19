-- SecondStay — notifications : abonnements push et journal des envois.

CREATE TABLE `push_subscription` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `endpoint`      VARCHAR(2000)   NOT NULL,
    `endpoint_hash` CHAR(64)        NOT NULL,
    `public_key`    VARCHAR(255)    NOT NULL,
    `auth_secret`   VARCHAR(64)     NOT NULL,
    `locale`        CHAR(2)         NOT NULL DEFAULT 'fr',
    `user_agent`    VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at`    DATETIME        NOT NULL,
    `last_used_at`  DATETIME        NULL,
    `failures`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_push_endpoint` (`endpoint_hash`),
    KEY `idx_push_user` (`user_id`),
    CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par tentative et par canal : e-mail et push sont indépendants.
CREATE TABLE `notification` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at`     DATETIME        NOT NULL,
    `event`          VARCHAR(64)     NOT NULL,
    `channel`        VARCHAR(16)     NOT NULL,
    `status`         VARCHAR(16)     NOT NULL,
    `user_id`        INT UNSIGNED    NULL,
    `locale`         CHAR(2)         NOT NULL DEFAULT 'fr',
    `subject`        VARCHAR(255)    NOT NULL DEFAULT '',
    `reference`      VARCHAR(190)    NOT NULL DEFAULT '',
    `error`          VARCHAR(255)    NOT NULL DEFAULT '',
    `correlation_id` CHAR(32)        NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_notification_event` (`event`, `created_at`),
    KEY `idx_notification_user` (`user_id`),
    CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Préférences par utilisateur et par canal ; l'absence de ligne vaut « actif ».
CREATE TABLE `notification_preference` (
    `user_id`  INT UNSIGNED NOT NULL,
    `channel`  VARCHAR(16)  NOT NULL,
    `enabled`  TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `channel`),
    CONSTRAINT `fk_preference_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
