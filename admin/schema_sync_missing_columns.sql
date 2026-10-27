-- ============================================================
-- Arslan Pharmacy — schema sync for missing columns
-- Run once in phpMyAdmin → pharmacy_db → SQL
-- Safe to re-run on MariaDB 10.4+ (errors on duplicate column: ignore)
-- ============================================================

-- Users: remember me
ALTER TABLE `users` ADD COLUMN `remember_token` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `remember_expires` DATETIME NULL DEFAULT NULL;

-- Sale returns: columns expected by app
ALTER TABLE `sale_returns` ADD COLUMN `refund_method` VARCHAR(30) NOT NULL DEFAULT 'cash';
ALTER TABLE `sale_returns` ADD COLUMN `returned_by` INT(11) NULL DEFAULT NULL;
ALTER TABLE `sale_returns` ADD COLUMN `created_by` INT(11) NULL DEFAULT NULL;
ALTER TABLE `sale_returns` ADD COLUMN `sale_item_id` INT(11) NULL DEFAULT NULL;
ALTER TABLE `sale_returns` ADD COLUMN `batch_id` INT(11) NULL DEFAULT NULL;
ALTER TABLE `sale_returns` ADD COLUMN `quantity` INT(11) NULL DEFAULT 0;
ALTER TABLE `sale_returns` ADD COLUMN `unit_price` DECIMAL(12,2) NULL DEFAULT 0.00;
ALTER TABLE `sale_returns` ADD COLUMN `total_amount` DECIMAL(12,2) NULL DEFAULT 0.00;
ALTER TABLE `sale_returns` ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

-- Backfill returned_by from created_by when empty
UPDATE `sale_returns` SET `returned_by` = `created_by` WHERE `returned_by` IS NULL AND `created_by` IS NOT NULL;
UPDATE `sale_returns` SET `created_by` = `returned_by` WHERE `created_by` IS NULL AND `returned_by` IS NOT NULL;

-- Purchase returns
ALTER TABLE `purchase_returns` ADD COLUMN `refund_method` VARCHAR(30) NOT NULL DEFAULT 'cash';
ALTER TABLE `purchase_returns` ADD COLUMN `returned_by` INT(11) NULL DEFAULT NULL;
ALTER TABLE `purchase_returns` ADD COLUMN `created_by` INT(11) NULL DEFAULT NULL;
ALTER TABLE `purchase_returns` ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE `purchase_returns` SET `returned_by` = `created_by` WHERE `returned_by` IS NULL AND `created_by` IS NOT NULL;
UPDATE `purchase_returns` SET `created_by` = `returned_by` WHERE `created_by` IS NULL AND `returned_by` IS NOT NULL;

-- Note: if a column already exists, MariaDB shows error for that line — skip and continue.
