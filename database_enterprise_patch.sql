-- ============================================================
-- ARSLAN'S PHARMACY ERP — Enterprise Schema Patch v7.0
-- Fix: M-01, M-02, M-03, P-01 through P-06
-- Author: Dr. Arslan Yasin Sukhera | June 2026
-- Run AFTER pharmacy_db.sql on fresh install,
-- or run standalone on existing installation (safe — uses IF NOT EXISTS / IF NOT EXISTS)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

-- ============================================================
-- 1. MISSING TABLE: customers (FIX M-01)
-- Customer master table — needed by credit, WhatsApp, POS
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_name` (`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. MISSING TABLE: invoice_sequences (FIX M-02)
-- Atomic invoice number generation — prevents race conditions
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoice_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prefix` varchar(10) NOT NULL,
  `year` int(4) NOT NULL,
  `month` int(2) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prefix_ym` (`prefix`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. MISSING TABLE: expense_categories (FIX M-03)
-- Expense category master for expenses module
-- ============================================================
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories
INSERT IGNORE INTO `expense_categories` (`name`, `description`) VALUES
('Rent', 'Monthly shop/office rent'),
('Utilities', 'Electricity, gas, water bills'),
('Salaries', 'Staff salaries and wages'),
('Supplies', 'Office and pharmacy supplies'),
('Maintenance', 'Equipment and premises maintenance'),
('Miscellaneous', 'Other expenses');

-- ============================================================
-- 4. PERFORMANCE INDEXES (FIX P-01 through P-06)
-- Covering indexes for FIFO, dashboard, expiry, cashbook, reports
-- ============================================================

-- batches: FIFO batch selection + expiry alert (FIX P-02)
ALTER TABLE `batches`
  ADD INDEX IF NOT EXISTS `idx_fifo` (`product_id`, `expiry_date`, `remaining_quantity`),
  ADD INDEX IF NOT EXISTS `idx_expiry` (`expiry_date`, `is_expired`, `remaining_quantity`);

-- sale_items: fast sale view and returns lookup (FIX P-03)
ALTER TABLE `sale_items`
  ADD INDEX IF NOT EXISTS `idx_sale_product` (`sale_id`, `product_id`, `batch_id`);

-- cashbook: date range + voided queries (FIX P-04)
ALTER TABLE `cashbook`
  ADD INDEX IF NOT EXISTS `idx_date_voided` (`entry_date`, `voided`);

-- activity_logs: user activity dashboard (FIX P-05)
ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_user_date` (`user_id`, `created_at`);

-- sales: date + status reports (FIX P-01 partial)
ALTER TABLE `sales`
  ADD INDEX IF NOT EXISTS `idx_date_status` (`sale_date`, `payment_status`),
  ADD INDEX IF NOT EXISTS `idx_customer_phone` (`customer_phone`);

-- purchases: date + supplier reports
ALTER TABLE `purchases`
  ADD INDEX IF NOT EXISTS `idx_date_supplier` (`purchase_date`, `supplier_id`);

-- products: status + parent queries
ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_status_parent` (`status`, `parent_id`);

-- ============================================================
-- 5. ENSURE EXISTING TABLES HAVE REQUIRED COLUMNS
-- ============================================================

-- Add 'stock' column if missing from user_permissions
ALTER TABLE `user_permissions`
  ADD COLUMN IF NOT EXISTS `can_stock` tinyint(1) NOT NULL DEFAULT 1 AFTER `can_void`;

SELECT 'Enterprise Patch v7.0 Applied Successfully' AS status;

-- ============================================================
-- Phase 15: Stock Transfers table
-- Run this patch if upgrading from Phase 14 or earlier
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `from_batch_id`   int(11)      NOT NULL,
  `to_batch_id`     int(11)      NOT NULL,
  `product_id`      int(11)      NOT NULL,
  `quantity`        int(11)      NOT NULL COMMENT 'units transferred',
  `reason`          varchar(255) NOT NULL DEFAULT '',
  `created_by`      int(11)      NOT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_st_product` (`product_id`),
  KEY `idx_st_from`    (`from_batch_id`),
  KEY `idx_st_to`      (`to_batch_id`),
  KEY `idx_st_date`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add stock_transfers movement types to stock_movements if not present
-- (stock_movements.movement_type already supports any varchar; no ALTER needed)
