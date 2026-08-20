-- SecondStay — contrats, documents et courrier entrant.

-- Documents rattachés à un séjour (SPECIFICATIONS.md §41).
--
-- Le fichier vit hors du document root ; seule sa localisation relative est
-- stockée. L'empreinte permet de reconnaître un doublon et de prouver qu'un
-- contrat accepté n'a pas changé depuis.
CREATE TABLE `document` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`    INT UNSIGNED    NULL,
    `kind`          VARCHAR(24)     NOT NULL DEFAULT 'other',
    `source`        VARCHAR(16)     NOT NULL DEFAULT 'generated',
    `filename`      VARCHAR(190)    NOT NULL,
    `mime`          VARCHAR(120)    NOT NULL DEFAULT 'application/octet-stream',
    `size_bytes`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `sha256`        CHAR(64)        NOT NULL DEFAULT '',
    `storage_path`  VARCHAR(255)    NOT NULL,
    `locale`        VARCHAR(5)      NOT NULL DEFAULT 'fr',
    -- Version du modèle de contrat, pour un instantané immuable.
    `version`       VARCHAR(24)     NOT NULL DEFAULT '',
    `mail_id`       BIGINT UNSIGNED NULL,
    `uploaded_by`   INT UNSIGNED    NULL,
    `sender`        VARCHAR(190)    NOT NULL DEFAULT '',
    `created_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_document_booking` (`booking_id`, `kind`),
    KEY `idx_document_mail` (`mail_id`),
    KEY `idx_document_hash` (`sha256`),
    CONSTRAINT `fk_document_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acceptation du contrat (SPECIFICATIONS.md §40).
--
-- L'acceptation porte la version et la langue exactes du texte présenté :
-- rejouer l'historique d'un séjour doit redonner ce que le client a lu, pas
-- la version courante du modèle.
CREATE TABLE `contract_acceptance` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED    NOT NULL,
    `document_id` BIGINT UNSIGNED NULL,
    `version`     VARCHAR(24)     NOT NULL,
    `locale`      VARCHAR(5)      NOT NULL,
    `sha256`      CHAR(64)        NOT NULL DEFAULT '',
    `user_id`     INT UNSIGNED    NULL,
    `accepted_by` VARCHAR(190)    NOT NULL DEFAULT '',
    -- Preuve technique raisonnable : l'adresse n'est pas conservée en clair.
    `ip_hash`     CHAR(64)        NOT NULL DEFAULT '',
    `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '',
    `accepted_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_contract_acceptance` (`booking_id`),
    CONSTRAINT `fk_contract_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courrier entrant : la table des e-mails sortants accueille aussi les
-- réponses, afin que la timeline de communication soit une seule liste
-- ordonnée (SPECIFICATIONS.md §37).
ALTER TABLE `mail_message`
    ADD COLUMN `from_address` VARCHAR(190)    NOT NULL DEFAULT '' AFTER `to_name`,
    ADD COLUMN `from_name`    VARCHAR(190)    NOT NULL DEFAULT '' AFTER `from_address`,
    ADD COLUMN `body_text`    MEDIUMTEXT      NULL                AFTER `subject`,
    ADD COLUMN `body_html`    MEDIUMTEXT      NULL                AFTER `body_text`,
    ADD COLUMN `in_reply_to`  VARCHAR(190)    NOT NULL DEFAULT '' AFTER `message_id`,
    ADD COLUMN `thread_id`    VARCHAR(190)    NOT NULL DEFAULT '' AFTER `in_reply_to`,
    ADD COLUMN `booking_id`   INT UNSIGNED    NULL                AFTER `user_id`,
    ADD COLUMN `linked_by`    VARCHAR(24)     NOT NULL DEFAULT '' AFTER `booking_id`,
    ADD COLUMN `mailbox`      VARCHAR(64)     NOT NULL DEFAULT '' AFTER `linked_by`,
    ADD COLUMN `uid`          INT UNSIGNED    NULL                AFTER `mailbox`,
    ADD COLUMN `received_at`  DATETIME        NULL                AFTER `sent_at`,
    ADD KEY `idx_mail_booking` (`booking_id`, `created_at`),
    ADD KEY `idx_mail_thread` (`thread_id`),
    -- Un même message ne doit pas être importé deux fois, même si la
    -- synchronisation repart en arrière ou se chevauche.
    ADD UNIQUE KEY `uniq_mail_inbound` (`mailbox`, `uid`);

ALTER TABLE `document`
    ADD CONSTRAINT `fk_document_mail` FOREIGN KEY (`mail_id`) REFERENCES `mail_message` (`id`) ON DELETE SET NULL;
