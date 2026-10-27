# Phase 21 — Role System: Permission Verification

**Author:** Dr. Arslan Yasin Sukhera | June 2026  
**Depends on:** Phase 20 (User Management)

---

## Roles Defined

| Role        | Description                                    | DB-backed Permissions |
|-------------|------------------------------------------------|-----------------------|
| **Admin**   | Full access — bypasses all permission checks   | No (shortcut in canDo) |
| **Manager** | Operational lead — broad access, no system ops | Yes                   |
| **Pharmacist** | Clinical/inventory — sales, products, reports | Yes                  |
| **Cashier** | POS only — sales, returns, credits             | Yes                   |
| **Viewer**  | Read-only — view + print + export              | Yes                   |
| **Custom**  | Manually configured per user by Admin          | Yes (all zeros base)  |

---

## Database Changes (`phase21_role_system.sql`)

1. **`users.role` ENUM expanded** — added `manager`, `custom` alongside existing values
2. **`user_permissions` columns verified** — `can_view`, `can_add`, `can_edit`, `can_delete`, `can_print`, `can_export`, `can_import`, `can_void`
3. **`role_permissions` table created** — canonical default template per role (14 modules × 5 roles)
4. **Seeds populated** — Manager, Pharmacist, Cashier, Viewer, Custom defaults
5. **Backfill query** — existing users with no `user_permissions` rows get their role's defaults applied automatically
6. **Unique key** on `user_permissions(user_id, module)` — prevents duplicate rows

---

## Permission Matrix (role_permissions)

### Modules
`dashboard` · `products` · `sales` · `purchases` · `returns` · `credits` · `suppliers` · `cashbook` · `expenses` · `stock` · `reports` · `users` · `settings` · `backup`

### Actions per module
`view` · `add` · `edit` · `delete` · `print` · `export` · `import` · `void`

---

## Code — Permission Functions (`includes/auth.php`)

| Function | Description |
|----------|-------------|
| `canDo($module, $action)` | Core: returns bool, admin bypasses, others DB-checked with static cache |
| `canView($module)` | Alias → canDo($module, 'view') |
| `canAdd($module)` | Alias → canDo($module, 'add') |
| `canEdit($module)` | Alias → canDo($module, 'edit') |
| `canDelete($module)` | Alias → canDo($module, 'delete') |
| `canPrint($module)` | Alias → canDo($module, 'print') |
| `canExport($module)` | Alias → canDo($module, 'export') |
| `canImport($module)` | Alias → canDo($module, 'import') |
| `canVoid($module)` | Alias → canDo($module, 'void') |
| `requirePermission($module, $action)` | Redirects (pages) or returns JSON error (AJAX) if denied |
| `isAdmin()` | Checks `$_SESSION['user_role']` or `$_SESSION['role']` === 'admin' |
| `isManager()` | Checks role === 'manager' |
| `hasAnyRole(array)` | True if current role is in given array |
| `requireManager()` | Redirect unless admin or manager |
| `requireAdmin()` | Redirect unless admin |

---

## AJAX Permission Verification — Files Patched

| File | Module | Action |
|------|--------|--------|
| `save_product.php` | products | add |
| `process_return.php` | returns | add |
| `inventory_adjustments.php` | stock | view |
| `inventory_transfers.php` | stock | view |
| `save_stock_transfer.php` | stock | add |
| `print_invoice.php` | sales | print |
| `print_barcode_labels.php` | products | print |
| `get_purchase_details.php` | purchases | view |
| `get_sale_details.php` | sales | view |
| `get_sales_chart_data.php` | dashboard | view |
| `get_stock_data.php` | stock | view |
| `get_batches.php` | products | view |
| `get_batch_fifo.php` | products | view |
| `get_product_batches.php` | products | view |
| `get_product_form_data.php` | products | view |
| `update_batch_price.php` | products | edit |
| `update_batch_reorder.php` | products | edit |
| `update_batch_sale_price.php` | sales | edit |
| `update_sale_price.php` | sales | edit |
| `update_product_image.php` | products | edit |
| `search_products.php` | products | view |
| `download_import_template.php` | products | import |
| `check_username.php` | — | requireLogin() |
| `complete_security_setup.php` | — | requireLogin() |
| `skip_security_setup.php` | — | requireLogin() |
| `get_notifications.php` | — | requireLogin() |

**All AJAX files are now guarded.** Total AJAX files verified: 77

---

## Page Permission Verification

### Reports — All patched with `canDo('reports','view')`
- `sales_report.php`, `purchase_report.php`, `stock_report.php`, `profit_loss_report.php`
- `expiry_report.php`, `supplier_report.php`, `customer_report.php`, `index.php`
- `export_excel.php` → `canDo('reports','export')`
- `ajax_expiry_search.php`, `ajax_stock_search.php` → already had guards (ISS-036)

### Other pages — All confirmed guarded via:
- `requireAdmin()` — admin-only (users/, backup/, settings/factory_reset, settings/theme_editor)  
- `requirePharmacist()` — admin + manager + pharmacist level (products/, purchases/, stock/)
- `requirePermission()` — granular canDo check (users/add, users/edit, suppliers/, etc.)
- `requireManager()` — admin + manager level (specific financial pages)

### Fragment files patched (session guard added)
- `sales/list_fragment.php` → session check
- `suppliers/list_fragment.php` → session check
- `customers/list_fragment.php` → session check
- `purchases/get_product_json.php` → `requireLogin()` + `canDo('purchases','view')`

---

## Sidebar — Menu Permission Gates (`includes/sidebar.php`)

All nav sections are gated with `canView($module)`:
- Dashboard, Products, Sales, Purchases, Returns, Credits, Suppliers
- Cashbook, Expenses, Stock, Reports, Users, Settings, Backup

Manager and Custom roles use DB-backed `canDo()` via `auth.php`. The sidebar fallback (for pages that load sidebar before auth.php) uses a conservative role map.

---

## Deployment Instructions

1. **Run SQL:** `phase21_role_system.sql` in phpMyAdmin or MySQL CLI
2. **Deploy PHP files** — no further changes needed; all guards are in-place
3. **Clear PHP opcache** if using OPcache: `opcache_reset()` or restart PHP-FPM
4. **Test each role:**
   - Admin → all pages accessible
   - Manager → no users/delete, no backup/restore, no factory-reset
   - Pharmacist → no users, no settings, no backup
   - Cashier → sales/POS only, no products/edit, no reports/export
   - Viewer → read-only everywhere, no add/edit/delete
   - Custom → only what admin explicitly grants

