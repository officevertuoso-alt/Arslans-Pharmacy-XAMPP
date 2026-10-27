ARSLAN'S PHARMACY ERP — CRON JOB SETUP
=======================================
Powered by Dr. Arslan Yasin Sukhera

WINDOWS (XAMPP) TASK SCHEDULER SETUP:
======================================
Since this runs on Windows 10 with XAMPP, use Windows Task Scheduler:

1. Open Task Scheduler (taskschd.msc)
2. Create Basic Task → Name: "Pharmacy Daily Sync"
3. Trigger: Daily at 11:59 PM (or midnight)
4. Action: Start a Program
   Program: C:\xampp\php\php.exe
   Arguments: C:\xampp\htdocs\pharmacy_software\cron\daily_sync.php
5. Click Finish

CRON JOBS:
==========
Daily (11:59 PM):
  C:\xampp\php\php.exe C:\xampp\htdocs\pharmacy_software\cron\daily_sync.php
  → Marks expired batches, sends expiry alerts, posts cashbook loss entries

Weekly (Sunday midnight):
  C:\xampp\php\php.exe C:\xampp\htdocs\pharmacy_software\cron\fix_sale_totals.php
  → Recalculates and verifies all sale totals for consistency

Monthly (1st of month):
  C:\xampp\php\php.exe C:\xampp\htdocs\pharmacy_software\cron\cleanup_invoice_sequences.php
  → Cleans up old invoice sequence rows from previous months

LINUX/CRONTAB (for VPS/Cloud):
================================
# Edit with: crontab -e
59 23 * * * /usr/bin/php /var/www/html/pharmacy_software/cron/daily_sync.php >> /var/www/html/pharmacy_software/logs/cron.log 2>&1
0  0  * * 0 /usr/bin/php /var/www/html/pharmacy_software/cron/fix_sale_totals.php >> /var/www/html/pharmacy_software/logs/cron.log 2>&1
0  0  1 * * /usr/bin/php /var/www/html/pharmacy_software/cron/cleanup_invoice_sequences.php >> /var/www/html/pharmacy_software/logs/cron.log 2>&1
