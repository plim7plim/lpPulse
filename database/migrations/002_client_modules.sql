-- Pulse v2: importar somente em banco que já recebeu schema.sql v1.
-- Execute uma única vez; faça backup antes de atualizar dados existentes.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE companies
    ADD COLUMN postal_code VARCHAR(12) NULL,
    ADD COLUMN address_line VARCHAR(191) NULL,
    ADD COLUMN address_complement VARCHAR(100) NULL,
    ADD COLUMN city VARCHAR(100) NULL,
    ADD COLUMN state_code CHAR(2) CHARACTER SET ascii NULL;

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
    service ENUM('mobile_plan', 'travel_esim') NOT NULL,
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
