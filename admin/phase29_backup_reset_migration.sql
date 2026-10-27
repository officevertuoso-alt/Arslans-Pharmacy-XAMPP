-- ════════════════════════════════════════════════════════════════════
-- Phase 29 — Backup & Reset Improvements Migration
-- Pharmacy ERP PharmaCare Pro
-- Date: 2026-06-10
-- ════════════════════════════════════════════════════════════════════

-- 1. Ensure user_settings table exists (needed for Dark Blue Professional
--    theme assignment after factory reset)
CREATE TABLE IF NOT EXISTS `user_settings` (
    `id`             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT           NOT NULL,
    `selected_theme` VARCHAR(50)   NOT NULL DEFAULT 'dark_blue_professional',
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add theme_preset column to company_settings if not present
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'company_settings'
      AND COLUMN_NAME  = 'theme_preset'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE company_settings ADD COLUMN `theme_preset` VARCHAR(30) NOT NULL DEFAULT 'dark_blue_professional'",
    "SELECT 'theme_preset column already exists' AS _info"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Ensure system_resets audit table exists (Phase 28 — defensive re-create)
CREATE TABLE IF NOT EXISTS `system_resets` (
    `id`                      INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reset_type`              VARCHAR(20)   NOT NULL,
    `reset_by`                INT           NOT NULL DEFAULT 0,
    `reset_at`                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`                   TEXT,
    `backup_filename_before`  VARCHAR(255),
    `ip_address`              VARCHAR(45)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- END Phase 29 migration
-- ════════════════════════════════════════════════════════════════════
