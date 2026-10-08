-- Execute UMA VEZ após v4. Não executar junto com schema.sql em banco vazio.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
-- Sessões antigas não têm empresa selecionada; a API exige um novo login.
ALTER TABLE auth_sessions ADD company_id BIGINT UNSIGNED NULL AFTER user_id,
 ADD KEY idx_session_membership (company_id,user_id),
 ADD CONSTRAINT fk_session_membership FOREIGN KEY (company_id,user_id) REFERENCES company_users (company_id,user_id);
ALTER TABLE users ADD contact_phone VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER email;
ALTER TABLE service_requests MODIFY service ENUM('mobile_plan','travel_esim','virtual_number','pabx','whatsapp_number','sms_number','sms_marketing','streaming','portability','toll_free','termination','sip_trunk','whatsapp_attendance') NOT NULL;
CREATE TABLE auth_login_attempts (
 attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL,
 PRIMARY KEY (attempt_key),
 KEY idx_login_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
