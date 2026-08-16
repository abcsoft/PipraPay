-- Migration: 2026_08_20_device_app_version_compat.sql
-- Description: Add app_version column to pp_device table for backward compatibility with live installations containing app_payversion

ALTER TABLE `{PREFIX}device`
    ADD COLUMN IF NOT EXISTS `app_version` text DEFAULT NULL AFTER `android_level`;
