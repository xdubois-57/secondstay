-- SecondStay — paiements : composants financiers, historique et webhooks.

-- Un objet par composant (SPECIFICATIONS.md §29) : hébergement, acompte,
-- solde, caution, ménage, taxe de séjour, ajustements et remboursements sont
-- des lignes distinctes, chacune avec son montant, son échéance et son état.
CREATE TABLE `payment` (
    `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`         INT UNSIGNED    NOT NULL,
    `kind`               VARCHAR(24)     NOT NULL,
    `status`             VARCHAR(24)     NOT NULL DEFAULT 'pending',
    `amount_cents`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `refunded_cents`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `currency`           CHAR(3)         NOT NULL DEFAULT 'EUR',
    `method`             VARCHAR(24)     NOT NULL DEFAULT 'provider',
    `due_on`             DATE            NULL,
    `provider`           VARCHAR(24)     NOT NULL DEFAULT '',
    -- NULL tant qu'aucun paiement n'est ouvert chez le fournisseur : sans
    -- cela, l'unicite ci-dessous interdirait deux composants en attente sur
    -- un meme sejour.
    `provider_reference` VARCHAR(190)    NULL DEFAULT NULL,
    `description`        VARCHAR(190)    NOT NULL DEFAULT '',
    -- Cycle propre à la caution (SPECIFICATIONS.md §32).
    `hold_status`        VARCHAR(24)     NOT NULL DEFAULT 'none',
    `created_at`         DATETIME        NOT NULL,
    `updated_at`         DATETIME        NOT NULL,
    `paid_at`            DATETIME        NULL,
    `refunded_at`        DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payment_provider_reference` (`provider`, `provider_reference`),
    KEY `idx_payment_booking` (`booking_id`, `kind`),
    KEY `idx_payment_status` (`status`, `due_on`),
    CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique de chaque composant (SPECIFICATIONS.md §29).
CREATE TABLE `payment_event` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id` INT UNSIGNED    NOT NULL,
    `created_at` DATETIME        NOT NULL,
    `type`       VARCHAR(48)     NOT NULL,
    `data`       JSON            NULL,
    PRIMARY KEY (`id`),
    KEY `idx_payment_event` (`payment_id`, `id`),
    CONSTRAINT `fk_payment_event` FOREIGN KEY (`payment_id`) REFERENCES `payment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotence des webhooks (SPECIFICATIONS.md §34).
--
-- L'unicité porte sur le couple fournisseur / identifiant d'événement : un
-- même événement rejoué, ou reçu dans le désordre, ne peut pas être traité
-- deux fois.
CREATE TABLE `webhook_event` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider`     VARCHAR(24)     NOT NULL,
    `external_id`  VARCHAR(190)    NOT NULL,
    `payload_hash` CHAR(64)        NOT NULL,
    `status`       VARCHAR(24)     NOT NULL DEFAULT 'received',
    `attempts`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `error`        VARCHAR(255)    NOT NULL DEFAULT '',
    `received_at`  DATETIME        NOT NULL,
    `processed_at` DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_webhook_event` (`provider`, `external_id`),
    KEY `idx_webhook_status` (`status`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
