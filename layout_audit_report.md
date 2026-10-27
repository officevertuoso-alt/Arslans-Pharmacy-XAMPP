# Pharmacy ERP — Layout Framework Audit Report
**Phase 2 · Layout Architecture Audit**
**Date:** 2026-06-09
**Scope:** All PHP page files — layout structure only. Business logic untouched.

---

## Executive Summary

The codebase uses **three competing layout patterns** across ~65 page files. The primary issue is an incomplete migration from the legacy `header.php` system to the new `page_head.php` system, leaving the app in a hybrid state. Five safe structural fixes were applied; all others are documented for Phase 3 resolution.

---

## Layout Patterns Identified

### Pattern A — Legacy (header.php)  *(7 pages)*
```
require header.php  → outputs full <!DOCTYPE>…<body>
include sidebar.php
include navbar.php
<div class="main-content"> … </div>
include footer.php  → outputs </body></html>
```
**Pages:**
- `dashboard.php`
- `credit/customer_detail.php`
- `returns/purchase_return.php`
- `returns/purchase_return_list.php`
- `returns/purchase_return_view.php`
- `stock/index.php`
- `stock/adjust.php`

---

### Pattern B — New/Hybrid (page_head.php)  *(~51 pages)*
```
<!DOCTYPE html>
<html>
<head>
  include page_head.php  ← outputs CSS vars, Bootstrap CSS, jQuery + Bootstrap JS, script.js
  [inline <style> block]
</head>
<body>
  include sidebar.php
  include navbar.php
  <div class="main-content"> … </div>
  ← NO footer.php on most pages (JS already loaded in head by page_head.php)
</body>
</html>
```
**Representative pages:** All products, sales, purchases, reports, users, settings, cashbook, expenses, returns (sale_return_*), credit, logs, customers…

---

### Pattern C — Fully Standalone  *(6 pages — no common layout)*
```
<!DOCTYPE html>
<html>
<head> … manually duplicated Bootstrap CDN … </head>
<body>
  include sidebar.php
  include navbar.php
  <div class="main-content"> … </div>
</body>
</html>
```
**Pages:**
- `settings/company.php`
- `suppliers/pay.php`
- `suppliers/statement.php` *(partially fixed — see below)*
- `sales/print_invoice.php` *(print-only page, intentional standalone)*
- `config/database.php` *(not a UI page)*
- `install.php` *(installer, intentional standalone)*

---

## Issues Found

### 1. Duplicate Headers — No True Duplicates Between Pages
There is no case of two pages both emitting a full `<head>` section simultaneously. However, `page_head.php` loads Bootstrap CSS **and** jQuery/Bootstrap JS inside `<head>`, while `footer.php` has the same JS load guarded by `CORE_JS_LOADED`. This means:
- For **Pattern B** pages: JS loads in `<head>` (render-blocking). `footer.php` is correctly skipped by the `CORE_JS_LOADED` guard.
- For **Pattern A** pages: JS loads correctly at end of body via `footer.php`.
- `auth/login.php` and several other Pattern B pages additionally hardcode a second Bootstrap CDN link — **duplicate CSS load**.

**Affected files (duplicate Bootstrap CSS load):**
| File | Extra Bootstrap Link |
|------|---------------------|
| `auth/forgot_password.php` | Line ~290 |
| `auth/login.php` | Line ~290 |
| `auth/register.php` | Line ~290 |
| `auth/reset-password.php` | Line ~290 |
| `customers/customers.php` | Inline |
| `logs/activity.php` | Inline |
| `returns/sale_return.php` | Inline |
| `returns/sale_return_list.php` | Inline |
| `returns/sale_return_view.php` | Inline |
| `sales/add.php` | Inline |
| `sales/list.php` | Inline |
| `sales/pos.php` | Inline |
| `sales/sale_view.php` | Inline |
| `sales/view.php` | Inline |

**Required fix:** Remove the inline `<link>` / `<script>` CDN tags from these 14 files — `page_head.php` already provides them.

---

### 2. Duplicate Sidebars — None Found
Each page includes `sidebar.php` exactly once. No duplicates detected.

---

### 3. Duplicate Footers — None Found
No page includes `footer.php` more than once.

---

### 4. Inconsistent Wrappers — `.main-content` CSS Variable Mismatch

Three pages used the undeclared `--sidebar-w` CSS variable instead of the correct `--sb-w` defined in `sidebar.php`. This caused the main content area to not respond to sidebar collapse.

| File | Issue | Status |
|------|-------|--------|
| `products/edit.php` | `--sidebar-w` instead of `--sb-w` | ✅ **FIXED** |
| `sales/pos.php` | `--sidebar-w` instead of `--sb-w` | ✅ **FIXED** |
| `suppliers/statement.php` | `--sidebar-w` declared and used | ✅ **FIXED** |

---

### 5. Broken Containers — Footer Inside `.main-content`

Two pages included `footer.php` **before** closing `</div><!-- /main-content -->`, placing `</body></html>` inside the content wrapper.

| File | Lines (before fix) | Status |
|------|-------------------|--------|
| `backup/index.php` | footer at line 387, `</div>` at line 500 | ✅ **FIXED** |
| `credit/customer_detail.php` | footer at line 464, `</div>` at line 465 | ✅ **FIXED** |

**Additional broken container — `returns/purchase_return_view.php`:**
Page sets `.main-content { margin-left: 0 !important }` in a print media block but also sets it unconditionally earlier, which partially hides the content behind the sidebar on screen. Not a broken container but a conflicting CSS rule.

**`suppliers/pay.php`** — Two `<body>` tags detected. Investigation confirmed the second instance is inside a JavaScript string: `w.document.write('</head><body>')` — this is a popup window print receipt. **Not a structural bug.**

---

### 6. Broken `sidebar → navbar` Include Order

The correct order for DOM rendering is: `sidebar.php` first (fixed element), then `navbar.php` (which emits the top navbar and breadcrumb that reference sidebar state).

**Pages with navbar included before sidebar (wrong order):**
| File | Current Order |
|------|--------------|
| `returns/purchase_return.php` | navbar (134) → sidebar (135) |
| `returns/purchase_return_list.php` | navbar (125) → sidebar (126) |
| `returns/purchase_return_view.php` | navbar (87) → sidebar (88) |
| `expenses/add.php` | navbar (161) → sidebar (165) |

> Note: `sales/add.php`, `sales/list.php`, `suppliers/view.php` show navbar in a *comment* at line 17 and actual `include` later — these are false positives.

**Required fix:** Swap include order in the 4 files above.

---

### 7. Pages Not Using Common Layout

The following non-utility pages render the full authenticated UI (sidebar + navbar) but bypass both `header.php` and `page_head.php`, duplicating head content manually:

| File | Issue |
|------|-------|
| `settings/company.php` | Standalone head, manual theme CSS |
| `suppliers/pay.php` | Standalone head, manual theme CSS |
| `suppliers/statement.php` | Standalone head *(CSS var partially fixed)* |

**Intentional standalones (no fix needed):**
| File | Reason |
|------|--------|
| `sales/print_invoice.php` | Print-only, no sidebar/navbar by design |
| `ajax/print_invoice.php` | Print popup |
| `ajax/print_barcode_labels.php` | Print popup |
| `config/database.php` | Config file only |
| `install.php` | Installer, pre-auth |
| `auth/login.php`, `auth/register.php`, etc. | Auth pages, intentionally no sidebar |

---

## Dependency Map

```
includes/
├── page_head.php        ← New standard. Outputs: title, favicon, CSS vars, Bootstrap CSS+JS
│   └── requires: getCompanySettings() from settings_functions.php
│   └── sets: CORE_JS_LOADED constant
│
├── header.php           ← Legacy standard. Outputs: full <!DOCTYPE>...<body>
│   └── requires: config/config.php, includes/functions.php
│   └── outputs: page-loader, noscript bar, theme CSS
│
├── sidebar.php          ← Outputs: <div id="mainSidebar"> + all JS/CSS for sidebar
│   └── requires: includes/auth.php (for canDo/canView)
│   └── requires: includes/theme_switcher.php
│   └── defines: CSS vars --sb-w, --sb-w-mini, --sb-bg, --sb-accent, --sb-primary
│
├── navbar.php           ← Outputs: <nav #topNavbar> + breadcrumb bar
│   └── defines: generateBreadcrumb()
│   └── references: --sb-w, --sb-w-mini (from sidebar.php — must load after sidebar)
│
├── footer.php           ← Outputs: jQuery, Bootstrap JS, script.js (guarded by CORE_JS_LOADED)
│   └── closes: </body></html>
│
└── page_head.php        ← Also loads jQuery/Bootstrap JS (guarded by CORE_JS_LOADED)
    └── NOTE: JS in <head> = render-blocking. Footer.php preferred for JS.
```

**Page assembly order (correct for Pattern B):**
```
<?php require config.php; ?>
<!DOCTYPE html><html><head>
  <?php include page_head.php; ?>
  [page-specific <style>]
</head>
<body>
  <?php include sidebar.php; ?>   ← must be before navbar
  <?php include navbar.php; ?>
  <div class="main-content">
    [page content]
  </div>
  [page-specific <script>]
</body></html>
```

**Page assembly order (correct for Pattern A — legacy):**
```
<?php include header.php; ?>    ← outputs <!DOCTYPE>...<body>
<?php include sidebar.php; ?>
<?php include navbar.php; ?>
<div class="main-content">
  [page content]
</div>
<?php include footer.php; ?>   ← outputs JS + </body></html>
```

---

## Applied Fixes (Safe — Layout Only)

| # | File | Fix Applied |
|---|------|-------------|
| 1 | `products/edit.php` | `--sidebar-w` → `--sb-w` (CSS variable name) |
| 2 | `sales/pos.php` | `--sidebar-w` → `--sb-w` (CSS variable name) |
| 3 | `suppliers/statement.php` | `--sidebar-w` → `--sb-w` (CSS var declaration + usage) |
| 4 | `backup/index.php` | Footer moved outside `</div><!-- /main-content -->` |
| 5 | `credit/customer_detail.php` | Footer moved outside `</div><!-- /main-content -->` |

---

## Deferred Fixes (Require Phase 3)

| Priority | Files | Fix Required |
|----------|-------|-------------|
| HIGH | 14 files (see Section 1) | Remove duplicate Bootstrap CDN `<link>` tags |
| HIGH | `returns/purchase_return.php`, `returns/purchase_return_list.php`, `returns/purchase_return_view.php`, `expenses/add.php` | Swap navbar/sidebar include order |
| MEDIUM | `settings/company.php`, `suppliers/pay.php`, `suppliers/statement.php` | Migrate to `page_head.php` pattern |
| MEDIUM | All 7 Pattern A pages | Migrate from `header.php` to `page_head.php` pattern |
| LOW | All 51 Pattern B pages | Move JS from `page_head.php` into page-end `<script>` blocks (render performance) |

---

*Report generated by Phase 2 Layout Audit — No business logic was modified.*
