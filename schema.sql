-- Pulse | Estrutura inicial v7 | MySQL 5.6.5+ / MariaDB 10.1+
-- Selecione o banco criado na Locaweb antes de importar pelo phpMyAdmin.
-- Execute UMA VEZ em um banco vazio. Não contém DROP, credenciais ou dados fictícios.
-- InnoDB + utf8mb4_unicode_ci; sem JSON nativo, CHECK, triggers ou privilégios especiais.
-- Datas em UTC; telefones E.164 (ex.: +5511999999999).
-- A aplicação deve validar status, valores, consentimento e isolamento por empresa.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    legal_name VARCHAR(191) NULL,
    document_number VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    contact_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    contact_phone VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    timezone VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT 'America/Sao_Paulo',
    status ENUM('pending', 'active', 'suspended', 'closed') NOT NULL DEFAULT 'pending',
    profile_completed_at DATETIME NULL,
    postal_code VARCHAR(12) NULL,
    address_line VARCHAR(191) NULL,
    address_complement VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    state_code CHAR(2) CHARACTER SET ascii NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_companies_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    contact_phone VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- Gerado por password_hash() no PHP; nunca armazenar senha em texto puro.
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending', 'active', 'blocked') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um usuário pode participar de várias empresas; papéis são definidos por empresa.
CREATE TABLE company_users (
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'manager', 'operator', 'viewer') NOT NULL DEFAULT 'viewer',
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, user_id),
    KEY idx_membership_user (user_id, status, company_id),
    CONSTRAINT fk_membership_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    -- SHA-256 hexadecimal do token aleatório, não o token original.
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY idx_sessions_user (user_id, expires_at),
    KEY idx_sessions_expiration (expires_at),
    KEY idx_session_membership (company_id, user_id),
    CONSTRAINT fk_session_membership FOREIGN KEY (company_id, user_id) REFERENCES company_users (company_id, user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_expiration (expires_at),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NULL,
    phone VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    status ENUM('active', 'unsubscribed', 'blocked') NOT NULL DEFAULT 'active',
    consent_status ENUM('unknown', 'granted', 'revoked') NOT NULL DEFAULT 'unknown',
    consent_source VARCHAR(191) NULL,
    consent_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_tenant_id (company_id, id),
    UNIQUE KEY uq_contacts_phone (company_id, phone),
    KEY idx_contacts_status (company_id, status, id),
    CONSTRAINT fk_contacts_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_lists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lists_tenant_id (company_id, id),
    UNIQUE KEY uq_lists_name (company_id, name),
    CONSTRAINT fk_lists_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_list_members (
    company_id BIGINT UNSIGNED NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, list_id, contact_id),
    KEY idx_list_member_contact (company_id, contact_id),
    CONSTRAINT fk_list_member_list FOREIGN KEY (company_id, list_id) REFERENCES contact_lists (company_id, id),
    CONSTRAINT fk_list_member_contact FOREIGN KEY (company_id, contact_id) REFERENCES contacts (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    audience_description VARCHAR(255) NULL,
    message_body TEXT NOT NULL,
    estimated_contacts INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'submitted', 'preparing', 'scheduled', 'sending', 'paused', 'completed', 'cancelled', 'failed') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    submitted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_tenant_id (company_id, id),
    KEY idx_campaign_creator (company_id, created_by),
    KEY idx_campaign_company_status (company_id, status, id),
    KEY idx_campaign_company_created (company_id, created_at, id),
    KEY idx_campaign_schedule (status, scheduled_at, id),
    CONSTRAINT fk_campaign_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_campaign_creator FOREIGN KEY (company_id, created_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila por destinatário. Snapshot da mensagem e telefone preserva o envio original.
CREATE TABLE campaign_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    phone_snapshot VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_snapshot TEXT NOT NULL,
    status ENUM('pending', 'processing', 'accepted', 'sent', 'delivered', 'read', 'retry', 'failed', 'cancelled', 'suppressed') NOT NULL DEFAULT 'pending',
    -- Chave estável por destinatário; reutilizar nos retries se o provedor suportar.
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_expires_at DATETIME NULL,
    provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_message_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error_message VARCHAR(1000) NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recipient_tenant_id (company_id, id),
    UNIQUE KEY uq_campaign_contact (company_id, campaign_id, contact_id),
    UNIQUE KEY uq_recipient_idempotency (idempotency_key),
    KEY idx_recipient_contact (company_id, contact_id),
    KEY idx_recipient_queue (status, available_at, id),
    KEY idx_recipient_lease (status, lease_expires_at, id),
    KEY idx_recipient_campaign_status (company_id, campaign_id, status, id),
    KEY idx_recipient_provider (provider, provider_message_id),
    CONSTRAINT fk_recipient_campaign FOREIGN KEY (company_id, campaign_id) REFERENCES campaigns (company_id, id),
    CONSTRAINT fk_recipient_contact FOREIGN KEY (company_id, contact_id) REFERENCES contacts (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- SHA-256(provider + identificador estável do evento) para deduplicar webhooks.
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type ENUM('accepted', 'sent', 'delivered', 'read', 'failed', 'unsubscribed') NOT NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- JSON serializado pela aplicação; remover dados sensíveis desnecessários.
    payload MEDIUMTEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_event (event_key),
    KEY idx_event_recipient_time (company_id, recipient_id, occurred_at, id),
    KEY idx_event_retention (received_at, id),
    CONSTRAINT fk_event_recipient FOREIGN KEY (company_id, recipient_id) REFERENCES campaign_recipients (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'BRL',
    status ENUM('draft', 'open', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
    due_date DATE NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_tenant_id (company_id, id),
    UNIQUE KEY uq_invoice_reference (company_id, reference),
    KEY idx_invoice_campaign (company_id, campaign_id),
    KEY idx_invoice_due (company_id, status, due_date, id),
    CONSTRAINT fk_invoice_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_invoice_campaign FOREIGN KEY (company_id, campaign_id) REFERENCES campaigns (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(191) NOT NULL,
    category ENUM('technical', 'commercial', 'financial', 'other') NOT NULL DEFAULT 'other',
    contact_phone VARCHAR(20) NULL,
    contact_email VARCHAR(254) NULL,
    status ENUM('open', 'in_progress', 'waiting_customer', 'closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_tenant_id (company_id, id),
    KEY idx_ticket_author (company_id, created_by),
    KEY idx_ticket_status (company_id, status, updated_at, id),
    CONSTRAINT fk_ticket_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_ticket_author FOREIGN KEY (company_id, created_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_message_tenant (company_id, id),
    KEY idx_support_message_ticket (company_id, ticket_id, id),
    KEY idx_support_message_author (company_id, author_id),
    CONSTRAINT fk_support_message_ticket FOREIGN KEY (company_id, ticket_id) REFERENCES support_tickets (company_id, id),
    CONSTRAINT fk_support_message_author FOREIGN KEY (company_id, author_id) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferências; não armazena PAN, CVV ou dados de cartão.
CREATE TABLE billing_preferences (
    company_id BIGINT UNSIGNED NOT NULL,
    billing_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    responsible_name VARCHAR(191) NOT NULL,
    preferred_method ENUM('pix', 'boleto', 'card') NOT NULL DEFAULT 'pix',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT fk_billing_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE company_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    invited_by BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    role ENUM('manager', 'operator', 'viewer') NOT NULL DEFAULT 'viewer',
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending', 'accepted', 'revoked', 'expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitation_token (token_hash),
    KEY idx_invitation_email (company_id, email, status),
    KEY idx_invitation_author (company_id, invited_by),
    KEY idx_invitation_expiry (status, expires_at),
    CONSTRAINT fk_invitation_author FOREIGN KEY (company_id, invited_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    method ENUM('pix', 'boleto') NOT NULL,
    status ENUM('pending', 'paid', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    provider_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_tenant_id (company_id, id),
    KEY idx_credit_author (company_id, created_by),
    KEY idx_credit_status (company_id, status, id),
    CONSTRAINT fk_credit_author FOREIGN KEY (company_id, created_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lançamentos imutáveis. Estornos são novos lançamentos de valor contrário.
-- amount: crédito positivo, débito negativo. Nunca usar FLOAT para saldo.
CREATE TABLE balance_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_request_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    entry_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type ENUM('credit', 'debit', 'reversal', 'adjustment') NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balance_entry_key (entry_key),
    KEY idx_balance_date (company_id, occurred_at, id),
    KEY idx_balance_credit (company_id, credit_request_id),
    KEY idx_balance_campaign (company_id, campaign_id),
    CONSTRAINT fk_balance_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_balance_credit FOREIGN KEY (company_id, credit_request_id) REFERENCES credit_requests (company_id, id),
    CONSTRAINT fk_balance_campaign FOREIGN KEY (company_id, campaign_id) REFERENCES campaigns (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campos específicos serializados em JSON pela API, validados por tipo de serviço.
CREATE TABLE service_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    service ENUM('mobile_plan', 'travel_esim', 'virtual_number', 'pabx', 'whatsapp_number', 'sms_number', 'sms_marketing', 'streaming', 'portability', 'toll_free', 'termination', 'sip_trunk', 'whatsapp_attendance') NOT NULL,
    details TEXT NOT NULL,
    status ENUM('draft', 'submitted', 'reviewing', 'quoted', 'accepted', 'cancelled') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_service_author (company_id, created_by),
    KEY idx_service_status (company_id, service, status, id),
    CONSTRAINT fk_service_author FOREIGN KEY (company_id, created_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partnership_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    type ENUM('affiliate', 'reseller') NOT NULL,
    details TEXT NOT NULL,
    status ENUM('draft', 'submitted', 'reviewing', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_partnership_author (company_id, created_by),
    KEY idx_partnership_status (company_id, type, status, id),
    CONSTRAINT fk_partnership_author FOREIGN KEY (company_id, created_by) REFERENCES company_users (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_request_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_payment_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_reference CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'BRL',
    status ENUM('creating', 'pending', 'approved', 'cancelled', 'rejected', 'expired', 'partially_refunded', 'refunded') NOT NULL DEFAULT 'creating',
    credited_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    reversed_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_request (company_id, credit_request_id),
    UNIQUE KEY uq_payment_provider (provider, provider_payment_id),
    UNIQUE KEY uq_payment_reference (external_reference),
    UNIQUE KEY uq_payment_idempotency (idempotency_key),
    KEY idx_payment_reconcile (status, checked_at, id),
    CONSTRAINT fk_payment_request FOREIGN KEY (company_id, credit_request_id) REFERENCES credit_requests (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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

CREATE TABLE auth_login_attempts (
    attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    PRIMARY KEY (attempt_key),
    KEY idx_login_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cobrança de SMS e administração
CREATE TABLE IF NOT EXISTS sms_billing_accounts (
 company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 rate_mills INT UNSIGNED NOT NULL DEFAULT 50,
 unlimited TINYINT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sms_charges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 payload TEXT NOT NULL,
 remote_id BIGINT UNSIGNED NULL,
 rate_mills INT UNSIGNED NOT NULL,
 segments INT UNSIGNED NOT NULL,
 recipients INT UNSIGNED NOT NULL,
 reserved_cents BIGINT UNSIGNED NOT NULL,
 charged_cents BIGINT UNSIGNED NULL,
 status ENUM('reserved','running','settled','rejected') NOT NULL DEFAULT 'reserved',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sms_request(company_id,request_id),
 KEY idx_sms_status(status,id),
 FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS admin_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(64) NOT NULL,
 details TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cadastro com confirmação de e-mail
CREATE TABLE IF NOT EXISTS email_verification_tokens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_email_verification_token(token_hash),
 KEY idx_email_verification_user(user_id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
