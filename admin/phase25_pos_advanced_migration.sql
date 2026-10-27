-- ============================================================
-- PHASE 25 – POS ADVANCED MIGRATION
-- Run once on pharmacy_db database.
-- ============================================================

USE `pharmacy_db`;

-- ──────────────────────────────────────────────────────────────
-- 1. sale_items: ensure batch_number & expiry_date stored
--    (for FEFO audit trail and invoice display)
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `batch_number`  VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Batch number snapshot at time of sale',
    ADD COLUMN IF NOT EXISTS `expiry_date`   DATE         NULL DEFAULT NULL
        COMMENT 'Expiry date snapshot at time of sale (FEFO audit)';

-- ──────────────────────────────────────────────────────────────
-- 2. sales: ensure all Phase 25 columns present
-- ──────────────────────────────────────────────────────────────
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `subtotal`              DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `discount_percent`      DECIMAL(6,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `tax_percent`           DECIMAL(6,2)  DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `tax_amount`            DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `paid_amount`           DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `balance`               DECIMAL(12,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `is_credit`             TINYINT(1)    DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `due_date`              DATE          NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_status`        VARCHAR(20)   DEFAULT 'paid',
    ADD COLUMN IF NOT EXISTS `notes`                 TEXT          NULL DEFAULT NULL;

-- ──────────────────────────────────────────────────────────────
-- 3. sale_returns: ensure exists for POS return flow
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sale_returns` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `sale_id`        INT(11)      NOT NULL,
    `return_no`      VARCHAR(50)  NOT NULL,
    `return_date`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason`         TEXT         NOT NULL,
    `refund_method`  VARCHAR(30)  NOT NULL DEFAULT 'cash',
    `total_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`         VARCHAR(20)  NOT NULL DEFAULT 'completed',
    `created_by`     INT(11)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_sr_sale` (`sale_id`),
    KEY `idx_sr_no`   (`return_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sale returns header';

CREATE TABLE IF NOT EXISTS `sale_return_items` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `return_id`         INT(11)      NOT NULL,
    `sale_item_id`      INT(11)      NOT NULL,
    `product_id`        INT(11)      NOT NULL,
    `batch_id`          INT(11)      NULL,
    `quantity`          INT(11)      NOT NULL DEFAULT 1,
    `unit_price`        DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_sri_return` (`return_id`),
    KEY `idx_sri_sitem`  (`sale_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4. invoice_pdf_log: optional — tracks PDF generation requests
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoice_pdf_log` (
    `id`         INT(11)  NOT NULL AUTO_INCREMENT,
    `sale_id`    INT(11)  NOT NULL,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `generated_by` INT(11) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_pdflog_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 5. Backfill batch_number / expiry_date into sale_items
--    for any existing rows that sold from a batch
-- ──────────────────────────────────────────────────────────────
UPDATE `sale_items` si
JOIN   `batches`    b ON b.id = si.batch_id
SET    si.batch_number = COALESCE(si.batch_number, b.batch_number),
       si.expiry_date  = COALESCE(si.expiry_date,  b.expiry_date)
WHERE  si.batch_id IS NOT NULL
  AND  (si.batch_number IS NULL OR si.expiry_date IS NULL);

-- ──────────────────────────────────────────────────────────────
-- Done
-- ──────────────────────────────────────────────────────────────
SELECT CONCAT('Phase 25 POS Advanced migration complete. sale_items columns ready.') AS status;
