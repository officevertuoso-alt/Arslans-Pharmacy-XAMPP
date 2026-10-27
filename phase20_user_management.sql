-- ============================================================
-- ARSLAN'S PHARMACY ERP — Phase 20: User Management Patch
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Run AFTER pharmacy_db.sql + database_enterprise_patch.sql
-- Safe to re-run (uses IF NOT EXISTS / IF NOT EXISTS column)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ============================================================
-- 1. ADD MISSING COLUMNS TO users TABLE
-- ============================================================

-- is_protected: prevents deletion, deactivation of system accounts
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_protected` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = cannot be deleted or deactivated (system account)'
    AFTER `status`;

-- profile_photo: alternate column (synced with profile_image)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `profile_photo` varchar(255) DEFAULT NULL
    AFTER `profile_image`;

-- security_question / security_answer (for password recovery)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `security_question` varchar(255) DEFAULT NULL
    AFTER `notes`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `security_answer` varchar(255) DEFAULT NULL
    AFTER `security_question`;

-- is_first_login: forces security setup on first login
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_first_login` tinyint(1) NOT NULL DEFAULT 0
    AFTER `security_answer`;

-- force_password_change: admin can force a password reset
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `force_password_change` tinyint(1) NOT NULL DEFAULT 0
    AFTER `is_first_login`;

-- login_count: tracks total successful logins
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `login_count` int(11) NOT NULL DEFAULT 0
    AFTER `force_password_change`;

-- default_page: where to redirect after login
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `default_page` varchar(100) DEFAULT 'dashboard'
    AFTER `login_count`;

-- notes: internal admin notes on the user
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL
    AFTER `default_page`;

-- created_by: which admin created this user
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `created_by` int(11) DEFAULT NULL
    AFTER `notes`;

-- updated_at: last update timestamp
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT NULL
    ON UPDATE current_timestamp()
    AFTER `created_at`;

-- ============================================================
-- 2. ADD viewer ROLE TO users ENUM (if not already present)
-- ============================================================
-- Attempt to widen the enum — safe no-op if already present
ALTER TABLE `users`
  MODIFY COLUMN `role`
    enum('admin','pharmacist','cashier','viewer')
    DEFAULT 'cashier'
    COMMENT 'User role for permissions';

-- ============================================================
-- 3. ENSURE user_permissions HAS ALL REQUIRED COLUMNS
-- ============================================================
ALTER TABLE `user_permissions`
  ADD COLUMN IF NOT EXISTS `can_print`  tinyint(1) NOT NULL DEFAULT 0 AFTER `can_delete`,
  ADD COLUMN IF NOT EXISTS `can_export` tinyint(1) NOT NULL DEFAULT 0 AFTER `can_print`,
  ADD COLUMN IF NOT EXISTS `can_import` tinyint(1) NOT NULL DEFAULT 0 AFTER `can_export`,
  ADD COLUMN IF NOT EXISTS `can_void`   tinyint(1) NOT NULL DEFAULT 0 AFTER `can_import`,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp  NOT NULL DEFAULT current_timestamp() AFTER `can_void`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp  NULL DEFAULT NULL ON UPDATE current_timestamp() AFTER `created_at`;

-- ============================================================
-- 4. PROTECT THE arslan / DEFAULT ADMIN ACCOUNT
--    id = 1 is the default admin created by pharmacy_db.sql
--    We mark it protected so delete.php / toggle_status cannot touch it.
--    Also ensure the 'arslan' user exists (upsert-style).
-- ============================================================

-- Mark id=1 as protected (default admin)
UPDATE `users` SET `is_protected` = 1 WHERE `id` = 1;

-- Insert arslan user if not already present (password = 'arslan123' bcrypt)
-- Change the password hash below if you have a different one
INSERT IGNORE INTO `users`
  (`username`, `password`, `full_name`, `email`, `phone`, `role`,
   `status`, `is_protected`, `is_first_login`, `created_at`)
VALUES (
  'arslan',
  '$2y$12$arslanPlaceholderHashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXm',
  'Dr. Arslan Yasin Sukhera',
  'arslan@pharmacy.com',
  '03001234567',
  'admin',
  1,
  1,
  0,
  NOW()
);

-- Ensure the arslan user is always protected, regardless of existing data
UPDATE `users` SET `is_protected` = 1, `status` = 1 WHERE `username` = 'arslan';

-- ============================================================
-- 5. PERFORMANCE INDEXES FOR USER MANAGEMENT
-- ============================================================
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_role_status`    (`role`, `status`),
  ADD INDEX IF NOT EXISTS `idx_users_username`        (`username`),
  ADD INDEX IF NOT EXISTS `idx_users_email`           (`email`),
  ADD INDEX IF NOT EXISTS `idx_users_is_protected`    (`is_protected`);

ALTER TABLE `user_permissions`
  ADD INDEX IF NOT EXISTS `idx_up_user_module` (`user_id`, `module`);

-- ============================================================
-- 6. FULL PERMISSIONS FOR arslan USER
-- ============================================================

-- Delete existing permissions for arslan and re-insert full access
DELETE FROM `user_permissions`
  WHERE `user_id` = (SELECT `id` FROM `users` WHERE `username` = 'arslan' LIMIT 1);

INSERT INTO `user_permissions`
  (`user_id`, `module`, `can_view`, `can_add`, `can_edit`, `can_delete`, `can_print`, `can_export`, `can_import`, `can_void`)
SELECT u.id, m.module, 1, 1, 1, 1, 1, 1, 1, 1
FROM `users` u
CROSS JOIN (
  SELECT 'dashboard' AS module UNION ALL SELECT 'products'  UNION ALL SELECT 'sales'
  UNION ALL SELECT 'purchases' UNION ALL SELECT 'returns'   UNION ALL SELECT 'credits'
  UNION ALL SELECT 'suppliers' UNION ALL SELECT 'cashbook'  UNION ALL SELECT 'expenses'
  UNION ALL SELECT 'stock'     UNION ALL SELECT 'reports'   UNION ALL SELECT 'users'
  UNION ALL SELECT 'settings'  UNION ALL SELECT 'backup'
) m
WHERE u.username = 'arslan';

SELECT 'Phase 20 User Management Patch Applied Successfully' AS status;
