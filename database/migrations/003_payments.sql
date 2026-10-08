-- Pulse v3 | Executar UMA VEZ somente em banco com schema v2.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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
