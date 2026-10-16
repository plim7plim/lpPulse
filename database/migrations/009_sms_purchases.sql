CREATE TABLE IF NOT EXISTS sms_purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
    quantity INT UNSIGNED NOT NULL,
    rate_mills INT UNSIGNED NOT NULL,
    remaining INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_purchase_company (company_id,status,id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (credit_request_id) REFERENCES credit_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sms_purchase_allocations (
    charge_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    units INT UNSIGNED NOT NULL,
    charged_units INT UNSIGNED NULL,
    PRIMARY KEY (charge_id,purchase_id),
    FOREIGN KEY (charge_id) REFERENCES sms_charges(id),
    FOREIGN KEY (purchase_id) REFERENCES sms_purchases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
