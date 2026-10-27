-- ============================================================
-- PHASE 23 — CASHBOOK INTEGRATION MIGRATION
-- Run once on any existing database to apply schema fixes.
-- Safe to re-run: all statements use IF NOT EXISTS / MODIFY.
-- ============================================================

-- 1. Expand cashbook entry_type ENUM to include 'purchase_credit'
--    (records credit/pending purchases as informational debit entries)
ALTER TABLE `cashbook`
  MODIFY COLUMN `entry_type` ENUM(
    'sale',
    'purchase_payment',
    'purchase_credit',
    'credit_received',
    'expense',
    'sale_return',
    'purchase_return',
    'opening',
    'closing',
    'credit_given',
    'expired_loss',
    'cash_in',
    'cash_out',
    'adjustment'
  ) NOT NULL;

-- 2. Ensure 'voided' column exists (defensive — should already be present)
ALTER TABLE `cashbook`
  ADD COLUMN IF NOT EXISTS `voided` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `voided_reason` VARCHAR(255) DEFAULT NULL;

-- 3. Ensure payment_method ENUM includes all methods
ALTER TABLE `cashbook`
  MODIFY COLUMN `payment_method` ENUM(
    'cash','card','online','cheque','bank','credit_note','adjustment'
  ) DEFAULT 'cash';

-- 4. Index for orphan detection queries (entry_type + reference_id + reference_type)
ALTER TABLE `cashbook`
  ADD INDEX IF NOT EXISTS `idx_cashbook_ref_type` (`entry_type`, `reference_id`, `reference_type`);

-- ============================================================
-- Backfill: create purchase_credit entries for any existing
-- pending/credit purchases that are missing cashbook entries
-- ============================================================
INSERT INTO `cashbook`
  (entry_date, entry_type, reference_id, reference_type, description,
   debit, credit, payment_method, created_by, voided)
SELECT
  DATE(p.purchase_date),
  'purchase_credit',
  p.id,
  'purchase_credit',
  CONCAT('Credit purchase (due) - ', COALESCE(p.purchase_invoice_no, CONCAT('PUR-', p.id))),
  (p.total - COALESCE(p.paid_amount, 0)),
  0,
  'credit_note',
  1,
  0
FROM purchases p
WHERE p.payment_status IN ('pending','credit')
  AND (p.total - COALESCE(p.paid_amount, 0)) > 0
  AND p.id NOT IN (
    SELECT reference_id FROM cashbook
    WHERE entry_type = 'purchase_credit'
      AND reference_type = 'purchase_credit'
  );

-- ============================================================
-- End of Phase 23 Migration
-- ============================================================
