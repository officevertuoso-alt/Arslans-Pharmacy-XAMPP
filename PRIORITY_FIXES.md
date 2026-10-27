# Priority fixes — Arslan Pharmacy XAMPP

## Done in this pass
1. **.htaccess install.php** — removed blanket `Require all denied` that blocked local installer before PHP could run. PHP IP lock inside `install.php` remains. Delete `install.php` after setup.

2. **.env.example** — synced with richer `env.example` content.

## Already fixed earlier (see FIXES_APPLIED.md)
- CSRF on mutation AJAX
- HTTP_REFERER open redirect
- Plaintext password fallback removed
- Unified SQL notes
- Security headers

## Common runtime issues (check on your PC)
| Symptom | Likely cause | Action |
|---------|--------------|--------|
| DB Connection Failed 2002 | MySQL not started | XAMPP Control → Start MySQL |
| White page / 500 | Missing .env or wrong DB name | Copy env.example → .env |
| install.php 403 | Was .htaccess deny | Fixed; hard-refresh / delete install after use |
| Login fails | Wrong password hash / empty users table | import SQL + install.php |
| BASE_URL wrong assets | Folder not named pharmacy_software | Keep path under htdocs/pharmacy_software |

## Next code passes (when you report exact screen error)
1. Duplicate `verifyCSRFToken` definitions audit (function_exists guards)
2. Cart/session edge cases
3. FIFO stock race under concurrent sales
4. Remove debug scripts (test.php, debug_db.php) from production tree
