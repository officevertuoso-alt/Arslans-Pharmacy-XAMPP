-- ============================================================
-- PHASE 24 – POS CORE MIGRATION
-- Run once on the pharmacy_db database.
-- ============================================================

USE `pharmacy_db`;

-- ────────────────────────────────────────────────────────────
-- 1. product_barcodes table
--    Allows multiple barcodes (EAN-13, QR, etc.) per product.
--    The barcode column on products.product_code is still the
--    PRIMARY scan target; this table is for additional codes.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_barcodes` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id`   INT(11)      NOT NULL,
    `barcode`      VARCHAR(100) NOT NULL,
    `barcode_type` VARCHAR(20)  DEFAULT 'EAN13'
                               COMMENT 'EAN13, QR, CODE128, etc.',
    `is_primary`   TINYINT(1)  DEFAULT 0,
    `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_barcode` (`barcode`),
    KEY `idx_pb_product` (`product_id`),
    CONSTRAINT `product_barcodes_ibfk_1`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Additional barcodes per product (EAN-13, QR, etc.)';

-- ────────────────────────────────────────────────────────────
-- 2. Performance indexes for POS search
--    These cover the correlated subqueries in
--    search_product_pos.php and get_product_by_barcode.php.
-- ────────────────────────────────────────────────────────────
ALTER TABLE `products`
    ADD INDEX IF NOT EXISTS `idx_products_status_deleted`
        (`status`, `deleted_at`),
    ADD INDEX IF NOT EXISTS `idx_products_code`
        (`product_code`);

ALTER TABLE `batches`
    ADD INDEX IF NOT EXISTS `idx_batches_pos_lookup`
        (`product_id`, `is_expired`, `remaining_quantity`, `expiry_date`);

-- ────────────────────────────────────────────────────────────
-- 3. Soft-delete column guard
--    products.deleted_at is used in WHERE clauses but may not
--    exist on older installs. Add it safely.
-- ────────────────────────────────────────────────────────────
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Soft delete timestamp; NULL = active';

-- ────────────────────────────────────────────────────────────
-- 4. Populate product_barcodes with existing product_codes
--    so barcode scanner works immediately after migration.
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO `product_barcodes` (`product_id`, `barcode`, `barcode_type`, `is_primary`)
SELECT `id`, `product_code`, 'CODE128', 1
FROM   `products`
WHERE  `product_code` != ''
  AND  `product_code` IS NOT NULL
  AND  `deleted_at` IS NULL;

-- ────────────────────────────────────────────────────────────
-- Done
-- ────────────────────────────────────────────────────────────
SELECT CONCAT('Phase 24 POS migration complete. product_barcodes has ',
              COUNT(*), ' rows.') AS status
FROM `product_barcodes`;
