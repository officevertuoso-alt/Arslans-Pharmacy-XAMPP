# PHASE 26 — DATABASE ENGINEERING AUDIT REPORT
**Arslan's Pharmacy ERP** | June 2026
**Input:** Phase 25 POS Advanced ZIP (`pharmacy_db (3).sql` + all migration files)

---

## 1. TABLES INVENTORY

| # | Table | Columns | PK | FKs (before) | FKs (after) | Notes |
|---|-------|---------|----|----|-----|-------|
| 1 | `active_sessions` | 9 | ✅ FIXED | 0 | 1 | PK was defined in CREATE but missing from ALTER block |
| 2 | `activity_logs` | 7 | ✅ | 1 | 1 | OK |
| 3 | `batches` | 19 | ✅ | 3 | 3 | Duplicate indexes removed (expiry_date, product_id) |
| 4 | `cashbook` | 13+2 | ✅ | 0 | 2 | No FKs existed; payment_method + created_by added |
| 5 | `categories` | 5 | ✅ | 0 | 0 | Correct — self-referencing parent_id not FK'd (no cycle risk) |
| 6 | `company_settings` | 70+ | ✅ | 0 | 0 | Singleton config table; no FK needed |
| 7 | `credit_payments` | 6 | ✅ | 1 | 1 | OK |
| 8 | `customer_credits` | 9 | ✅ | 0 | 1 | sale_id FK added |
| 9 | `customers` | 10+1 | ✅ | 0 | 0 | Standalone; total_due column normalised |
| 10 | `dosage_forms` | 4 | ✅ | 0 | 0 | Lookup table; correct |
| 11 | `expense_categories` | 4+1 | ✅ | 0 | 0 | status column added |
| 12 | `expenses` | 10+1 | ✅ | 0 | 2 | added_by + category_id FKs added |
| 13 | `expiry_alerts_view` | VIEW | — | — | — | FIXED: is_blocked filter + deleted_at filter |
| 14 | `invoice_pdf_log` | 4 | ✅ | 0 | 2 | Phase 25 table — sale_id + generated_by FKs added |
| 15 | `invoice_sequences` | 3→5 | ✅ | 0 | 0 | year + month columns added; unique key rebuilt |
| 16 | `product_barcodes` | 6 | ✅ | 1 | 1 | Phase 24 table — already correct |
| 17 | `products` | 27+1 | ✅ | 1 | 1 | deleted_at added (Phase 24); composite index added |
| 18 | `product_variants` | 8 | ✅ | 1 | 1 | OK |
| 19 | `purchase_items` | 11 | ✅ | 2 | 2 | Duplicate index removed |
| 20 | `purchase_returns` | 13+1 | ✅ | 1 | 4 | purchase_item_id + batch_id + created_by FKs added |
| 21 | `purchases` | 15 | ✅ | 1 | 2 | created_by FK added |
| 22 | `role_permissions` | 12 | ✅ | 0 | 0 | Phase 21 table; UNIQUE key on role+module OK |
| 23 | `sale_items` | 11+2 | ✅ | 2 | 2 | Phase 25 added batch_number + expiry_date cols |
| 24 | `sale_return_items` | 8 | ✅ | 0 | 4 | Phase 25 table — all FKs added |
| 25 | `sale_returns` | 13 | ✅ | 1 | 4 | sale_item_id + batch_id + created_by FKs added |
| 26 | `sales` | 14+6 | ✅ | 0 | 1 | Phase 25 added 6 columns; created_by FK added |
| 27 | `stock_movements` | 11 | ✅ | 1 | 2 | created_by FK + reference covering index added |
| 28 | `stock_summary_view` | VIEW | — | — | — | FIXED: deleted_at filter added |
| 29 | `stock_transfers` | 8+1 | ✅ | 0 | 4 | All FKs added; notes column added |
| 30 | `strengths` | 4 | ✅ | 0 | 0 | Lookup table; correct |
| 31 | `supplier_payments` | 8+1 | ✅ | 2 | 3 | created_by column + FK added |
| 32 | `suppliers` | 13 | ✅ | 0 | 0 | OK |
| 33 | `system_preferences` | 7 | ✅ | 0 | 0 | Singleton config; correct |
| 34 | `user_permissions` | 10+3 | ✅ | 0 | 1 | user_id FK added |
| 35 | `users` | 14+8 | ✅ | 0 | 0 | Correct — is root entity |

---

## 2. FOREIGN KEYS — BEFORE vs AFTER

| FK | Table | Column | References | Status |
|----|-------|--------|-----------|--------|
| FK-01 | `cashbook` | `added_by` | `users.id` | ➕ ADDED |
| FK-02 | `cashbook` | `created_by` | `users.id` | ➕ ADDED |
| FK-03 | `expenses` | `added_by` | `users.id` | ➕ ADDED |
| FK-04 | `expenses` | `category_id` | `expense_categories.id` | ➕ ADDED |
| FK-05 | `customer_credits` | `sale_id` | `sales.id` | ➕ ADDED |
| FK-06 | `purchase_returns` | `purchase_item_id` | `purchase_items.id` | ➕ ADDED |
| FK-07 | `purchase_returns` | `batch_id` | `batches.id` | ➕ ADDED |
| FK-08 | `purchase_returns` | `created_by` | `users.id` | ➕ ADDED |
| FK-09 | `sale_returns` | `sale_item_id` | `sale_items.id` | ➕ ADDED |
| FK-10 | `sale_returns` | `batch_id` | `batches.id` | ➕ ADDED |
| FK-11 | `sale_returns` | `created_by` | `users.id` | ➕ ADDED |
| FK-12 | `stock_movements` | `created_by` | `users.id` | ➕ ADDED |
| FK-13 | `stock_transfers` | `from_batch_id` | `batches.id` | ➕ ADDED |
| FK-14 | `stock_transfers` | `to_batch_id` | `batches.id` | ➕ ADDED |
| FK-15 | `stock_transfers` | `product_id` | `products.id` | ➕ ADDED |
| FK-16 | `stock_transfers` | `created_by` | `users.id` | ➕ ADDED |
| FK-17 | `invoice_pdf_log` | `sale_id` | `sales.id` | ➕ ADDED |
| FK-18 | `invoice_pdf_log` | `generated_by` | `users.id` | ➕ ADDED |
| FK-19 | `sale_return_items` | `return_id` | `sale_returns.id` | ➕ ADDED |
| FK-20 | `sale_return_items` | `sale_item_id` | `sale_items.id` | ➕ ADDED |
| FK-21 | `sale_return_items` | `product_id` | `products.id` | ➕ ADDED |
| FK-22 | `sale_return_items` | `batch_id` | `batches.id` | ➕ ADDED |
| FK-23 | `user_permissions` | `user_id` | `users.id` | ➕ ADDED |
| FK-24 | `purchases` | `created_by` | `users.id` | ➕ ADDED |
| FK-25 | `sales` | `created_by` | `users.id` | ➕ ADDED |
| FK-26 | `supplier_payments` | `created_by` | `users.id` | ➕ ADDED |

**Total FKs before Phase 26:** 18  
**Total FKs after Phase 26:** 44  

---

## 3. INDEXES — ADDED & CLEANED

### New Composite/Covering Indexes Added (28)

| Table | Index Name | Columns | Purpose |
|-------|-----------|---------|---------|
| `active_sessions` | `idx_as_user` | `user_id` | Session lookup by user |
| `active_sessions` | `idx_as_session` | `session_id` | Token auth lookup |
| `active_sessions` | `idx_as_activity` | `last_activity` | Timeout cleanup |
| `batches` | `idx_batch_supplier_date` | `supplier_id, purchase_date` | Supplier report queries |
| `cashbook` | `idx_cb_date_type` | `entry_date, entry_type, voided` | Cashbook report range scan |
| `cashbook` | `idx_cb_ref` | `reference_type, reference_id` | Orphan + link detection |
| `customers` | `idx_cust_phone` | `phone` | POS customer lookup |
| `customers` | `idx_cust_status` | `status` | Active customer filter |
| `customers` | `idx_cust_name` | `name` | Search |
| `customer_credits` | `idx_cc_phone` | `customer_phone` | Credit module lookup |
| `customer_credits` | `idx_cc_status` | `status` | Pending filter |
| `customer_credits` | `idx_cc_due` | `due_date` | Overdue reports |
| `expenses` | `idx_exp_date` | `expense_date` | Date-range expense report |
| `expenses` | `idx_exp_category` | `category` | Category filter (legacy) |
| `expenses` | `idx_exp_cat_id` | `category_id` | FK join performance |
| `invoice_sequences` | `idx_iseq_prefix` | `prefix, year` | Sequence lookup |
| `stock_movements` | `idx_sm_ref` | `reference_type, reference_id` | Reference join |
| `stock_movements` | `idx_sm_batch_type` | `batch_id, movement_type, created_at` | Batch history |
| `sale_return_items` | `idx_sri_product` | `product_id` | Product return history |
| `sale_return_items` | `idx_sri_batch` | `batch_id` | Batch return history |
| `products` | `idx_prod_deleted_status` | `deleted_at, status` | Soft-delete filter |
| `products` | `idx_prod_brand` | `brand_name` | Brand search |
| `users` | `idx_users_email` | `email` | Email login + lookup |
| `users` | `idx_users_status` | `status` | Active user filter |
| `users` | `idx_users_role` | `role` | Role-based query |
| `supplier_payments` | `idx_sp_supplier_date` | `supplier_id, payment_date` | Supplier statement |
| `purchase_returns` | `idx_pr_batch` | `batch_id` | Batch return lookup |
| `sale_returns` | `idx_sr_batch` | `batch_id` | Batch return lookup |

### Duplicate Indexes Removed (5)

| Table | Index Dropped | Reason |
|-------|--------------|--------|
| `batches` | `expiry_date` | Duplicate of `idx_batches_expiry` |
| `batches` | `product_id` | Duplicate of `idx_batches_product` |
| `sales` | `sale_date` | Duplicate of `idx_sales_date` |
| `purchase_items` | `purchase_id` | Duplicate of `idx_purchase_items_purchase` |
| `sale_items` | `sale_id` | Duplicate of `idx_sale_items_sale` |

---

## 4. VIEWS

| View | Status | Change |
|------|--------|--------|
| `expiry_alerts_view` | 🔧 FIXED | Added `is_blocked = 0` filter; added `deleted_at IS NULL` guard |
| `stock_summary_view` | 🔧 FIXED | Added `deleted_at IS NULL` filter for soft-deleted products |
| `low_stock_view` | ➕ NEW | Products at or below `reorder_level` — feeds dashboard widget |
| `pending_credits_view` | ➕ NEW | Active credits with balance + overdue days — feeds credit module |
| `daily_cashflow_view` | ➕ NEW | Daily credit/debit totals by entry_type — feeds cashbook reports |

---

## 5. TRIGGERS

| Trigger | Table | Event | Status | Purpose |
|---------|-------|-------|--------|---------|
| `update_expired_flag` | `batches` | BEFORE UPDATE | ✅ Exists | Sets is_expired on date change |
| `set_expired_flag_on_insert` | `batches` | BEFORE INSERT | ➕ NEW | Sets is_expired on new batch |
| `sync_product_stock_after_batch_update` | `batches` | AFTER UPDATE | ➕ NEW | Keeps products.stock_quantity accurate |
| `sync_product_stock_after_batch_insert` | `batches` | AFTER INSERT | ➕ NEW | Keeps products.stock_quantity accurate |

---

## 6. STORED PROCEDURES

| Procedure | Status | Purpose |
|-----------|--------|---------|
| `sp_get_next_invoice_no(prefix, OUT invoice)` | ➕ NEW | Atomic invoice number generation — eliminates race conditions in multi-user POS |
| `sp_fefo_batch_pick(product_id, qty, OUT batch_id, batch_no, expiry, available)` | ➕ NEW | FEFO batch selection — replaces duplicated PHP logic in process_sale.php |

---

## 7. COLUMN ADDITIONS

| Table | Column | Type | Reason |
|-------|--------|------|--------|
| `invoice_sequences` | `year` | INT(4) | Part of composite unique key for monthly sequences |
| `invoice_sequences` | `month` | INT(2) | Part of composite unique key for monthly sequences |
| `cashbook` | `payment_method` | ENUM | Was missing from base (3).sql; added by Phase 23 migration |
| `cashbook` | `created_by` | INT(11) | Alias of `added_by`; unified naming |
| `expense_categories` | `status` | TINYINT(1) | Needed for soft-enable/disable of categories |
| `expenses` | `category_id` | INT(11) FK | FK to `expense_categories`; replaces freetext category string |
| `customers` | `total_due` | DECIMAL(12,2) | Normalise naming — enterprise patch used `total_due`, (3).sql used `outstanding_balance` |
| `supplier_payments` | `created_by` | INT(11) FK | Audit trail for payments |
| `stock_transfers` | `notes` | TEXT | Free-text note for transfer reason detail |

---

## 8. KNOWN LEGACY ORPHAN TABLES

The following tables were found in `pharmacy_db.sql` (old version) but are **NOT** in the Phase 25 schema. They appear to be legacy tables from a previous system and should be reviewed for removal or data migration:

| Table | Issue |
|-------|-------|
| `collection` | Legacy — uses `varchar` for all monetary amounts. No FK. No relation to current schema. |
| `customer` | Legacy — completely different schema from `customers`. |
| `products` (old) | Legacy — completely different schema from current `products`. |
| `purchases` (old) | Legacy — no relation to current `purchases`. |
| `purchases_item` | Legacy — standalone with no FK. |
| `sales` (old) | Legacy — different schema. |
| `sales_order` | Legacy — no FK constraints. |
| `supliers` | Legacy — note the typo. No FK. |
| `user` | Legacy — single table, separate from `users`. |

**Recommendation:** Confirm none of these tables are referenced by any active PHP files, then drop them in Phase 27.

---

## 9. USAGE NOTES FOR sp_get_next_invoice_no

Replace PHP invoice generation in `process_sale.php` with:

```sql
CALL sp_get_next_invoice_no('INV', @invoice_no);
SELECT @invoice_no;
```

Or in PHP:
```php
$stmt = $pdo->prepare("CALL sp_get_next_invoice_no('INV', @inv)");
$stmt->execute();
$row = $pdo->query("SELECT @inv AS invoice_no")->fetch();
$invoice_no = $row['invoice_no']; // e.g. INV-2026-06-0042
```

---

*Phase 26 complete. Next: Phase 27 — Legacy Cleanup & API Layer.*
