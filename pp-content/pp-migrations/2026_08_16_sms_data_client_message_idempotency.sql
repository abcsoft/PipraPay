-- Migration: 2026_08_16_sms_data_client_message_idempotency.sql
-- Description: Add client_message_id column to pp_sms_data table for companion idempotency

ALTER TABLE `{PREFIX}sms_data`
  ADD COLUMN IF NOT EXISTS `client_message_id` varchar(70) DEFAULT NULL AFTER `device_id`,
  ADD INDEX IF NOT EXISTS `idx_device_client_msg_id` (`device_id`, `client_message_id`);
