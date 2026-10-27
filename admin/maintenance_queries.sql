-- =====================================================
-- MAINTENANCE QUERIES for Pharmacy Management System
-- Run these periodically or after upgrades
-- =====================================================

USE pharmacy_db;

-- -----------------------------------------------------
-- 1. Update expired batches flag
-- -----------------------------------------------------
UPDATE batches SET is_expired = 1 
WHERE expiry_date < CURDATE() AND is_expired = 0;

-- -----------------------------------------------------
-- 2. Clean orphan stock_movements (if any)
--    Foreign key cascade should handle, but just in case
-- -----------------------------------------------------
DELETE sm FROM stock_movements sm
LEFT JOIN batches b ON sm.batch_id = b.id
WHERE b.id IS NULL;

-- -----------------------------------------------------
-- 3. Initialize invoice sequences for current month
--    (based on max existing invoice numbers)
-- -----------------------------------------------------
SET @year = YEAR(CURDATE());
SET @month = MONTH(CURDATE());

-- For Sales (INV)
INSERT INTO invoice_sequences (prefix, year, month, last_number)
SELECT 'INV', @year, @month, COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_no, '-', -1) AS UNSIGNED)), 0)
FROM sales
WHERE invoice_no LIKE CONCAT('INV-', @year, LPAD(@month, 2, '0'), '-%')
ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number));

-- For Purchases (PUR)
INSERT INTO invoice_sequences (prefix, year, month, last_number)
SELECT 'PUR', @year, @month, COALESCE(MAX(CAST(SUBSTRING_INDEX(purchase_invoice_no, '-', -1) AS UNSIGNED)), 0)
FROM purchases
WHERE purchase_invoice_no LIKE CONCAT('PUR-', @year, LPAD(@month, 2, '0'), '-%')
ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number));

-- -----------------------------------------------------
-- 4. Reset products.stock_quantity to 0
--    (stock is now managed via batches table)
-- -----------------------------------------------------
UPDATE products SET stock_quantity = 0 
WHERE stock_quantity IS NULL OR stock_quantity != 0;

-- -----------------------------------------------------
-- 5. (Optional) Recalculate supplier current_balance
--    Based on purchases - payments
-- -----------------------------------------------------
UPDATE suppliers SET current_balance = (
    SELECT COALESCE(SUM(p.total), 0) - COALESCE(SUM(sp.amount), 0)
    FROM purchases p
    LEFT JOIN supplier_payments sp ON p.id = sp.purchase_id
    WHERE p.supplier_id = suppliers.id
);

-- -----------------------------------------------------
-- 6. Remove expired batches that have zero remaining
--    (soft delete by setting is_blocked = 1)
-- -----------------------------------------------------
UPDATE batches SET is_blocked = 1
WHERE is_expired = 1 AND remaining_quantity = 0 AND is_blocked = 0;

-- -----------------------------------------------------
-- 7. Rebuild expiry_alerts_view (if needed)
-- -----------------------------------------------------
CREATE OR REPLACE VIEW expiry_alerts_view AS
SELECT 
    b.id AS batch_id,
    b.batch_number,
    p.product_name,
    p.generic_name,
    b.expiry_date,
    b.remaining_quantity,
    DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expire,
    CASE 
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 0 THEN 'Expired'
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 30 THEN 'Critical'
        WHEN DATEDIFF(b.expiry_date, CURDATE()) <= 90 THEN 'Warning'
        ELSE 'Normal'
    END AS expiry_status
FROM batches b
JOIN products p ON b.product_id = p.id
WHERE b.remaining_quantity > 0
ORDER BY b.expiry_date ASC;

-- -----------------------------------------------------
-- 8. Rebuild stock_summary_view
-- -----------------------------------------------------
CREATE OR REPLACE VIEW stock_summary_view AS
SELECT 
    p.id AS product_id,
    p.product_name,
    p.generic_name,
    p.brand_name,
    p.strength,
    p.strength_unit,
    p.dosage_form,
    p.unit_type,
    p.pack_quantity,
    COALESCE(SUM(b.remaining_quantity), 0) AS stock_in_pieces,
    COUNT(DISTINCT b.id) AS batch_count,
    MIN(b.expiry_date) AS nearest_expiry,
    CASE 
        WHEN COALESCE(SUM(b.remaining_quantity), 0) = 0 THEN 'Out of Stock'
        WHEN COALESCE(SUM(b.remaining_quantity), 0) <= p.reorder_level THEN 'Low Stock'
        ELSE 'In Stock'
    END AS stock_status
FROM products p
LEFT JOIN batches b ON p.id = b.product_id AND b.is_expired = 0 AND b.is_blocked = 0
WHERE p.status = 1
GROUP BY p.id;