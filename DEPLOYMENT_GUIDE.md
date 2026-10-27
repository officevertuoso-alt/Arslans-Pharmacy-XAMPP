# Arslan's Pharmacy ERP v7.0 — Deployment Guide
**Powered by Dr. Arslan Yasin Sukhera**

---

## Target Environment

| Component | Version |
|-----------|---------|
| OS | Windows 10 Pro |
| Server | XAMPP 8.2.12 |
| PHP | 8.2.12 |
| Apache | 2.4.58 |
| Database | MariaDB 10.4.32 |

---

## Step 1 — Copy Files

1. Copy the entire `pharmacy_software/` folder to:
   ```
   C:\xampp\htdocs\pharmacy_software\
   ```

2. Verify the structure:
   ```
   C:\xampp\htdocs\pharmacy_software\
   ├── config/
   ├── includes/
   ├── ajax/
   ├── sales/
   ├── purchases/
   ├── auth/
   ├── dashboard.php
   ├── pharmacy_db.sql
   ├── database_enterprise_patch.sql
   └── install.php
   ```

---

## Step 2 — Create .env File

Copy `.env.example` to `.env` and edit:

```
DB_HOST=localhost
DB_NAME=pharmacy_db
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
APP_ENV=production
```

> For XAMPP default: DB_USER=root, DB_PASS= (blank)

---

## Step 3 — Create Database

1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Create database: `pharmacy_db` (utf8mb4_unicode_ci)
3. Import **`pharmacy_db.sql`** (root-level file — has all data)
4. Then import **`database_enterprise_patch.sql`** (adds 3 missing tables + 8 indexes)

---

## Step 4 — Run Auto-Installer

Open browser: **http://localhost/pharmacy_software/install.php**

The installer will:
- Verify database connection
- Apply enterprise schema patch
- Create missing tables and indexes
- Verify default admin user
- Check directory permissions

**⚠️ Delete `install.php` after successful installation!**

---

## Step 5 — Configure Apache (mod_headers)

The `.htaccess` uses security headers that require `mod_headers`. Enable it:

1. Open `C:\xampp\apache\conf\httpd.conf`
2. Find and uncomment:
   ```
   LoadModule headers_module modules/mod_headers.so
   ```
3. Restart Apache in XAMPP Control Panel

---

## Step 6 — Login

**URL:** http://localhost/pharmacy_software/auth/login.php

**Credentials:**
- Username: `arslan`
- Password: `arslan123`

> Change password immediately after first login: Settings → Change Password

---

## Step 7 — Set Up Cron Jobs

See `cron/README_CRON_SETUP.txt` for Windows Task Scheduler setup.

Minimum recommended: daily_sync.php at 11:59 PM daily.

---

## Fixes Applied in v7.0

| ID | Category | Fix |
|----|----------|-----|
| S-02 | Security | XSS fixed in register.php — htmlspecialchars() on all POST echoes |
| S-03 | Security | requireLogin() added to sales/add.php |
| S-04 | Security | requireLogin() added to sales/list.php |
| S-05 | Security | requireLogin() added to sales/view.php |
| S-07 | Security | Auth check added to purchases/search_product_purchase.php |
| S-08 | Security | Security headers added to .htaccess (X-Frame-Options, CSP, etc.) |
| S-09 | Security | sidebar.php now loads auth.php DB-backed RBAC before fallback |
| S-10 | Bug | cashbook void: getOne() → run() for UPDATE (was silently failing) |
| S-11 | Security | APP_ENV defaults to 'production' (was 'development') |
| S-12 | Security | XSS fixed in suppliers/add.php opening_balance echo |
| M-01 | Database | customers table created |
| M-02 | Database | invoice_sequences table created |
| M-03 | Database | expense_categories table created with default categories |
| M-04 | Feature | customers/whatsapp.php WhatsApp integration implemented |
| M-10 | DevOps | cron/README_CRON_SETUP.txt with Windows Task Scheduler guide |
| M-11 | Bug | Purchase return now validates against unsold quantity only |
| P-02 | Performance | batches composite index idx_fifo + idx_expiry added |
| P-03 | Performance | sale_items idx_sale_product index added |
| P-04 | Performance | cashbook idx_date_voided index added |
| P-05 | Performance | activity_logs idx_user_date index added |
| P-06 | Performance | sales idx_date_status + idx_customer_phone indexes added |
| C-08 | Code Quality | Debug error_log() statements removed from purchases/add.php |
| C-09 | Security | Log files cleared of sensitive path/error data |
| U-02 | UI/UX | Dashboard chart clicks now navigate to sales/purchase report pages |
| U-04 | UI/UX | Invoice footer "Powered by Dr. Arslan Yasin Sukhera" in both print and preview |

---

## Factory Reset Credentials

If you use factory reset (Settings → Factory Reset):
- Username: `arslan`
- Password: `arslan123`

---

## Support

Powered by **Dr. Arslan Yasin Sukhera**
