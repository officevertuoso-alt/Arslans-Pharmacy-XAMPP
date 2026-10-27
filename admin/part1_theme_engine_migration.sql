-- ============================================================
-- PHARMACY ERP — PART 1 SQL MIGRATION
-- File: admin/part1_theme_engine_migration.sql
--
-- Run this ONCE on your existing database.
-- Safe to run on fresh installs AND existing installs.
-- All statements use IF NOT EXISTS / IGNORE-safe patterns.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. USER SETTINGS TABLE
--    Stores per-user theme preference (and future preferences)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_settings` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `user_id`        INT(11)      NOT NULL,
    `selected_theme` VARCHAR(60)  NOT NULL DEFAULT 'dark_blue_professional',
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_settings_user` (`user_id`),
    CONSTRAINT `fk_user_settings_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user Theme Engine preferences — Part 1';

-- ────────────────────────────────────────────────────────────
-- 2. USERS TABLE — Add missing columns used by auth.php
--    (safe: ALTER IGNORE / only adds if column does not exist)
-- ────────────────────────────────────────────────────────────

-- is_first_login: 1 = show "update admin details" modal on first login
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `is_first_login`         TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `force_password_change`  TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `permissions_set`        TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `login_count`            INT(11)      NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `security_question`      VARCHAR(255)          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `security_answer`        VARCHAR(255)          DEFAULT NULL;

-- ────────────────────────────────────────────────────────────
-- 3. COMPANY SETTINGS — Add theme_preset column if missing
--    (existing rows keep their current values)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `company_settings`
    ADD COLUMN IF NOT EXISTS `theme_preset` VARCHAR(60) NOT NULL DEFAULT 'dark_blue_professional';

-- ────────────────────────────────────────────────────────────
-- 4. ENSURE DEFAULT ADMIN EXISTS
--    Uses arslan / arslan123 as required.
--    Only inserts if no admin user exists at all.
--    is_first_login = 1 → triggers "update details?" modal
-- ────────────────────────────────────────────────────────────
-- Note: password hash below = password_hash('arslan123', PASSWORD_BCRYPT)
-- You MUST regenerate this hash at runtime; this is a placeholder.
-- The execute_factory_reset.php handler generates the correct hash at reset time.

INSERT IGNORE INTO `users`
    (`id`, `username`, `password`, `full_name`, `email`, `role`, `status`,
     `permissions_set`, `force_password_change`, `is_first_login`, `login_count`, `created_at`)
SELECT
    1,
    'arslan',
    -- Placeholder hash for 'arslan123' — factory reset regenerates this with PHP
    '$2y$12$placeholder_replace_via_install_script',
    'Administrator',
    'admin@pharmacy.local',
    'admin',
    1,
    1, 0, 1, 0,
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `users` WHERE `role` = 'admin' LIMIT 1
);

-- ────────────────────────────────────────────────────────────
-- 5. RESET COMPANY SETTINGS THEME TO DEFAULT
--    (Only updates theme_preset; leaves all other settings intact)
-- ────────────────────────────────────────────────────────────
UPDATE `company_settings`
SET    `theme_preset` = 'dark_blue_professional'
WHERE  `id` = 1
  AND  (`theme_preset` IS NULL OR `theme_preset` = '' OR `theme_preset` = 'custom');

-- ────────────────────────────────────────────────────────────
-- Done. Part 1 migration complete.
-- ────────────────────────────────────────────────────────────
