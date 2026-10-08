-- Banco existente v3: executar uma vez após backup.
-- Banco vazio: importar somente o schema.sql completo.
ALTER TABLE support_tickets
    ADD COLUMN category ENUM('technical', 'commercial', 'financial', 'other') NOT NULL DEFAULT 'other' AFTER subject,
    ADD COLUMN contact_phone VARCHAR(20) NULL AFTER category,
    ADD COLUMN contact_email VARCHAR(254) NULL AFTER contact_phone;
ALTER TABLE support_messages
    ADD UNIQUE KEY uq_support_message_tenant (company_id, id);

-- Arquivos privados, nunca gravar URLs públicas ou o binário no banco.
CREATE TABLE support_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_storage_key (storage_key),
    KEY idx_attachment_message (company_id, message_id, id),
    CONSTRAINT fk_attachment_message FOREIGN KEY (company_id, message_id) REFERENCES support_messages (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NULL,
    title VARCHAR(191) NOT NULL,
    body TEXT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_unread (company_id, user_id, read_at, id),
    KEY idx_notification_ticket (company_id, ticket_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (company_id, user_id) REFERENCES company_users (company_id, user_id),
    CONSTRAINT fk_notification_ticket FOREIGN KEY (company_id, ticket_id) REFERENCES support_tickets (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
