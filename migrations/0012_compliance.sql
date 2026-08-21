-- SecondStay — conformité France, textes légaux versionnés et rétention.

-- Textes légaux publiés, une version par langue (SPECIFICATIONS.md §65).
--
-- Une version publiée est **immuable** : c'est un instantané du texte au
-- moment où il a été mis en ligne. Sans cela, une réservation ne pourrait pas
-- prouver ce que son voyageur a réellement accepté.
CREATE TABLE `legal_document` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `type`         VARCHAR(24)     NOT NULL,
    `locale`       VARCHAR(5)      NOT NULL,
    `version`      VARCHAR(32)     NOT NULL,
    `title`        VARCHAR(190)    NOT NULL DEFAULT '',
    `body`         MEDIUMTEXT      NOT NULL,
    `sha256`       CHAR(64)        NOT NULL,
    `published_at` DATETIME        NOT NULL,
    `published_by` INT UNSIGNED    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_legal_version` (`type`, `locale`, `version`),
    KEY `idx_legal_current` (`type`, `locale`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce qu'un séjour a réellement accepté : la version **et** la langue.
CREATE TABLE `booking_consent` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED    NOT NULL,
    `type`        VARCHAR(24)     NOT NULL,
    `version`     VARCHAR(32)     NOT NULL,
    `locale`      VARCHAR(5)      NOT NULL,
    `document_id` INT UNSIGNED    NULL,
    `sha256`      CHAR(64)        NOT NULL DEFAULT '',
    `accepted_at` DATETIME        NOT NULL,
    -- L'adresse n'est conservée que hachée : elle sert de preuve, pas de
    -- moyen de retrouver quelqu'un (SECURITY.md).
    `ip_hash`     CHAR(64)        NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_booking_consent` (`booking_id`, `type`),
    CONSTRAINT `fk_consent_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_consent_document` FOREIGN KEY (`document_id`)
        REFERENCES `legal_document` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assistant conformité (SPECIFICATIONS.md §61 et §62).
--
-- Une ligne par sujet : ce qui est propre à **ce** logement — statut, valeur,
-- source officielle, date de vérification — vit ici, jamais dans le code.
CREATE TABLE `compliance_item` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `topic`         VARCHAR(48)     NOT NULL,
    `status`        VARCHAR(24)     NOT NULL DEFAULT 'to_verify',
    `value`         VARCHAR(190)    NOT NULL DEFAULT '',
    `notes`         TEXT            NULL,
    `source_url`    VARCHAR(500)    NOT NULL DEFAULT '',
    `last_verified` DATE            NULL,
    `next_review`   DATE            NULL,
    `evidence_id`   BIGINT UNSIGNED NULL,
    `updated_at`    DATETIME        NOT NULL,
    `updated_by`    INT UNSIGNED    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_compliance_topic` (`topic`),
    KEY `idx_compliance_review` (`status`, `next_review`),
    CONSTRAINT `fk_compliance_evidence` FOREIGN KEY (`evidence_id`)
        REFERENCES `document` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de taxe de séjour, versionnées par date d'effet
-- (SPECIFICATIONS.md §63).
CREATE TABLE `tourist_tax_rule` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `territory`             VARCHAR(120)    NOT NULL DEFAULT '',
    `classification`        VARCHAR(48)     NOT NULL DEFAULT 'unclassified',
    `effective_from`        DATE            NOT NULL,
    -- Ouverte tant que la règle suivante n'existe pas.
    `effective_to`          DATE            NULL,
    `per_adult_night_cents` INT UNSIGNED    NOT NULL DEFAULT 0,
    `cap_per_stay_cents`    INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Âge à partir duquel la taxe est due (18 ans en France).
    `taxable_from_age`      TINYINT UNSIGNED NOT NULL DEFAULT 18,
    `source_url`            VARCHAR(500)    NOT NULL DEFAULT '',
    `notes`                 VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at`            DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tax_rule_period` (`classification`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contexte de calcul figé avec le séjour : un séjour historique reste
-- explicable même après un changement de barème.
CREATE TABLE `booking_tax_context` (
    `booking_id`   INT UNSIGNED    NOT NULL,
    `rule_id`      INT UNSIGNED    NULL,
    `amount_cents` INT UNSIGNED    NOT NULL DEFAULT 0,
    `context`      TEXT            NOT NULL,
    `computed_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`booking_id`),
    CONSTRAINT `fk_tax_context_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tax_context_rule` FOREIGN KEY (`rule_id`)
        REFERENCES `tourist_tax_rule` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fiche de police (SPECIFICATIONS.md §64).
--
-- Uniquement si applicable, données chiffrées, purge automatique à
-- l'échéance : le contenu n'est jamais lisible en base, et il ne survit pas à
-- la durée de conservation configurée.
CREATE TABLE `police_record` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `booking_id` INT UNSIGNED    NOT NULL,
    `payload`    TEXT            NOT NULL,
    `locale`     VARCHAR(5)      NOT NULL DEFAULT 'fr',
    `created_at` DATETIME        NOT NULL,
    `created_by` INT UNSIGNED    NULL,
    `purge_after` DATE           NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_police_booking` (`booking_id`),
    KEY `idx_police_purge` (`purge_after`),
    CONSTRAINT `fk_police_booking` FOREIGN KEY (`booking_id`)
        REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
