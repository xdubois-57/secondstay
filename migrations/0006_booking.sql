-- SecondStay — réservations : séjours, nuits réservées, journal et codes promo.

CREATE TABLE `booking` (
    `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `reference`          VARCHAR(16)     NOT NULL,
    `user_id`            INT UNSIGNED    NULL,
    `status`             VARCHAR(24)     NOT NULL,
    `arrival`            DATE            NOT NULL,
    `departure`          DATE            NOT NULL,
    `adults`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `children`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `infants`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locale`             CHAR(2)         NOT NULL DEFAULT 'fr',
    `guest_email`        VARCHAR(190)    NOT NULL DEFAULT '',
    `guest_name`         VARCHAR(190)    NOT NULL DEFAULT '',
    `guest_phone`        VARCHAR(40)     NOT NULL DEFAULT '',
    `message`            TEXT            NULL,
    `cleaning`           TINYINT(1)      NOT NULL DEFAULT 1,
    `promo_code`         VARCHAR(32)     NOT NULL DEFAULT '',
    -- Montants figés à la réservation : un changement de tarif ultérieur ne
    -- réécrit jamais un séjour déjà engagé.
    `accommodation_cents` INT UNSIGNED   NOT NULL DEFAULT 0,
    `cleaning_cents`      INT UNSIGNED   NOT NULL DEFAULT 0,
    `discount_cents`      INT UNSIGNED   NOT NULL DEFAULT 0,
    `total_cents`         INT UNSIGNED   NOT NULL DEFAULT 0,
    `deposit_cents`       INT UNSIGNED   NOT NULL DEFAULT 0,
    `security_deposit_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`           CHAR(3)         NOT NULL DEFAULT 'EUR',
    -- Sous-états séparés (SPECIFICATIONS.md §26).
    `contract_status`    VARCHAR(24)     NOT NULL DEFAULT 'none',
    `payment_status`     VARCHAR(24)     NOT NULL DEFAULT 'none',
    `deposit_status`     VARCHAR(24)     NOT NULL DEFAULT 'none',
    `cleaning_status`    VARCHAR(24)     NOT NULL DEFAULT 'none',
    `checkin_status`     VARCHAR(24)     NOT NULL DEFAULT 'none',
    `checkout_status`    VARCHAR(24)     NOT NULL DEFAULT 'none',
    `expires_at`         DATETIME        NULL,
    `created_at`         DATETIME        NOT NULL,
    `updated_at`         DATETIME        NOT NULL,
    `confirmed_at`       DATETIME        NULL,
    `cancelled_at`       DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_booking_reference` (`reference`),
    KEY `idx_booking_status` (`status`, `arrival`),
    KEY `idx_booking_user` (`user_id`),
    KEY `idx_booking_dates` (`arrival`, `departure`),
    KEY `idx_booking_expires` (`expires_at`),
    CONSTRAINT `fk_booking_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cœur de l'anti-double-réservation (SPECIFICATIONS.md §27).
--
-- Une nuit occupée existe ici une fois et une seule : la contrainte d'unicité
-- fait le travail à la place d'un verrou applicatif. Deux transactions
-- concurrentes qui visent la même nuit ne peuvent pas réussir toutes les
-- deux, quel que soit l'ordre d'exécution.
CREATE TABLE `booking_night` (
    `day`        DATE         NOT NULL,
    `booking_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`day`),
    KEY `idx_night_booking` (`booking_id`),
    CONSTRAINT `fk_night_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline de toutes les étapes importantes (SPECIFICATIONS.md §25).
CREATE TABLE `booking_event` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`     INT UNSIGNED    NOT NULL,
    `created_at`     DATETIME        NOT NULL,
    `type`           VARCHAR(48)     NOT NULL,
    `actor_user_id`  INT UNSIGNED    NULL,
    `actor_label`    VARCHAR(190)    NOT NULL DEFAULT '',
    `data`           JSON            NULL,
    PRIMARY KEY (`id`),
    KEY `idx_event_booking` (`booking_id`, `id`),
    CONSTRAINT `fk_event_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `promo_code` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`        VARCHAR(32)     NOT NULL,
    `kind`        VARCHAR(16)     NOT NULL DEFAULT 'percent',
    `value`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `starts_on`   DATE            NULL,
    `ends_on`     DATE            NULL,
    `max_uses`    SMALLINT UNSIGNED NULL,
    `used_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `label`       VARCHAR(190)    NOT NULL DEFAULT '',
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_promo_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liste d'attente (SPECIFICATIONS.md §28).
CREATE TABLE `waitlist_entry` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NULL,
    `email`       VARCHAR(190) NOT NULL,
    `arrival`     DATE         NOT NULL,
    `departure`   DATE         NOT NULL,
    `locale`      CHAR(2)      NOT NULL DEFAULT 'fr',
    `created_at`  DATETIME     NOT NULL,
    `notified_at` DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_waitlist` (`email`, `arrival`, `departure`),
    KEY `idx_waitlist_dates` (`arrival`, `departure`),
    CONSTRAINT `fk_waitlist_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
