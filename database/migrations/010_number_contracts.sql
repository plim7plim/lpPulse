CREATE TABLE IF NOT EXISTS number_contracts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 product ENUM('online','subscription') NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 price_cents INT UNSIGNED NOT NULL,
 status ENUM('paid','active','expired','cancelled') NOT NULL DEFAULT 'paid',
 cycle INT UNSIGNED NOT NULL DEFAULT 1,
 started_at DATETIME NULL,
 expires_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_contract_request(company_id,request_key),
 KEY idx_contract_company(company_id,status,id),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS number_contract_orders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 contract_id BIGINT UNSIGNED NOT NULL,
 cycle INT UNSIGNED NOT NULL,
 service_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
 quantity INT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','reserved','failed') NOT NULL DEFAULT 'pending',
 UNIQUE KEY uq_contract_order(contract_id,request_key),
 FOREIGN KEY(contract_id) REFERENCES number_contracts(id),
 FOREIGN KEY(service_request_id) REFERENCES service_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS number_contract_renewals (
 contract_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(contract_id,request_key),
 FOREIGN KEY(contract_id) REFERENCES number_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @pulse_channel_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_purchases' AND COLUMN_NAME='channel')=0,
 'ALTER TABLE sms_purchases ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT ''sms''','SELECT 1');
PREPARE pulse_channel_stmt FROM @pulse_channel_sql;
EXECUTE pulse_channel_stmt;
DEALLOCATE PREPARE pulse_channel_stmt;
