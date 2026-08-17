-- Migration: 2026_08_21_personal_payment_sessions.sql
-- Description: Personal payment waiting sessions for companion SMS auto verification

CREATE TABLE IF NOT EXISTS `{PREFIX}personal_payment_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `transaction_ref` VARCHAR(64) NOT NULL,
  `brand_id` VARCHAR(64) NOT NULL,
  `gateway_id` VARCHAR(64) NOT NULL,
  `sender_key` VARCHAR(32) NOT NULL,
  `sender_type` VARCHAR(32) NOT NULL DEFAULT 'Personal',
  `payer_number` VARCHAR(32) NOT NULL,
  `expected_amount` DECIMAL(18, 2) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'waiting',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `matched_sms_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_date` DATETIME NOT NULL,
  `updated_date` DATETIME NOT NULL,
  INDEX `idx_pps_tx_ref` (`transaction_ref`),
  INDEX `idx_pps_brand_sender` (`brand_id`, `sender_key`, `status`),
  INDEX `idx_pps_lookup` (`brand_id`, `sender_key`, `payer_number`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
