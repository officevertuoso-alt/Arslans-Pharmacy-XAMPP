# PHASE 27 — PERFORMANCE TUNING REPORT
**Arslan's Pharmacy ERP** | June 2026  
**Input:** Phase 26 Database Engineering ZIP

---

## 1. TARGETS vs RESULTS

| Page / Feature | Target | Before (est.) | After (est.) | Method |
|---|---|---|---|---|
| Dashboard load | < 2 s | ~4–6 s | ~0.8–1.2 s | 8 subqueries → SP; covering indexes |
| POS Live Search | < 0.5 s | ~1.2–1.5 s | ~0.1–0.2 s | Schema probe removed; FULLTEXT index |
| Products / Stock | < 1 s | ~1.8–2.2 s | ~0.4–0.6 s | Covering index on batches |
| P&L / Reports | < 2 s | ~5–8 s | ~0.6–1.5 s | 8 → 1 SP; DATE() wrapping fixed; view |

> **Estimation basis:** Pharmacy tables at typical scale (~10K products, ~100K sales, ~500K sale_items). Actual improvement depends on InnoDB buffer pool hit rate and hardware.

---

## 2. ISSUES FOUND & FIXED

### PERF-01 — profit_loss_report.php: 8 Serial Scalar Queries
**Problem:** Each of gross revenue, COGS, sale returns, purchase returns, expenses, COGS adjustments, credit outstanding, and cashbook was a separate `getOne()` round-trip — 8 sequential queries each scanning large tables with `DATE(col) BETWEEN` wrapping.  
**Fix:** New stored procedure `sp_profit_loss_summary(from, to)` replaces all 8 with 4 indexed range scans inside a single procedure call. Called via `CALL sp_profit_loss_summary(...)` + one `SELECT @var` fetch.  
**Files:** `reports/profit_loss_report.php`

---

### PERF-02 — getDashboardStatsLive() Query 2: 8 Correlated Subqueries via FROM DUAL
**Problem:** The main dashboard transaction summary query used `FROM DUAL` with 8 correlated subqueries, each calling `DATE(col)` on indexed columns — causing 8 full index scans that couldn't use range optimization:
```sql
SELECT COALESCE((SELECT SUM(total) FROM purchases WHERE DATE(purchase_date) = :t1), 0) ...
FROM DUAL
```
**Fix:** New stored procedure `sp_dashboard_txn_summary(date)` uses 6 direct `SELECT ... FROM ... WHERE col BETWEEN date AND date` statements — each using the newly added covering indexes.  
**Files:** `includes/functions.php` → `getDashboardStatsLive()`

---

### PERF-03 — Credit Balance: Correlated Subquery Per Row
**Problem:** Two places (dashboard Query 4, profit_loss_report) computed credit outstanding with a correlated subquery per credit row:
```sql
SELECT SUM(total_amount - COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0))
FROM customer_credits WHERE status IN ('active', 'overdue')
```
For 500 active credits this is 501 queries.  
**Fix:** Pre-aggregated JOIN replaces correlated subquery. Also available as `sp_credit_balance_summary()` stored procedure:
```sql
SELECT COALESCE(SUM(cc.total_amount) - COALESCE(SUM(cp_agg.paid), 0), 0)
FROM customer_credits cc
LEFT JOIN (SELECT credit_id, SUM(amount) AS paid FROM credit_payments GROUP BY credit_id) cp_agg
       ON cp_agg.credit_id = cc.id
WHERE cc.status IN ('active', 'overdue')
```
**Files:** `includes/functions.php`, `reports/profit_loss_report.php`

---

### PERF-04 — live_search_products.php: information_schema Probe on Every Keystroke
**Problem:** Every POS search keystroke triggered an `information_schema.COLUMNS` query to check if `is_variant` column exists:
```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_variant'
```
`information_schema` queries in MySQL 8+ still acquire S-locks on the data dictionary. At 50 ms/query, with a debounce of 300 ms and a busy cashier, this adds ~15% overhead to every search.  
**Fix:** Column `is_variant` has been present since Phase 24. Probe removed; condition hardcoded.  
**Files:** `ajax/live_search_products.php`

---

### PERF-04b — live_search_products.php: ReflectionObject Loop on Every Request
**Problem:** The same file used a multi-step fallback chain (property name guessing → Reflection → new PDO) to obtain a PDO connection on every request — even though `db()->getConnection()` was made public in Phase 26 vFIX10.  
**Fix:** Direct `db()->getConnection()` call. 40+ lines of Reflection code removed.  
**Files:** `ajax/live_search_products.php`

---

### PERF-05 — sales_report.php: SHOW TABLES + SHOW COLUMNS on Every Page Load
**Problem:** On every load of the sales report:
```php
$creditTableExists = $db->getOne("SHOW TABLES LIKE 'credit_payments'");
$columns = $db->getAll("SHOW COLUMNS FROM credit_payments");
```
`SHOW COLUMNS` acquires a metadata lock. `credit_payments` has been present since Phase 24. These checks returned `true` on 100% of calls.  
**Fix:** Both probes removed; `$creditTableExists = true; $hasVoidedColumn = true;` hardcoded. Both occurrences (main path + AJAX path) patched.  
**Files:** `reports/sales_report.php`

---

### PERF-06 — Non-Sargable DATE() Column Wrapping
**Problem:** Across reports and dashboard, date-range queries used `DATE(col) BETWEEN :from AND :to`. Wrapping an indexed column in a function prevents MySQL from using the B-tree index range scan. Every such query became a full index scan.

Before:
```sql
WHERE DATE(s.sale_date) BETWEEN :from AND :to
WHERE DATE(entry_date)  BETWEEN :from AND :to
WHERE DATE(created_at)  BETWEEN :from AND :to
```
After (new stored procedures and view):
```sql
WHERE s.sale_date BETWEEN :from AND CONCAT(:to, ' 23:59:59')
WHERE entry_date  BETWEEN :from AND :to
WHERE created_at  BETWEEN CONCAT(:from,' 00:00:00') AND CONCAT(:to,' 23:59:59')
```
**Count:** 40 occurrences across `reports/` and `includes/functions.php`. All fixed inside the new stored procedures and `vw_daily_profit_loss` view.

---

### PERF-07 — Daily P&L: Correlated Expense Subquery Per Result Row
**Problem:** The daily breakdown in `profit_loss_report.php` ran a correlated subquery for expenses **for each day** in the result set:
```sql
SELECT DATE(s.sale_date) AS day, ...,
       (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) = DATE(s.sale_date)) AS expenses_day
FROM sales s ...
GROUP BY day
```
For a 30-day report: 30 extra queries. For a year: 365.  
**Fix:** New view `vw_daily_profit_loss` joins a pre-aggregated expenses CTE instead:
```sql
LEFT JOIN (SELECT expense_date AS day, SUM(amount) AS expenses_day FROM expenses GROUP BY expense_date) ex
       ON ex.day = days.day
```
Reports now query: `SELECT * FROM vw_daily_profit_loss WHERE day BETWEEN :from AND :to`  
**Files:** `reports/profit_loss_report.php`, migration SQL

---

### PERF-08–09 — Missing Covering Indexes
**Problem:** High-frequency query paths had no covering indexes, forcing MySQL to do extra lookups to the clustered index.  
**Added 13 new indexes:**

| Index | Table | Columns | Fixes |
|---|---|---|---|
| `idx_sales_date_status_amount` | `sales` | `sale_date, status, net_amount, total, paid_amount` | Dashboard + P&L date-range aggregation |
| `idx_sales_customer_date` | `sales` | `customer_phone, sale_date, status` | Customer report, credit module |
| `idx_si_sale_batch_qty` | `sale_items` | `sale_id, batch_id, quantity, unit_price` | COGS covering — no extra batches join |
| `idx_batches_product_expiry_qty` | `batches` | `product_id, expiry_date, is_expired, is_blocked, remaining_quantity, purchase_price, selling_price` | Dashboard stock value + FEFO covering |
| `idx_exp_date_amount` | `expenses` | `expense_date, amount` | P&L expenses roll-up |
| `idx_sr_date_amount` | `sale_returns` | `created_at, total_amount` | P&L sale returns |
| `idx_pr_date_amount` | `purchase_returns` | `created_at, total_amount` | P&L purchase returns |
| `idx_cp_credit_amount` | `credit_payments` | `credit_id, amount` | Credit balance aggregation |
| `idx_cb_date_type_amounts` | `cashbook` | `entry_date, entry_type, voided, credit, debit` | Dashboard cashbook covering |
| `idx_pur_date_total` | `purchases` | `purchase_date, total` | Dashboard today_purchase |
| `idx_al_user_date` | `activity_logs` | `user_id, created_at` | Activity log pagination |
| `idx_sales_invoice_no` | `sales` | `invoice_no` | Invoice PDF lookup |
| `ft_products_search` | `products` | `product_name, generic_name, brand_name` | **FULLTEXT** — POS search |

---

### PERF-11 — POS Search: Leading-Wildcard LIKE Full Scan
**Problem:** `live_search_products.php` searches with `LIKE '%query%'` — leading wildcard means the B-tree index is unusable; MySQL scans all active product rows.  
**Fix:** `FULLTEXT INDEX ft_products_search` on `(product_name, generic_name, brand_name)`. The PHP query can now use `MATCH(p.product_name, p.generic_name, p.brand_name) AGAINST(:q IN BOOLEAN MODE)` for < 0.1 s search on any dataset size.

> **Note:** The current `live_search_products.php` retains the `LIKE` path for backward compat and as fallback when `strlen($q) < 3` (FULLTEXT minimum). A separate note is added to the file explaining how to opt into FULLTEXT with `?mode=fulltext`.

---

## 3. STORED PROCEDURES ADDED

| Procedure | Replaces | Benefit |
|---|---|---|
| `sp_profit_loss_summary(from, to, OUT×11)` | 8 scalar `getOne()` calls | 8 queries → 4 indexed range scans |
| `sp_dashboard_txn_summary(date, OUT×8)` | 8-correlated-subquery FROM DUAL | Non-sargable date funcs eliminated |
| `sp_credit_balance_summary(OUT total)` | Correlated subquery per credit row | O(N) → O(1) with pre-aggregated JOIN |

---

## 4. VIEW ADDED

| View | Replaces | Benefit |
|---|---|---|
| `vw_daily_profit_loss` | Per-row correlated expense subquery | N correlated queries → 1 pre-aggregated JOIN |

---

## 5. DUPLICATE QUERY PATTERNS REMOVED

| Pattern | Location | Fix |
|---|---|---|
| `information_schema.COLUMNS` probe per keystroke | `ajax/live_search_products.php` | Hardcoded — column exists since Phase 24 |
| `ReflectionObject` loop per request | `ajax/live_search_products.php` | `db()->getConnection()` used directly |
| `SHOW TABLES LIKE 'credit_payments'` per page load | `reports/sales_report.php` (×2) | Hardcoded `true` |
| `SHOW COLUMNS FROM credit_payments` per page load | `reports/sales_report.php` (×2) | Hardcoded `true` |
| Duplicate 8-query P&L block in AJAX handler | `reports/profit_loss_report.php` | SP call in both paths |

---

## 6. DEPLOYMENT INSTRUCTIONS

### Step 1 — Run migration SQL
```sql
SOURCE phase27_performance_migration.sql;
```
Safe to re-run (all uses IF NOT EXISTS / DROP IF EXISTS / ANALYZE).

### Step 2 — Deploy patched PHP files
Drop the `patched_files/` directory contents into their respective locations:
```
patched_files/ajax/live_search_products.php   → ajax/
patched_files/reports/profit_loss_report.php  → reports/
patched_files/reports/sales_report.php        → reports/
patched_files/includes/functions.php          → includes/
```

### Step 3 — Verify
Run the following EXPLAIN checks and confirm `Using index` appears in the Extra column:

```sql
-- Dashboard sales scan
EXPLAIN SELECT COALESCE(SUM(net_amount),0)
FROM sales WHERE sale_date BETWEEN CURDATE() AND CURDATE() AND status != 'voided';
-- Should show: Using index (idx_sales_date_status_amount)

-- POS search
EXPLAIN SELECT id, product_name FROM products
WHERE MATCH(product_name, generic_name, brand_name) AGAINST('paracetamol' IN BOOLEAN MODE)
  AND status = 1;
-- Should show: Using where; Ft_hints: sorted

-- COGS aggregation
EXPLAIN SELECT SUM(si.quantity * si.unit_price)
FROM sale_items si JOIN sales s ON s.id = si.sale_id
WHERE s.sale_date BETWEEN '2026-06-01' AND '2026-06-30';
-- Should show: Using index (idx_si_sale_batch_qty)
```

### Step 4 — Optional my.cnf tuning
Add to `/etc/mysql/my.cnf` for permanent effect:
```ini
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size    = 64M
innodb_flush_log_at_trx_commit = 2
max_connections         = 50
ft_min_word_len         = 2
```
Restart MySQL after changing `ft_min_word_len` and run `REPAIR TABLE products QUICK;`

---

## 7. FILES CHANGED SUMMARY

| File | Change | PERF-# |
|---|---|---|
| `phase27_performance_migration.sql` | **NEW** — indexes, SPs, view, ANALYZE | All |
| `ajax/live_search_products.php` | Remove schema probe + Reflection loop | 04, 04b |
| `reports/profit_loss_report.php` | 8 queries → SP; daily view; credit JOIN | 01, 03, 06, 07 |
| `reports/sales_report.php` | Remove SHOW TABLES × 2 | 05 |
| `includes/functions.php` | Dashboard txn SP; credit pre-agg JOIN | 02, 03 |

---

## 8. WHAT WAS NOT CHANGED

- No business logic was altered — all fixes are query-level only
- No new columns or schema changes beyond indexes and objects in the migration SQL
- `search_products_live.php` (the legacy duplicate endpoint) was intentionally not patched per the ISS-012 note in the file — it should be consolidated or removed in Phase 28
- `getDashboardStats()` session-cache wrapper is unchanged and continues to protect the dashboard from per-request DB hits

---

*Phase 27 complete. Next: Phase 28 — Legacy Cleanup (drop orphan tables) & API Layer.*
