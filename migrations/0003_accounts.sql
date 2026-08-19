-- SecondStay — comptes clients : jetons, passkeys, consentements et journal e-mail.

CREATE TABLE `user_token` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    NOT NULL,
    `type`       VARCHAR(32)     NOT NULL,
    `token_hash` CHAR(64)        NOT NULL,
    `created_at` DATETIME        NOT NULL,
    `expires_at` DATETIME        NOT NULL,
    `used_at`    DATETIME        NULL,
    `ip`         VARCHAR(45)     NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token_hash` (`token_hash`),
    KEY `idx_token_user_type` (`user_id`, `type`),
    KEY `idx_token_expires` (`expires_at`),
    CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webauthn_credential` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `credential_id` VARCHAR(255) NOT NULL,
    `public_key`    TEXT         NOT NULL,
    `sign_count`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `transports`    VARCHAR(120) NOT NULL DEFAULT '',
    `label`         VARCHAR(120) NOT NULL DEFAULT '',
    `created_at`    DATETIME     NOT NULL,
    `last_used_at`  DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_credential_id` (`credential_id`),
    KEY `idx_credential_user` (`user_id`),
    CONSTRAINT `fk_credential_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mail_message` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at`     DATETIME        NOT NULL,
    `direction`      VARCHAR(12)     NOT NULL DEFAULT 'outbound',
    `status`         VARCHAR(16)     NOT NULL DEFAULT 'queued',
    `template`       VARCHAR(64)     NOT NULL DEFAULT '',
    `locale`         VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `to_address`     VARCHAR(190)    NOT NULL,
    `to_name`        VARCHAR(190)    NOT NULL DEFAULT '',
    `subject`        VARCHAR(255)    NOT NULL DEFAULT '',
    `message_id`     VARCHAR(190)    NOT NULL DEFAULT '',
    `error`          TEXT            NULL,
    `user_id`        INT UNSIGNED    NULL,
    `correlation_id` CHAR(32)        NOT NULL DEFAULT '',
    `sent_at`        DATETIME        NULL,
    PRIMARY KEY (`id`),
    KEY `idx_mail_created` (`created_at`),
    KEY `idx_mail_status` (`status`),
    KEY `idx_mail_user` (`user_id`),
    KEY `idx_mail_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `consent` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `type`        VARCHAR(48)     NOT NULL,
    `version`     VARCHAR(32)     NOT NULL DEFAULT '1',
    `locale`      VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `accepted_at` DATETIME        NOT NULL,
    `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_consent_user_type` (`user_id`, `type`),
    CONSTRAINT `fk_consent_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user`
    ADD COLUMN `anonymised_at` DATETIME NULL AFTER `last_login_at`,
    ADD COLUMN `deletion_requested_at` DATETIME NULL AFTER `anonymised_at`;
