-- Migration: 2026_08_15_device_ownership_and_token_security.sql
-- Description: Companion device ownership tracking, token security, and index alignment

ALTER TABLE `{PREFIX}device`
  MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT 'processing',
  ADD COLUMN IF NOT EXISTS `token` text DEFAULT NULL AFTER `otp`,
  ADD COLUMN IF NOT EXISTS `token_hash` varchar(64) DEFAULT NULL AFTER `token`,
  ADD COLUMN IF NOT EXISTS `otp_expires_at` varchar(20) DEFAULT NULL AFTER `token_hash`,
  ADD COLUMN IF NOT EXISTS `admin_id` varchar(40) DEFAULT NULL AFTER `otp_expires_at`,
  ADD COLUMN IF NOT EXISTS `brand_id` varchar(40) DEFAULT NULL AFTER `admin_id`,
  ADD COLUMN IF NOT EXISTS `paired_at` varchar(30) DEFAULT NULL AFTER `brand_id`,
  ADD COLUMN IF NOT EXISTS `revoked_at` varchar(30) DEFAULT NULL AFTER `paired_at`;
