-- Migration: 2026_08_17_device_heartbeat_last_seen.sql
-- Description: Add last_seen_at column to pp_device for companion heartbeat updates

ALTER TABLE `{PREFIX}device`
    ADD COLUMN IF NOT EXISTS `last_seen_at` varchar(20) DEFAULT NULL AFTER `last_sync`;
