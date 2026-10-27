-- ============================================================
-- ARSLAN'S PHARMACY ERP — Phase 21: Role System
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Run AFTER phase20_user_management.sql
-- Safe to re-run (uses IF NOT EXISTS / MODIFY guards)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ============================================================
-- 1. EXPAND users.role ENUM — add manager + custom
-- ============================================================
ALTER TABLE `users`
  MODIFY COLUMN `role`
    ENUM('admin','manager','pharmacist','cashier','viewer','custom')
    NOT NULL DEFAULT 'cashier'
    COMMENT 'User role: admin=full, manager=ops lead, pharmacist=clinical, cashier=pos only, viewer=read-only, custom=granular';

-- ============================================================
-- 2. ENSURE user_permissions HAS ALL Phase-21 COLUMNS
-- ============================================================
ALTER TABLE `user_permissions`
  ADD COLUMN IF NOT EXISTS `can_view`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `module`,
  ADD COLUMN IF NOT EXISTS `can_add`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view`,
  ADD COLUMN IF NOT EXISTS `can_edit`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_add`,
  ADD COLUMN IF NOT EXISTS `can_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_edit`,
  ADD COLUMN IF NOT EXISTS `can_print`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_delete`,
  ADD COLUMN IF NOT EXISTS `can_export` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_print`,
  ADD COLUMN IF NOT EXISTS `can_import` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_export`,
  ADD COLUMN IF NOT EXISTS `can_void`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_import`;

-- ============================================================
-- 3. role_permissions — DEFAULT TEMPLATE TABLE
--    Stores the canonical default permissions per named role.
--    When a new user is created with a standard role, these
--    rows are copied into user_permissions for that user.
--    Admin is omitted (gets all permissions via canDo shortcut).
-- ============================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `role`       ENUM('manager','pharmacist','cashier','viewer','custom')
               NOT NULL,
  `module`     VARCHAR(50)  NOT NULL,
  `can_view`   TINYINT(1)   NOT NULL DEFAULT 0,
  `can_add`    TINYINT(1)   NOT NULL DEFAULT 0,
  `can_edit`   TINYINT(1)   NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_print`  TINYINT(1)   NOT NULL DEFAULT 0,
  `can_export` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_import` TINYINT(1)   NOT NULL DEFAULT 0,
  `can_void`   TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role`, `module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Default permission templates per role — copied to user_permissions on user create';

-- ============================================================
-- 4. SEED role_permissions — MANAGER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('manager','dashboard', 1,0,0,0,0,0,0,0),
  ('manager','products',  1,1,1,0,1,1,1,0),
  ('manager','sales',     1,1,1,0,1,1,0,1),
  ('manager','purchases', 1,1,1,1,1,1,1,0),
  ('manager','returns',   1,1,0,0,1,1,0,0),
  ('manager','credits',   1,1,1,0,0,1,0,0),
  ('manager','suppliers', 1,1,1,0,1,1,0,0),
  ('manager','cashbook',  1,1,0,0,1,1,0,0),
  ('manager','expenses',  1,1,1,0,0,1,0,0),
  ('manager','stock',     1,1,1,0,0,1,0,0),
  ('manager','reports',   1,0,0,0,1,1,0,0),
  ('manager','users',     1,1,1,0,0,0,0,0),
  ('manager','settings',  1,0,1,0,0,0,0,0),
  ('manager','backup',    1,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 5. SEED role_permissions — PHARMACIST
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('pharmacist','dashboard', 1,0,0,0,0,0,0,0),
  ('pharmacist','products',  1,1,1,0,1,1,1,0),
  ('pharmacist','sales',     1,1,0,0,1,1,0,1),
  ('pharmacist','purchases', 1,1,0,0,1,1,0,0),
  ('pharmacist','returns',   1,1,0,0,1,0,0,0),
  ('pharmacist','credits',   1,1,0,0,0,0,0,0),
  ('pharmacist','suppliers', 1,1,1,0,0,0,0,0),
  ('pharmacist','cashbook',  1,0,0,0,0,0,0,0),
  ('pharmacist','expenses',  1,1,0,0,0,0,0,0),
  ('pharmacist','stock',     1,1,0,0,0,0,0,0),
  ('pharmacist','reports',   1,0,0,0,1,1,0,0),
  ('pharmacist','users',     0,0,0,0,0,0,0,0),
  ('pharmacist','settings',  0,0,0,0,0,0,0,0),
  ('pharmacist','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 6. SEED role_permissions — CASHIER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('cashier','dashboard', 1,0,0,0,0,0,0,0),
  ('cashier','products',  1,0,0,0,0,0,0,0),
  ('cashier','sales',     1,1,0,0,1,0,0,0),
  ('cashier','purchases', 0,0,0,0,0,0,0,0),
  ('cashier','returns',   1,1,0,0,0,0,0,0),
  ('cashier','credits',   1,1,0,0,0,0,0,0),
  ('cashier','suppliers', 0,0,0,0,0,0,0,0),
  ('cashier','cashbook',  1,0,0,0,0,0,0,0),
  ('cashier','expenses',  1,1,0,0,0,0,0,0),
  ('cashier','stock',     0,0,0,0,0,0,0,0),
  ('cashier','reports',   1,0,0,0,0,0,0,0),
  ('cashier','users',     0,0,0,0,0,0,0,0),
  ('cashier','settings',  0,0,0,0,0,0,0,0),
  ('cashier','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 7. SEED role_permissions — VIEWER
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('viewer','dashboard', 1,0,0,0,0,0,0,0),
  ('viewer','products',  1,0,0,0,0,0,0,0),
  ('viewer','sales',     1,0,0,0,1,1,0,0),
  ('viewer','purchases', 1,0,0,0,0,0,0,0),
  ('viewer','returns',   0,0,0,0,0,0,0,0),
  ('viewer','credits',   0,0,0,0,0,0,0,0),
  ('viewer','suppliers', 0,0,0,0,0,0,0,0),
  ('viewer','cashbook',  0,0,0,0,0,0,0,0),
  ('viewer','expenses',  0,0,0,0,0,0,0,0),
  ('viewer','stock',     0,0,0,0,0,0,0,0),
  ('viewer','reports',   1,0,0,0,1,1,0,0),
  ('viewer','users',     0,0,0,0,0,0,0,0),
  ('viewer','settings',  0,0,0,0,0,0,0,0),
  ('viewer','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit),
  can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export),
  can_import=VALUES(can_import), can_void=VALUES(can_void);

-- ============================================================
-- 8. SEED role_permissions — CUSTOM (all-zeros template)
-- ============================================================
INSERT INTO `role_permissions` (role, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void) VALUES
  ('custom','dashboard', 0,0,0,0,0,0,0,0),
  ('custom','products',  0,0,0,0,0,0,0,0),
  ('custom','sales',     0,0,0,0,0,0,0,0),
  ('custom','purchases', 0,0,0,0,0,0,0,0),
  ('custom','returns',   0,0,0,0,0,0,0,0),
  ('custom','credits',   0,0,0,0,0,0,0,0),
  ('custom','suppliers', 0,0,0,0,0,0,0,0),
  ('custom','cashbook',  0,0,0,0,0,0,0,0),
  ('custom','expenses',  0,0,0,0,0,0,0,0),
  ('custom','stock',     0,0,0,0,0,0,0,0),
  ('custom','reports',   0,0,0,0,0,0,0,0),
  ('custom','users',     0,0,0,0,0,0,0,0),
  ('custom','settings',  0,0,0,0,0,0,0,0),
  ('custom','backup',    0,0,0,0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  can_view=0, can_add=0, can_edit=0, can_delete=0,
  can_print=0, can_export=0, can_import=0, can_void=0;

-- ============================================================
-- 9. BACKFILL user_permissions FOR EXISTING USERS
--    Any user that has a role but NO rows in user_permissions
--    gets the default template applied automatically.
--    Admin users are skipped (they bypass permission checks).
-- ============================================================
INSERT IGNORE INTO `user_permissions`
  (user_id, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void)
SELECT
  u.id,
  rp.module,
  rp.can_view, rp.can_add, rp.can_edit, rp.can_delete,
  rp.can_print, rp.can_export, rp.can_import, rp.can_void
FROM `users` u
JOIN `role_permissions` rp ON rp.role = u.role
WHERE u.role != 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `user_permissions` up2
    WHERE up2.user_id = u.id AND up2.module = rp.module
  );

-- ============================================================
-- 10. UNIQUE KEY on user_permissions (user_id, module)
--     Prevents duplicate rows on re-runs.
-- ============================================================
ALTER TABLE `user_permissions`
  ADD UNIQUE KEY IF NOT EXISTS `uq_user_module` (`user_id`, `module`);

-- ============================================================
-- DONE
-- ============================================================
SELECT 'Phase 21 Role System migration complete.' AS status;
